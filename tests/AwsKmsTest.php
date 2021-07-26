<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsKmsTest extends TestCase
{
    public function testEncryptAndDecrypt() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        // $kms = new \Kyte\Aws\Kms($credential, AWS_KMS_KEYID);
        $kms = new \Kyte\Aws\Kms($credential);
        $this->assertIsObject($kms);

        // attempt to create a new symmetric CMK
        $this->assertTrue($kms->createKey());

        // encrypt and decrypt
        $cipher = $kms->encrypt('Test');
        $text = $kms->decrypt($cipher);
        $this->assertEquals('Test', $text);

        // schedule key deletion - default to 7 days
        $this->assertTrue($kms->scheduleDelete());
    }
}

?>  