<?php
namespace Kyte\Test;

use Kyte\Core\ActivityLogger;
use Kyte\Core\Api;
use Kyte\Core\SensitivityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the ActivityLogger write-cap (KYTE-#182).
 *
 * request_data and changes are LONGTEXT. Before the cap, ActivityLogger
 * stored the FULL json-encoded request body / diff — a single page or
 * script save carries 300KB+ of HTML/JS/CSS, which is what let
 * KyteActivityLog grow to 10GB and OOM the admin log query. The cap
 * (KYTE_ACTIVITY_LOG_MAX_FIELD_BYTES, default 16KB) replaces an
 * over-limit value with a small audit-preserving marker that records the
 * original byte size and the top-level field names.
 *
 * These tests drive the real ActivityLogger::log() against the real
 * KyteActivityLog table and read the row back to assert what was stored.
 * The model name used here is NOT flagged sensitive, so the payload is
 * captured (then capped) rather than dropped — exercising exactly the cap
 * path. Sensitive-drop behavior is covered separately in
 * ActivityLoggerSensitivityTest.
 */
class ActivityLoggerWriteCapTest extends TestCase
{
    private const ACCOUNT = 'al-cap-test';
    private const MODEL   = 'AlCapTestModel';

    private Api $api;
    private int $accountId;

    protected function setUp(): void
    {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        $this->api = new Api();

        \Kyte\Core\DBI::createTable(KyteAccount);
        \Kyte\Core\DBI::createTable(Controller);
        \Kyte\Core\DBI::createTable(DataModel);
        \Kyte\Core\DBI::createTable(ModelAttribute);
        \Kyte\Core\DBI::createTable(KyteActivityLog);

        \Kyte\Core\DBI::query("DELETE FROM `KyteAccount` WHERE number = '" . self::ACCOUNT . "'");
        \Kyte\Core\DBI::query("DELETE FROM `KyteActivityLog` WHERE model_name = '" . self::MODEL . "'");

        $acct = new \Kyte\Core\ModelObject(KyteAccount);
        $acct->create(['number' => self::ACCOUNT, 'name' => 'AL Cap Test']);
        $this->accountId = (int)$acct->id;

        SensitivityPolicy::resetForTests();

        $this->api->account = new \Kyte\Core\ModelObject(KyteAccount);
        $this->api->account->retrieve('id', $this->accountId);
        ActivityLogger::getInstance()->setContext($this->api);
    }

    public function testOversizedRequestDataIsTruncatedToMarker(): void
    {
        // ~20KB of content in one field — comfortably over the 16KB cap.
        $big = str_repeat('A', 20000);

        ActivityLogger::getInstance()->log(
            'POST', self::MODEL, null, null,
            ['html' => $big, 'route' => '/some/page'],
            200, 'success'
        );

        $row = $this->latestLogRow();
        $this->assertNotNull($row, 'log row was written');

        $decoded = json_decode($row['request_data'], true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['_truncated'] ?? false, 'oversized request_data → truncation marker');
        $this->assertGreaterThan(20000, $decoded['_original_bytes'], 'marker records original byte size');
        $this->assertContains('html', $decoded['_fields'], 'marker preserves which fields were present');
        $this->assertContains('route', $decoded['_fields']);
        // The stored value itself must be small — that is the whole point.
        $this->assertLessThan(2000, strlen($row['request_data']), 'stored marker is small');
    }

    public function testWithinLimitRequestDataIsStoredVerbatim(): void
    {
        ActivityLogger::getInstance()->log(
            'POST', self::MODEL, null, null,
            ['note' => 'a small, normal payload', 'count' => 3],
            200, 'success'
        );

        $row = $this->latestLogRow();
        $this->assertNotNull($row);

        $decoded = json_decode($row['request_data'], true);
        $this->assertArrayNotHasKey('_truncated', $decoded, 'within-limit payload is not marked truncated');
        $this->assertSame('a small, normal payload', $decoded['note']);
        $this->assertSame(3, $decoded['count']);
    }

    public function testOversizedChangesDiffIsTruncatedToMarker(): void
    {
        $logger = ActivityLogger::getInstance();

        // Simulate the pre-update capture Api.php performs before a PUT.
        $reflection = new \ReflectionClass($logger);
        $prop = $reflection->getProperty('preUpdateState');
        $prop->setAccessible(true);
        $prop->setValue($logger, ['javascript' => 'old']);

        $logger->log(
            'PUT', self::MODEL, 'id', 1,
            ['javascript' => str_repeat('B', 20000)],
            200, 'success'
        );

        $row = $this->latestLogRow();
        $this->assertNotNull($row);

        $decoded = json_decode($row['changes'], true);
        $this->assertTrue($decoded['_truncated'] ?? false, 'oversized changes diff → truncation marker');
        $this->assertContains('javascript', $decoded['_fields']);
    }

    private function latestLogRow(): ?array
    {
        $rows = \Kyte\Core\DBI::query(
            "SELECT * FROM `KyteActivityLog` WHERE model_name = '" . self::MODEL . "' ORDER BY id DESC LIMIT 1"
        );
        if (!is_array($rows) || count($rows) === 0) {
            return null;
        }
        return $rows[0];
    }
}
