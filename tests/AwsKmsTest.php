<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsKmsTest extends TestCase
{
    public function testEncryptAndDecrypt() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $kms = new \Kyte\Aws\Kms($credential, AWS_KMS_ID);
        $this->assertIsObject($s3);

        $cipher = $kms->encrypt('Test');
        $text = $kms->decrypt($cipher);
        $this->assertEquals('Test', $text);
    }
}

?>  