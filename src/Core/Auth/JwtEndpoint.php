<?php
namespace Kyte\Core\Auth;

use Firebase\JWT\JWT;
use Kyte\Core\Api;
use Kyte\Core\ModelObject;
use Kyte\Exception\SessionException;

/**
 * URL handler for the /jwt/* endpoint family.
 *
 * Endpoints:
 *
 *   POST /jwt/login        body {email, password, app_identifier?}
 *                          → 200 {access_token, refresh_token, token_type,
 *                                 expires_in, refresh_expires_at}
 *                          → 401 invalid credentials
 *                          → 400 missing fields
 *
 *   POST /jwt/refresh      body {refresh_token}
 *                          → 200 {access_token, refresh_token, ...}
 *                          → 401 invalid / expired / reuse-detected
 *
 *   POST /jwt/logout       body {refresh_token}
 *                          → 200 {ok: true}     (idempotent)
 *
 *   POST /jwt/logout-all   body {refresh_token}
 *                          → 200 {ok: true, revoked: <count>}
 *                          Revokes EVERY active refresh token for the
 *                          user whose token was presented.
 *
 * Routing: Api::route() detects a `/jwt` first segment and dispatches
 * here before the normal MVC pipeline runs (same pattern as /mcp).
 *
 * Multilogon: every /jwt/login creates a NEW token family. Logging in
 * from device A and then device B yields two independent families;
 * revoking either does not affect the other. This is the modern norm
 * and differs from HMAC's ALLOW_MULTILOGON-gated single-session-per-user.
 *
 * Testability: process() is pure (Api, $_SERVER-shape array, raw body)
 * → return ['status' => N, 'body' => [...]]. handle() binds it to PHP
 * globals.
 */
final class JwtEndpoint
{
    private const DEFAULT_ACCESS_TTL = 900;
    private const DEFAULT_ISSUER = 'kyte';

    /**
     * Production entry point — reads globals, writes to SAPI.
     *
     * Handles CORS inline. Api::cors() lives in validateRequest() which
     * runs *downstream* of the /jwt dispatch in Api::route(), so by the
     * time we get here, no CORS headers have been emitted. Browser
     * preflight (OPTIONS) on /jwt/login would otherwise see a 405 with
     * no Access-Control-Allow-Origin and block the real POST. Mirrors
     * the permissive Origin policy in Api::cors().
     */
    public static function handle(Api $api): void
    {
        self::emitCorsHeaders();

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            $reqHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header("Access-Control-Allow-Headers: {$reqHeaders}");
            http_response_code(204);
            return;
        }

        $rawBody = (string)file_get_contents('php://input');
        $result = self::process($api, $_SERVER, $rawBody);

