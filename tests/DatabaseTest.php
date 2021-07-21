<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testDatabaseCreation() {
        $this->assertTrue(\Kyte\Core\DBI::create("TestModel", "TestUser", $password));
    }
}

?>  