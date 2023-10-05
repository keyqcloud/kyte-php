<?php

namespace Kyte\Util;

class Email {
    private $credentials;
    private $region;
    private $accessKey;
    private $secretKey;
    private $ses;
    private $appId;

    public function __construct($sender = null, $region, $accessKey = null, $secretKey = null, $appId = null) {
        try {
            // check to make sure valid sender information is provided, either as a paramter or in the configs
            if ($sender == null) {
                if (!defined('APP_NAME')) {
                    throw new \Exception('Sender parameter is null but APP_NAME is not defined. Please supply a sender parameter or define APP_NAME in your configuration file.');
                }
                if (!defined('APP_EMAIL')) {
                    throw new \Exception('Sender parameter is null but APP_EMAIL is not defined. Please supply a sender parameter or define APP_EMAIL in your configuration file.');
                }
            }
            $this->appId = $appId;
            $this->accessKey = $accessKey;
            $this->secretKey = $secretKey;
            if (($accessKey === null || $secretKey === null) && $appId === null) {
                throw new \Exception('If AWS access and secret keys are left empty, application ID is required');
            } else if($appId !== null) {
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $appId)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                $this->accessKey = $app->aws_public_key;
                $this->secretKey = $app->aws_private_key;
            }
            $this->region = $region;
            $this->credentials = new \Kyte\Aws\Credentials($this->region, $this->accessKey, $this->secretKey);
            $this->ses = new \Kyte\Aws\Ses($this->credentials, $sender ? $sender : APP_NAME.' <'.APP_EMAIL.'>');
        } catch (\Exception $e) {
			throw "Unable to create credential for ".$this->$accessKey.'. [ERROR] '.$e;
			return false;
		}
    }

    public function sendUsingTemplate($emailAddresses, $subject, $appId, $template_identifier, $data) {
        // check if app id is present
        if ($appId == null && $this->appId == null) {
            throw new \Exception("Application id was not specified. Please provide the application ID in the constructor or as a parameter to sendUsingTemplate");
        }
        // if app id member property is null, then use the parameter and set
        // or if appId parameter is set, then use the parameter
        if ($this->appId == null || $appId != null) {
            $this->appId = $appId;
        }
        // retrieve email template
        $emailTemplate = new \Kyte\Core\ModelObject(EmailTemplate);
        if (!$emailTemplate->retrieve('identifier', $template_identifier, [['field' => 'application', 'value' => $this->appId]])) {
            throw new \Exception("Unable to find specified email template for id $template_identifier and application $appId");
        }

        // replace placeholders and return finalized html
        $html = $this->replacePlaceholders($emailTemplate->html, $data);

        // check to make sure it's not null
        if ($html == null) {
            throw new \Exception("Processed HTML is null. Cannot send email with empty body.");
        }

        // send email
        $this->sendEmail($emailAddresses, $subject, $html);
    }

    public function sendEmail($emailAddresses, $subject, $body) {
        return $this->ses->send($emailAddresses, $subject, $body);
    }

    private function replacePlaceholders($htmlTemplate, $data) {
        if (strlen($htmlTemplate) < 1) {
            throw new \Exception("Supplied template HTML is empty.");
        }
        $pattern = array();
        $replacement = array();

        // Loop through the associative array and build the replacement array
        foreach ($data as $key => $value) {
            $pattern[] = '/{{'.$key.'}}/'; // pattern to match {{<key>}}
            $replacement[] = $value;
        }
      
        // Perform the replacement using preg_replace
        $result = preg_replace($pattern, $replacement, $htmlTemplate);
      
        return $result;
    }
}