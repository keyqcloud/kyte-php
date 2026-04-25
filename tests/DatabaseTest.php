<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    protected function setUp(): void {
        \Kyte\Core\DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');
        \Kyte\Core\DBI::query("DROP USER IF EXISTS 'TestUser1'@'%'");
        \Kyte\Core\DBI::query("DROP USER IF EXISTS 'TestUser2'@'%'");
        \Kyte\Core\DBI::query("DROP DATABASE IF EXISTS `TestDatabase1`");
        \Kyte\Core\DBI::query("DROP DATABASE IF EXISTS `TestDatabase2`");
        \Kyte\Core\DBI::query("FLUSH PRIVILEGES");
    }

    public function testDatabaseCreation() {
        $password = null;
        $this->assertTrue(\Kyte\Core\DBI::createDatabase("TestDatabase1", "TestUser1", $password));
    }

    public function testDatabaseCreationAndSwitch() {
        $password = null;
        $this->assertTrue(\Kyte\Core\DBI::createDatabase("TestDatabase2", "TestUser2", $password, true));
    }

    /**
     * Regression test: createTable must accept fields declared with
     * 'default' => null and emit DEFAULT NULL in the generated SQL.
     *
     * Before this was fixed, buildFieldDefinition's default-value branch fell
     * through to a fall-through concat that coerced null to '', producing
     * broken SQL like `language varchar(5) DEFAULT  NOT NULL,`. The Application
     * model's `language` field has 'default' => null, so any fresh createTable
     * of it (e.g. during install) blew up. gust/lib/Database.php still has an
     * identical copy of this bug and should be patched when gust is next
     * touched (see design doc section 11, gust evaluation).
     */
    public function testCreateTableAcceptsNullDefault() {
        // The preceding createDatabase tests leave the mysqli connection
        // without an active database context. Re-select kytedev explicitly.
        // dbInit won't help here because connect() early-returns once a
        // connection object already exists.
        \Kyte\Core\DBI::query('USE `' . KYTE_DB_DATABASE . '`');

        $tblName = 'NullDefaultProbe_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $modelDef = [
            'name' => $tblName,
            'struct' => [
                'id' => [
                    'type'     => 'i',
                    'required' => true,
                    'pk'       => true,
                    'size'     => 11,
                    'date'     => false,
                ],
                'optional_label' => [
                    'type'     => 's',
                    'required' => false,
                    'size'     => 64,
                    'date'     => false,
                    'default'  => null,
                ],
            ],
        ];

        try {
            $this->assertTrue(\Kyte\Core\DBI::createTable($modelDef));

            $rows = \Kyte\Core\DBI::query("SHOW CREATE TABLE `$tblName`");
            $this->assertNotEmpty($rows);
            $createSql = $rows[0]['Create Table'] ?? '';
            $this->assertStringContainsStringIgnoringCase(
                'DEFAULT NULL',
                $createSql,
                "Column with 'default' => null should surface as DEFAULT NULL in SHOW CREATE TABLE output."
            );
        } finally {
            \Kyte\Core\DBI::query("DROP TABLE IF EXISTS `$tblName`");
        }
    }
}
