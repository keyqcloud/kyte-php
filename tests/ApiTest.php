<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class APiTest extends TestCase
{   
    public function testInitApiFail() {
        $api = new \Kyte\Core\Api();
        $this->assertFalse($api->init('FOO'));
    }

    public function testInitApiSuccess() {
        $api = new \Kyte\Core\Api();
        $this->assertTrue($api->init('BAR'));
    }
}

?>  