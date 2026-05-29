<?php
namespace Kyte\Test;

use Kyte\Core\DBI;
use Kyte\Core\ModelObject;
use Kyte\Mcp\Session\DbSessionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV4;

/**
 * Exercises the DB-backed MCP session store against a live database (the same
 * MariaDB the rest of the DB suite uses). Verifies the SessionStoreInterface
 * contract, TTL expiry, per-account isolation, and gc().
 *
 * Mirrors ModelTest's harness: constructing Api defines the model constants
 * (KyteMCPSession among them) and the typing constants ModelObject reads, then
 * DBI::createTable builds the table from that definition.
 */
class DbSessionStoreTest extends TestCase
{
    private const ACCOUNT_A = 1;
    private const ACCOUNT_B = 2;

    protected function setUp(): void
    {
        new \Kyte\Core\Api();
        DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');

        // Clean slate so repeat runs against a persistent container don't
        // accumulate rows that skew the gc / isolation assertions.
        DBI::query('DROP TABLE IF EXISTS `KyteMCPSession`');
        $this->assertTrue(DBI::createTable(KyteMCPSession));
    }

    public function testWriteThenReadRoundTrips(): void
    {
        $store = new DbSessionStore(self::ACCOUNT_A);
        $id = new UuidV4();
        $payload = json_encode(['initialized' => true, 'protocol_version' => '2025-06-18']);

        $this->assertTrue($store->write($id, $payload));
        $this->assertTrue($store->exists($id));
        $this->assertSame($payload, $store->read($id));
    }

    public function testMissReturnsFalse(): void
    {
        $store = new DbSessionStore(self::ACCOUNT_A);
        $id = new UuidV4();

        $this->assertFalse($store->exists($id));
        $this->assertFalse($store->read($id));
    }

    public function testWriteIsUpsertNotDuplicate(): void
    {
        $store = new DbSessionStore(self::ACCOUNT_A);
        $id = new UuidV4();

        $this->assertTrue($store->write($id, json_encode(['v' => 1])));
        $this->assertTrue($store->write($id, json_encode(['v' => 2])));

        // Latest payload wins...
        $this->assertSame(json_encode(['v' => 2]), $store->read($id));

        // ...and there is exactly one row for that session_id.
        $rows = DBI::query("SELECT COUNT(*) AS c FROM `KyteMCPSession` WHERE `session_id` = '" . $id->toRfc4122() . "'");
        $this->assertSame(1, (int)$rows[0]['c']);
    }

    public function testDestroyRemovesSession(): void
    {
        $store = new DbSessionStore(self::ACCOUNT_A);
        $id = new UuidV4();
        $store->write($id, json_encode(['initialized' => true]));

        $this->assertTrue($store->destroy($id));
        $this->assertFalse($store->exists($id));
        $this->assertFalse($store->read($id));
    }

    public function testSessionsAreAccountScoped(): void
    {
        $storeA = new DbSessionStore(self::ACCOUNT_A);
        $storeB = new DbSessionStore(self::ACCOUNT_B);
        $id = new UuidV4();

        $storeA->write($id, json_encode(['owner' => 'A']));

        // Same UUID, different account → invisible.
        $this->assertTrue($storeA->exists($id));
        $this->assertFalse($storeB->exists($id));
        $this->assertFalse($storeB->read($id));
    }

    public function testExpiredSessionIsAMiss(): void
    {
        $store = new DbSessionStore(self::ACCOUNT_A, 3600);
        $id = new UuidV4();
        $store->write($id, json_encode(['initialized' => true]));

        // Backdate last activity beyond the TTL.
        $this->backdate($id, 7200);

        $this->assertFalse($store->exists($id));
        // read() reports the miss and purges the stale row.
        $this->assertFalse($store->read($id));
        $rows = DBI::query("SELECT COUNT(*) AS c FROM `KyteMCPSession` WHERE `session_id` = '" . $id->toRfc4122() . "'");
        $this->assertSame(0, (int)$rows[0]['c']);
    }

    public function testGcPurgesExpiredAndReturnsIds(): void
    {
        $store = new DbSessionStore(self::ACCOUNT_A, 3600);

        $fresh = new UuidV4();
        $stale = new UuidV4();
        $store->write($fresh, json_encode(['k' => 'fresh']));
        $store->write($stale, json_encode(['k' => 'stale']));
        $this->backdate($stale, 7200);

        $deleted = $store->gc();

        $deletedStrings = array_map(static fn ($u) => $u->toRfc4122(), $deleted);
        $this->assertContains($stale->toRfc4122(), $deletedStrings);
        $this->assertNotContains($fresh->toRfc4122(), $deletedStrings);

        // Fresh session survives; stale is gone.
        $this->assertTrue($store->exists($fresh));
        $this->assertFalse($store->exists($stale));
    }

    /**
     * Push a session's last_activity into the past by $seconds via a direct
     * UPDATE — simulating an idle session without sleeping the test.
     */
    private function backdate(UuidV4 $id, int $seconds): void
    {
        $past = time() - $seconds;
        DBI::query("UPDATE `KyteMCPSession` SET `last_activity` = $past WHERE `session_id` = '" . $id->toRfc4122() . "'");
    }
}
