<?php
namespace Kyte\Aws;

private $credentials;
private $region;

class Credentials
{
    public function __construct($region) {
        $this->region = $region;
        $this->credentials = new \Aws\Credentials\Credentials(AWS_ACCESS_KEY_ID, AWS_SECRET_KEY);
    }

    public function getCredentials() {
        return $this->credentials;
    }

    public function getRegion() {
        return $this->region;
    }
}
?>
