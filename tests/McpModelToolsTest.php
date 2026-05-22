<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\ModelTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModelTools (list_models / read_model).
 *
 * Same shape as McpControllerToolsTest — drives the tool methods
 * directly, covers the cross-account isolation contract, plus the
 * read_model JSON-decode behavior (well-formed JSON, missing JSON,
 * malformed JSON).
 */
class McpModelToolsTest extends TestCase
{
    private const OWN_ACCOUNT   = 'mcp-model-test-own';
    private const OTHER_ACCOUNT = 'mcp-model-test-other';

    private Api $api;
    private ModelTools $tools;
    private int $ownAccountId;
    private int $otherAccountId;
    private int $ownAppId;
    private int $otherAppId;
    private int $ownModelId;
    private int $otherModelId;
    private int $ownMalformedModelId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(DataModel);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-model-test-%'");
        \Kyte\Core\DBI::query("DELETE FROM `DataModel` WHERE name LIKE 'McpModelTest%'");

        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT, 'Own');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other');

        $this->ownAppId   = $this->createApp('mcp-model-test-own',   $this->ownAccountId);
        $this->otherAppId = $this->createApp('mcp-model-test-other', $this->otherAccountId);

        $wellFormed = json_encode([
            'name'   => 'McpModelTestOwn',
            'struct' => [
                'name' => ['type' => 's', 'size' => 255, 'required' => true],
                'qty'  => ['type' => 'i', 'size' => 11,  'required' => false],
            ],
        ]);
        $this->ownModelId = $this->createDataModel('McpModelTestOwn', $this->ownAppId, $this->ownAccountId, $wellFormed);

        $this->otherModelId = $this->createDataModel(
            'McpModelTestOther',
            $this->otherAppId,
            $this->otherAccountId,
            json_encode(['name' => 'McpModelTestOther', 'struct' => []])
        );

        // Malformed JSON variant — exercises the read_model defensive path.
        $this->ownMalformedModelId = $this->createDataModel(
            'McpModelTestOwnBroken',
            $this->ownAppId,
            $this->ownAccountId,
            '{not valid json'
        );

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->ownAccountId);
        $this->api->mcpScopes = ['read'];

        $this->tools = new ModelTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    public function testListModelsReturnsModelsForOwnApp(): void
    {
        $result = $this->tools->listModels($this->ownAppId);
        $rows = $result['models'];

        // Two own models in this app: one well-formed, one malformed.
        $this->assertCount(2, $rows);
        $names = array_column($rows, 'name');
        $this->assertContains('McpModelTestOwn', $names);
        $this->assertContains('McpModelTestOwnBroken', $names);
    }

    public function testListModelsRejectsForeignApplicationId(): void
    {
        $result = $this->tools->listModels($this->otherAppId);
        $this->assertSame(['models' => []], $result);
    }

    public function testReadModelDecodesJsonDefinition(): void
    {
        $row = $this->tools->readModel($this->ownModelId);

        $this->assertNotNull($row);
        $this->assertSame('McpModelTestOwn', $row['name']);
        $this->assertIsArray($row['definition']);
        $this->assertSame('McpModelTestOwn', $row['definition']['name']);
        $this->assertArrayHasKey('struct', $row['definition']);
        $this->assertSame('s', $row['definition']['struct']['name']['type']);
        $this->assertNull($row['raw_definition'], 'raw_definition should be null when JSON parses cleanly');
    }

    public function testReadModelPreservesRawWhenJsonInvalid(): void
    {
        $row = $this->tools->readModel($this->ownMalformedModelId);

        $this->assertNotNull($row);
        $this->assertNull($row['definition']);
        $this->assertSame('{not valid json', $row['raw_definition']);
    }

    public function testReadModelRejectsForeignModelId(): void
    {
        $row = $this->tools->readModel($this->otherModelId);
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
            'name'         => 'App ' . $identifier,
            'identifier'   => $identifier,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }

    private function createDataModel(string $name, int $appId, int $accountId, string $definition): int
    {
        $obj = new \Kyte\Core\ModelObject(DataModel);
        $obj->create([
            'name'             => $name,
            'application'      => $appId,
            'model_definition' => $definition,
            'kyte_account'     => $accountId,
        ]);
        return (int)$obj->id;
    }
}
