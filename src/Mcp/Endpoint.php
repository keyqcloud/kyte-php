<?php
namespace Kyte\Mcp;

use Kyte\Core\Api;
use Kyte\Core\Auth\AuthDispatcher;
use Kyte\Core\Auth\McpTokenStrategy;
use Kyte\Exception\SessionException;
use Kyte\Mcp\Session\DbSessionStore;
use Kyte\Mcp\Session\SaveSafeSessionFactory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Capability\Registry as McpRegistry;
use Mcp\Capability\Registry\Container as McpContainer;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Server;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Entry point for `/mcp` requests. Owns the full MCP handling lifecycle:
 *   1. Authenticate via AuthDispatcher (McpTokenStrategy resolves the bearer
 *      token, populates $api->account).
 *   2. Run the mcp/sdk Server with attribute-based discovery on the Tools dir.
 *   3. Emit the resulting PSR-7 response back to the client.
 *
 * Bypasses Api::validateRequest() entirely. The standard MVC pipeline assumes
 * Kyte's URL-shaped routing (POST /{model} + data) and HMAC-style response
 * envelopes, neither of which apply to JSON-RPC over MCP. Calling
 * Api::route() detects `/mcp` early and delegates here before any of that runs.
 *
 * Session storage defaults to the DB-backed DbSessionStore (the KyteMCPSession
 * table), so multi-instance / load-balanced deployments resolve the same
 * protocol session on any host. The SDK's bundled FileSessionStore (per-host,
 * under sys_get_temp_dir()) remains available as an escape hatch via the
 * KYTE_MCP_SESSION_STORE='file' constant for single-instance installs that
 * prefer to avoid the table. KYTE_MCP_SESSION_TTL overrides the idle TTL
 * (default 3600s). See buildSessionStore() and design doc section 11.
 *
 * The handle()/process() split is for testability: process() is pure
 * request-in / response-out and can be exercised from PHPUnit, while handle()
 * binds it to PHP superglobals + SapiEmitter for real request handling.
 */
final class Endpoint
{
    /** Production entry point. Reads from globals, emits to SAPI. */
    public static function handle(Api $api): void
    {
        $request = self::requestFromGlobals();
        $response = self::process($api, $request);
        (new SapiEmitter())->emit($response);
    }

