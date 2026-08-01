<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;

/**
 * URL handler for this install's OAuth 2.1 Authorization Server — the front
 * door that lets Claude.ai (web/Desktop) and ChatGPT add Kyte's MCP server as
 * a remote connector via a URL + OAuth login (KYTE-#551, hosted MCP).
 *
 * The OAuth layer does NOT introduce a new enforcement path: a successful
 * authorization-code exchange mints a scoped `kmcp_live_` KyteMCPToken as the
 * access token, and every downstream MCP request is authenticated exactly as
 * before (McpTokenStrategy + scopes + sessions + audit).
 *
 * Endpoints (routed here by Api::route() before the MVC pipeline, same pattern
 * as /mcp and /jwt):
 *
 *   GET  /.well-known/oauth-authorization-server   RFC 8414 AS metadata
 *   GET  /.well-known/oauth-protected-resource      RFC 9728 resource metadata
 *   POST /oauth/register     RFC 7591 dynamic client registration  [P1-B]
 *   GET  /oauth/authorize    authorization-code + PKCE + consent    [P1-C]
 *   POST /oauth/token        code+verifier -> mint kmcp_live_       [P1-D]
 *
 * Blueprint: keyq-slipstream api/src/routes/oauth-as.ts (swap "mint PAT" ->
 * "mint kmcp_live_"). Design: docs/design/hosted-mcp-oauth.md.
 *
 * This slice (P1-A) implements discovery only; register/authorize/token return
 * 501 until their cards land. Testability: process() is pure (Api, $server,
 * raw body) -> ['status', 'body'|'raw', 'headers'].
 */
class OAuthEndpoint
{
    /**
     * kmcp scopes this AS can grant. Mirrors KyteMCPTokenController::VALID_SCOPES
     * — the OAuth scope grant maps onto these before a token is minted.
     */
    private const SUPPORTED_SCOPES = ['read', 'draft', 'commit', 'provision', 'schema'];

    /** Registration guardrails (open DCR — hardening tracked in #556). */
    private const MAX_REDIRECT_URIS = 10;
    private const MAX_URI_LENGTH    = 2048;

