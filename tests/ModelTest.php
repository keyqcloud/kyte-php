<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function createTables() {
        $this->assertTrue(\Kyte\Core\DBI::create("TestModel"));
    }
}

?>  