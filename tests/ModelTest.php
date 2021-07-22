<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testCreateTable() {
        // create test user table
        $this->assertTrue(\Kyte\Core\DBI::createTable(User));
    }
}

?>  