<?php
namespace Kyte\Aws;

private $credentials;
private $region;
private $accessKey;
private $secretKey;

class Credentials
{
    public function __construct($region, $accessKey = null, $secretKey = null) {
        $this->accessKey = $accessKey ? $accessKey : AWS_ACCESS_KEY_ID;
        $this->secretKey = $secretKey ? $secretKey : AWS_SECRET_KEY;
        $this->region = $region;
        $this->credentials = new \Aws\Credentials\Credentials($this->accessKey, $this->secretKey);
    }

    public function getCredentials() {
        return $this->credentials;
    }

    public function getRegion() {
        return $this->region;
    }

    public function getAccessKey() {
        return $this->accessKey;
    }

    public function getSecretKey() {
        return $this->secretKey;
    }
}
?>