    /** Authorization codes are short-lived (single-use besides). */
    private const CODE_TTL_SECONDS  = 300;

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
     * Pure dispatcher. Returns ['status' => int, 'body' => array|'raw' => string,
     * 'headers' => string[]].
     */
    public static function process(Api $api, array $server, string $rawBody): array
    {
        $path = ltrim((string)parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');

        try {
            if (strcasecmp($path, '.well-known/oauth-authorization-server') === 0) {
                return self::authorizationServerMetadata($server);
            }
            // RFC 9728 allows a path-suffixed variant (…/mcp); match the family.
            if (stripos($path, '.well-known/oauth-protected-resource') === 0) {
                return self::protectedResourceMetadata($server);
            }

            $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
            $segments = explode('/', $path);
            $action = $segments[1] ?? '';   // oauth/<action>
            switch ($action) {
                case 'register':   // RFC 7591 dynamic client registration [P1-B / #553]
                    if ($method !== 'POST') {
                        return self::error(405, 'invalid_request', 'POST required for /oauth/register.');
                    }
                    return self::register(self::decodeBody($rawBody));
                case 'authorize':  // interactive consent lives in Shipyard  [P1-C / #554]
                    // The consent page (which reuses Shipyard's login) is a
                    // Shipyard SPA route. Redirect any direct hit there,
                    // preserving the OAuth query params.
                    $qs = (string)($server['QUERY_STRING'] ?? '');
                    return [
                        'status'  => 302,
                        'headers' => [
                            'Location: ' . self::shipyardConsentUrl() . ($qs !== '' ? '?' . $qs : ''),
                            'Cache-Control: no-store',
                        ],
                    ];
                case 'consent':    // authed helpers the Shipyard consent page calls [P1-C / #554]
                    $sub = $segments[2] ?? '';
                    if ($sub === 'client') {
                        if ($method !== 'GET') {
                            return self::error(405, 'invalid_request', 'GET required for /oauth/consent/client.');
                        }
                        return self::consentClient($api, self::queryParams($server));
                    }
                    if ($sub === 'approve') {
                        if ($method !== 'POST') {
                            return self::error(405, 'invalid_request', 'POST required for /oauth/consent/approve.');
                        }
                        return self::consentApprove($api, self::decodeBody($rawBody));
                    }
                    return self::error(404, 'not_found', "Unknown OAuth endpoint: /{$path}.");
                case 'token':      // code+verifier -> mint kmcp_live_       [P1-D / #555]
                    return self::notImplemented('token');
                default:
                    return self::error(404, 'not_found', "Unknown OAuth endpoint: /{$path}.");
            }
        } catch (\Throwable $e) {
            error_log('OAuthEndpoint: ' . $e->getMessage());
            return self::error(500, 'server_error', 'Internal error.');
        }
    }

    /**
     * Canonical HTTPS base URL for this install's AS. Prefers an explicit
     * operator override, then the server-set host (API_URL = SERVER_NAME, not
     * the client-controlled Host header — avoids host-header injection into the
     * issuer identifier), then HTTP_HOST as a last resort.
     */
    public static function baseUrl(array $server): string
    {
        if (defined('KYTE_OAUTH_ISSUER') && KYTE_OAUTH_ISSUER) {
            return rtrim((string)KYTE_OAUTH_ISSUER, '/');
        }
        $host = (defined('API_URL') && API_URL)
            ? (string)API_URL
            : (string)($server['HTTP_HOST'] ?? 'localhost');
        return 'https://' . $host;
    }

    private static function authorizationServerMetadata(array $server): array
    {
        $base = self::baseUrl($server);
        return self::json(200, [
            'issuer'                                => $base,
            'authorization_endpoint'                => self::shipyardConsentUrl(),
            'token_endpoint'                        => $base . '/oauth/token',
            'registration_endpoint'                 => $base . '/oauth/register',
            'scopes_supported'                      => self::SUPPORTED_SCOPES,
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
        ]);
    }

    private static function protectedResourceMetadata(array $server): array
    {
        $base = self::baseUrl($server);
        return self::json(200, [
            'resource'                 => $base . '/mcp',
            'authorization_servers'    => [$base],
            'scopes_supported'         => self::SUPPORTED_SCOPES,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    private static function notImplemented(string $what): array
    {
        return self::error(501, 'not_implemented', "OAuth /{$what} is not implemented yet (KYTE-#551).");
    }

    /**
     * RFC 7591 dynamic client registration. Claude / ChatGPT self-register as
     * PUBLIC clients (PKCE, no secret, token_endpoint_auth_method=none) before
     * running the authorization-code flow. Open registration is the MCP model;
     * the real gate is user consent (P1-C) + PKCE (P1-D), not client auth.
     *
     * @param array<string,mixed> $body
     */
    private static function register(array $body): array
    {
        // redirect_uris — required, non-empty; each https (or http on loopback
        // for native/desktop per RFC 8252). Exact-matched at authorize/token.
        $redirectUris = $body['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || count($redirectUris) === 0) {
            return self::error(400, 'invalid_redirect_uri', 'redirect_uris is required (non-empty array).');
        }
        if (count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return self::error(400, 'invalid_redirect_uri', 'Too many redirect_uris.');
        }
        $uriError = self::validateRedirectUris($redirectUris);
        if ($uriError !== null) {
            return self::error(400, 'invalid_redirect_uri', $uriError);
        }

        // Only the public-client authorization-code + PKCE profile is supported.
        $grantTypes = self::intersectCsv($body['grant_types'] ?? ['authorization_code'], ['authorization_code']);
        if ($grantTypes === '') {
            return self::error(400, 'invalid_client_metadata', 'Only the authorization_code grant is supported.');
        }
        $responseTypes = self::intersectCsv($body['response_types'] ?? ['code'], ['code']);
        if ($responseTypes === '') {
            return self::error(400, 'invalid_client_metadata', 'Only the "code" response_type is supported.');
        }

        $scope      = self::filterScope(isset($body['scope']) ? (string)$body['scope'] : '');
        $clientName = isset($body['client_name']) && is_string($body['client_name'])
            ? substr($body['client_name'], 0, 255)
            : null;
        $clientId   = self::generateClientId();

        $client = new \Kyte\Core\ModelObject(KyteOAuthClient);
        $client->create([
            'client_id'                  => $clientId,
            'client_name'                => $clientName,
            'redirect_uris'              => json_encode(array_values($redirectUris)),
            'grant_types'                => $grantTypes,
            'response_types'             => $responseTypes,
            'token_endpoint_auth_method' => 'none',
            'scope'                      => $scope,
            'kyte_account'               => 0,   // unscoped until consent binds an account
        ]);

        // RFC 7591 §3.2.1 client information response.
        return [
            'status'  => 201,
            'headers' => ['Cache-Control: no-store', 'Pragma: no-cache'],
            'body'    => [
                'client_id'                  => $clientId,
                'client_id_issued_at'        => (int)$client->date_created,
                'client_name'                => $clientName,
                'redirect_uris'              => array_values($redirectUris),
                'grant_types'                => explode(',', $grantTypes),
                'response_types'             => explode(',', $responseTypes),
                'token_endpoint_auth_method' => 'none',
                'scope'                      => $scope,
            ],
        ];
    }

    /** @param array<int,mixed> $uris  Returns an error string, or null on pass. */
    private static function validateRedirectUris(array $uris): ?string
    {
        foreach ($uris as $uri) {
            if (!is_string($uri) || $uri === '' || strlen($uri) > self::MAX_URI_LENGTH) {
                return 'Each redirect_uri must be a non-empty URI under ' . self::MAX_URI_LENGTH . ' chars.';
            }
            $parts = parse_url($uri);
            if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
                return "Invalid redirect_uri: {$uri}";
            }
            $scheme   = strtolower($parts['scheme']);
            $host     = strtolower($parts['host']);
            $loopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
            if ($scheme === 'https' || ($scheme === 'http' && $loopback)) {
                continue;
            }
            return "redirect_uri must use https (or http on a loopback host): {$uri}";
        }
        return null;
    }

    /**
     * Opaque, unguessable public client identifier. `kyoc_` = kyte-oauth-client.
     * ~140 bits from a CSPRNG; the UNIQUE index is the collision backstop.
     */
    private static function generateClientId(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $len = strlen($alphabet);
        $body = '';
        $bytes = random_bytes(24);
        for ($i = 0; $i < 24; $i++) {
            $body .= $alphabet[ord($bytes[$i]) % $len];
        }
        return 'kyoc_' . $body;
    }

    /**
     * Intersect a JSON-array-or-string metadata value with an allowlist,
     * returning a CSV for storage. @param mixed $value
     */
    private static function intersectCsv($value, array $allow): string
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $items = preg_split('/[\s,]+/', trim($value)) ?: [];
        } else {
            $items = [];
        }
        $keep = array_values(array_intersect(array_map('strval', $items), $allow));
        return implode(',', array_unique($keep));
    }

    /**
     * Filter a requested OAuth scope string to what this AS grants; default to
     * least-privilege "read" when nothing valid is requested. Space-separated
     * per OAuth convention. The effective grant is re-confirmed at consent.
     */
    private static function filterScope(string $scope): string
    {
        return implode(' ', self::scopeList($scope));
    }

    private static function decodeBody(string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [];
        }
        $decoded = json_decode($rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ----- P1-C: authorize / consent -------------------------------------

    /**
     * Consent URL in Shipyard — the interactive authorize page reuses the
     * Shipyard login the user already has. Per-install SHIPYARD_URL; falls
     * back to this host if unset.
     */
    private static function shipyardConsentUrl(): string
    {
        $base = (defined('SHIPYARD_URL') && SHIPYARD_URL)
            ? rtrim((string)SHIPYARD_URL, '/')
            : self::baseUrl($_SERVER);
        return $base . '/oauth/authorize';
    }

    /**
     * Return client display info for the consent screen (authenticated — the
     * Shipyard consent page is post-login). Validates the authorize request
     * without minting anything.
     *
     * @param array<string,mixed> $params
     */
    private static function consentClient(Api $api, array $params): array
    {
        $authErr = self::authenticateUser($api);
        if ($authErr !== null) {
            return $authErr;
        }

        $v = self::validateAuthorizeParams($params);
        if (isset($v['error'])) {
            return self::error(400, (string)$v['error'], (string)$v['message']);
        }

        $client = $v['client'];
        $name = ($client->client_name !== null && $client->client_name !== '')
            ? (string)$client->client_name
            : 'The application';

        return self::json(200, [
            'client_id'    => (string)$client->client_id,
            'client_name'  => $name,
            'redirect_uri' => $v['redirect_uri'],
            'scopes'       => $v['scopes'],   // kmcp scopes the client requested
            'state'        => $v['state'],
            'account'      => ['id' => (int)$api->account->id],
        ]);
    }

    /**
     * Mint a single-use authorization code bound to the consenting user's
     * account + the PKCE challenge (authenticated). Returns the redirect target
     * for the Shipyard page to send the browser back to the client.
     *
     * @param array<string,mixed> $body
     */
    private static function consentApprove(Api $api, array $body): array
    {
        $authErr = self::authenticateUser($api);
        if ($authErr !== null) {
            return $authErr;
        }

        $v = self::validateAuthorizeParams($body);
        if (isset($v['error'])) {
            return self::error(400, (string)$v['error'], (string)$v['message']);
        }

        $rawCode    = self::generateAuthCode();
        $kyteScopes = implode(',', $v['scopes']);   // kmcp scopes to mint (CSV)

        $code = new \Kyte\Core\ModelObject(KyteOAuthCode);
        $code->create([
            'code_hash'             => hash('sha256', $rawCode),
            'client_id'             => (string)$v['client']->client_id,
            'redirect_uri'          => $v['redirect_uri'],
            'code_challenge'        => $v['code_challenge'],
            'code_challenge_method' => 'S256',
            'scope'                 => implode(' ', $v['scopes']),
            'kyte_scopes'           => $kyteScopes !== '' ? $kyteScopes : 'read',
            'application'           => null,   // account-wide (v1)
            'expires_at'            => time() + self::CODE_TTL_SECONDS,
            'consumed_at'           => 0,
            'kyte_account'          => (int)$api->account->id,
        ], (int)$api->user->id); // created_by = the consenting user

        return self::json(200, [
            'redirect_uri' => $v['redirect_uri'],
            'code'         => $rawCode,
            'state'        => $v['state'],
        ]);
    }

    /**
     * Validate an authorize/consent request (client + redirect_uri exact-match
     * + response_type=code + PKCE S256). Returns the resolved bundle, or an
     * ['error','message'] pair.
     *
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private static function validateAuthorizeParams(array $p): array
    {
        $clientId     = isset($p['client_id']) ? (string)$p['client_id'] : '';
        $redirectUri  = isset($p['redirect_uri']) ? (string)$p['redirect_uri'] : '';
        $responseType = isset($p['response_type']) ? (string)$p['response_type'] : 'code';
        $challenge    = isset($p['code_challenge']) ? (string)$p['code_challenge'] : '';
        $method       = isset($p['code_challenge_method']) ? (string)$p['code_challenge_method'] : '';
        $state        = isset($p['state']) ? (string)$p['state'] : '';
        $scopeStr     = isset($p['scope']) ? (string)$p['scope'] : '';

        if ($clientId === '') {
            return ['error' => 'invalid_request', 'message' => 'client_id is required.'];
        }
        $client = self::findClient($clientId);
        if ($client === null) {
            return ['error' => 'invalid_client', 'message' => 'Unknown client_id.'];
        }
        if ($redirectUri === '' || !self::redirectUriAllowed($client, $redirectUri)) {
            return ['error' => 'invalid_request', 'message' => 'redirect_uri does not match a registered URI.'];
        }
        if ($responseType !== 'code') {
            return ['error' => 'unsupported_response_type', 'message' => 'Only response_type=code is supported.'];
        }
        if ($challenge === '') {
            return ['error' => 'invalid_request', 'message' => 'code_challenge (PKCE) is required.'];
        }
        if (strtoupper($method) !== 'S256') {
            return ['error' => 'invalid_request', 'message' => 'code_challenge_method must be S256.'];
        }

        return [
            'client'         => $client,
            'redirect_uri'   => $redirectUri,
            'scopes'         => self::scopeList($scopeStr),
            'code_challenge' => $challenge,
            'state'          => $state,
        ];
    }

    private static function findClient(string $clientId): ?\Kyte\Core\ModelObject
    {
        $c = new \Kyte\Core\ModelObject(KyteOAuthClient);
        return $c->retrieve('client_id', $clientId) ? $c : null;
    }

    private static function redirectUriAllowed(\Kyte\Core\ModelObject $client, string $uri): bool
    {
        $registered = json_decode((string)$client->redirect_uris, true);
        return is_array($registered) && in_array($uri, $registered, true);
    }

    /**
     * Authenticate the caller as a Kyte USER session (the Shipyard consent
     * page's JWT/HMAC session), populating $api->user + $api->account. Returns
     * a 401 error array on failure, or null on success. An MCP bearer is not a
     * user session and is rejected.
     */
    private static function authenticateUser(Api $api): ?array
    {
        try {
            $strategy = AuthDispatcher::buildDefault()->select();
            if ($strategy === null || $strategy instanceof McpTokenStrategy) {
                return self::error(401, 'login_required', 'Authentication required.');
            }
            $strategy->preAuth($api);
            $strategy->verify($api);
        } catch (\Kyte\Exception\SessionException $e) {
            return self::error(401, 'login_required', 'Authentication required.');
        } catch (\Throwable $e) {
            error_log('OAuthEndpoint::authenticateUser - ' . $e->getMessage());
            return self::error(401, 'login_required', 'Authentication required.');
        }

        if (!isset($api->user->id) || !$api->user->id
            || !isset($api->account->id) || !$api->account->id) {
            return self::error(401, 'login_required', 'Authentication required.');
        }
        return null;
    }

    /** Filtered kmcp scope list (default least-privilege ['read']). */
    private static function scopeList(string $scope): array
    {
        $requested = trim($scope) !== '' ? (preg_split('/\s+/', trim($scope)) ?: []) : [];
        $keep = array_values(array_intersect(array_map('strval', $requested), self::SUPPORTED_SCOPES));
        if (empty($keep)) {
            $keep = ['read'];
        }
        return array_values(array_unique($keep));
    }

    private static function generateAuthCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $len = strlen($alphabet);
        $body = '';
        $bytes = random_bytes(32);
        for ($i = 0; $i < 32; $i++) {
            $body .= $alphabet[ord($bytes[$i]) % $len];
        }
        return 'kyac_' . $body; // kyte auth code
    }

    /** @param array<string,mixed> $server */
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

    /** @param array<string,mixed> $body */
    private static function json(int $status, array $body): array
    {
        return [
            'status'  => $status,
            'body'    => $body,
            'headers' => ['Cache-Control: no-store'],
        ];
    }

    private static function error(int $status, string $code, string $message): array
    {
        // OAuth 2.0 error response shape (RFC 6749 §5.2 / §4.1.2.1).
        return [
            'status'  => $status,
            'body'    => ['error' => $code, 'error_description' => $message],
            'headers' => ['Cache-Control: no-store'],
        ];
    }

    private static function emitCorsHeaders(): void
    {
        // Browser-based connectors (claude.ai) hit discovery + token cross-origin.
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '') {
            header("Access-Control-Allow-Origin: {$origin}");
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Credentials: true');
    }
}
