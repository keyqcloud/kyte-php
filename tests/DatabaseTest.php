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
}
