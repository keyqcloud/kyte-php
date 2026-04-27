<?php

namespace Kyte\Mvc\Controller;

/**
 * Token issuance and revocation for MCP bearer tokens.
 *
 * Endpoints:
 *   POST   /KyteMCPToken          → mint a new token (returns raw token once)
 *   GET    /KyteMCPToken          → list account's tokens (no hashes; prefix only)
 *   DELETE /KyteMCPToken/{id}     → revoke + soft-delete
 *
 * Auth surface: the standard model-controller dispatch path. Callers
 * authenticate via Shipyard's existing HMAC session (or whichever auth
 * strategy the install has flipped on). MCP bearer tokens deliberately
 * do NOT authenticate this endpoint — chicken-and-egg.
 *
 * Security model on issuance:
 *   - kyte_account is force-overridden to the auth account regardless of
 *     what the request sends. ModelController::new defaults kyte_account
 *     from the request data first, falling back to the auth context — for
 *     this model that's a privilege-escalation vector (a user could mint
 *     tokens scoped to another account by setting kyte_account in the
 *     POST body). The override here closes that.
 *   - token_hash + token_prefix are server-generated; if present in the
 *     request they're discarded.
 *   - scopes is validated against {read, draft, commit}.
 *   - expires_at defaults to 30 days when not provided. 0 (never) is
 *     accepted but never the default — design doc R2 calls out short
 *     TTLs as a primary mitigation for the bearer-token theft window.
 *
 * Audit:
 *   - MCP_TOKEN_ISSUE on successful new (carries scopes + expiry + ip).
 *   - MCP_TOKEN_REVOKE on delete (carries the prefix + last_used_at so
 *     the audit row tells you what was revoked, not just an opaque id).
 *
 * The raw token is returned in the `new` response under a `raw_token`
 * key, exactly once. The model never persists or re-emits it; clients
 * that lose the value must mint a new token.
 */
class KyteMCPTokenController extends ModelController
{
    /** Allowed scope values per design doc § 5.4. */
    private const VALID_SCOPES = ['read', 'draft', 'commit'];

    /** Default TTL when the request omits expires_at: 30 days. */
    private const DEFAULT_TTL_SECONDS = 30 * 86400;

