<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;

/**
 * AuthStrategy for MCP (Model Context Protocol) bearer tokens.
 *
 * Claims requests with `Authorization: Bearer kmcp_live_...` and validates
 * against KyteMCPToken (sha256 lookup, scope check, expiration, IP allowlist,
 * revocation). On success, populates $api->account and $api->key so downstream
 * controllers see the same context as the HMAC path.
 *
 * Scaffolding status (Phase 2 commit 2, 2026-04-24):
 *   SKELETON ONLY. matches() always returns false, so the dispatcher never
 *   selects this strategy — even if registered. The class exists so Phase 2
 *   proper has a place to fill in. Not registered in AuthDispatcher yet.
 *
 * Phase 2 implementation checklist (not this commit):
 *   1. matches() — real header parse + prefix check.
 *   2. preAuth() — sha256 lookup in KyteMCPToken, scope validation,
 *      expiration + revocation + IP allowlist enforcement. Set $api->key
 *      and $api->account from the token's FKs.
 *   3. verify() — no-op (bearer tokens don't have a separate verification
 *      phase). Kept as an explicit empty method to satisfy the interface
 *      and match the two-phase shape of HmacSessionStrategy.
 *   4. Update last_used_at / last_used_ip asynchronously (out-of-band from
 *      the request path; fire-and-forget).
 *   5. Log events via ActivityLogger with MCP_TOKEN_USE / MCP_SCOPE_VIOLATION
 *      action types per risk R7.
 *   6. In-process cache of validated tokens (~60s) to avoid hitting the DB
 *      per request.
 */
class McpTokenStrategy implements AuthStrategy
{
    public const TOKEN_PREFIX = 'kmcp_live_';

    public function name(): string
    {
        return 'mcp_token';
    }

    /**
     * Phase 2 skeleton: always declines. Real implementation will check
     * the Authorization header for the `kmcp_live_` prefix.
     */
    public function matches(): bool
    {
        return false;
    }

    public function preAuth(Api $api): void
    {
        throw new \LogicException('McpTokenStrategy::preAuth not implemented (Phase 2 scaffolding).');
    }

    public function verify(Api $api): void
    {
        // Bearer-token auth has no verify phase; validation happens in preAuth.
        // Kept empty to satisfy the AuthStrategy interface and mirror the
        // two-phase shape of HmacSessionStrategy. Phase 2 proper: leave as-is.
    }
}
