<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class FunctionTest extends TestCase
{
    public function test_base64url_encode() {
        $encoding = base64url_encode("test");
        $this->assertEquals('dGVzdA', $encoding);

        return $encoding;
    }

    /**
     * @depends test_base64url_encode
     */
    public function test_base64url_decode($encoding) {
        $decoded = base64url_decode($encoding);

        $this->assertEquals('test', $decoded);
    }
}

?>  