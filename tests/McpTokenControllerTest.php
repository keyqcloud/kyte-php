<?php
namespace Kyte\Test;

use Kyte\Mvc\Controller\KyteMCPTokenController;
use PHPUnit\Framework\TestCase;

/**
 * Subclass that disables the framework's authenticate() check so tests
 * don't have to fabricate a full session. The behavior under test is
 * the hook logic and force-override semantics, neither of which depend
 * on auth — and the alternative is dragging a mock session/user into
 * every test, which would test the test harness more than the code.
 */
final class TestableKyteMCPTokenController extends KyteMCPTokenController
{
    protected function authenticate()
    {
        // no-op; tests inject a fake api->account directly
    }
}

/**
 * Tests for KyteMCPToken issuance and revocation flow.
 *
 * Drives the controller's new() / delete() methods directly with a
 * forged Api context. Each test verifies one of:
 *   - successful issuance returns the raw token in the response and
 *     persists only the hash + prefix
 *   - the kyte_account override is honored even when the request
 *     tries to overwrite it (privilege-escalation guard)
 *   - scope validation rejects unknown scope strings
 *   - default expires_at falls into a sane window when the request
 *     omits it
 *   - revoke logs MCP_TOKEN_REVOKE with enough context to identify
 *     what was revoked (prefix + scopes + last_used metadata)
 *   - token_hash never appears in response payloads (protected:true on
 *     the model field)
 */
class McpTokenControllerTest extends TestCase
{
    private const OWN_ACCOUNT      = 'mcp-issuance-test-own';
    private const FOREIGN_ACCOUNT  = 'mcp-issuance-test-foreign';

    /** @var \Kyte\Core\Api */
    private $api;
    private int $ownAccountId;
    private int $foreignAccountId;
    private array $response;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new \Kyte\Core\Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(KyteMCPToken);
        \Kyte\Core\DBI::createTable(KyteActivityLog);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::FOREIGN_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `KyteMCPToken` WHERE name LIKE 'McpIssuanceTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteActivityLog` WHERE action IN ('MCP_TOKEN_ISSUE','MCP_TOKEN_REVOKE')");

        $this->ownAccountId     = $this->createAccount(self::OWN_ACCOUNT, 'Own');
        $this->foreignAccountId = $this->createAccount(self::FOREIGN_ACCOUNT, 'Foreign');

