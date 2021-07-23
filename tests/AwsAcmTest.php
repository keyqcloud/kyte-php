<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsAcmTest extends TestCase
{
    public function testRequestCertificate() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $acm = new \Kyte\Aws\Acm($credential);
        $this->assertIsObject($acm);

        $result = $acm->request('www.perryhough.com', ['www.perryhough.com']);
        $arn = $result['CertificateArn'];
        $this->assertIsString($arn);

        return $arn;
    }

    /**
     * @depends testRequestCertificate
     */
    public function testDescribeCertificate($arn) {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $acm = new \Kyte\Aws\Acm($credential, $arn);
        $this->assertIsObject($acm);

        $certificate = $acm->describe();

        $this->assertEquals('www.perryhough.com', $certificate['Certificate']['DomainName']);

        return $arn;
    }

    /**
     * @depends testDescribeCertificate
     */
    public function testListCertificate($arn) {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $acm = new \Kyte\Aws\Acm($credential, $arn);
        $this->assertIsObject($acm);

        $certificates = $acm->list();

        $this->assertCount(1, $certificates['CertificateSummaryList']);

        $this->assertEquals('www.perryhough.com', $certificates['CertificateSummaryList'][0]['DomainName']);

        return $arn;
    }

    /**
     * @depends testListCertificate
     */
    public function testDeleteCertificate($arn) {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create s3 client for private bucket
        $acm = new \Kyte\Aws\Acm($credential, $arn);
        $this->assertIsObject($acm);

        $this->assertTrue($acm->delete());
    }
}

?>  