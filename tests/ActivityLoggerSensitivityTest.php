<?php
namespace Kyte\Test;

use Kyte\Core\ActivityLogger;
use Kyte\Core\Api;
use Kyte\Core\SensitivityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ActivityLogger consulting SensitivityPolicy.
 *
 * Matrix:
 *   - Controller-only flag (no-model controller case) → request_data null
 *   - Model flag set                                  → request_data null
 *                                                       and PUT changes diff null
 *   - Field flag set, model not flagged              → flagged field redacted,
 *                                                       other fields preserved
 *   - No flags                                        → baseline behavior preserved
 *                                                       (hardcoded SENSITIVE_FIELDS
 *                                                       still runs)
 *   - PUT changes with field flag                    → flagged field's old/new
 *                                                       both '[REDACTED]'
 *
 * Drives the real ActivityLogger::log() call against the real
 * KyteActivityLog table; reads the row back to assert what was stored.
 */
class ActivityLoggerSensitivityTest extends TestCase
{
    private const ACCOUNT = 'al-sens-test';
    private const SENS_CTRL    = 'AlSensTestVirtualCtrl';
    private const SENS_MODEL   = 'AlSensTestSensitiveModel';
    private const PLAIN_MODEL  = 'AlSensTestPlainModel';

    private Api $api;
    private int $accountId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Application);
        \Kyte\Core\DBI::createTable(Controller);
        \Kyte\Core\DBI::createTable(DataModel);
        \Kyte\Core\DBI::createTable(ModelAttribute);
        \Kyte\Core\DBI::createTable(KyteActivityLog);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `Controller` WHERE name LIKE 'AlSensTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `DataModel` WHERE name LIKE 'AlSensTest%'");
        \Kyte\Core\DBI::query("DELETE FROM `ModelAttribute` WHERE name LIKE 'al_sens_test_%'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteActivityLog` WHERE model_name LIKE 'AlSensTest%'");

        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => self::ACCOUNT, 'name' => 'AL Sens Test']);
        $this->accountId = (int)$acct->id;

        // Sensitive controller (no associated model — simulates a
        // pass-through endpoint whose body contents are regulated).
        $ctrl = new \Kyte\Core\ModelObject(Controller);
        $ctrl->create([
            'name'         => self::SENS_CTRL,
            'sensitive'    => 1,
            'kyte_account' => $this->accountId,
        ]);

        // Sensitive model.
        $sensModel = new \Kyte\Core\ModelObject(DataModel);
        $sensModel->create([
            'name'         => self::SENS_MODEL,
            'sensitive'    => 1,
            'kyte_account' => $this->accountId,
        ]);

        // Plain model with one sensitive field.
        $plainModel = new \Kyte\Core\ModelObject(DataModel);
        $plainModel->create([
            'name'         => self::PLAIN_MODEL,
            'sensitive'    => 0,
            'kyte_account' => $this->accountId,
        ]);
        $plainModelId = (int)$plainModel->id;

        $attr = new \Kyte\Core\ModelObject(ModelAttribute);
        $attr->create([
            'name'         => 'al_sens_test_secretvalue',
            'type'         => 's',
            'dataModel'    => $plainModelId,
            'sensitive'    => 1,
            'kyte_account' => $this->accountId,
        ]);

        SensitivityPolicy::resetForTests();

        // Set ActivityLogger context manually (we're not going through Api).
        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->accountId);
        ActivityLogger::getInstance()->setContext($this->api);
    }

    public function testSensitiveControllerDropsRequestBody(): void
    {
        ActivityLogger::getInstance()->log(
            'POST', self::SENS_CTRL, null, null,
            ['payload' => 'regulated text that must not be stored'],
            200, 'success'
        );

        $row = $this->latestLogRow(self::SENS_CTRL);
        $this->assertNotNull($row, 'log row was written');
        $this->assertNull($row['request_data'], 'sensitive controller → request_data dropped');
        $this->assertSame('POST', $row['action']);
        $this->assertSame(200, (int)$row['response_code']);
    }

    public function testSensitiveModelDropsRequestBody(): void
    {
        ActivityLogger::getInstance()->log(
            'POST', self::SENS_MODEL, null, null,
            ['some_field' => 'regulated value', 'note' => 'also dropped'],
            200, 'success'
        );

        $row = $this->latestLogRow(self::SENS_MODEL);
        $this->assertNotNull($row);
        $this->assertNull($row['request_data']);
    }

    public function testSensitiveFieldRedactedOnPlainModel(): void
    {
        ActivityLogger::getInstance()->log(
            'POST', self::PLAIN_MODEL, null, null,
            [
                'al_sens_test_secretvalue' => 'redact-me',
                'note'                     => 'keep this',
            ],
            200, 'success'
        );

        $row = $this->latestLogRow(self::PLAIN_MODEL);
        $this->assertNotNull($row);
        $decoded = json_decode($row['request_data'], true);
        $this->assertSame('[REDACTED]', $decoded['al_sens_test_secretvalue']);
        $this->assertSame('keep this', $decoded['note']);
    }

    public function testHardcodedRedactionStillRunsForUnflaggedModel(): void
    {
        // The hardcoded SENSITIVE_FIELDS list (password, token, etc.) must
        // still apply even when no policy flags are set. This is the
        // pre-existing baseline behavior.
        ActivityLogger::getInstance()->log(
            'POST', self::PLAIN_MODEL, null, null,
            [
                'password' => 'hunter2',
                'note'     => 'keep',
            ],
            200, 'success'
        );

        $row = $this->latestLogRow(self::PLAIN_MODEL);
        $this->assertNotNull($row);
        $decoded = json_decode($row['request_data'], true);
        $this->assertSame('[REDACTED]', $decoded['password']);
        $this->assertSame('keep', $decoded['note']);
    }

    public function testPutChangesDroppedWhenModelSensitive(): void
    {
        $logger = ActivityLogger::getInstance();

        // Simulate the pre-update capture that Api.php would have invoked.
        $reflection = new \ReflectionClass($logger);
        $prop = $reflection->getProperty('preUpdateState');
        $prop->setAccessible(true);
        $prop->setValue($logger, ['some_field' => 'old', 'other' => 'unchanged']);

        $logger->log(
            'PUT', self::SENS_MODEL, 'id', 1,
            ['some_field' => 'new'],
            200, 'success'
        );

        $row = $this->latestLogRow(self::SENS_MODEL);
        $this->assertNotNull($row);
        $this->assertNull($row['changes'], 'sensitive model → changes diff dropped');
        $this->assertNull($row['request_data']);
    }

    public function testPutChangesRedactsSensitiveFieldsOnPlainModel(): void
    {
        $logger = ActivityLogger::getInstance();

        $reflection = new \ReflectionClass($logger);
        $prop = $reflection->getProperty('preUpdateState');
        $prop->setAccessible(true);
        $prop->setValue($logger, [
            'al_sens_test_secretvalue' => 'old-secret',
            'note'                     => 'old-note',
        ]);

        $logger->log(
            'PUT', self::PLAIN_MODEL, 'id', 1,
            [
                'al_sens_test_secretvalue' => 'new-secret',
                'note'                     => 'new-note',
            ],
            200, 'success'
        );

        $row = $this->latestLogRow(self::PLAIN_MODEL);
        $this->assertNotNull($row);
        $changes = json_decode($row['changes'], true);
        $this->assertSame('[REDACTED]', $changes['al_sens_test_secretvalue']['old']);
        $this->assertSame('[REDACTED]', $changes['al_sens_test_secretvalue']['new']);
        $this->assertSame('old-note', $changes['note']['old']);
        $this->assertSame('new-note', $changes['note']['new']);
    }

    private function latestLogRow(string $modelName): ?array
    {
        $escaped = str_replace("'", "''", $modelName);
        $rows = \Kyte\Core\DBI::query(
            "SELECT * FROM `KyteActivityLog` WHERE model_name = '$escaped' ORDER BY id DESC LIMIT 1"
        );
        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }
        return $rows[0];
    }
}
