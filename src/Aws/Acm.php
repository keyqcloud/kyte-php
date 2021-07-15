<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Acm\AcmClient;

class Acm extends Client
{
    public function __construct($credentials, $arn = null) {
        $this->Arn = $arn;
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

        $this->Arn = $result['CertificateArn'];

        return [ 'CertificateArn' => $result['CertificateArn'], 'IdempotencyToken' => $idemToken ];
    }

    public function describe($arn = null) {
        $arn = $this->Arn ? $this->Arn : $arn;

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
        $arn = $this->Arn ? $this->Arn : $arn;

        $result = $client->deleteCertificate([
            'CertificateArn' => $arn, // REQUIRED
        ]);

        $this->$Arn = null;
    }

    public function list($params = []) {
        $result = $this->client->listCertificates($params);

        return $result;
    }
}
?>
