<?php
namespace Kyte\Core\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Kyte\Core\Api;
use Kyte\Core\ModelObject;
use Kyte\Exception\SessionException;

/**
 * Phase 3 auth strategy: HS256 access JWTs.
 *
 * Claims at issuance time:
 *   iss   issuer string (KYTE_JWT_ISSUER, defaults to 'kyte')
 *   sub   user id (KyteUser.id or app-user.id)
 *   aud   kyte_account id as string
 *   exp   issue time + access TTL (KYTE_JWT_ACCESS_TTL, default 900s)
 *   nbf   same as iat
 *   iat   issue time (unix seconds)
 *   jti   random per-token id for revocation telemetry
 *   email user email (convenience for clients; not used for auth decisions)
 *   app   application identifier when app-scoped, omitted otherwise
 *
 * The strategy claims any request with an `Authorization: Bearer <token>`
 * header whose body starts with `eyJ` (a JWT-style base64url first segment).
 * It does NOT claim `kmcp_live_` MCP bearer tokens — those start with
 * 'kmcp_' so the prefix check is unambiguous.
 *
 * Verification (preAuth):
 *   - Decode + verify signature using KYTE_JWT_SECRET.
 *   - Confirm exp not in the past (firebase/php-jwt enforces).
 *   - Confirm iss matches expected issuer.
 *   - Resolve sub → user, aud → account, app (if present) → application.
 *   - Populate $api->user, $api->account, $api->app, $api->session.
 *   - 401 SessionException on any failure.
 *
 * Refresh tokens are NOT JWTs. They live in KyteRefreshToken (separate
 * commit) and are exchanged at /jwt/refresh for a new (access, refresh)
 * pair with reuse detection. The strategy here only validates access
 * tokens — refresh token handling is in the endpoint controller.
 *
 * Config constants (define in config.php on each install):
 *   KYTE_JWT_SECRET       Required. HS256 signing key. Should be at least
 *                         256 bits of entropy. NEVER commit to version
 *                         control.
 *   KYTE_JWT_ISSUER       Optional. Defaults to 'kyte'. Mismatched tokens
 *                         are rejected at preAuth time.
 *   KYTE_JWT_ACCESS_TTL   Optional. Seconds. Defaults to 900 (15 min).
 *   KYTE_JWT_REFRESH_TTL  Optional. Seconds. Used by /jwt/refresh code,
 *                         not by this strategy. Defaults to 604800 (7d).
 *
 * See docs/design/kyte-mcp-and-auth-migration.md section 6, Phase 3.
 */
class JwtSessionStrategy implements AuthStrategy
{
    /** Returned by name() — used in shadow-mode telemetry rows. */
    public const NAME = 'jwt_session';

    private const ALGO = 'HS256';

    private const DEFAULT_ISSUER = 'kyte';

    private const DEFAULT_ACCESS_TTL = 900;

    /**
     * Inspect the Authorization header. We claim any Bearer token that
     * looks like a JWT — three base64url segments separated by '.' —
     * but not the kmcp_live_ prefix used by McpTokenStrategy.
     */
    public function matches(): bool
    {
        if (!defined('KYTE_JWT_SECRET') || KYTE_JWT_SECRET === '') {
            // JWT support not configured on this install.
            return false;
        }

        $header = $this->authorizationHeader();
        if ($header === null) {
            return false;
        }

        // Strict prefix: 'Bearer ' followed by an opaque token.
        if (stripos($header, 'Bearer ') !== 0) {
            return false;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return false;
        }

        // MCP tokens are kmcp_live_... — let McpTokenStrategy claim those.
        if (stripos($token, 'kmcp_') === 0) {
            return false;
        }

        // A JWT has three dot-separated base64url segments. The first
        // segment decodes to JSON like {"alg":"HS256","typ":"JWT"}.
        // We don't decode here — that's preAuth's job — we just check
        // the structural shape so a Bearer that happens to be some
        // other opaque token isn't claimed.
        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return false;
        }

