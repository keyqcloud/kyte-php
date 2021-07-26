<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsS3Test extends TestCase
{
    public function testBucketFunctions() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        // test create private bucket
        $this->assertTrue($s3->createBucket());

        // test write file
        $this->assertTrue($s3->write('Test', 'Hello'));

        // test file exists
        $this->assertTrue($s3->fileExists('Test'));

        // test is file
        $this->assertTrue($s3->isFile('Test'));

        // test append
        $this->assertTrue($s3->append('Test', ' World'));
    
        // test rename
        $this->assertTrue($s3->rename('Test', 'HelloWorld'));
    
        // test get
        $url = $s3->getObject('HelloWorld');
        $content = file_get_contents($url);
        $this->assertEquals('Hello World', $content);

        // test list objects
        $result = $s3->listObjects();
        $this->assertIsArray($result);
        $this->assertEquals('HelloWorld', $result['Contents'][0]['Key']);

        // test delete using stream wrapper
        $this->assertTrue($s3->unlink('HelloWorld'));
    
        // test delete bucket
        $this->assertTrue($s3->deleteBucket());
    }

    public function testUploadSignature() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);
        
        // create s3 client for private bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PRIVATE_BUCKET_NAME);
        $this->assertIsObject($s3);

        $signature = $s3->getUploadSignature('Test');
        $this->assertEquals(hash_hmac('sha1', AWS_ACCESS_KEY_ID, 'us-east-1'), hash_hmac('sha1', $signature['access_key'], 'us-east-1'));
    }
}

?>  