        http_response_code($result['status']);
        echo json_encode($result['body']);
    }

    /**
     * Emit the per-request CORS headers shared by all /jwt/* responses,
     * including the OPTIONS preflight reply. Mirrors Api::cors() so a
     * browser opening a session via /jwt/login sees the same Origin /
     * Credentials / Content-Type contract as it would for HMAC login.
     */
    private static function emitCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN']
            ?? $_SERVER['HTTP_REFERER']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '';
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Content-Type: application/json; charset=utf-8');
    }

    /**
     * Pure dispatcher. Returns ['status' => httpCode, 'body' => array].
     */
    public static function process(Api $api, array $server, string $rawBody): array
    {
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $path = ltrim((string)parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $segments = explode('/', $path);
        $action = $segments[1] ?? '';

        if ($method !== 'POST') {
            return self::error(405, 'method_not_allowed', 'POST required.');
        }

        $body = self::decodeBody($rawBody);
        $ip = self::clientIp($server);

        try {
            switch ($action) {
                case 'login':      return self::login($body, $ip);
                case 'refresh':    return self::refresh($body, $ip);
                case 'logout':     return self::logout($body);
                case 'logout-all': return self::logoutAll($body);
                default:
                    return self::error(404, 'not_found', "Unknown JWT endpoint: {$action}.");
            }
        } catch (SessionException $e) {
            return self::error(401, 'unauthorized', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('JwtEndpoint: ' . $e->getMessage());
            return self::error(500, 'internal_error', 'Internal error.');
        }
    }

    private static function login(array $body, string $ip): array
    {
        $appIdentifier = isset($body['app_identifier']) && $body['app_identifier'] !== ''
            ? (string)$body['app_identifier']
            : null;

        $context = self::resolveAuthContext($appIdentifier);
        $usernameField = $context['username_field'];
        $passwordField = $context['password_field'];
        $userModel     = $context['user_model'];
        $app           = $context['app'];

        if (empty($body[$usernameField]) || empty($body[$passwordField])) {
            return self::error(400, 'invalid_request', "Missing {$usernameField} or {$passwordField}.");
        }

        $user = new ModelObject($userModel);
        if (!$user->retrieve($usernameField, $body[$usernameField])) {
            // Avoid revealing whether the username exists.
            return self::error(401, 'invalid_credentials', 'Invalid credentials.');
        }

        $hashed = $user->{$passwordField} ?? null;
        if (!is_string($hashed) || !password_verify((string)$body[$passwordField], $hashed)) {
            return self::error(401, 'invalid_credentials', 'Invalid credentials.');
        }

        // Resolve the user's account. KyteUser has a direct kyte_account FK,
        // but app-scoped User models (created via DataModel in Shipyard) do
        // not — the user belongs to the Application, and the Application
        // belongs to the account. Fall back to $app->kyte_account in that
        // case. This mirrors how HMAC's account context is derived: the
        // session is on the app, and the app carries the account FK.
        $accountId = isset($user->kyte_account) ? (int)$user->kyte_account : 0;
        if ($accountId === 0 && $app !== null && isset($app->kyte_account)) {
            $accountId = (int)$app->kyte_account;
        }
        if ($accountId === 0) {
            return self::error(401, 'invalid_credentials', 'Invalid credentials.');
        }

        // KyteAccount lives in the default (system) DB. After the app-scoped
        // user retrieve above, dbswitch is set to App DB. ModelObject->retrieve
        // toggles back to default automatically when the target model has no
        // 'appId' (KyteAccount doesn't), so no explicit dbswitch needed here.
        $account = new ModelObject(KyteAccount);
        if (!$account->retrieve('id', $accountId)) {
            return self::error(401, 'invalid_credentials', 'Invalid credentials.');
        }

        $appId = $app !== null ? (int)$app->id : null;

        $accessToken = self::mintAccessJwt($user, $account, $appIdentifier);
        $refresh = RefreshTokenStore::issue((int)$user->id, $accountId, $appId, $ip);

        // Best-effort lastLogin update — matches SessionController behavior.
        if (isset($userModel['struct']['lastLogin'])) {
            try {
                $user->save(['lastLogin' => time()]);
            } catch (\Throwable $e) {
                error_log('JwtEndpoint: lastLogin update failed - ' . $e->getMessage());
            }
        }

        // Mirror HMAC SessionController::new response shape so apps that
        // consumed the HMAC session response (data[0], uid, account_id)
        // keep working under JWT without code changes. The HMAC-only
        // fields (kyte_pub, kyte_iden, kyte_num, session, token) are
        // intentionally omitted — JWT doesn't have an API-handoff cred
        // model, and there's no sessionToken/txToken concept.
        $userData = self::userToArray($user);
        $useSessionMap = defined('USE_SESSION_MAP') && USE_SESSION_MAP;

        return self::success([
            'access_token'       => $accessToken,
            'token_type'         => 'Bearer',
            'expires_in'         => self::accessTtl(),
            'refresh_token'      => $refresh['raw'],
            'refresh_expires_at' => $refresh['expires_at'],
            'uid'                => (int)$user->id,
            'account_id'         => (int)$account->id,
            'data'               => $useSessionMap ? $userData : [$userData],
        ]);
    }

    /**
     * Serialize a ModelObject for the JWT login response. Mirrors the
     * subset of ModelController::getObject that the customer app actually
     * consumes:
     *   - Strip fields marked `protected: true` (password hash, etc.)
     *   - Expand FK references into nested objects when SESSION_RETURN_FK
     *     is enabled (default true). Without this, `user.org` would be
     *     the integer `2` instead of `{id:2, org_type:'LP', ...}` — and
     *     frontends doing `user.org.org_type` would silently break.
     *
     * Recursion-bounded at depth 3 to prevent runaway expansion on
     * cyclic FKs (rare but possible in customer schemas).
     */
    private static function userToArray(ModelObject $user, int $depth = 0): array
    {
        $params = $user->getAllParams();
        $struct = $user->kyte_model['struct'] ?? [];
        $expandFks = $depth < 3
            && (!defined('SESSION_RETURN_FK') || SESSION_RETURN_FK);

        foreach ($params as $key => $value) {
            if (!isset($struct[$key])) {
                continue;
            }

            if (isset($struct[$key]['protected']) && $struct[$key]['protected']) {
                $params[$key] = '';
                continue;
            }

            // Expand FK if struct declares one, value is non-empty, and
            // SESSION_RETURN_FK allows.
            if ($expandFks && isset($struct[$key]['fk'], $value) && !empty($value)) {
                $fk = $struct[$key]['fk'];
                if (isset($fk['model'], $fk['field']) && defined($fk['model'])) {
                    $fkModel = constant($fk['model']);
                    $fkObj = new ModelObject($fkModel);
                    // retrieve() handles dbswitch automatically based on
                    // whether the FK model has 'appId' (app-scoped vs default).
                    if ($fkObj->retrieve($fk['field'], $value, null, null, true)) {
                        $params[$key] = self::userToArray($fkObj, $depth + 1);
                    }
                }
            }
        }

        // The kyte_model handle itself should never leak to clients.
        unset($params['kyte_model']);
        return $params;
    }

    private static function refresh(array $body, string $ip): array
    {
        $raw = (string)($body['refresh_token'] ?? '');
        if ($raw === '') {
            return self::error(400, 'invalid_request', 'refresh_token required.');
        }

        $result = RefreshTokenStore::rotate($raw, $ip);

        // Re-load user + account for the new access token.
        $user = new ModelObject(KyteUser);
        if (!$user->retrieve('id', $result['user_id'])) {
            // The user's row was removed between issuance and refresh.
            // Family revocation is appropriate — the principal is gone.
            return self::error(401, 'invalid_credentials', 'Refresh token principal not found.');
        }

        $account = new ModelObject(KyteAccount);
        if (!$account->retrieve('id', $result['account_id'])) {
            return self::error(401, 'invalid_credentials', 'Refresh token account not found.');
        }

        $appIdentifier = null;
        if ($result['app_id'] !== null) {
            $app = new ModelObject(Application);
            if ($app->retrieve('id', $result['app_id'])) {
                $appIdentifier = (string)$app->identifier;
            }
        }

        $accessToken = self::mintAccessJwt($user, $account, $appIdentifier);

        return self::success([
            'access_token'       => $accessToken,
            'token_type'         => 'Bearer',
            'expires_in'         => self::accessTtl(),
            'refresh_token'      => $result['raw'],
            'refresh_expires_at' => $result['expires_at'],
        ]);
    }

    private static function logout(array $body): array
    {
        $raw = (string)($body['refresh_token'] ?? '');
        if ($raw === '') {
            return self::error(400, 'invalid_request', 'refresh_token required.');
        }
        // Idempotent — already-revoked tokens still return ok.
        RefreshTokenStore::revokeByToken($raw, 'logout');
        return self::success(['ok' => true]);
    }

    private static function logoutAll(array $body): array
    {
        $raw = (string)($body['refresh_token'] ?? '');
        if ($raw === '') {
            return self::error(400, 'invalid_request', 'refresh_token required.');
        }

        // Resolve the user/account from the presented token first. We
        // don't require the token to still be active — even an expired
        // refresh token's owner can request a global logout.
        $hash = hash('sha256', $raw);
        $token = new ModelObject(KyteRefreshToken);
        if (!$token->retrieve('token_hash', $hash)) {
            return self::error(401, 'unauthorized', 'Refresh token not recognized.');
        }

        $count = RefreshTokenStore::revokeAllForUser(
            (int)$token->user,
            (int)$token->kyte_account,
            'logout_all'
        );
        return self::success(['ok' => true, 'revoked' => $count]);
    }

    /**
     * Mint a fresh access JWT. Claims:
     *   iss   KYTE_JWT_ISSUER (default 'kyte')
     *   sub   user id
     *   aud   account id (as int)
     *   exp   now + KYTE_JWT_ACCESS_TTL (default 900s)
     *   nbf   now
     *   iat   now
     *   jti   random per-token id
     *   email user email (if available)
     *   app   application identifier when app-scoped, omitted otherwise
     */
    private static function mintAccessJwt(ModelObject $user, ModelObject $account, ?string $appIdentifier): string
    {
        if (!defined('KYTE_JWT_SECRET') || KYTE_JWT_SECRET === '') {
            throw new \RuntimeException('KYTE_JWT_SECRET is not configured.');
        }

        $now = time();
        $payload = [
            'iss'   => defined('KYTE_JWT_ISSUER') ? KYTE_JWT_ISSUER : self::DEFAULT_ISSUER,
            'sub'   => (int)$user->id,
            'aud'   => (int)$account->id,
            'iat'   => $now,
            'nbf'   => $now,
            'exp'   => $now + self::accessTtl(),
            'jti'   => bin2hex(random_bytes(8)),
        ];
        if (isset($user->email)) {
            $payload['email'] = (string)$user->email;
        }
        if ($appIdentifier !== null) {
            $payload['app'] = $appIdentifier;
        }
        return JWT::encode($payload, KYTE_JWT_SECRET, 'HS256');
    }

    /**
     * Pick the right (user_model, username_field, password_field) tuple
     * for the optional app_identifier in the login request. When the app
     * declares its own user model, use that; otherwise default to
     * KyteUser + USERNAME_FIELD / PASSWORD_FIELD constants.
     *
     * @return array{user_model: array, username_field: string, password_field: string, app: ?ModelObject}
     */
    private static function resolveAuthContext(?string $appIdentifier): array
    {
        $defaultUserField = defined('USERNAME_FIELD') ? USERNAME_FIELD : 'email';
        $defaultPassField = defined('PASSWORD_FIELD') ? PASSWORD_FIELD : 'password';

        if ($appIdentifier === null) {
            return [
                'user_model'     => KyteUser,
                'username_field' => $defaultUserField,
                'password_field' => $defaultPassField,
                'app'            => null,
            ];
        }

        $app = new ModelObject(Application);
        if (!$app->retrieve('identifier', $appIdentifier)) {
            // Unknown app — fall back to default user model. Login will
            // still fail at password_verify if the credentials don't
            // match a KyteUser, which is the safer surface.
            return [
                'user_model'     => KyteUser,
                'username_field' => $defaultUserField,
                'password_field' => $defaultPassField,
                'app'            => null,
            ];
        }

        if ($app->user_model !== null && $app->username_colname !== null && $app->password_colname !== null) {
            // Mirror the subset of per-app setup that Api::route() does for
            // HMAC. JWT dispatches BEFORE the normal MVC pipeline runs, so
            // without this block the app-specific user model can't be
            // resolved or queried:
            //
            //   1. loadAppModels    — registers app DataModel constants
            //      like "User" so constant($app->user_model) resolves.
            //      Without this, PHP 8 fatals "Undefined constant".
            //   2. dbappconnect     — sets the app's DB credentials.
            //      Without this, ModelObject->retrieve on an app-scoped
            //      model auto-switches to the app DB (because the loaded
            //      model_definition has 'appId' set) and mysqli connects
            //      with null host/user/password → "No such file or
            //      directory" socket error → HTTP 500.
            //
            // We skip defineAppEnvironmentConstants / defineAppDataStore
            // (private instance methods) because login is just user
            // lookup + password_verify; those constants matter for
            // controller execution, not auth.
            \Kyte\Core\Api::loadAppModels($app);
            \Kyte\Core\Api::dbappconnect($app->db_name, $app->db_username, $app->db_password);

            if (!defined($app->user_model)) {
                // loadAppModels couldn't define it (no matching DataModel
                // row, or the row references a name that wasn't registered).
                // Fall back to KyteUser rather than fatal — login will then
                // fail at password_verify, which is the safer surface.
                error_log("JwtEndpoint: app '{$appIdentifier}' references user_model '{$app->user_model}' but no DataModel row defines it; falling back to KyteUser.");
                return [
                    'user_model'     => KyteUser,
                    'username_field' => $defaultUserField,
                    'password_field' => $defaultPassField,
                    'app'            => $app,
                ];
            }

            return [
                'user_model'     => constant($app->user_model),
                'username_field' => (string)$app->username_colname,
                'password_field' => (string)$app->password_colname,
                'app'            => $app,
            ];
        }

        return [
            'user_model'     => KyteUser,
            'username_field' => $defaultUserField,
            'password_field' => $defaultPassField,
            'app'            => $app,
        ];
    }

    private static function accessTtl(): int
    {
        return defined('KYTE_JWT_ACCESS_TTL') ? (int)KYTE_JWT_ACCESS_TTL : self::DEFAULT_ACCESS_TTL;
    }

    private static function decodeBody(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }
        $decoded = json_decode($rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function clientIp(array $server): string
    {
        // Mirror the resolution policy used by Kyte\Mcp\Util\ClientIp,
        // adapted to take an explicit $server array (the MCP helper
        // reads $_SERVER directly, which we can't do from process()
        // without breaking testability).
        if (defined('KYTE_TRUST_PROXY_IP_HEADERS') && KYTE_TRUST_PROXY_IP_HEADERS) {
            $cf = $server['HTTP_CF_CONNECTING_IP'] ?? null;
            if (is_string($cf) && $cf !== '') {
                return $cf;
            }
            $xff = $server['HTTP_X_FORWARDED_FOR'] ?? null;
            if (is_string($xff) && $xff !== '') {
                $firstHop = trim(explode(',', $xff)[0]);
                if ($firstHop !== '') {
                    return $firstHop;
                }
            }
        }
        return (string)($server['REMOTE_ADDR'] ?? '');
    }

    private static function success(array $body): array
    {
        return ['status' => 200, 'body' => $body];
    }

    private static function error(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'body'   => ['error' => $code, 'message' => $message],
        ];
    }
}
