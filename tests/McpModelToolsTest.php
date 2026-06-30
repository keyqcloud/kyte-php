<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Mcp\Tools\ModelTools;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModelTools.
 *
 * Two layers:
 *   1. Read tools (list_models / read_model) — metadata + JSON-decode + the
 *      cross-account isolation contract. These drive rows created directly.
 *   2. Write tools (create_model / add_attribute / update_attribute /
 *      rename_model / remove_attribute / delete_model) — the `schema`-scope
 *      migration tools. Unlike SiteTools (whose AWS work is deferred to a
 *      worker), the model controllers run REAL DDL synchronously against the
 *      application's own database. Api::dbappconnect refuses an empty password,
 *      so these tests point the application at the test database via a
 *      dedicated password-bearing MySQL user; CREATE/ALTER/DROP TABLE then run
 *      against the test schema and are cleaned up here.
 *
 * The security property under test for the write tools is account isolation:
 * every caller-supplied model/attribute id is re-scoped to the token's account,
 * and the destructive ops (remove_attribute / delete_model) refuse without
 * confirm_destructive.
 */
class McpModelToolsTest extends TestCase
{
    private const OWN_ACCOUNT   = 'mcp-model-test-own';
    private const OTHER_ACCOUNT = 'mcp-model-test-other';

    /** Dedicated app-DB user so dbappconnect (which rejects empty passwords) can
     *  reach the test schema for the synchronous DDL the controllers run. */
    private const DDL_USER = 'kyte_mcp_mt';
    private const DDL_PASS = 'McpModelTest_123';

    /** Physical tables the write tests create via DDL — dropped on each setUp. */
    private const TEST_TABLES = [
        'McpModelTestNew', 'McpModelTestScoped', 'McpModelTestAttr',
        'McpModelTestDec', 'McpModelTestUpd', 'McpModelTestRen',
        'McpModelTestRenamed', 'McpModelTestDrop', 'McpModelTestFk',
        'McpModelTestFkTarget',
    ];

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
        // Always start on the main connection — a prior test whose DDL threw
        // before dbswitch()-ing back could otherwise leave us pointed at the
        // app DB, and the cleanup below (incl. CREATE USER) needs root/main.
        \Kyte\Core\DBI::$useAppDB = false;

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(DataModel);
        \Kyte\Core\DBI::createTable(ModelAttribute);

        // App-DB user for the DDL path (idempotent across runs).
        \Kyte\Core\DBI::query("CREATE USER IF NOT EXISTS '" . self::DDL_USER . "'@'%' IDENTIFIED BY '" . self::DDL_PASS . "'");
        \Kyte\Core\DBI::query("GRANT ALL PRIVILEGES ON `" . KYTE_DB_DATABASE . "`.* TO '" . self::DDL_USER . "'@'%'");
        \Kyte\Core\DBI::query("FLUSH PRIVILEGES");

