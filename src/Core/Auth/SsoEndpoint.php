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
                case 'callback':   // [P1 next slice — #561]
                    return self::notImplemented('callback');
                case 'exchange':   // [P1 next slice — #561]
                    return self::notImplemented('exchange');
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
