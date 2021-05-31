<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;

private $credentials;
private $SesClient;
private $sender;

class Ses
{
    public function __construct($credentials, $sender) {
        $this->credentials = $credentials;
        $this->sender = $sender;

        $this->SesClient = new SesClient([
            'credentials' => $this->credentials->getCredentials(),
            'version' => '2010-12-01',
            'region'	=> $this->credentials->getRegion()
        ]);
    }

    public function send($recipients, $subject, $html_body, $char_set = 'UTF-8') {
        $result = $this->SesClient->sendEmail([
            'Destination' => [
                'ToAddresses' => $recipients,
            ],
            'ReplyToAddresses' => [$this->sender],
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

        return = $result['MessageId'];
    }
}
?>
