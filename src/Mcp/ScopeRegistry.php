<?php
namespace Kyte\Mcp;

use Kyte\Mcp\Attribute\RequiresScope;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;

/**
 * Resolves the MCP scope required to call a given tool name.
 *
 * Decoupled from the SDK on purpose — scope policy is a Kyte concern
 * and shouldn't be wedged into the SDK's tool registry. This class
 * reads #[RequiresScope] off the resolved tool method via reflection,
 * caching per name. Reflection cost is paid at most once per tool per
 * request; subsequent lookups are O(1).
 *
 * Returns null whenever a scope cannot be confidently resolved:
 *   - tool not registered in the SDK registry,
 *   - handler is not a [class, method] pair (closures, plain functions),
 *   - method exists but carries no #[RequiresScope].
 *
 * Callers must treat null as "deny." The fail-closed default is
 * intentional — see the RequiresScope attribute docblock.
 */
final class ScopeRegistry
{
    /** @var array<string, ?string> tool name → required scope, null = deny */
    private array $cache = [];

    public function __construct(private readonly RegistryInterface $sdkRegistry)
    {
    }

    public function requiredScopeFor(string $toolName): ?string
    {
        if (\array_key_exists($toolName, $this->cache)) {
            return $this->cache[$toolName];
        }

        try {
            $reference = $this->sdkRegistry->getTool($toolName);
        } catch (ToolNotFoundException) {
            // Unknown tool. Returning null routes through the dispatcher's
            // deny path; the SDK's CallToolHandler would have surfaced its
            // own ToolNotFoundException anyway, so the user sees a scope
            // error first instead of a not-found error. That's fine — a
            // missing tool that requires no scope is still a missing tool.
            return $this->cache[$toolName] = null;
        }

        $handler = $reference->handler;
        if (!\is_array($handler) || \count($handler) !== 2) {
            // Discovered Kyte tools always come back as [class, method] (see
            // Discoverer::processMethod in vendor/mcp/sdk). A closure or
            // function-typed handler would mean someone hand-registered a
            // tool through Builder::addTool — we don't do that today, and if
            // we ever do, those tools must also expose a scope somehow. For
            // now, refuse to dispatch.
            return $this->cache[$toolName] = null;
        }

        [$className, $methodName] = $handler;
        try {
            $reflection = new \ReflectionMethod($className, $methodName);
        } catch (\ReflectionException) {
            return $this->cache[$toolName] = null;
        }

        $attrs = $reflection->getAttributes(RequiresScope::class);
        if (empty($attrs)) {
            return $this->cache[$toolName] = null;
        }

        return $this->cache[$toolName] = $attrs[0]->newInstance()->scope;
    }
}
