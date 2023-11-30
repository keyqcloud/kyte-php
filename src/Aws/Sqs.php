<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\Sqs\SqsClient;

class Sqs extends Client
{
    private $queueUrl;
    private $isFifo;

    public function __construct($credentials, $queueUrl) {
        $this->credentials = $credentials;
        $this->queueUrl = $queueUrl;
        $this->isFifo = strpos($this->queueUrl, '.fifo') !== false;

        $this->client = new SqsClient([
            'credentials' => $this->credentials->getCredentials(),
            'version' => '2012-11-05',
            'region'	=> $this->credentials->getRegion()
        ]);
    }

    public function send($message, $messageGroupId = null) {
        if (!is_string($message)) {
            $message = json_encode($message); // Convert to JSON string
        }

        $param = [
            'QueueUrl' => $this->queueUrl,
            'MessageBody' => $message
        ];
        if ($this->isFifo) {
            $param['MessageGroupId'] = strval($messageGroupId);
        }
        $result = $this->client->sendMessage($param);

        if (!isset($result['MessageId'])) {
            throw new \Exception("Unable to send message");
        }

        return $result['MessageId'];
    }

    public function receive() {
        $param = [
            'QueueUrl' => $this->queueUrl,
            'MaxNumberOfMessages' => 1
        ];
        $result = $this->client->receiveMessage($param);

        if (!isset($result['Messages'][0])) {
            return null;
        }

        $message = $result['Messages'][0];
        $param = [
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => $message['ReceiptHandle']
        ];
        $this->client->deleteMessage($param);

        return $message;
    }
}
