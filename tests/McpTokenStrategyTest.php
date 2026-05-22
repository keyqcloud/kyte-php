<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Kyte\Core\Auth\McpTokenStrategy.
 *
 * Same shape as HmacSessionStrategyTest — real DB fixtures, exercise the
 * strategy surface directly. Phase 2 contract for the bearer-token path.
 *
 * See docs/design/kyte-mcp-and-auth-migration.md section 5.4.
 */
class McpTokenStrategyTest extends TestCase
{
    private const RAW_TOKEN        = 'kmcp_live_test_raw_token_abc123xyz789';
    private const REVOKED_RAW      = 'kmcp_live_revoked_token_abcdef123456';
    private const EXPIRED_RAW      = 'kmcp_live_expired_token_abcdef987654';
    private const IP_RESTRICTED_RAW = 'kmcp_live_iprestricted_1234567890abcd';
    private const FIXED_ACCOUNT    = 'test-account-mcp-strat';
    private const CLIENT_IP        = '203.0.113.42';

    /** @var \Kyte\Core\Api */
    private $api;

    /** @var \Kyte\Core\Auth\McpTokenStrategy */
    private $strategy;

    /** @var int */
    private $accountId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new \Kyte\Core\Api();
        $this->strategy = new \Kyte\Core\Auth\McpTokenStrategy();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KyteMCPToken);
        \Kyte\Core\DBI::createTable(KyteActivityLog);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::FIXED_ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteMCPToken` WHERE token_prefix LIKE 'kmcp_live_%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteActivityLog` WHERE action = 'MCP_TOKEN_USE'");

        $account = new \Kyte\Core\ModelObject(KyteAccount);
        $account->create([
            'name'   => 'MCP Strategy Test Account',
            'number' => self::FIXED_ACCOUNT,
        ]);
        $this->accountId = $account->id;

        $this->seedToken(self::RAW_TOKEN,         ['expires_at' => time() + 3600]);
        $this->seedToken(self::REVOKED_RAW,       ['revoked_at' => time() - 60, 'expires_at' => time() + 3600]);
        $this->seedToken(self::EXPIRED_RAW,       ['expires_at' => time() - 60]);
        $this->seedToken(self::IP_RESTRICTED_RAW, [
            'expires_at'   => time() + 3600,
            'ip_allowlist' => '203.0.113.0/24, 2001:db8::/32',
        ]);

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);

        $_SERVER = ['REMOTE_ADDR' => self::CLIENT_IP];
    }

    private function seedToken(string $raw, array $overrides = []): void
    {
        $token = new \Kyte\Core\ModelObject(KyteMCPToken);
        $token->create(array_merge([
            'token_hash'   => hash('sha256', $raw),
            'token_prefix' => substr($raw, 0, 16),
            'name'         => 'Test token: ' . substr($raw, 10, 8),
            'scopes'       => 'read,draft',
            'expires_at'   => 0,
            'revoked_at'   => 0,
            'kyte_account' => $this->accountId,
        ], $overrides));
    }

    private function setAuthHeader(string $raw): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $raw;
    }

    public function testMatchesTrueForBearerWithMcpPrefix(): void
    {
        $this->setAuthHeader(self::RAW_TOKEN);
        $this->assertTrue($this->strategy->matches());
    }

    public function testMatchesFalseWhenNoAuthorizationHeader(): void
    {
        $this->assertFalse($this->strategy->matches());
    }

    public function testMatchesFalseForNonBearerAuthScheme(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('user:pass');
        $this->assertFalse($this->strategy->matches());
    }

    public function testMatchesFalseForBearerWithoutMcpPrefix(): void
    {
        // A JWT-shaped bearer should fall through to the future JwtSessionStrategy,
        // not be claimed by this one.
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer eyJhbGciOiJSUzI1NiJ9.payload.sig';
        $this->assertFalse($this->strategy->matches());
    }

    public function testMatchesReadsRedirectAuthorizationFallback(): void
    {
        // Apache+FPM without the Authorization-passthrough rewrite exposes the
        // header in REDIRECT_HTTP_AUTHORIZATION. We accept both.
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . self::RAW_TOKEN;
        $this->assertTrue($this->strategy->matches());
    }

    public function testPreAuthHappyPathPopulatesAccountAndScopes(): void
    {
        $this->setAuthHeader(self::RAW_TOKEN);

        $this->strategy->preAuth($this->api);

        $this->assertSame(self::FIXED_ACCOUNT, $this->api->account->number);
        $this->assertSame(['read', 'draft'], $this->strategy->scopes);
        $this->assertNotNull($this->strategy->token);
        $this->assertSame(hash('sha256', self::RAW_TOKEN), $this->strategy->token->token_hash);
    }

    public function testPreAuthUpdatesLastUsedAtAndIp(): void
    {
        $this->setAuthHeader(self::RAW_TOKEN);
        $before = time();

        $this->strategy->preAuth($this->api);

        $fresh = new \Kyte\Core\ModelObject(KyteMCPToken);
        $fresh->retrieve('token_hash', hash('sha256', self::RAW_TOKEN));
        $this->assertGreaterThanOrEqual($before, (int)$fresh->last_used_at);
        $this->assertSame(self::CLIENT_IP, $fresh->last_used_ip);
    }

    public function testPreAuthLogsMcpTokenUseAuditRow(): void
    {
        $this->setAuthHeader(self::RAW_TOKEN);
        $this->strategy->preAuth($this->api);

        $rows = \Kyte\Core\DBI::query(
            "SELECT * FROM `KyteActivityLog` WHERE action = 'MCP_TOKEN_USE' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotEmpty($rows, 'Expected an MCP_TOKEN_USE row after successful auth');

        $row = $rows[0];
        $this->assertSame('KyteMCPToken', $row['model_name']);
        $this->assertSame('token_prefix', $row['field']);
        $this->assertSame(substr(self::RAW_TOKEN, 0, 16), $row['value']);
        $this->assertSame('200', (string)$row['response_code']);
        $this->assertSame('authenticated', $row['response_status']);
        $this->assertSame((int)$this->strategy->token->id, (int)$row['record_id']);

        $payload = json_decode($row['request_data'], true);
        $this->assertSame(['read', 'draft'], $payload['scopes']);
        $this->assertSame(self::CLIENT_IP, $payload['ip']);
    }

    public function testPreAuthRejectsUnknownToken(): void
    {
        $this->setAuthHeader('kmcp_live_does_not_exist_xxxxxxxxxxxx');

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('Invalid MCP token');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsRevokedToken(): void
    {
        $this->setAuthHeader(self::REVOKED_RAW);

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('revoked');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsExpiredToken(): void
    {
        $this->setAuthHeader(self::EXPIRED_RAW);

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('expired');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthAcceptsClientIpInsideAllowlist(): void
    {
        $this->setAuthHeader(self::IP_RESTRICTED_RAW);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        $this->strategy->preAuth($this->api);
        $this->assertSame(self::FIXED_ACCOUNT, $this->api->account->number);
    }

    public function testPreAuthRejectsClientIpOutsideAllowlist(): void
    {
        $this->setAuthHeader(self::IP_RESTRICTED_RAW);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('not permitted from this source IP');
        $this->strategy->preAuth($this->api);
    }

    public function testPreAuthRejectsMalformedBearerHeader(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer something_without_prefix';

        $this->expectException(\Kyte\Exception\SessionException::class);
        $this->expectExceptionMessage('missing or malformed');
        $this->strategy->preAuth($this->api);
    }

    public function testVerifyIsNoOp(): void
    {
        $this->setAuthHeader(self::RAW_TOKEN);
        $this->strategy->preAuth($this->api);
        $this->strategy->verify($this->api);
        $this->addToAssertionCount(1);
    }

    public function testNameIsMcpToken(): void
    {
        $this->assertSame('mcp_token', $this->strategy->name());
    }
}
