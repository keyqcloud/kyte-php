<?php
namespace Kyte\Mcp;

use Kyte\Core\Api;
use Kyte\Core\Auth\AuthDispatcher;
use Kyte\Core\Auth\McpTokenStrategy;
use Kyte\Exception\SessionException;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Capability\Registry\Container as McpContainer;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
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
 * Session storage uses the SDK's bundled FileSessionStore, scoped per-install
 * under sys_get_temp_dir(). MCP sessions are short-lived and per-Claude-
 * conversation, so file storage is sufficient at per-tenant scale. A MySQL-
 * backed store can replace this if/when SaaS-scale deployment forces the
 * issue (see design doc section 11).
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

        try {
            self::authenticate($api, $request);
        } catch (SessionException $e) {
            return self::jsonRpcError($psr17, 401, -32001, $e->getMessage());
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

        $sessionDir = self::sessionDirectory();

        $server = Server::builder()
            ->setServerInfo(
                'Kyte MCP',
                \Kyte\Core\Version::get(),
                'Kyte low-code framework MCP endpoint'
            )
            ->setInstructions(
                'Tools operate on the account associated with the bearer token. ' .
                'Use list_applications to discover apps, then traditional Kyte ' .
                'workflows for further work. Additional tools land in subsequent ' .
                'Phase 2 commits.'
            )
            ->setContainer($container)
            ->setSession(new FileSessionStore($sessionDir))
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
