<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;

class Kms extends Client
{
    private $kmsKeyId;
    
    public function __construct($credentials, $kmsKeyId) {
        $this->credentials = $credentials;
        $this->kmsKeyId = $kmsKeyId;
        $this->client = new Aws\Kms\KmsClient([
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
            'KeyId' => $this->kmsKeyId,
            'Plaintext' => $data,
        ]);
        
        if (!isset($aws_res["CiphertextBlob"])) {
            throw new \Exception("Did not return cipher");
        }

        return $aws_res["CiphertextBlob"];
    }

    public function decrypt($cipher) {
        $aws_res = $this->v->decrypt([
            'CiphertextBlob' => $cipher,
        ]);

        if (!isset($aws_res["Plaintext"])) {
            throw new \Exception("Did not return plaintext");
        }

        return $aws_res['Plaintext'];
    }
}
?>