    /**
     * Pure request-in / response-out. Authenticates, dispatches via the SDK,
     * returns the resulting PSR-7 response. Auth failures and pre-dispatch
     * errors come back as JSON-RPC-shaped error responses with the
     * appropriate HTTP status — never as exceptions thrown to the caller.
     */
    public static function process(Api $api, ServerRequestInterface $request): ResponseInterface
    {
        $psr17 = new Psr17Factory();

        // Origin check runs before auth — DNS rebinding doesn't care about
        // tokens, and rejecting fast saves a DB hit on the bearer lookup.
        $originError = self::checkOrigin($request);
        if ($originError !== null) {
            return self::jsonRpcError($psr17, 403, -32011, $originError);
        }

        try {
            self::authenticate($api, $request);
        } catch (SessionException $e) {
            // RFC 9728 / MCP auth: point unauthenticated clients at this
            // install's protected-resource metadata so Claude.ai / ChatGPT can
            // auto-discover the OAuth authorization server (KYTE-#551).
            $resourceMetadata = \Kyte\Core\Auth\OAuthEndpoint::baseUrl($request->getServerParams())
                . '/.well-known/oauth-protected-resource';
            return self::jsonRpcError($psr17, 401, -32001, $e->getMessage())
                ->withHeader('WWW-Authenticate', 'Bearer resource_metadata="' . $resourceMetadata . '"');
        } catch (\Throwable $e) {
            return self::jsonRpcError($psr17, 500, -32603, 'Internal MCP error: ' . $e->getMessage());
        }

        $transport = new StreamableHttpTransport(
            $request,
            responseFactory: $psr17,
            streamFactory: $psr17,
        );

        $container = new McpContainer();
        $container->set(Api::class, $api);

        $sessionStore = self::buildSessionStore($api);

        // Build registry + inner CallToolHandler ourselves so we can wrap the
        // dispatch with ScopedCallToolHandler. The Builder otherwise creates
        // these privately inside build(); registering our own registry via
        // setRegistry() lets the SDK's loaders populate the same instance our
        // wrapper later reads from. addRequestHandler() prepends to the
        // handler list, so our wrapper wins the first-supports-wins dispatch
        // in Server\Protocol over the SDK's default CallToolHandler.
        $registry        = new McpRegistry();
        $referenceHandler = new ReferenceHandler($container);
        $innerCallTool   = new CallToolHandler($registry, $referenceHandler);
        $scopedCallTool  = new ScopedCallToolHandler($innerCallTool, new ScopeRegistry($registry), $api);

        $server = Server::builder()
            ->setServerInfo(
                'Kyte MCP',
                \Kyte\Core\Version::get(),
                'Kyte low-code framework MCP endpoint'
            )
            ->setInstructions(
                'Tools operate on the Kyte account tied to the bearer token. Start with '
                . 'list_applications (or get_app_info) to discover apps, then work down: '
                . 'models + controllers/functions (backend), sites + pages + scripts (frontend). '
                . "\n\n"
                . 'CONNECTION: call get_app_info(application_id) for the API endpoint, the app '
                . 'identifier, and each site URL — do not guess or hard-code the endpoint.'
                . "\n\n"
                . 'WRITING PAGE / SCRIPT JS: Kyte injects a ready-to-use API client into every '
                . 'published page as the GLOBAL variable `k` (a Kyte instance). Do NOT create your '
                . 'own client or hard-code URLs/keys — just call `k`. Data access is model-based, '
                . 'not REST URLs: k.get(model, field, value, headers, onOk, onErr) to read, '
                . 'k.post(model, data, formData, headers, onOk, onErr) to create, '
                . 'k.put(model, field, value, data, formData, headers, onOk, onErr) to update, '
                . 'k.delete(model, field, value, headers, onOk, onErr) to delete. onOk receives a '
                . 'response whose `.data` is ALWAYS an array. `model` is a controller/model name '
                . '(e.g. "Task"). Call get_kytejs_guide for the full signatures + a worked example '
                . 'BEFORE writing any page or script JavaScript.'
                . "\n\n"
                . 'WRITING CONTROLLER / FUNCTION PHP: controller hooks and method overrides have '
                . 'template-specific signatures (several params are by-reference) and a specific '
                . '$this context ($this->user, $this->account, $this->response, $this->model) + '
                . 'query API. Call get_controller_guide for the exact signatures + examples BEFORE '
                . 'writing function code with write_function_code.'
                . "\n\n"
                . 'EDIT FLOW: create_* makes a draft; add code/content with write_page_part / '
                . 'write_script_content / write_function_code; publish with commit_draft.'
            )
            ->setContainer($container)
            ->setRegistry($registry)
            ->addRequestHandler($scopedCallTool)
            ->setSession($sessionStore, new SaveSafeSessionFactory())
            ->setDiscovery(__DIR__ . '/Tools')
            ->build();

        return $server->run($transport);
    }

    private static function requestFromGlobals(): ServerRequestInterface
    {
        $psr17 = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
        return $creator->fromGlobals();
    }

    /**
     * Run the auth dispatcher to populate $api->account from the bearer token.
     * Bypasses validateRequest() since we don't want its HMAC-flavored response
     * envelope side-effects (kyte_pub, kyte_iden, etc.) leaking into MCP.
     *
     * Reads the Authorization header from the PSR-7 request rather than from
     * globals so this path is testable. McpTokenStrategy still consults
     * $_SERVER itself; the test harness sets both consistently.
     */
    private static function authenticate(Api $api, ServerRequestInterface $request): void
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if ($authHeader === '') {
            throw new SessionException('[ERROR] /mcp requires an Authorization header.');
        }

