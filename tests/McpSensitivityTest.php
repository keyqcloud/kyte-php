<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Core\SensitivityPolicy;
use Kyte\Mcp\Tools\ControllerTools;
use Kyte\Mcp\Tools\ModelTools;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for MCP read tools consulting SensitivityPolicy.
 *
 * Matrix:
 *   list_controllers   → 'sensitive' field present on each row
 *   read_controller    → code withheld + sensitive:true when flagged
 *   read_controller    → code intact when not flagged
 *   read_function      → code withheld when parent controller flagged
 *   read_function      → code intact when parent controller not flagged
 *   list_models        → 'sensitive' field present on each row
 *   read_model         → definition withheld + sensitive:true when model flagged
 *   read_model         → field-level: flagged fields stripped from struct,
 *                        listed in sensitive_fields
 *   read_model         → no flags: existing behavior unchanged plus
 *                        sensitive:false / sensitive_fields:[] additions
 */
class McpSensitivityTest extends TestCase
{
    private const ACCOUNT = 'mcp-sens-test';

    private Api $api;
    private ControllerTools $controllerTools;
    private ModelTools $modelTools;
    private int $accountId;
    private int $appId;
    private int $sensCtrlId;
    private int $plainCtrlId;
    private int $sensFnId;
    private int $plainFnId;
    private int $sensModelId;
    private int $plainModelWithFieldsId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(Controller);
        \Kyte\Core\DBI::createTable(DataModel);
        \Kyte\Core\DBI::createTable(ModelAttribute);
        \Kyte\Core\DBI::createTable(constant('Function'));

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `Application` WHERE identifier = 'mcp-sens-test-app'");
        \Kyte\Core\DBI::query("DELETE FROM `Controller` WHERE name LIKE 'McpSensTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `DataModel` WHERE name LIKE 'McpSensTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `ModelAttribute` WHERE name LIKE 'mcp_sens_test_%'");
        \Kyte\Core\DBI::query("DELETE FROM `Function` WHERE name LIKE 'McpSensTestFn%'");

        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => self::ACCOUNT, 'name' => 'MCP Sens Test']);
        $this->accountId = (int)$acct->id;

        $app = new \Kyte\Core\ModelObject(Application);
        $app->create([
            'name'         => 'McpSensTestApp',
            'identifier'   => 'mcp-sens-test-app',
            'kyte_account' => $this->accountId,
        ]);
        $this->appId = (int)$app->id;

        // Sensitive controller with source code.
        $sensCtrl = new \Kyte\Core\ModelObject(Controller);
        $sensCtrl->create([
            'name'         => 'McpSensTestSensitiveCtrl',
            'code'         => 'public function passThrough() { /* regulated logic */ }',
            'sensitive'    => 1,
            'application'  => $this->appId,
            'kyte_account' => $this->accountId,
        ]);
        $this->sensCtrlId = (int)$sensCtrl->id;

        // Plain controller with source code.
        $plainCtrl = new \Kyte\Core\ModelObject(Controller);
        $plainCtrl->create([
            'name'         => 'McpSensTestPlainCtrl',
            'code'         => 'public function helper() { return "ok"; }',
            'sensitive'    => 0,
            'application'  => $this->appId,
            'kyte_account' => $this->accountId,
        ]);
        $this->plainCtrlId = (int)$plainCtrl->id;

        // Functions attached to each controller.
        $sensFn = new \Kyte\Core\ModelObject(constant('Function'));
        $sensFn->create([
            'name'         => 'McpSensTestFnSensitive',
            'type'         => 'custom',
            'code'         => 'function logic for sensitive ctrl',
            'controller'   => $this->sensCtrlId,
            'kyte_account' => $this->accountId,
        ]);
        $this->sensFnId = (int)$sensFn->id;

        $plainFn = new \Kyte\Core\ModelObject(constant('Function'));
        $plainFn->create([
            'name'         => 'McpSensTestFnPlain',
            'type'         => 'custom',
            'code'         => 'function logic for plain ctrl',
            'controller'   => $this->plainCtrlId,
            'kyte_account' => $this->accountId,
        ]);
        $this->plainFnId = (int)$plainFn->id;

        // Sensitive model.
        $sensModel = new \Kyte\Core\ModelObject(DataModel);
        $sensModel->create([
            'name'             => 'McpSensTestSensitiveModel',
            'model_definition' => json_encode([
                'name' => 'McpSensTestSensitiveModel',
                'struct' => [
                    'field_one' => ['type' => 's', 'size' => 255],
                ],
            ]),
            'application'  => $this->appId,
            'sensitive'    => 1,
            'kyte_account' => $this->accountId,
        ]);
        $this->sensModelId = (int)$sensModel->id;

        // Plain model with one sensitive field and one plain field.
        $plainModel = new \Kyte\Core\ModelObject(DataModel);
        $plainModel->create([
            'name'             => 'McpSensTestPlainModelWithFields',
            'model_definition' => json_encode([
                'name' => 'McpSensTestPlainModelWithFields',
                'struct' => [
                    'mcp_sens_test_secret' => ['type' => 's', 'size' => 255],
                    'mcp_sens_test_locale' => ['type' => 's', 'size' => 32],
                ],
            ]),
            'application'  => $this->appId,
            'sensitive'    => 0,
            'kyte_account' => $this->accountId,
        ]);
        $this->plainModelWithFieldsId = (int)$plainModel->id;

        // Attribute rows that mark mcp_sens_test_secret as field-sensitive.
        $attr1 = new \Kyte\Core\ModelObject(ModelAttribute);
        $attr1->create([
            'name'         => 'mcp_sens_test_secret',
            'type'         => 's',
            'dataModel'    => $this->plainModelWithFieldsId,
            'sensitive'    => 1,
            'kyte_account' => $this->accountId,
        ]);
        $attr2 = new \Kyte\Core\ModelObject(ModelAttribute);
        $attr2->create([
            'name'         => 'mcp_sens_test_locale',
            'type'         => 's',
            'dataModel'    => $this->plainModelWithFieldsId,
            'sensitive'    => 0,
            'kyte_account' => $this->accountId,
        ]);

        SensitivityPolicy::resetForTests();

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->accountId);
        $this->api->mcpScopes = ['read'];

        $this->controllerTools = new ControllerTools($this->api);
        $this->modelTools = new ModelTools($this->api);

        $_SERVER = ['REMOTE_ADDR' => '127.0.0.1'];
    }

    public function testListControllersIncludesSensitiveFlag(): void
    {
        $result = $this->controllerTools->listControllers($this->appId);
        $rows = $result['controllers'];

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']] = $r;
        }
        $this->assertArrayHasKey('McpSensTestSensitiveCtrl', $byName);
        $this->assertArrayHasKey('McpSensTestPlainCtrl', $byName);
        $this->assertTrue($byName['McpSensTestSensitiveCtrl']['sensitive']);
        $this->assertFalse($byName['McpSensTestPlainCtrl']['sensitive']);
    }

    public function testReadControllerWithholdsCodeWhenSensitive(): void
    {
        $row = $this->controllerTools->readController($this->sensCtrlId);
        $this->assertNotNull($row);
        $this->assertTrue($row['sensitive']);
        $this->assertNull($row['code'], 'sensitive controller → code withheld');
        $this->assertSame('McpSensTestSensitiveCtrl', $row['name']);
        $this->assertSame($this->appId, $row['application'], 'metadata preserved');
    }

    public function testReadControllerReturnsCodeWhenNotSensitive(): void
    {
        $row = $this->controllerTools->readController($this->plainCtrlId);
        $this->assertNotNull($row);
        $this->assertFalse($row['sensitive']);
        $this->assertSame('public function helper() { return "ok"; }', $row['code']);
    }

    public function testReadFunctionWithholdsCodeWhenParentControllerSensitive(): void
    {
        $row = $this->controllerTools->readFunction($this->sensFnId);
        $this->assertNotNull($row);
        $this->assertTrue($row['sensitive']);
        $this->assertNull($row['code']);
        $this->assertSame('McpSensTestFnSensitive', $row['name']);
    }

    public function testReadFunctionReturnsCodeWhenParentControllerNotSensitive(): void
    {
        $row = $this->controllerTools->readFunction($this->plainFnId);
        $this->assertNotNull($row);
        $this->assertFalse($row['sensitive']);
        $this->assertSame('function logic for plain ctrl', $row['code']);
    }

    public function testListModelsIncludesSensitiveFlag(): void
    {
        $result = $this->modelTools->listModels($this->appId);
        $rows = $result['models'];

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']] = $r;
        }
        $this->assertArrayHasKey('McpSensTestSensitiveModel', $byName);
        $this->assertArrayHasKey('McpSensTestPlainModelWithFields', $byName);
        $this->assertTrue($byName['McpSensTestSensitiveModel']['sensitive']);
        $this->assertFalse($byName['McpSensTestPlainModelWithFields']['sensitive']);
    }

    public function testReadModelWithholdsDefinitionWhenSensitive(): void
    {
        $row = $this->modelTools->readModel($this->sensModelId);
        $this->assertNotNull($row);
        $this->assertTrue($row['sensitive']);
        $this->assertNull($row['definition']);
        $this->assertNull($row['raw_definition']);
        $this->assertSame('McpSensTestSensitiveModel', $row['name']);
    }

    public function testReadModelStripsSensitiveFieldsFromStruct(): void
    {
        $row = $this->modelTools->readModel($this->plainModelWithFieldsId);
        $this->assertNotNull($row);
        $this->assertFalse($row['sensitive']);
        $this->assertContains('mcp_sens_test_secret', $row['sensitive_fields']);
        $this->assertNotContains('mcp_sens_test_locale', $row['sensitive_fields']);

        $struct = $row['definition']['struct'] ?? [];
        $this->assertArrayNotHasKey('mcp_sens_test_secret', $struct,
            'field-sensitive attribute stripped from returned struct');
        $this->assertArrayHasKey('mcp_sens_test_locale', $struct,
            'non-sensitive field still present');
    }
}
