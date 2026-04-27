<?php
namespace Kyte\Core\Auth;

use Kyte\Core\Api;
use Kyte\Exception\SessionException;

/**
 * AuthStrategy for MCP (Model Context Protocol) bearer tokens.
 *
 * Claims requests carrying `Authorization: Bearer kmcp_live_...` and validates
 * them against KyteMCPToken: sha256 lookup, revocation check, TTL check, optional
 * CIDR allowlist, and audit-trail update. On success, populates $api->account
 * from the token's FK so downstream controllers run under the correct tenancy.
 *
 * Intentionally does NOT populate $api->key. MCP tokens are not API keys —
 * they're a separate credential scheme. Any code path that still references
 * $api->key inside an MCP flow should be considered a mis-route (MCP tools
 * operate on account-scoped models directly).
 *
 * Scope enforcement is NOT performed here. matches()/preAuth() answer
 * "is this caller authenticated at all"; whether the caller may invoke a
 * specific tool is a decision the /mcp endpoint makes by reading
 * $strategy->scopes. Splitting auth from authorization this way matches the
 * MCP spec's JSON-RPC dispatch model.
 *
 * NOT yet registered in AuthDispatcher::buildDefault(). Real registration
 * happens alongside the /mcp endpoint in a later Phase 2 commit — once routed,
 * this strategy becomes live on any install with AUTH_STRATEGY_DISPATCHER='on'
 * (currently 'shadow' on dev, 'off' at customers).
 */
class McpTokenStrategy implements AuthStrategy
{
    public const TOKEN_PREFIX = 'kmcp_live_';

    /** @var \Kyte\Core\ModelObject|null Set by preAuth() on success. */
    public $token = null;

    /** @var string[] Parsed scopes from the validated token. Empty until preAuth() succeeds. */
    public $scopes = [];

    public function name(): string
    {
        return 'mcp_token';
    }

    /**
     * True iff the request carries `Authorization: Bearer <TOKEN_PREFIX>...`.
     *
     * Checks both HTTP_AUTHORIZATION and REDIRECT_HTTP_AUTHORIZATION because
     * Apache strips the Authorization header from CGI/FPM by default; customers
     * who haven't added `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`
     * or the equivalent mod_rewrite rule will see it only in REDIRECT_*.
     * Kyte's default .htaccess handles this on existing installs, but new
     * installs or custom vhosts may not — accept both to avoid a confusing
     * "token rejected" debugging session.
     */
    public function matches(): bool
    {
        $raw = $this->rawToken();
        return $raw !== null && str_starts_with($raw, self::TOKEN_PREFIX);
    }

    /**
     * Validates the token and populates $api->account. See class docblock for
     * what is intentionally left out of this path (API key, app binding, scope
     * check).
     */
    public function preAuth(Api $api): void
    {
        $raw = $this->rawToken();
        if ($raw === null || !str_starts_with($raw, self::TOKEN_PREFIX)) {
            throw new SessionException('[ERROR] MCP bearer token missing or malformed.');
        }

        $hash = hash('sha256', $raw);

        $token = new \Kyte\Core\ModelObject(KyteMCPToken);
        if (!$token->retrieve('token_hash', $hash)) {
            throw new SessionException('[ERROR] Invalid MCP token.');
        }

        if ((int)$token->revoked_at !== 0) {
            throw new SessionException('[ERROR] MCP token has been revoked.');
        }

        $now = time();
        if ((int)$token->expires_at !== 0 && $now > (int)$token->expires_at) {
            throw new SessionException('[ERROR] MCP token has expired.');
        }

        $allowlist = trim((string)$token->ip_allowlist);
        if ($allowlist !== '' && !self::ipAllowed($this->clientIp(), $allowlist)) {
            throw new SessionException('[ERROR] MCP token not permitted from this source IP.');
        }

        if (!$api->account->retrieve('id', (int)$token->kyte_account)) {
            throw new SessionException("[ERROR] MCP token's owning account no longer exists.");
        }

        $this->token  = $token;
        $this->scopes = array_values(array_filter(array_map('trim', explode(',', (string)$token->scopes))));

        // Synchronous audit-trail update. One UPDATE per request is cheap at
        // per-tenant MCP volumes (1–10 concurrent developers). If this becomes
        // hot in telemetry, revisit as a background write.
        $token->save([
            'last_used_at' => $now,
            'last_used_ip' => $this->clientIp(),
        ]);

        // MCP_TOKEN_USE audit row per design doc R7. Best-effort — the auth
        // decision still stands if the log write fails. The token's id goes
        // in record_id (not request_data) to dodge the redactSensitive
        // "token" substring match that would blank a token_id field.
        try {
            \Kyte\Core\ActivityLogger::getInstance()->log(
                'MCP_TOKEN_USE',
                'KyteMCPToken',
                'token_prefix',
                (string)$token->token_prefix,
                [
                    'scopes'   => $this->scopes,
                    'ip'       => $this->clientIp(),
                ],
                200,
                'authenticated',
                null,
                (int)$token->id
            );
        } catch (\Throwable $e) {
            error_log('McpTokenStrategy: failed to log MCP_TOKEN_USE - ' . $e->getMessage());
        }
    }

    /**
     * No-op. Bearer tokens complete their validation in preAuth(); the
     * verify() slot exists only to satisfy the two-phase AuthStrategy contract
     * (mirroring HmacSessionStrategy's preAuth/verify split).
     */
    public function verify(Api $api): void
    {
    }

    /**
     * Extract the raw bearer token from whichever Authorization header slot
     * the SAPI exposed. Returns null if no valid Bearer header is present.
     */
    private function rawToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;

        if ($header === null) {
            return null;
        }

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        return $token === '' ? null : $token;
    }

    private function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }

    /**
     * Checks an IP against a comma-separated CIDR allowlist. Supports IPv4 and
     * IPv6. Bare addresses are treated as /32 (v4) or /128 (v6). Returns false
     * if the client IP is empty (unknowable source → deny when allowlist is set).
     */
    private static function ipAllowed(string $client, string $allowlist): bool
    {
        if ($client === '') {
            return false;
        }

        $clientPacked = @inet_pton($client);
        if ($clientPacked === false) {
            return false;
        }

        foreach (explode(',', $allowlist) as $cidr) {
            $cidr = trim($cidr);
            if ($cidr === '') {
                continue;
            }

            if (self::cidrMatch($clientPacked, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function cidrMatch(string $clientPacked, string $cidr): bool
    {
        $slash = strpos($cidr, '/');
        if ($slash === false) {
            $network = $cidr;
            $prefix  = null;
        } else {
            $network = substr($cidr, 0, $slash);
            $prefix  = (int)substr($cidr, $slash + 1);
        }

        $networkPacked = @inet_pton($network);
        if ($networkPacked === false) {
            return false;
        }

        if (strlen($networkPacked) !== strlen($clientPacked)) {
            return false; // v4/v6 mismatch
        }

        $fullBits = strlen($networkPacked) * 8;
        if ($prefix === null) {
            $prefix = $fullBits;
        }
        if ($prefix < 0 || $prefix > $fullBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainder = $prefix % 8;

        if ($fullBytes > 0 && substr($networkPacked, 0, $fullBytes) !== substr($clientPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);
        return (ord($networkPacked[$fullBytes]) & ord($mask)) === (ord($clientPacked[$fullBytes]) & ord($mask));
    }
}