    /**
     * Raw token generated in hook_preprocess(new) and surfaced in
     * hook_response_data(new). Lives only for the duration of the request.
     */
    private ?string $newRawToken = null;

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $this->prepareIssuance($r);
                break;
            case 'update':
                // Don't allow callers to PUT a new token_hash / prefix /
                // scopes-bypass via update. Revocation goes through DELETE
                // only for now; if PUT-based revoke is added later it must
                // flow through this controller's update hook.
                unset($r['token_hash'], $r['token_prefix'], $r['kyte_account']);
                break;
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                $this->finalizeIssuance($o, $r);
                break;
            case 'delete':
                $this->logRevoke($o);
                break;
            default:
                break;
        }
    }

    /**
     * Generate raw token + populate request data with hash/prefix.
     *
     * Force-overrides kyte_account so a session for account A cannot
     * mint a token scoped to account B by sending `kyte_account: B` in
     * the POST body — see class docblock for the framework default that
     * makes this a real attack surface.
     *
     * @param array<string, mixed> $r
     */
    private function prepareIssuance(array &$r): void
    {
        // Mandatory account binding from auth context. Discard whatever
        // the request sent for this field.
        if (!isset($this->api->account->id)) {
            throw new \Exception('Cannot issue MCP token without an authenticated account context.');
        }
        $r['kyte_account'] = (int)$this->api->account->id;

        // Discard server-controlled fields if present in the request.
        unset($r['token_hash'], $r['token_prefix'], $r['last_used_at'], $r['last_used_ip'], $r['revoked_at']);

        // Validate scopes (CSV: any combination of read/draft/commit, no others).
        if (!isset($r['scopes']) || !is_string($r['scopes']) || trim($r['scopes']) === '') {
            throw new \Exception('scopes is required (CSV of: ' . implode(', ', self::VALID_SCOPES) . ')');
        }
        $requested = array_values(array_filter(array_map('trim', explode(',', $r['scopes']))));
        $invalid = array_diff($requested, self::VALID_SCOPES);
        if (!empty($invalid)) {
            throw new \Exception('Invalid scope(s): ' . implode(', ', $invalid) . '. Allowed: ' . implode(', ', self::VALID_SCOPES));
        }
        $r['scopes'] = implode(',', $requested);

        // TTL default — explicit "never expires" (0) is allowed but never
        // automatic. Design doc R2 says short TTLs are a primary mitigation;
        // we make the safe choice the default.
        //
        // Date fields go through ModelController::sift() which calls
        // strtotime() on the value before persisting. Passing a Unix int
        // makes strtotime() return false, which lands in the DB as 0 —
        // silently breaking the expiry. We format as ISO 8601 so sift's
        // strtotime parses it back to the integer we wanted.
        if (!isset($r['expires_at']) || $r['expires_at'] === '' || $r['expires_at'] === null) {
            $r['expires_at'] = date('Y-m-d H:i:s', time() + self::DEFAULT_TTL_SECONDS);
        } elseif (is_int($r['expires_at']) || ctype_digit((string)$r['expires_at'])) {
            // Caller sent a Unix timestamp; convert so sift doesn't blank it.
            $r['expires_at'] = date('Y-m-d H:i:s', (int)$r['expires_at']);
        }

        // Generate the raw token. 32 base62 chars after the prefix gives
        // ~190 bits of entropy — plenty against brute force.
        $raw = self::generateRawToken();
        $this->newRawToken = $raw;
        $r['token_hash']   = hash('sha256', $raw);
        $r['token_prefix'] = substr($raw, 0, 16);
    }

    /**
     * Inject the raw token into the response and emit MCP_TOKEN_ISSUE.
     *
     * @param array<string, mixed>|null $r the response payload, mutated in place
     */
    private function finalizeIssuance($o, &$r): void
    {
        if (is_array($r) && $this->newRawToken !== null) {
            $r['raw_token'] = $this->newRawToken;
        }

        try {
            \Kyte\Core\ActivityLogger::getInstance()->log(
                'MCP_TOKEN_ISSUE',
                'KyteMCPToken',
                'token_prefix',
                (string)$o->token_prefix,
                [
                    'scopes'     => (string)$o->scopes,
                    'expires_at' => (int)$o->expires_at,
                    'ip_allowlist' => (string)($o->ip_allowlist ?? ''),
                ],
                201,
                'issued',
                null,
                (int)$o->id
            );
        } catch (\Throwable $e) {
            error_log('KyteMCPTokenController: failed to log MCP_TOKEN_ISSUE - ' . $e->getMessage());
        }

        // Clear the cached raw so a follow-up new() call in the same
        // request lifecycle doesn't accidentally inherit the prior value.
        $this->newRawToken = null;
    }

    private function logRevoke($o): void
    {
        try {
            \Kyte\Core\ActivityLogger::getInstance()->log(
                'MCP_TOKEN_REVOKE',
                'KyteMCPToken',
                'token_prefix',
                (string)$o->token_prefix,
                [
                    'scopes'       => (string)$o->scopes,
                    'last_used_at' => (int)($o->last_used_at ?? 0),
                    'last_used_ip' => (string)($o->last_used_ip ?? ''),
                ],
                200,
                'revoked',
                null,
                (int)$o->id
            );
        } catch (\Throwable $e) {
            error_log('KyteMCPTokenController: failed to log MCP_TOKEN_REVOKE - ' . $e->getMessage());
        }
    }

    /**
     * Format: `kmcp_live_<32 base62 chars>`. Matches the constant
     * McpTokenStrategy uses to identify MCP tokens at auth time.
     *
     * Uses random_bytes (CSPRNG) and trims to base62 — concentrated
     * entropy, no padding chars that could trip URL-encoding.
     */
    private static function generateRawToken(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $alphabetLen = strlen($alphabet);
        $body = '';
        $bytes = random_bytes(32);
        for ($i = 0; $i < 32; $i++) {
            $body .= $alphabet[ord($bytes[$i]) % $alphabetLen];
        }
        return \Kyte\Core\Auth\McpTokenStrategy::TOKEN_PREFIX . $body;
    }
}