        // Fake auth context: bind the controller to OWN account.
        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);

        // Minimum app/user/session shape ModelController constructor reads.
        // The controller's authenticate() is no-op'd in the subclass, so
        // these are only here so the framework's null-safe accesses don't
        // trip during init().
        $this->api->user    = new \Kyte\Core\ModelObject(KyteAPIKey);
        $this->api->session = new \stdClass();
        $this->api->session->hasSession = true;
        $this->api->app     = new \stdClass();
        $this->api->app->org_model = null;
        $this->api->app->userorg_colname = null;

        $this->response = [];
        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    private function makeController(): TestableKyteMCPTokenController
    {
        return new TestableKyteMCPTokenController(KyteMCPToken, $this->api, 'U', $this->response);
    }

    public function testIssuanceReturnsRawTokenAndPersistsHash(): void
    {
        $controller = $this->makeController();
        $controller->new([
            'name'   => 'McpIssuanceTestLaptop',
            'scopes' => 'read,draft',
        ]);

        $this->assertArrayHasKey('data', $this->response);
        $this->assertCount(1, $this->response['data']);
        $created = $this->response['data'][0];

        $this->assertArrayHasKey('raw_token', $created, 'response must include the raw token exactly once');
        $this->assertStringStartsWith('kmcp_live_', $created['raw_token']);
        $this->assertSame(42, strlen($created['raw_token']), 'kmcp_live_ + 32 body chars');

        // Hash is server-side; never echoed back. The framework keeps the
        // field key present but blanks the value when `protected:true` is
        // set on the model — that's the right shape for downstream API
        // consumers (the field's existence is documented; only the value
        // is hidden). Match that shape in the assertion.
        $this->assertSame('', $created['token_hash'], 'token_hash value must be blanked (protected:true)');

        // Prefix in response = first 16 chars of raw token.
        $this->assertSame(substr($created['raw_token'], 0, 16), $created['token_prefix']);

        // Persisted row carries the hash; raw is never stored.
        $row = new \Kyte\Core\ModelObject(KyteMCPToken);
        $row->retrieve('id', $created['id']);
        $this->assertSame(hash('sha256', $created['raw_token']), $row->token_hash);
        $this->assertSame($this->ownAccountId, (int)$row->kyte_account);
        $this->assertSame('read,draft', $row->scopes);
    }

    public function testIssuanceForcesAccountOverrideOnRequest(): void
    {
        $controller = $this->makeController();

        // Malicious request: try to mint a token bound to a foreign account.
        $controller->new([
            'name'         => 'McpIssuanceTestEvil',
            'scopes'       => 'commit',
            'kyte_account' => $this->foreignAccountId,
        ]);

        $this->assertArrayHasKey('data', $this->response);
        $this->assertCount(1, $this->response['data']);
        $created = $this->response['data'][0];

        $row = new \Kyte\Core\ModelObject(KyteMCPToken);
        $row->retrieve('id', $created['id']);
        $this->assertSame($this->ownAccountId, (int)$row->kyte_account, 'kyte_account override must win regardless of request body');
        $this->assertNotSame($this->foreignAccountId, (int)$row->kyte_account);
    }

    public function testIssuanceRejectsInvalidScope(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Invalid scope/');

        $controller = $this->makeController();
        $controller->new([
            'name'   => 'McpIssuanceTestBadScope',
            'scopes' => 'read,admin', // 'admin' isn't valid
        ]);
    }

    public function testIssuanceRejectsMissingScopes(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/scopes is required/');

        $controller = $this->makeController();
        $controller->new(['name' => 'McpIssuanceTestNoScope']);
    }

    public function testIssuanceDefaultsExpiresAtTo30Days(): void
    {
        $before = time();
        $controller = $this->makeController();
        $controller->new([
            'name'   => 'McpIssuanceTestDefaultTtl',
            'scopes' => 'read',
        ]);

        $row = new \Kyte\Core\ModelObject(KyteMCPToken);
        $row->retrieve('id', $this->response['data'][0]['id']);

        $expected = $before + 30 * 86400;
        // Allow a few seconds of clock drift between $before and the row insert.
        $this->assertGreaterThanOrEqual($expected, (int)$row->expires_at);
        $this->assertLessThan($expected + 60, (int)$row->expires_at);
    }

    public function testIssuanceWritesMcpTokenIssueAuditRow(): void
    {
        $controller = $this->makeController();
        $controller->new([
            'name'   => 'McpIssuanceTestAudit',
            'scopes' => 'read',
        ]);

        $rows = \Kyte\Core\DBI::query(
            "SELECT * FROM `KyteActivityLog` WHERE action = 'MCP_TOKEN_ISSUE' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotEmpty($rows);
        $row = $rows[0];

        $this->assertSame('KyteMCPToken', $row['model_name']);
        $this->assertSame('token_prefix', $row['field']);
        $this->assertSame((int)$this->response['data'][0]['id'], (int)$row['record_id']);
        $this->assertSame('issued', $row['response_status']);

        $payload = json_decode($row['request_data'], true);
        $this->assertSame('read', $payload['scopes']);
        $this->assertGreaterThan(time(), (int)$payload['expires_at']);
    }

    public function testRevokeWritesMcpTokenRevokeAuditRow(): void
    {
        // First issue a token, then delete it through the same controller.
        $controller = $this->makeController();
        $controller->new([
            'name'   => 'McpIssuanceTestRevokeMe',
            'scopes' => 'read',
        ]);
        $createdId = $this->response['data'][0]['id'];
        $createdPrefix = $this->response['data'][0]['token_prefix'];

        // delete() expects (field, value).
        $controller->delete('id', $createdId);

        $rows = \Kyte\Core\DBI::query(
            "SELECT * FROM `KyteActivityLog` WHERE action = 'MCP_TOKEN_REVOKE' ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotEmpty($rows);
        $row = $rows[0];

        $this->assertSame('revoked', $row['response_status']);
        $this->assertSame($createdPrefix, $row['value']);
        $this->assertSame((int)$createdId, (int)$row['record_id']);
    }

    private function createAccount(string $number, string $name): int
    {
        $obj = new \Kyte\Core\ModelObject(KyteAccount);
        $obj->create(['number' => $number, 'name' => $name]);
        return (int)$obj->id;
    }
}
