<?php
namespace Kyte\Mcp\Util;

/**
 * Resolves the real client IP for an inbound MCP request.
 *
 * Why this exists: Kyte instances are commonly fronted by Cloudflare
 * (or another reverse proxy / load balancer). `$_SERVER['REMOTE_ADDR']`
 * in that topology is the proxy's edge IP, not the client's. That has
 * two real consequences for the MCP surface:
 *
 *   1. The IP allowlist on `KyteMCPToken` (per design doc § 5.4) becomes
 *      useless — every token's source IP looks like a Cloudflare IP, so
 *      pinning a token to a developer's office network never matches.
 *      A token holder behind a different ISP would also pass the check.
 *
 *   2. Audit rows (`MCP_TOKEN_USE`, `MCP_TOKEN_REVOKE`,
 *      `MCP_SCOPE_VIOLATION`, `last_used_ip`) record the proxy IP,
 *      which destroys forensic value during a compliance review.
 *
 * Resolution policy:
 *   - If `KYTE_TRUST_PROXY_IP_HEADERS` is true, prefer
 *     `CF-Connecting-IP` (Cloudflare's authoritative client-IP header,
 *     stripped from inbound requests so spoofing requires bypassing
 *     Cloudflare entirely), then the first hop of `X-Forwarded-For`
 *     (general L7 proxy convention).
 *   - Fall back to `REMOTE_ADDR` always — it's the only thing we can
 *     trust without proxy-aware config, and it's correct on installs
 *     that don't sit behind a proxy.
 *
 * Trust gating is mandatory: blindly honoring `X-Forwarded-For` from
 * any inbound request would let an attacker forge their source IP
 * and bypass the allowlist. Each install opts in by setting the
 * constant in `config.php` after confirming their proxy strips/sets
 * these headers correctly.
 */
final class ClientIp
{
    public static function resolve(): string
    {
        $trustProxy = defined('KYTE_TRUST_PROXY_IP_HEADERS') && KYTE_TRUST_PROXY_IP_HEADERS === true;

        if ($trustProxy) {
            $cfHeader = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
            if (self::isValidIp($cfHeader)) {
                return $cfHeader;
            }

            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff !== '') {
                // First hop is the original client; subsequent entries are
                // intermediate proxies appended on each hop.
                $candidate = trim(explode(',', $xff)[0]);
                if (self::isValidIp($candidate)) {
                    return $candidate;
                }
            }
        }

        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    private static function isValidIp(string $candidate): bool
    {
        $candidate = trim($candidate);
        return $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false;
    }
}
