<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Sns\SnsClient;

class Sns extends Client
{
    private $topicArn;

    public function __construct($credentials, $topicArn) {
        $this->credentials = $credentials;
        $this->topicArn = $topicArn;

        $this->client = new SnsClient([
            'credentials' => $this->credentials->getCredentials(),
            'version' => '2010-03-31',
            'region'	=> $this->credentials->getRegion()
        ]);
    }

    public function publish($message) {
        if (!is_string($message)) {
            $message = json_encode($message); // Convert to JSON string
        }

        $param = [
            'TopicArn' => $this->topicArn,
            'Message' => $message
        ];
        $result = $this->client->publish($param);

        if (!isset($result['MessageId'])) {
            throw new \Exception("Unable to send message");
        }

        return $result['MessageId'];
    }
}
