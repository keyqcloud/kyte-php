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

        $result = $acm->request('www.perryhough.com');
        $this->assertIsString($result['CertificateArn']);

        return $acm;
    }

    /*
     * @depends testRequestCertificate
     * */
    public function testDescribeCertificate($acm) {
        $certificate = $acm->describe();

        $this->assertEquals('www.perryhough.com', $certificate['Certificate']['DomainName']);

        return $acm;
    }

    /*
     * @depends testDescribeCertificate
     * */
    public function testListCertificate($acm) {
        $certificates = $acm->list();

        $this->assertCount(1, $certificates['CertificateSummaryList']);

        $this->assertEquals('www.perryhough.com', $certificates['CertificateSummaryList'][0]['DomainName']);

        return $acm;
    }

    /*
     * @depends testListCertificate
     * */
    public function testDeleteCertificate($acm) {
        $this->assertTrue($acm->delete());
        
        return $acm;
    }
}

?>  