        // First segment must start with 'eyJ' (the base64url encoding
        // of '{"' which begins every JWT header).
        return strncmp($segments[0], 'eyJ', 3) === 0;
    }

    /**
     * Decode the bearer JWT, verify the signature, and populate Api state.
     */
    public function preAuth(Api $api): void
    {
        $token = $this->extractToken();
        if ($token === null) {
            throw new SessionException('Missing JWT bearer token.');
        }

        try {
            $decoded = JWT::decode($token, new Key(KYTE_JWT_SECRET, self::ALGO));
        } catch (\Throwable $e) {
            // Catches: SignatureInvalidException, BeforeValidException,
            // ExpiredException, UnexpectedValueException, etc.
            throw new SessionException('Invalid or expired JWT: ' . $e->getMessage());
        }

        $expectedIssuer = defined('KYTE_JWT_ISSUER') ? KYTE_JWT_ISSUER : self::DEFAULT_ISSUER;
        if (!isset($decoded->iss) || $decoded->iss !== $expectedIssuer) {
            throw new SessionException('JWT issuer mismatch.');
        }

        if (!isset($decoded->sub, $decoded->aud)) {
            throw new SessionException('JWT missing required claims (sub/aud).');
        }

        // Resolve account from aud claim.
        $account = new ModelObject(KyteAccount);
        if (!$account->retrieve('id', (int)$decoded->aud)) {
            throw new SessionException('JWT account not found.');
        }
        $api->account = $account;

        // Resolve user. For app-scoped tokens, $api->app->user_model is
        // the right table; otherwise it's KyteUser. Mirrors the pattern
        // in Api::route().
        $appIdentifier = $decoded->app ?? null;
        if ($appIdentifier !== null) {
            $app = new ModelObject(Application);
            if (!$app->retrieve('identifier', $appIdentifier)) {
                throw new SessionException('JWT application not found.');
            }
            if ((int)$app->kyte_account !== (int)$account->id) {
                throw new SessionException('JWT application/account mismatch.');
            }
            $api->app = $app;
            $api->appId = $appIdentifier;

            $userModel = $app->user_model !== null ? constant($app->user_model) : KyteUser;
        } else {
            $userModel = KyteUser;
        }

        $user = new ModelObject($userModel);
        if (!$user->retrieve('id', (int)$decoded->sub)) {
            throw new SessionException('JWT user not found.');
        }
        if (isset($user->kyte_account) && (int)$user->kyte_account !== (int)$account->id) {
            throw new SessionException('JWT user/account mismatch.');
        }
        $api->user = $user;

        // The HMAC path uses $api->session (SessionManager) to hand out
        // rotating tokens. JWT has no rotating-session state; we set
        // the response slot to indicate the session is JWT-mediated so
        // ActivityLogger and others can tell.
        $api->response['session'] = 'jwt';
        $api->response['uid'] = (int)$user->id;
    }

    /**
     * Phase 2 of the contract. JWT verification is fully completed in
     * preAuth — there's no second-pass signature step like HMAC. This
     * is a no-op.
     */
    public function verify(Api $api): void
    {
        // Intentionally empty. See class docblock.
    }

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Return the Authorization header value or null.
     *
     * Apache sometimes hides the Authorization header on PHP-FPM unless
     * a RewriteRule sets HTTP_AUTHORIZATION. We check both common
     * surfaces. apache_request_headers() is a third fallback when
     * available.
     */
    private function authorizationHeader(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (\function_exists('apache_request_headers')) {
            $headers = \apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp($name, 'Authorization') === 0 && is_string($value) && $value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Return just the token portion of the Authorization header, or null
     * if no JWT bearer token is present.
     */
    private function extractToken(): ?string
    {
        $header = $this->authorizationHeader();
        if ($header === null || stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));
        return $token === '' ? null : $token;
    }
}
