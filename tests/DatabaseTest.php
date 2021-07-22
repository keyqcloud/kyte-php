<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testDatabaseCreation() {
        $this->assertTrue(\Kyte\Core\DBI::createDatabase("TestDatabase1", "TestUser1", $password));
    }

    public function testDatabaseCreationAndSwitch() {
        $this->assertTrue(\Kyte\Core\DBI::createDatabase("TestDatabase2", "TestUser2", $password, true));
    }
}

?>  