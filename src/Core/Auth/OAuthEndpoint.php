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

            $segments = explode('/', $path);
            $action = $segments[1] ?? '';   // oauth/<action>
            switch ($action) {
                case 'register':   // RFC 7591 dynamic client registration [P1-B / #553]
                    return self::notImplemented('register');
                case 'authorize':  // authorization-code + PKCE + consent   [P1-C / #554]
                    return self::notImplemented('authorize');
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
            'authorization_endpoint'                => $base . '/oauth/authorize',
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
