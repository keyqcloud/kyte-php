<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsWebsiteTest extends TestCase
{
    // test creating an S3 static website (no CloudFront)
    public function testCreateS3Website() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for public bucket
        $s3 = new \Kyte\Aws\S3($credential, AWS_PUBLIC_BUCKET_NAME);
        $this->assertIsObject($s3);

        // test create bucket
        $this->assertTrue($s3->createBucket());

        // create website
        $this->assertTrue($s3->createWebsite());

        // test enable public policy
        $this->assertTrue($s3->enablePublicAccess());

        // enable versioning
        $this->assertTrue($s3->enableVersioning());

        // test write
        $html = '<html><head><title>Test</title></head><body><h1>Test</h1></body></html>';
        $this->assertTrue($s3->write('index.html',$html));

        // check and see if our content matches...
        $testUrl = 'http://'.AWS_PUBLIC_BUCKET_NAME.'.s3-website-'.$credential->getRegion().'.amazonaws.com/';
        $content = file_get_contents($testUrl);
        $this->assertEquals($html, $content);

        // test delete using deleteObject
        $this->assertTrue($s3->deleteObject('index.html'));

        // list object versions
        $result = $s3->listObjectVersions();
        $this->assertIsObject($result);
        $this->assertEquals('index.html', $result['DeleteMarkers'][0]['Key']);
        $this->assertEquals('index.html', $result['Versions'][0]['Key']);

        // delete each version
        $this->assertTrue($s3->deleteObject('index.html', $result['Versions'][0]['VersionId']));
        $this->assertTrue($s3->deleteObject('index.html', $result['DeleteMarkers'][0]['VersionId']));

        // suspend versioning
        $this->assertTrue($s3->suspendVersioning());

        // deletewebsite
        $this->assertTrue($s3->deleteWebsite());

        // delete policy
        $this->assertTrue($s3->deletePolicy());

        // test delete
        $this->assertTrue($s3->deleteBucket());
    }
}

?>  