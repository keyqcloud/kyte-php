<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsCredentialTest extends TestCase
{
    public function testCredential() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        $this->assertEquals('us-east-1', $credential->getRegion());

        $this->assertEquals(hash_hmac('sha1', AWS_ACCESS_KEY_ID, 'us-east-1'), hash_hmac('sha1', $credential->getAccessKey(), 'us-east-1'));

        $this->assertEquals(hash_hmac('sha1', AWS_SECRET_KEY, 'us-east-1'), hash_hmac('sha1', $credential->getSecretKey(), 'us-east-1'));
    }
}

?>  