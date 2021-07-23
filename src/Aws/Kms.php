<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;

class Kms extends Client
{
    public function __construct($credentials, $kmsKeyId) {
        $this->credentials = $credentials;
        $this->Id = $kmsKeyId;
        $this->client = new KmsClient([
            'credentials'	=> $this->credentials->getCredentials(),
            'version'	=> '2014-11-01',
            'region'	=> $this->credentials->getRegion()
        ]);
    }

    public function encrypt($data) {
        if (is_array($data)) {
            $data = json_encode($data);
        }
        $aws_res = $this->client->encrypt([
            'KeyId' => $this->Id,
            'Plaintext' => $data,
        ]);
        
        if (!isset($aws_res["CiphertextBlob"])) {
            throw new \Exception("Did not return cipher");
        }

        return $aws_res["CiphertextBlob"];
    }

    public function decrypt($cipher) {
        $aws_res = $this->client->decrypt([
            'CiphertextBlob' => $cipher,
        ]);

        if (!isset($aws_res["Plaintext"])) {
            throw new \Exception("Did not return plaintext");
        }

        return $aws_res['Plaintext'];
    }
}
?>
