<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;

/**
 * Selects an auth strategy for the current request by consulting each
 * registered strategy's matches() method in order. First match wins;
 * no further strategies are consulted.
 *
 * Returns the selected strategy (or null if none claimed the request).
 * Does not invoke preAuth/verify — the caller runs those at the correct
 * points in the request lifecycle to preserve Api::validateRequest() ordering.
 *
 * See docs/design/kyte-mcp-and-auth-migration.md section 3.5.
 */
class AuthDispatcher
{
    /** @var AuthStrategy[] */
    private $strategies;

    /**
     * @param AuthStrategy[] $strategies Ordered list. First match wins.
     */
    public function __construct(array $strategies)
    {
        $this->strategies = $strategies;
    }

    /**
     * Returns the first strategy whose matches() returns true, or null.
     */
    public function select(): ?AuthStrategy
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->matches()) {
                return $strategy;
            }
        }
        return null;
    }

    /**
     * Convenience constructor wiring the default strategy stack for the
     * current migration state. Phase 2: McpToken first (matches only on
     * `Authorization: Bearer kmcp_live_...`, leaves all other traffic to
     * Hmac), then Hmac for everything else. Phase 3 will add JwtSession
     * between them.
     *
     * Order matters: McpToken's matches() is strict-prefix and rejects any
     * non-MCP request, so putting it first is safe — Hmac still wins for
     * every existing customer flow.
     */
    public static function buildDefault(): self
    {
        return new self([
            new McpTokenStrategy(),
            new HmacSessionStrategy(),
        ]);
    }
}
