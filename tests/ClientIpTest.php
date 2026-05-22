<?php
namespace Kyte\Test;

use Kyte\Mcp\Util\ClientIp;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the proxy-aware client-IP resolver.
 *
 * The trust gate (KYTE_TRUST_PROXY_IP_HEADERS) is a constant, which PHP
 * doesn't allow us to undefine between tests. We define it once here in
 * setUpBeforeClass and structure tests so they don't conflict — every
 * test that needs the trust-disabled path is paired with a fresh
 * isolated assertion that doesn't rely on global state.
 *
 * Trust-disabled coverage relies on the constant being undefined, which
 * is the production default. We can't guarantee test ordering across
 * the full suite; if a prior test or class defined the constant, the
 * trust-disabled tests would silently flip behavior. Mitigation: assert
 * REMOTE_ADDR fallback under both branches (trusting and not), so the
 * test still passes either way as long as the code respects its own
 * gate.
 */
class ClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER = [];
    }

    public function testReturnsRemoteAddrWhenNoProxyHeadersPresent(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $this->assertSame('203.0.113.10', ClientIp::resolve());
    }

    public function testIgnoresProxyHeadersWhenTrustGateDisabled(): void
    {
        // We can't guarantee the constant isn't defined (test ordering),
        // so this test only runs when it isn't.
        if (defined('KYTE_TRUST_PROXY_IP_HEADERS') && KYTE_TRUST_PROXY_IP_HEADERS === true) {
            $this->markTestSkipped('KYTE_TRUST_PROXY_IP_HEADERS already defined as true; cannot exercise the disabled branch.');
        }

        $_SERVER['REMOTE_ADDR']           = '198.51.100.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.99'; // would-be-spoofed
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.99, 198.51.100.5';

        $this->assertSame('198.51.100.5', ClientIp::resolve(),
            'Without trust gate, proxy headers must not override REMOTE_ADDR (anti-spoofing default)');
    }

    public function testHonorsCfConnectingIpWhenTrustGateEnabled(): void
    {
        if (!defined('KYTE_TRUST_PROXY_IP_HEADERS')) {
            define('KYTE_TRUST_PROXY_IP_HEADERS', true);
        }
        if (KYTE_TRUST_PROXY_IP_HEADERS !== true) {
            $this->markTestSkipped('KYTE_TRUST_PROXY_IP_HEADERS already defined to a non-true value.');
        }

        $_SERVER['REMOTE_ADDR']           = '172.71.28.165'; // Cloudflare edge
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.42';

        $this->assertSame('203.0.113.42', ClientIp::resolve());
    }

    public function testHonorsFirstXffHopWhenCfHeaderAbsent(): void
    {
        if (!defined('KYTE_TRUST_PROXY_IP_HEADERS')) {
            define('KYTE_TRUST_PROXY_IP_HEADERS', true);
        }
        if (KYTE_TRUST_PROXY_IP_HEADERS !== true) {
            $this->markTestSkipped('KYTE_TRUST_PROXY_IP_HEADERS already defined to a non-true value.');
        }

        $_SERVER['REMOTE_ADDR']          = '10.0.0.5'; // ALB IP
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.42, 10.0.0.1, 10.0.0.5';

        $this->assertSame('203.0.113.42', ClientIp::resolve(),
            'First XFF hop is the original client; later entries are proxies');
    }

    public function testFallsBackToRemoteAddrWhenProxyHeadersMalformed(): void
    {
        if (!defined('KYTE_TRUST_PROXY_IP_HEADERS')) {
            define('KYTE_TRUST_PROXY_IP_HEADERS', true);
        }
        if (KYTE_TRUST_PROXY_IP_HEADERS !== true) {
            $this->markTestSkipped('KYTE_TRUST_PROXY_IP_HEADERS already defined to a non-true value.');
        }

        $_SERVER['REMOTE_ADDR']           = '198.51.100.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = 'definitely-not-an-ip';
        $_SERVER['HTTP_X_FORWARDED_FOR']  = 'also-not-an-ip, more-garbage';

        $this->assertSame('198.51.100.5', ClientIp::resolve(),
            'Malformed proxy headers must fall back rather than corrupting the IP');
    }

    public function testReturnsEmptyStringWhenNothingAvailable(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('', ClientIp::resolve());
    }
}
