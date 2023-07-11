<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;

class Ses extends Client
{
    private $sender;
    private $replyToAddresses;

    public function __construct($credentials, $sender, $replyToAddresses = []) {
        $this->credentials = $credentials;
        $this->sender = $sender;
        $this->replyToAddresses = count($replyToAddresses) == 0 ? [$this->sender] : $replyToAddresses;

        $this->client = new SesClient([
            'credentials' => $this->credentials->getCredentials(),
            'version' => '2010-12-01',
            'region'	=> $this->credentials->getRegion()
        ]);
    }

    public function send($recipients, $subject, $html_body, $char_set = 'UTF-8') {
        $result = $this->client->sendEmail([
            'Destination' => [
                'ToAddresses' => $recipients,
            ],
            'ReplyToAddresses' => $this->replyToAddresses,
            'Source' => $this->sender,
            'Message' => [
                'Body' => [
                    'Html' => [
                        'Charset' => $char_set,
                        'Data' => $html_body,
                    ],
                ],
                'Subject' => [
                    'Charset' => $char_set,
                    'Data' => $subject,
                ],
            ],
        ]);

        if (!isset($result['MessageId'])) {
            throw new \Exception("Unable to send message");
        }

        return $result['MessageId'];
    }
}
