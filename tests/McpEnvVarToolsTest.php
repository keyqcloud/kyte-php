<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\EnvironmentVariableTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EnvironmentVariableTools: the per-app KYTE_APP_ENV surface
 * (list_app_env_vars / set_app_env_var / delete_app_env_var).
 *
 * Contract points under test: set is an upsert by key; list returns KEYS but
 * never values (secrets are write-only over MCP); every application_id is
 * re-scoped to the token's account so one account cannot touch another's vars;
 * an empty/oversized key is rejected.
 */
class McpEnvVarToolsTest extends TestCase
{
    private const OWN_ACCOUNT   = 'mcp-env-test-own';
    private const OTHER_ACCOUNT = 'mcp-env-test-other';

    private Api $api;
    private EnvironmentVariableTools $tools;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $ownAppId;
    private int $otherAppId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        foreach ([KyteAccount, Application, KyteAPIKey, KyteEnvironmentVariable] as $model) {
            \Kyte\Core\DBI::createTable($model);
        }

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-env-test-%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteEnvironmentVariable` WHERE `key` LIKE 'TEST_%'");

        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT,   'Own');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other');

        $this->ownAppId   = $this->createApp('mcp-env-test-own',   $this->ownAccountId);
        $this->otherAppId = $this->createApp('mcp-env-test-other', $this->otherAccountId);

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);
        $this->api->mcpScopes = ['read', 'provision'];

        $this->tools = new EnvironmentVariableTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'test.local'];
    }

    public function testListStartsEmpty(): void
    {
        $res = $this->tools->listAppEnvVars($this->ownAppId);
        $this->assertSame(0, $res['count']);
        $this->assertSame([], $res['variables']);
    }

    public function testSetCreatesAndListShowsKeyNotValue(): void
    {
        $res = $this->tools->setAppEnvVar($this->ownAppId, 'TEST_STRIPE_KEY', 'sk_live_supersecret');
        $this->assertTrue($res['updated'], $res['error'] ?? 'set failed');
        $this->assertSame('created', $res['action']);

        $list = $this->tools->listAppEnvVars($this->ownAppId);
        $this->assertSame(1, $list['count']);
        $this->assertSame('TEST_STRIPE_KEY', $list['variables'][0]['key']);
        $this->assertTrue($list['variables'][0]['has_value']);
        // Secret must never be echoed back.
        $this->assertArrayNotHasKey('value', $list['variables'][0]);
    }

    public function testSetIsUpsert(): void
    {
        $this->tools->setAppEnvVar($this->ownAppId, 'TEST_ENDPOINT', 'https://a.example');
        $second = $this->tools->setAppEnvVar($this->ownAppId, 'TEST_ENDPOINT', 'https://b.example');
        $this->assertSame('updated', $second['action']);

        $list = $this->tools->listAppEnvVars($this->ownAppId);
        $this->assertSame(1, $list['count'], 'upsert must not create a duplicate key');
    }

    public function testDeleteRemoves(): void
    {
        $this->tools->setAppEnvVar($this->ownAppId, 'TEST_GONE', 'x');
        $del = $this->tools->deleteAppEnvVar($this->ownAppId, 'TEST_GONE');
        $this->assertTrue($del['deleted'], $del['error'] ?? 'delete failed');
        $this->assertSame(0, $this->tools->listAppEnvVars($this->ownAppId)['count']);
    }

    public function testDeleteMissingKeyFails(): void
    {
        $del = $this->tools->deleteAppEnvVar($this->ownAppId, 'TEST_NEVER_EXISTED');
        $this->assertFalse($del['deleted']);
    }

    public function testRejectsEmptyKey(): void
    {
        $res = $this->tools->setAppEnvVar($this->ownAppId, '   ', 'x');
        $this->assertFalse($res['updated']);
    }

    public function testRejectsForeignApp(): void
    {
        $set = $this->tools->setAppEnvVar($this->otherAppId, 'TEST_X', 'y');
        $this->assertFalse($set['updated'], 'cannot set an env var on another account\'s app');

        $list = $this->tools->listAppEnvVars($this->otherAppId);
        $this->assertArrayHasKey('error', $list);
        $this->assertSame([], $list['variables']);

        $del = $this->tools->deleteAppEnvVar($this->otherAppId, 'TEST_X');
        $this->assertFalse($del['deleted']);
    }

    private function createAccount(string $number, string $name): int
    {
        $obj = new \Kyte\Core\ModelObject(KyteAccount);
        $obj->create(['number' => $number, 'name' => $name]);
        return (int)$obj->id;
    }

    private function createApp(string $identifier, int $accountId): int
    {
        $obj = new \Kyte\Core\ModelObject(Application);
        $obj->create([
            'name'         => 'App ' . $identifier,
            'identifier'   => $identifier,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }
}
