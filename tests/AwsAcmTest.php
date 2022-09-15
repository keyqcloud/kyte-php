<?php
namespace Kyte\Test;

use PHPUnit\Framework\TestCase;

class AwsAcmTest extends TestCase
{
    public function testRequestCertificate() {
        $credential = new \Kyte\Aws\Credentials('us-east-1');
        $this->assertIsObject($credential);

        // create acm client
        $acm = new \Kyte\Aws\Acm($credential);
        $this->assertIsObject($acm);

        // test request certificate
        $result = $acm->request('www.perryhough.com', ['www.perryhough.com']);
        $arn = $result['CertificateArn'];
        $this->assertIsString($arn);

        // wait 5 secs
        sleep(5);

        // get certificate details
        $certificate = $acm->describe();
        $this->assertIsObject($certificate);
        $this->assertEquals('www.perryhough.com', $certificate['Certificate']['DomainName']);

        // list acm certs
        $certificates = $acm->list();
        $this->assertCount(1, $certificates['CertificateSummaryList']);
        $this->assertEquals('www.perryhough.com', $certificates['CertificateSummaryList'][0]['DomainName']);

        // test delete certificate
        $this->assertTrue($acm->delete());
    }
}
