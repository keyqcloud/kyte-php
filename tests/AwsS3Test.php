<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsS3Test extends TestCase
{
    public function testCreateBucket() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->createBucket());

        return $s3;
    }

    // add file
    public function testWriteFile() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->write('Test', 'Hello'));
    }

    // check if file exists
    public function testFileExists() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->fileExists('Test'));
    }

    // test if it is file
    public function testIsFile() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->isFile('Test'));
    }

    // append
    public function testAppendsFile() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->append('Test', ' World'));
    }

    // rename file
    public function testRenameFile() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->rename('Test', 'HelloWorld'));
    }

    // remove file
    public function testDeleteFile() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->unlink('HelloWorld'));
    }

    public function testDeleteBucket() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $this->assertTrue($s3->deleteBucket());
    }

    public function testUploadSignature() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $signature = $this->getUploadSignature('Test');
        $this->assertEquals(hash_hmac('sha1', AWS_ACCESS_KEY_ID, 'us-east-1'), hash_hmac('sha1', $signature['access_key'], 'us-east-1'));
    }
}

?>  