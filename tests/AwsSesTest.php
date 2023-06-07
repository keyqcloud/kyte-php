<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsSesTest extends TestCase
{
    public function testEncryptAndDecrypt() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $ses = new \Kyte\Aws\Ses($credential, 'kenneth@keyqcloud.com');
        $this->assertIsObject($ses);

        $result = $ses->send(['kenneth@keyqcloud.com'], 'Test run from Travis CI', 'Testing SES send email');

        $this->assertIsString($result);
    }
}
