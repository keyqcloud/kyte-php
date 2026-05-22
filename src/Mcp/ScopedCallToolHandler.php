<?php
namespace Kyte\Mcp;

use Kyte\Core\ActivityLogger;
use Kyte\Core\Api;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\Handler\Request\CallToolHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Pre-empts the SDK's CallToolHandler to enforce MCP token scopes.
 *
 * Registered via Builder::addRequestHandler() before build(). Builder
 * merges user handlers ahead of its defaults, and Server\Protocol
 * dispatches first-supports-wins, so every CallToolRequest lands here
 * before reaching the SDK's own handler. On scope pass we delegate to
 * the inner CallToolHandler unchanged. On miss we return a JSON-RPC
 * error and emit an MCP_SCOPE_VIOLATION audit row per design doc R7.
 *
 * The scope set comes from $api->mcpScopes, populated by McpTokenStrategy
 * in Endpoint::authenticate. We do not re-validate the token here — auth
 * has already happened. This class is strictly authorization.
 *
 * JSON-RPC error code -32010 is in the application-error range
 * (-32000..-32099 per the JSON-RPC 2.0 spec). Endpoint already uses
 * -32001 for auth failures; the SDK reserves -32002 internally for
 * RESOURCE_NOT_FOUND. -32010 keeps scope failures distinguishable in
 * client logs without colliding with anything the SDK emits.
 *
 * @implements RequestHandlerInterface<\Mcp\Schema\Result\CallToolResult>
 */
final class ScopedCallToolHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CallToolHandler $inner,
        private readonly ScopeRegistry $scopes,
        private readonly Api $api,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $toolName = $request->name;
        $required = $this->scopes->requiredScopeFor($toolName);
        $present  = $this->api->mcpScopes ?? [];

        if ($required === null) {
            $this->logViolation($toolName, '<undeclared>', $present);
            return new Error(
                $request->getId(),
                -32010,
                "Tool '{$toolName}' has no scope declaration; refusing to dispatch."
            );
        }

        if (!\in_array($required, $present, true)) {
            $this->logViolation($toolName, $required, $present);
            return new Error(
                $request->getId(),
                -32010,
                "Tool '{$toolName}' requires scope '{$required}'; token scopes: ["
                . implode(',', $present) . "]."
            );
        }

        return $this->inner->handle($request, $session);
    }

    /**
     * Best-effort audit write. ActivityLogger swallows its own failures and
     * we wrap again here because a logging error must never escape into
     * the JSON-RPC response — the scope decision still stands.
     *
     * @param string[] $present
     */
    private function logViolation(string $toolName, string $required, array $present): void
    {
        // The token's primary key goes into the dedicated record_id column
        // rather than into request_data. ActivityLogger::redactSensitive
        // does substring matching against a "token" needle, so any payload
        // key containing "token" (including a harmless surrogate like
        // "token_id") would be replaced with [REDACTED] and the audit row
        // would lose the link back to the offending credential.
        $tokenId = isset($this->api->mcpToken->id) ? (int)$this->api->mcpToken->id : null;

        try {
            ActivityLogger::getInstance()->log(
                'MCP_SCOPE_VIOLATION',
                'KyteMCPToken',
                'tool',
                $toolName,
                [
                    'required_scope' => $required,
                    'present_scopes' => $present,
                ],
                403,
                'denied',
                "Scope '{$required}' missing from token",
                $tokenId
            );
        } catch (\Throwable $e) {
            error_log('ScopedCallToolHandler: failed to log scope violation - ' . $e->getMessage());
        }
    }
}
