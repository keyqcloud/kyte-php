<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;
use Kyte\Core\ModelObject;

/**
 * URL handler for app-level SSO (OIDC) — an app's END USERS sign in with their
 * identity provider (Microsoft/Entra first) and receive a Kyte JWT session
 * (KYTE-#560). Kyte is the OIDC relying party (the mirror of the #551 hosted-MCP
 * OAuth server, where Kyte was the provider).
 *
 * Endpoints (routed by Api::route() before the MVC pipeline, like /jwt, /mcp,
 * /oauth):
 *
 *   GET  /sso/authorize?app_identifier=&provider=microsoft&redirect=<app url>
 *          Look up the app's provider config, run OIDC discovery, build
 *          state+nonce+PKCE, persist a KyteSsoState, 302 to the provider.
 *   GET  /sso/callback?code=&state=      [P1 next slice #561]
 *          Validate state, exchange the code, validate the id_token, map the
 *          user (JIT), mint a Kyte session, redirect back with a one-time code.
 *   POST /sso/exchange   body {sso_code}  [P1 next slice #561]
 *          Redeem the one-time code -> the Kyte JWT session (access+refresh).
 *
 * Config (KyteAppIdentityProvider) is Shipyard-managed and carries a
 * KMS-encrypted client secret — never exposed via MCP. Design:
 * docs/design/app-microsoft-sso.md.
 */
class SsoEndpoint
{
    private const STATE_TTL = 600; // 10 min to complete the provider round-trip

    public static function handle(Api $api): void
    {
        self::emitCorsHeaders();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            $reqHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header("Access-Control-Allow-Headers: {$reqHeaders}");
            http_response_code(204);
            return;
        }

        $rawBody = (string)file_get_contents('php://input');
        $result = self::process($api, $_SERVER, $rawBody);

        foreach (($result['headers'] ?? []) as $header) {
            header($header);
        }
        http_response_code((int)$result['status']);
        if (array_key_exists('raw', $result)) {
            echo $result['raw'];
        } elseif (isset($result['body'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result['body']);
        }
    }

