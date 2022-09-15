<?php
namespace Kyte\Aws;

class Credentials
{
    private $credentials;
    private $region;
    private $accessKey;
    private $secretKey;

    public function __construct($region, $accessKey = null, $secretKey = null) {
        try {
            $this->accessKey = $accessKey ? $accessKey : AWS_ACCESS_KEY_ID;
            $this->secretKey = $secretKey ? $secretKey : AWS_SECRET_KEY;
            $this->region = $region;
            $this->credentials = new \Aws\Credentials\Credentials($this->accessKey, $this->secretKey);
        } catch (\Exception $e) {
			throw "Unable to create credential for ".$this->$accessKey.'. [ERROR] '.$e;
			return false;
		}
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