        // Drop any physical tables left by a prior run before recreating rows.
        foreach (self::TEST_TABLES as $tbl) {
            \Kyte\Core\DBI::query("DROP TABLE IF EXISTS `$tbl`");
        }

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number IN ('" . self::OWN_ACCOUNT . "','" . self::OTHER_ACCOUNT . "')");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier LIKE 'mcp-model-test-%'");
        \Kyte\Core\DBI::query("DELETE FROM `DataModel` WHERE name LIKE 'McpModelTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `ModelAttribute` WHERE name LIKE 'mcpattr%'");

        $this->ownAccountId   = $this->createAccount(self::OWN_ACCOUNT, 'Own');
        $this->otherAccountId = $this->createAccount(self::OTHER_ACCOUNT, 'Other');

        // Own app is DDL-capable (points at the test DB via the DDL user) so the
        // write tools' migrations run. The other app needs no creds — the
        // foreign-account paths are rejected before any DDL.
        $this->ownAppId   = $this->createApp('mcp-model-test-own', $this->ownAccountId, true);
        $this->otherAppId = $this->createApp('mcp-model-test-other', $this->otherAccountId, false);

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
        $this->api->mcpScopes = ['read', 'schema'];

        $this->tools = new ModelTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    protected function tearDown(): void
    {
        // Reset to main and drop the physical tables the write tests created.
        \Kyte\Core\DBI::$useAppDB = false;
        foreach (self::TEST_TABLES as $tbl) {
            try {
                \Kyte\Core\DBI::query("DROP TABLE IF EXISTS `$tbl`");
            } catch (\Throwable $e) {
                // best-effort cleanup
            }
        }
    }

    // ---- read tools -------------------------------------------------------

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

    // ---- write tools: create_model ---------------------------------------

    public function testCreateModelCreatesTableAndRow(): void
    {
        $result = $this->tools->createModel($this->ownAppId, 'McpModelTestNew');

        $this->assertTrue($result['created'], $result['error'] ?? 'create failed');
        $this->assertSame('McpModelTestNew', $result['model']['name']);
        $this->assertSame($this->ownAppId, $result['model']['application']);
        // Physical table exists with the standard primary key.
        $this->assertSame('int', strtolower(substr((string)$this->appColumnType('McpModelTestNew', 'id'), 0, 3)));
    }

    public function testCreateModelScopesToCallerAccount(): void
    {
        $result = $this->tools->createModel($this->ownAppId, 'McpModelTestScoped');
        $this->assertTrue($result['created'], $result['error'] ?? 'create failed');

        $dm = new \Kyte\Core\ModelObject(DataModel);
        $dm->retrieve('id', (int)$result['model']['id']);
        $this->assertSame($this->ownAccountId, (int)$dm->kyte_account, 'new model must be stamped with the caller account');
    }

    public function testCreateModelRejectsForeignApplication(): void
    {
        $result = $this->tools->createModel($this->otherAppId, 'McpModelTestEvil');
        $this->assertFalse($result['created'], 'cannot create a model under another account\'s application');
    }

    // ---- write tools: add_attribute --------------------------------------

    public function testAddAttributeAddsColumn(): void
    {
        $modelId = $this->createModelReturningId('McpModelTestAttr');

        $result = $this->tools->addAttribute($modelId, 'mcpattrTitle', 's', size: 255, required: true);
        $this->assertTrue($result['added'], $result['error'] ?? 'add failed');
        $this->assertSame('mcpattrTitle', $result['attribute']['name']);

        // Field shows up in the regenerated model definition...
        $row = $this->tools->readModel($modelId);
        $this->assertArrayHasKey('mcpattrTitle', $row['definition']['struct']);
        // ...and as a real column.
        $this->assertStringStartsWith('varchar', strtolower((string)$this->appColumnType('McpModelTestAttr', 'mcpattrTitle')));
    }

    public function testAddAttributeDecimalCarriesPrecisionScale(): void
    {
        $modelId = $this->createModelReturningId('McpModelTestDec');

        $result = $this->tools->addAttribute($modelId, 'mcpattrPrice', 'd', precision: 10, scale: 2);
        $this->assertTrue($result['added'], $result['error'] ?? 'add failed');
        $this->assertSame(10, $result['attribute']['precision']);
        $this->assertSame(2, $result['attribute']['scale']);
        // The decimal precision/scale reach the physical column definition.
        $this->assertSame('decimal(10,2)', strtolower((string)$this->appColumnType('McpModelTestDec', 'mcpattrPrice')));
    }

    public function testAddAttributeDecimalWithoutPrecisionIsRejected(): void
    {
        $modelId = $this->createModelReturningId('McpModelTestDec');

        $result = $this->tools->addAttribute($modelId, 'mcpattrBad', 'd');
        $this->assertFalse($result['added'], 'a decimal column without precision/scale must be refused');
    }

    public function testAddAttributeRejectsForeignModel(): void
    {
        $result = $this->tools->addAttribute($this->otherModelId, 'mcpattrEvil', 's', size: 50);
        $this->assertFalse($result['added'], 'cannot add a column to a foreign model');
    }

    // ---- write tools: update_attribute -----------------------------------

    public function testUpdateAttributeChangesColumn(): void
    {
        $modelId = $this->createModelReturningId('McpModelTestUpd');
        $add = $this->tools->addAttribute($modelId, 'mcpattrOld', 's', size: 100);
        $attrId = (int)$add['attribute']['id'];

        $result = $this->tools->updateAttribute($attrId, 'mcpattrNew', 'i', unsigned: true);
        $this->assertTrue($result['updated'], $result['error'] ?? 'update failed');
        $this->assertSame('mcpattrNew', $result['attribute']['name']);
        $this->assertSame('i', $result['attribute']['type']);

        $type = strtolower((string)$this->appColumnType('McpModelTestUpd', 'mcpattrNew'));
        $this->assertStringStartsWith('int', $type);
        $this->assertStringContainsString('unsigned', $type);
    }

    public function testUpdateAttributeRejectsForeignAttribute(): void
    {
        // An attribute owned by the other account.
        $foreignAttrId = $this->createModelAttribute('mcpattrForeign', $this->otherModelId, $this->otherAccountId);
        $result = $this->tools->updateAttribute($foreignAttrId, 'mcpattrHijack', 's', size: 10);
        $this->assertFalse($result['updated'], 'cannot update a foreign attribute');
    }

    // ---- write tools: rename_model ---------------------------------------

    public function testRenameModelRenamesTable(): void
    {
        $modelId = $this->createModelReturningId('McpModelTestRen');

        $result = $this->tools->renameModel($modelId, 'McpModelTestRenamed');
        $this->assertTrue($result['renamed'], $result['error'] ?? 'rename failed');
        $this->assertSame('McpModelTestRenamed', $result['model']['name']);

        // New table exists, old one gone.
        $this->assertNotNull($this->appColumnType('McpModelTestRenamed', 'id'));
        $this->assertNull($this->appColumnType('McpModelTestRen', 'id'));
    }

    public function testRenameModelRejectsForeignModel(): void
    {
        $result = $this->tools->renameModel($this->otherModelId, 'McpModelTestRenamed');
        $this->assertFalse($result['renamed'], 'cannot rename a foreign model');
    }

    // ---- write tools: remove_attribute (destructive) ---------------------

    public function testRemoveAttributeRequiresConfirmation(): void
    {
        $modelId = $this->createModelReturningId('McpModelTestAttr');
        $add = $this->tools->addAttribute($modelId, 'mcpattrTemp', 's', size: 20);
        $attrId = (int)$add['attribute']['id'];

        $refused = $this->tools->removeAttribute($attrId);
        $this->assertFalse($refused['removed'], 'destructive op must be refused without confirm_destructive');
        // Column still present.
        $this->assertNotNull($this->appColumnType('McpModelTestAttr', 'mcpattrTemp'));

        $ok = $this->tools->removeAttribute($attrId, true);
        $this->assertTrue($ok['removed'], $ok['error'] ?? 'remove failed');
        $this->assertNull($this->appColumnType('McpModelTestAttr', 'mcpattrTemp'), 'column should be dropped');
    }

    public function testRemoveAttributeRejectsForeignAttribute(): void
    {
        $foreignAttrId = $this->createModelAttribute('mcpattrForeign2', $this->otherModelId, $this->otherAccountId);
        $result = $this->tools->removeAttribute($foreignAttrId, true);
        $this->assertFalse($result['removed'], 'cannot remove a foreign attribute');
    }

    // ---- write tools: delete_model (destructive) -------------------------

    public function testDeleteModelRequiresConfirmation(): void
    {
        $modelId = $this->createModelReturningId('McpModelTestDrop');

        $refused = $this->tools->deleteModel($modelId);
        $this->assertFalse($refused['deleted'], 'destructive op must be refused without confirm_destructive');
        $this->assertNotNull($this->appColumnType('McpModelTestDrop', 'id'), 'table must still exist after a refused delete');

        $ok = $this->tools->deleteModel($modelId, true);
        $this->assertTrue($ok['deleted'], $ok['error'] ?? 'delete failed');
        $this->assertNull($this->appColumnType('McpModelTestDrop', 'id'), 'table should be dropped');
    }

    public function testDeleteModelRejectsForeignModel(): void
    {
        $result = $this->tools->deleteModel($this->otherModelId, true);
        $this->assertFalse($result['deleted'], 'cannot delete a foreign model');
    }

    public function testDeleteModelBlockedByForeignKeyDependency(): void
    {
        $targetId = $this->createModelReturningId('McpModelTestFkTarget');
        $sourceId = $this->createModelReturningId('McpModelTestFk');
        // Source.ref -> target (FK). Dropping the target must now be refused.
        $fk = $this->tools->addAttribute($sourceId, 'mcpattrRef', 'i', foreign_key_model: $targetId);
        $this->assertTrue($fk['added'], $fk['error'] ?? 'fk add failed');

        $result = $this->tools->deleteModel($targetId, true);
        $this->assertFalse($result['deleted'], 'a model referenced by another model\'s FK must not be droppable');
        // The FK guard fires before any DROP — target still present.
        $this->assertNotNull($this->appColumnType('McpModelTestFkTarget', 'id'));
    }

    // ---- helpers ----------------------------------------------------------

    private function createModelReturningId(string $name): int
    {
        $result = $this->tools->createModel($this->ownAppId, $name);
        $this->assertTrue($result['created'], $result['error'] ?? "failed to create $name");
        return (int)$result['model']['id'];
    }

    /**
     * SHOW COLUMNS for a column on the app (test) DB; null when the column or
     * table is absent. Returns the column Type string (e.g. "decimal(10,2)").
     */
    private function appColumnType(string $table, string $column): ?string
    {
        \Kyte\Core\Api::dbappconnect(KYTE_DB_DATABASE, self::DDL_USER, self::DDL_PASS, KYTE_DB_HOST);
        \Kyte\Core\Api::dbswitch(true);
        try {
            $rows = \Kyte\Core\DBI::query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        } catch (\Throwable $e) {
            $rows = [];
        } finally {
            \Kyte\Core\Api::dbswitch();
        }
        return $rows[0]['Type'] ?? null;
    }

    private function createAccount(string $number, string $name): int
    {
        $obj = new \Kyte\Core\ModelObject(KyteAccount);
        $obj->create(['number' => $number, 'name' => $name]);
        return (int)$obj->id;
    }

    private function createApp(string $identifier, int $accountId, bool $withDb): int
    {
        $data = [
            'name'         => 'App ' . $identifier,
            'identifier'   => $identifier,
            'kyte_account' => $accountId,
        ];
        if ($withDb) {
            $data['db_name']     = KYTE_DB_DATABASE;
            $data['db_username'] = self::DDL_USER;
            $data['db_password'] = self::DDL_PASS;
            $data['db_host']     = KYTE_DB_HOST;
        }
        $obj = new \Kyte\Core\ModelObject(Application);
        $obj->create($data);
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

    private function createModelAttribute(string $name, int $modelId, int $accountId): int
    {
        $obj = new \Kyte\Core\ModelObject(ModelAttribute);
        $obj->create([
            'name'         => $name,
            'type'         => 's',
            'size'         => 50,
            'dataModel'    => $modelId,
            'kyte_account' => $accountId,
        ]);
        return (int)$obj->id;
    }
}