        $strategy = AuthDispatcher::buildDefault()->select();
        if (!$strategy instanceof McpTokenStrategy) {
            throw new SessionException('[ERROR] /mcp requires an MCP bearer token (kmcp_live_...).');
        }

        $strategy->preAuth($api);
        $strategy->verify($api); // no-op for bearer; kept for symmetry

        $api->mcpToken  = $strategy->token;
        $api->mcpScopes = $strategy->scopes;
    }

    /**
     * MCP spec § Security requires servers to validate the Origin header to
     * prevent DNS rebinding attacks. The attack vector is browser-only:
     * malicious JavaScript on attacker.com tricks the victim's browser into
     * POSTing to a Kyte instance, exploiting the bearer-token auth that the
     * browser may have cached. CLI clients (Claude Code) are not affected
     * because there is no shared-cookie / shared-credential context for an
     * attacker to exploit.
     *
     * Policy:
     *   - No Origin header → allow. CLI clients (Claude Code, curl, gust)
     *     don't send Origin. Forcing one would break every non-browser
     *     integration without a security benefit.
     *   - Origin present + matches allowlist → allow.
     *   - Origin present + no match → 403 + JSON-RPC -32011.
     *
     * Allowlist source: the per-install `MCP_ALLOWED_ORIGINS` PHP constant
     * (CSV of full origins, e.g. `"https://claude.ai,https://app.example.com"`).
     * Empty / undefined means "no browser origins are allowed" — Claude.ai
     * custom-connector users must opt in by setting the constant in their
     * config.php. Restrictive default is intentional: a permissive
     * allowlist would broaden the attack surface for browser-borne
     * requests without an explicit opt-in signal from the operator.
     *
     * Returns the rejection reason string on failure, or null on pass.
     */
    private static function checkOrigin(ServerRequestInterface $request): ?string
    {
        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return null;
        }

        $allowed = defined('MCP_ALLOWED_ORIGINS') ? (string)MCP_ALLOWED_ORIGINS : '';
        $list = array_values(array_filter(array_map('trim', explode(',', $allowed)), fn ($v) => $v !== ''));

        if (in_array($origin, $list, true)) {
            return null;
        }

        return "Origin '{$origin}' is not in the MCP_ALLOWED_ORIGINS allowlist.";
    }

    /**
     * Select the MCP protocol-session store.
     *
     * Defaults to the DB-backed store so load-balanced / multi-instance
     * installs work out of the box (the SDK's FileSessionStore is per-host and
     * breaks the moment `initialize` and its follow-ups land on different
     * boxes — see DbSessionStore). Single-instance installs may opt back to the
     * file store with KYTE_MCP_SESSION_STORE='file'. KYTE_MCP_SESSION_TTL (s)
     * overrides the idle TTL for either backend.
     *
     * Requires the 4.6.0 migration (creates KyteMCPSession) to have run when
     * the DB backend is active.
     */
    private static function buildSessionStore(Api $api): SessionStoreInterface
    {
        $ttl = (defined('KYTE_MCP_SESSION_TTL') && (int)KYTE_MCP_SESSION_TTL > 0)
            ? (int)KYTE_MCP_SESSION_TTL
            : DbSessionStore::DEFAULT_TTL;

        $backend = defined('KYTE_MCP_SESSION_STORE')
            ? strtolower(trim((string)KYTE_MCP_SESSION_STORE))
            : 'db';

        if ($backend === 'file') {
            return new FileSessionStore(self::sessionDirectory(), $ttl);
        }

        return new DbSessionStore((int)$api->account->id, $ttl);
    }

    private static function sessionDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/kyte-mcp-sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    private static function jsonRpcError(Psr17Factory $psr17, int $httpStatus, int $rpcCode, string $message): ResponseInterface
    {
        $body = json_encode([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => $rpcCode, 'message' => $message],
        ]);
        return $psr17->createResponse($httpStatus)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream($body));
    }
}
