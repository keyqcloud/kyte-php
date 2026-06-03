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
     * Convenience constructor wiring the default strategy stack.
     *
     * Order matters. Strategies are tried in declaration order; the
     * first that claims the request wins. The chosen order below relies
     * on each strategy's matches() being strict — McpToken only claims
     * Bearer `kmcp_live_...`, JwtSession only claims Bearer JWT shapes
     * (with KYTE_JWT_SECRET defined), and Hmac is the catch-all that
     * handles every existing customer flow.
     *
     * Phase 2 (MCP) added McpTokenStrategy.
     * Phase 3 (JWT) appended JwtSessionStrategy between MCP and HMAC.
     * Phase 3 follow-on added AppContextStrategy between Jwt and HMAC — it
     * claims ONLY anonymous app-only requests (x-kyte-appid, no Bearer/
     * signature/identity), so it cannot shadow the authenticated flows above
     * or the HMAC catch-all below.
     * Each is opt-in by config: a token can only be claimed by a
     * strategy that's actually configured on the install.
     */
    public static function buildDefault(): self
    {
        return new self([
            new McpTokenStrategy(),
            new JwtSessionStrategy(),
            new AppContextStrategy(),
            new HmacSessionStrategy(),
        ]);
    }
}
