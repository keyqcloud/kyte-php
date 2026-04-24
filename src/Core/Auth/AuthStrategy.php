<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;

/**
 * Contract for request-authentication strategies.
 *
 * A strategy inspects the request (headers, config) to decide whether it
 * claims responsibility, then performs its two-phase auth work against a
 * supplied Api instance.
 *
 * Two-phase split preserves current Api::validateRequest() ordering:
 *   1. preAuth()  — populate $api->key, $api->account, $api->user, $api->session,
 *                   and the identity-related $api->response slots.
 *   2. (caller performs URL parsing + response hydration)
 *   3. verify()   — final signature/token check. May be a no-op.
 *
 * See docs/design/kyte-mcp-and-auth-migration.md section 3.5.
 */
interface AuthStrategy
{
    /**
     * Does this strategy claim responsibility for the current request?
     * Inspects headers and per-install config. Pure; no side effects.
     */
    public function matches(): bool;

    /**
     * Phase 1: identity parse + user/account/session lookup.
     * Populates Api state. Throws SessionException / Exception on failure.
     */
    public function preAuth(Api $api): void;

    /**
     * Phase 2: final signature or token verification.
     * Called after URL parsing + response hydration. May be a no-op
     * (e.g. identity-only mode). Throws SessionException on failure.
     */
    public function verify(Api $api): void;

    /**
     * Short label for logging and telemetry (e.g. "hmac_session").
     */
    public function name(): string;
}
