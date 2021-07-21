<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testCreateTable() {
        $this->assertTrue(\Kyte\Core\DBI::createDatabase("TestModel2", "TestUser2", $password));
    }
}

?>  