    /**
     * Pure dispatcher. @return array{status:int, body?:array, raw?:string, headers?:string[]}
     */
    public static function process(Api $api, array $server, string $rawBody): array
    {
        $path = ltrim((string)parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $segments = explode('/', $path);
        $action = $segments[1] ?? '';   // sso/<action>

        try {
            switch ($action) {
                case 'authorize':
                    return self::authorize(self::queryParams($server));
                case 'callback':
                    return self::callback(self::queryParams($server));
                case 'exchange':
                    return self::exchange(self::parseBody($rawBody, $server), self::clientIp($server));
                default:
                    return self::error(404, 'not_found', "Unknown SSO endpoint: /{$path}.");
            }
        } catch (\Throwable $e) {
            error_log('SsoEndpoint: ' . $e->getMessage());
            return self::error(500, 'server_error', 'SSO error.');
        }
    }

    /**
     * Begin an SSO login: resolve the app's provider, discover the provider's
     * authorization endpoint, and redirect the user there with state + nonce +
     * PKCE (all persisted in a short-lived KyteSsoState for the callback).
     *
     * @param array<string,mixed> $params
     */
    private static function authorize(array $params): array
    {
        $appIdentifier = isset($params['app_identifier']) ? (string)$params['app_identifier'] : '';
        $providerName  = isset($params['provider']) && $params['provider'] !== '' ? (string)$params['provider'] : 'microsoft';
        $returnUrl     = isset($params['redirect']) ? (string)$params['redirect'] : '';

        if ($appIdentifier === '') {
            return self::error(400, 'invalid_request', 'app_identifier is required.');
        }

        $app = new ModelObject(\Application);
        if (!$app->retrieve('identifier', $appIdentifier)) {
            return self::error(404, 'not_found', 'Application not found.');
        }

        $cfg = new ModelObject(\KyteAppIdentityProvider);
        $found = $cfg->retrieve('application', (int)$app->id, [
            ['field' => 'provider', 'value' => $providerName],
            ['field' => 'enabled',  'value' => 1],
        ]);
        if (!$found) {
            return self::error(404, 'sso_not_configured', "SSO ({$providerName}) is not enabled for this application.");
        }
        if (empty($cfg->client_id)) {
            return self::error(500, 'sso_misconfigured', 'SSO provider is missing a client_id.');
        }

        // OIDC discovery → authorization_endpoint.
        $discovery = self::discover($cfg);
        if ($discovery === null || empty($discovery['authorization_endpoint'])) {
            return self::error(502, 'discovery_failed', 'Could not load the SSO provider configuration.');
        }

        // state + nonce + PKCE.
        $state    = self::randToken(32);
        $nonce    = self::randToken(32);
        $verifier = self::randToken(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $redirectUri = !empty($cfg->redirect_uri) ? (string)$cfg->redirect_uri : (self::baseUrl($_SERVER) . '/sso/callback');

        $stateRow = new ModelObject(\KyteSsoState);
        $stateRow->create([
            'state'         => $state,
            'nonce'         => $nonce,
            'code_verifier' => $verifier,
            'application'   => (int)$app->id,
            'provider'      => $providerName,
            'return_url'    => $returnUrl,
            'redirect_uri'  => $redirectUri,
            'expires_at'    => time() + self::STATE_TTL,
            'consumed_at'   => 0,
            'kyte_account'  => (int)$app->kyte_account,
        ]);

        $scopes = !empty($cfg->scopes) ? (string)$cfg->scopes : 'openid profile email';
        $authUrl = (string)$discovery['authorization_endpoint'] . '?' . http_build_query([
            'client_id'             => (string)$cfg->client_id,
            'response_type'         => 'code',
            'redirect_uri'          => $redirectUri,
            'response_mode'         => 'query',
            'scope'                 => $scopes,
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return ['status' => 302, 'headers' => ['Location: ' . $authUrl, 'Cache-Control: no-store']];
    }

    /**
     * Fetch the provider's OIDC discovery document (authorization/token
     * endpoints + jwks_uri). Uses the explicit discovery_url, else the issuer +
     * /.well-known/openid-configuration.
     *
     * @return array<string,mixed>|null
     */
    private static function discover(ModelObject $cfg): ?array
    {
        $url = !empty($cfg->discovery_url)
            ? (string)$cfg->discovery_url
            : rtrim((string)$cfg->issuer, '/') . '/.well-known/openid-configuration';
        if ($url === '/.well-known/openid-configuration') {
            return null;
        }
        return self::httpGetJson($url);
    }

    /** @return array<string,mixed>|null */
    private static function httpGetJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : null;
    }

    // ----- callback + exchange (KYTE-#560 P1) ----------------------------

    /**
     * OIDC callback: validate the state, exchange the code, validate the
     * id_token, map/JIT the app user, and hand back a single-use sso_code the
     * app front-end redeems at /sso/exchange.
     *
     * @param array<string,mixed> $params
     */
    private static function callback(array $params): array
    {
        $state   = isset($params['state']) ? (string)$params['state'] : '';
        $code    = isset($params['code']) ? (string)$params['code'] : '';
        $provErr = isset($params['error']) ? (string)$params['error'] : '';

        if ($state === '') {
            return self::error(400, 'invalid_request', 'Missing state.');
        }

        // Look up + single-use consume the in-flight state.
        $st = new ModelObject(\KyteSsoState);
        if (!$st->retrieve('state', $state)) {
            return self::error(400, 'invalid_state', 'Unknown or expired login state.');
        }
        if ((int)$st->consumed_at !== 0) {
            return self::error(400, 'invalid_state', 'Login state already used.');
        }
        if ((int)$st->expires_at < time()) {
            return self::error(400, 'invalid_state', 'Login state expired.');
        }
        $st->save(['consumed_at' => time()]);

        $returnUrl = (string)($st->return_url ?? '');

        if ($provErr !== '') {
            return self::backToApp($returnUrl, ['error' => $provErr]);
        }
        if ($code === '') {
            return self::backToApp($returnUrl, ['error' => 'no_code']);
        }

        $app = new ModelObject(\Application);
        if (!$app->retrieve('id', (int)$st->application)) {
            return self::error(400, 'invalid_state', 'Application not found.');
        }
        $cfg = new ModelObject(\KyteAppIdentityProvider);
        if (!$cfg->retrieve('application', (int)$app->id, [
            ['field' => 'provider', 'value' => (string)$st->provider],
            ['field' => 'enabled',  'value' => 1],
        ])) {
            return self::backToApp($returnUrl, ['error' => 'sso_not_configured']);
        }

        $disc = self::discover($cfg);
        if ($disc === null || empty($disc['token_endpoint']) || empty($disc['jwks_uri'])) {
            return self::backToApp($returnUrl, ['error' => 'discovery_failed']);
        }

        // Exchange the authorization code (client_secret decrypted server-side).
        try {
            $secret = self::decryptSecret((string)$cfg->client_secret);
        } catch (\Throwable $e) {
            error_log('SsoEndpoint: secret decrypt failed - ' . $e->getMessage());
            return self::backToApp($returnUrl, ['error' => 'secret_error']);
        }
        $tok = self::httpPostForm((string)$disc['token_endpoint'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => (string)$st->redirect_uri,
            'client_id'     => (string)$cfg->client_id,
            'client_secret' => $secret,
            'code_verifier' => (string)$st->code_verifier,
            'scope'         => (string)($cfg->scopes ?: 'openid profile email'),
        ]);
        if ($tok === null || empty($tok['id_token'])) {
            return self::backToApp($returnUrl, ['error' => 'token_exchange_failed']);
        }

        // Validate the id_token (signature + aud + nonce + tenant).
        $claims = self::validateIdToken((string)$tok['id_token'], $disc, $cfg, (string)$st->nonce);
        if ($claims === null) {
            return self::backToApp($returnUrl, ['error' => 'id_token_invalid']);
        }

        // Map to an app user.
        $emailClaim = (string)($cfg->user_email_claim ?: 'email');
        $email = $claims[$emailClaim] ?? ($claims['email'] ?? ($claims['preferred_username'] ?? null));
        if (!$email || !is_string($email)) {
            return self::backToApp($returnUrl, ['error' => 'no_email_claim']);
        }
        $user = self::findOrCreateUser($app, $email, (int)$cfg->jit_enabled === 1, (int)$cfg->restrict_to_existing === 1);
        if ($user === null) {
            return self::backToApp($returnUrl, ['error' => 'user_not_provisioned']);
        }

        // Single-use hand-off code (no tokens at rest).
        $rawCode = self::randToken(48);
        $codeRow = new ModelObject(\KyteSsoCode);
        $codeRow->create([
            'code_hash'    => hash('sha256', $rawCode),
            'application'  => (int)$app->id,
            'sso_user_id'  => (int)$user->id,
            'expires_at'   => time() + 120,
            'consumed_at'  => 0,
            'kyte_account' => (int)$app->kyte_account,
        ]);

        return self::backToApp($returnUrl, ['sso_code' => $rawCode]);
    }

    /**
     * Redeem a single-use sso_code for the Kyte JWT session (access + refresh).
     *
     * @param array<string,mixed> $body
     */
    private static function exchange(array $body, string $ip): array
    {
        $rawCode = isset($body['sso_code']) ? (string)$body['sso_code'] : '';
        if ($rawCode === '') {
            return self::error(400, 'invalid_request', 'sso_code is required.');
        }

        $codeRow = new ModelObject(\KyteSsoCode);
        if (!$codeRow->retrieve('code_hash', hash('sha256', $rawCode))) {
            return self::error(400, 'invalid_grant', 'Invalid sso_code.');
        }
        if ((int)$codeRow->consumed_at !== 0) {
            return self::error(400, 'invalid_grant', 'sso_code already used.');
        }
        if ((int)$codeRow->expires_at < time()) {
            return self::error(400, 'invalid_grant', 'sso_code expired.');
        }
        $codeRow->save(['consumed_at' => time()]);

        $app = new ModelObject(\Application);
        if (!$app->retrieve('id', (int)$codeRow->application)) {
            return self::error(400, 'invalid_grant', 'Application not found.');
        }
        // resolveAuthContext registers the app's user_model + sets its DB context.
        $ctx = JwtEndpoint::resolveAuthContext((string)$app->identifier);
        $user = new ModelObject($ctx['user_model']);
        if (!$user->retrieve('id', (int)$codeRow->sso_user_id)) {
            return self::error(400, 'invalid_grant', 'User not found.');
        }
        $account = new ModelObject(\KyteAccount);
        if (!$account->retrieve('id', (int)$app->kyte_account)) {
            return self::error(500, 'server_error', 'Account not found.');
        }

        $session = JwtEndpoint::issueSession($user, $account, $app, $ip);
        return ['status' => 200, 'headers' => ['Cache-Control: no-store'], 'body' => $session];
    }

    /**
     * Validate an OIDC id_token: signature via the provider JWKS (firebase JWK),
     * then audience, nonce, issuer, and tenant (tid) scoping. Returns the claims
     * or null.
     *
     * @param array<string,mixed> $disc
     * @return array<string,mixed>|null
     */
    private static function validateIdToken(string $idToken, array $disc, ModelObject $cfg, string $expectedNonce): ?array
    {
        try {
            $jwks = self::httpGetJson((string)$disc['jwks_uri']);
            if ($jwks === null || empty($jwks['keys'])) {
                return null;
            }
            $keys = \Firebase\JWT\JWK::parseKeySet($jwks);
            // JWT::decode validates the signature + exp/nbf and throws otherwise.
            $claims = (array)\Firebase\JWT\JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            error_log('SsoEndpoint id_token validation: ' . $e->getMessage());
            return null;
        }

        // Audience must be our client_id.
        $aud = $claims['aud'] ?? null;
        $clientId = (string)$cfg->client_id;
        if (is_array($aud)) {
            if (!in_array($clientId, array_map('strval', $aud), true)) {
                return null;
            }
        } elseif ((string)$aud !== $clientId) {
            return null;
        }

        // Nonce must match the one we issued (id_token replay defense).
        if (!isset($claims['nonce']) || !hash_equals($expectedNonce, (string)$claims['nonce'])) {
            return null;
        }

        // Issuer: exact-match the discovered issuer when it's concrete (a
        // tenant-specific config). Skip when it carries a {placeholder} (the
        // 'common'/'organizations' endpoints) — tenant scoping below covers it.
        if (!empty($disc['issuer']) && strpos((string)$disc['issuer'], '{') === false
            && isset($claims['iss']) && (string)$claims['iss'] !== (string)$disc['issuer']) {
            return null;
        }

        // Tenant scoping: the token's tid must match the app's configured tenant.
        if (!empty($cfg->tenant) && (!isset($claims['tid']) || (string)$claims['tid'] !== (string)$cfg->tenant)) {
            return null;
        }

        return $claims;
    }

    /**
     * Find the app user by the mapped email, or JIT-create it (unless
     * restrict_to_existing / jit disabled). Runs in the app's user_model + DB
     * context (resolveAuthContext sets that up).
     */
    private static function findOrCreateUser(ModelObject $app, string $email, bool $jit, bool $restrict): ?ModelObject
    {
        $ctx = JwtEndpoint::resolveAuthContext((string)$app->identifier);
        $userModel     = $ctx['user_model'];
        $usernameField = (string)$ctx['username_field'];
        $passwordField = (string)($ctx['password_field'] ?? '');

        $user = new ModelObject($userModel);
        if ($user->retrieve($usernameField, $email)) {
            return $user;
        }
        if ($restrict || !$jit) {
            return null;
        }

        // JIT provision. SSO users never password-login, but the model may
        // require a password column — set a random (unusable) hash.
        $data = [$usernameField => $email];
        if ($passwordField !== '' && isset($userModel['struct'][$passwordField])) {
            $data[$passwordField] = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
        }
        if (isset($userModel['struct']['kyte_account'])) {
            $data['kyte_account'] = (int)$app->kyte_account;
        }
        try {
            $newUser = new ModelObject($userModel);
            if (!$newUser->create($data)) {
                return null;
            }
            return $newUser;
        } catch (\Throwable $e) {
            error_log('SsoEndpoint JIT user create failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Redirect back to the app's return URL with a query (sso_code or error);
     * JSON if no return URL was given.
     *
     * SECURITY: validating return_url against the app's own sites (open-redirect
     * / code-interception defense) is required before exposure — flagged for the
     * P1 security review (#561).
     *
     * @param array<string,string> $query
     */
    private static function backToApp(string $returnUrl, array $query): array
    {
        if ($returnUrl === '') {
            return ['status' => 200, 'body' => $query, 'headers' => ['Cache-Control: no-store']];
        }
        $sep = strpos($returnUrl, '?') !== false ? '&' : '?';
        return [
            'status'  => 302,
            'headers' => ['Location: ' . $returnUrl . $sep . http_build_query($query), 'Cache-Control: no-store'],
        ];
    }

    /** @param array<string,string> $fields @return array<string,mixed>|null */
    private static function httpPostForm(string $url, array $fields): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return null;
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return null;
        }
        if ($code < 200 || $code >= 300) {
            error_log('SsoEndpoint token endpoint ' . $code . ': ' . substr((string)$body, 0, 300));
            return null;
        }
        return $decoded;
    }

    /** @param array<string,mixed> $server @return array<string,mixed> */
    private static function parseBody(string $raw, array $server): array
    {
        $ct = strtolower((string)($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? ''));
        if (strpos($ct, 'application/json') !== false) {
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        }
        $out = [];
        parse_str($raw, $out);
        return $out;
    }

    /** @param array<string,mixed> $server */
    private static function clientIp(array $server): string
    {
        return (string)($server['REMOTE_ADDR'] ?? '');
    }

    /**
     * 32-byte libsodium key for the client_secret at rest. Prefers an explicit
     * install key, else derives deterministically from KYTE_JWT_SECRET (both are
     * install-level secrets). See docs/design/app-microsoft-sso.md §6.
     */
    private static function ssoKey(): string
    {
        if (defined('KYTE_SSO_SECRET_KEY') && KYTE_SSO_SECRET_KEY !== '') {
            $k = base64_decode((string)KYTE_SSO_SECRET_KEY, true);
            if ($k !== false && strlen($k) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $k;
            }
        }
        if (defined('KYTE_JWT_SECRET') && KYTE_JWT_SECRET !== '') {
            return hash('sha256', 'kyte-sso-secret:' . KYTE_JWT_SECRET, true); // 32 bytes
        }
        throw new \Exception('No SSO encryption key (set KYTE_SSO_SECRET_KEY or KYTE_JWT_SECRET).');
    }

    /** Encrypt a provider client_secret for storage (base64 of nonce+ciphertext). */
    public static function encryptSecret(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::ssoKey()));
    }

    private static function decryptSecret(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \Exception('Invalid encrypted secret.');
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, self::ssoKey());
        if ($plain === false) {
            throw new \Exception('Secret decryption failed.');
        }
        return $plain;
    }

    private static function randToken(int $len): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        $bytes = random_bytes($len);
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[ord($bytes[$i]) % ($max + 1)];
        }
        return $out;
    }

    public static function baseUrl(array $server): string
    {
        if (defined('KYTE_OAUTH_ISSUER') && KYTE_OAUTH_ISSUER) {
            return rtrim((string)KYTE_OAUTH_ISSUER, '/');
        }
        $host = (defined('API_URL') && API_URL) ? (string)API_URL : (string)($server['HTTP_HOST'] ?? 'localhost');
        return 'https://' . $host;
    }

    private static function notImplemented(string $what): array
    {
        return self::error(501, 'not_implemented', "SSO /{$what} is not implemented yet (KYTE-#560 P1 next slice).");
    }

    /** @param array<string,mixed> $server @return array<string,mixed> */
    private static function queryParams(array $server): array
    {
        $qs = (string)($server['QUERY_STRING'] ?? '');
        if ($qs === '') {
            return [];
        }
        $out = [];
        parse_str($qs, $out);
        return $out;
    }

    private static function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $code, 'error_description' => $message], 'headers' => ['Cache-Control: no-store']];
    }

    private static function emitCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        }
    }
}
