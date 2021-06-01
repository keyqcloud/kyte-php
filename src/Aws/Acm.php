<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Acm\AcmClient;

class Acm extends Client
{
    public function __construct($credentials) {
        $this->credentials = $credentials;
        $this->client = new AcmClient([
            'credentials'	=> $this->credentials->getCredentials(),
            'version'	=> '2014-11-01',
            'region'	=> $this->credentials->getRegion()
        ]);
    }

    public function request($domainName, $san = []) {
        // create idempotency token
        $idemToken = $domainName.time();

        // request certificate
        $result = $this->client->requestCertificate([
            'DomainName' => $domainName, // REQUIRED
            'SubjectAlternativeNames' => $san,
            'IdempotencyToken' => $idemToken,
            'ValidationMethod' => 'DNS',
        ]);

        if (!isset($result['CertificateArn'])) {
            throw new \Exception('Unable to create new certificate');
        }

        return [ 'arn' => $result['CertificateArn'], 'IdempotencyToken' => $idemToken ];
    }

    public function describe($arn) {
        $result = $this->client->describeCertificate([
            'CertificateArn' => $arn, // REQUIRED
        ]);

        // CNAME records required
        // 'DomainValidationOptions' => [
        //     [
        //         'DomainName' => '<string>',
        //         'ResourceRecord' => [
        //             'Name' => '<string>',
        //             'Type' => 'CNAME',
        //             'Value' => '<string>',
        //     ],

        return $result;
    }

    public function delete($arn) {
        $result = $client->deleteCertificate([
            'CertificateArn' => $arn, // REQUIRED
        ]);
    }

    public function list($arn, $params = []) {
        $result = $this->client->listCertificates($params);

        return $result;
    }
}
?>
