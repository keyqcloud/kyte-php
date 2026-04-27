<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\ControllerTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ControllerTools — the controller/function read tools.
 *
 * Drives the tool methods directly (skips the full MCP dispatch path,
 * which is already exercised by McpScopeTest and McpEndpointTest). The
 * specific properties under test here are:
 *   - account scoping: a tool with a token for account A must never
 *     return data belonging to account B, no matter which numeric id
 *     is passed in. Each tool re-verifies independently — these tests
 *     prove that contract holds for every code path.
 *   - read_function honors the optional version_number param.
 *
 * Two accounts are seeded in setUp — one for the token under test, one
 * for the "other" account whose data should remain invisible.
 */
class McpControllerToolsTest extends TestCase
{
    private const OWN_ACCOUNT   = 'mcp-tools-test-own';
    private const OTHER_ACCOUNT = 'mcp-tools-test-other';

    private Api $api;
    private ControllerTools $tools;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $ownAppId;
    private int $otherAppId;
    private int $ownControllerId;
    private int $otherControllerId;
    private int $ownFunctionId;
    private int $otherFunctionId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(Controller);
        \Kyte\Core\DBI::createTable(constant('Function'));
        \Kyte\Core\DBI::createTable(KyteFunctionVersion);
        \Kyte\Core\DBI::createTable(KyteFunctionVersionContent);

        // Wipe any prior test state for both accounts so reruns are stable.
        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-tools-test-%'");
        \Kyte\Core\DBI::query("DELETE FROM `Controller` WHERE name LIKE 'McpToolsTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `Function` WHERE name LIKE 'mcptt_%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteFunctionVersion` WHERE content_hash LIKE 'mcptt-%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteFunctionVersionContent` WHERE content_hash LIKE 'mcptt-%'");

        // Two accounts. Token-under-test belongs to OWN; OTHER exists only as
        // foreign data the tool must refuse to return.
        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT, 'Own Account');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other Account');

        $this->ownAppId   = $this->createApp('mcp-tools-test-own-app',   $this->ownAccountId);
        $this->otherAppId = $this->createApp('mcp-tools-test-other-app', $this->otherAccountId);

        $this->ownControllerId = $this->createController(
            'McpToolsTestOwnController',
            $this->ownAppId,
            $this->ownAccountId,
            "<?php // own controller code\n"
        );
        $this->otherControllerId = $this->createController(
            'McpToolsTestOtherController',
            $this->otherAppId,
            $this->otherAccountId,
            "<?php // other controller code\n"
        );

        $this->ownFunctionId = $this->createFunction(
            'mcptt_own_hook_init',
            $this->ownControllerId,
            $this->ownAccountId,
            'hook_init',
            "// live source for own function v2\n"
        );
        $this->otherFunctionId = $this->createFunction(
            'mcptt_other_hook_init',
            $this->otherControllerId,
            $this->otherAccountId,
            'hook_init',
            "// other account, must not be visible\n"
        );

        // Seed one historical version on the own function so read_function's
        // versioned branch has something to find.
        $this->createFunctionVersion(
            $this->ownFunctionId,
            $this->ownAccountId,
            1,
            'mcptt-own-v1',
            "// historical own function v1\n"
        );

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);
        $this->api->mcpScopes = ['read'];

        $this->tools = new ControllerTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    public function testListControllersReturnsControllersForOwnApp(): void
    {
        $result = $this->tools->listControllers($this->ownAppId);
        $rows = $result['controllers'];

        $this->assertCount(1, $rows);
        $this->assertSame($this->ownControllerId, $rows[0]['id']);
        $this->assertSame('McpToolsTestOwnController', $rows[0]['name']);
        $this->assertFalse($rows[0]['kyte_locked']);
    }

    public function testListControllersRejectsForeignApplicationId(): void
    {
        $result = $this->tools->listControllers($this->otherAppId);
        $this->assertSame(['controllers' => []], $result, 'list_controllers must not return controllers for an application owned by another account');
    }

    public function testReadControllerReturnsFullRecordWithCode(): void
    {
        $row = $this->tools->readController($this->ownControllerId);

        $this->assertNotNull($row);
        $this->assertSame($this->ownControllerId, $row['id']);
        $this->assertSame('McpToolsTestOwnController', $row['name']);
        $this->assertStringContainsString('own controller code', $row['code']);
        $this->assertSame($this->ownAppId, $row['application']);
    }

    public function testReadControllerRejectsForeignControllerId(): void
    {
        $row = $this->tools->readController($this->otherControllerId);
        $this->assertNull($row, 'read_controller must not return a controller belonging to another account');
    }

    public function testListFunctionsReturnsFunctionsForOwnController(): void
    {
        $result = $this->tools->listFunctions($this->ownControllerId);
        $rows = $result['functions'];

        $this->assertCount(1, $rows);
        $this->assertSame($this->ownFunctionId, $rows[0]['id']);
        $this->assertSame('hook_init', $rows[0]['type']);
    }

    public function testListFunctionsRejectsForeignControllerId(): void
    {
        $result = $this->tools->listFunctions($this->otherControllerId);
        $this->assertSame(['functions' => []], $result);
    }

    public function testReadFunctionWithoutVersionReturnsLiveSource(): void
    {
        $row = $this->tools->readFunction($this->ownFunctionId);

        $this->assertNotNull($row);
        $this->assertStringContainsString('live source for own function v2', $row['code']);
        $this->assertNull($row['version']);
    }

    public function testReadFunctionWithVersionReturnsHistoricalSnapshot(): void
    {
        $row = $this->tools->readFunction($this->ownFunctionId, 1);

        $this->assertNotNull($row);
        $this->assertSame(1, $row['version']);
        $this->assertStringContainsString('historical own function v1', $row['code']);
    }

    public function testReadFunctionWithUnknownVersionReturnsNull(): void
    {
        // Version 999 was never created.
        $row = $this->tools->readFunction($this->ownFunctionId, 999);
        $this->assertNull($row);
    }

    public function testReadFunctionRejectsForeignFunctionId(): void
    {
        $row = $this->tools->readFunction($this->otherFunctionId);
        $this->assertNull($row);
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
            'name'         => 'App for ' . $identifier,
            'identifier'   => $identifier,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createController(string $name, int $appId, int $accountId, string $code): int
    {
        $obj = new \Kyte\Core\ModelObject(Controller);
        $obj->create([
            'name'         => $name,
            'application'  => $appId,
            'code'         => $code,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createFunction(string $name, int $controllerId, int $accountId, string $type, string $code): int
    {
        $obj = new \Kyte\Core\ModelObject(constant('Function'));
        $obj->create([
            'name'         => $name,
            'controller'   => $controllerId,
            'type'         => $type,
            'code'         => $code,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createFunctionVersion(int $functionId, int $accountId, int $versionNumber, string $contentHash, string $code): void
    {
        $content = new \Kyte\Core\ModelObject(KyteFunctionVersionContent);
        $content->create([
            'content_hash'    => $contentHash,
            'code'            => $code,
            'reference_count' => 1,
            'last_referenced' => time(),
            'kyte_account'    => $accountId,
        ]);

        $version = new \Kyte\Core\ModelObject(KyteFunctionVersion);
        $version->create([
            'function'       => $functionId,
            'version_number' => $versionNumber,
            'version_type'   => 'manual_save',
            'content_hash'   => $contentHash,
            'kyte_account'   => $accountId,
        ]);
    }
}
