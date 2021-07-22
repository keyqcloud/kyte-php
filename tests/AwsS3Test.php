<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsS3Test extends TestCase
{
    private $credential;
    private $s3;

    public function testCreateCredential() {
        $this->credential = new \Kyte\Aws\Credentials('us-east-1');

        $this->assertIsObject($this->credential);
    }

    public function testCreateS3Client() {
        // create s3 client for private bucket
        $this->s3 = new \Kyte\Aws\S3($this->credential, 'Kyte_MyTestBucket_'.time());

        $this->assertIsObject($this->s3);
    }

    public function testCreateBucket() {
        // create private bucket
        $this->assertTrue($this->s3->createBucket());
    }

    // add file

    // remove file

    public function testDeleteBucket() {
        // delete private bucket
        $this->assertTrue($this->s3->deleteBucket());
    }
}

?>  