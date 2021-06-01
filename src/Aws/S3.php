<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

class S3 extends Client
{
    private $bucket;
    private $acl;

    public function __construct($credentials, $bucket = null, $acl = 'private') {
        $this->credentials = $credentials;
        $this->bucket = $bucket;
        $this->acl = $acl;

        $this->client = new S3Client([
            'credentials'	=> $this->credentials->getCredentials(),
            'region'		=> $this->credentials->getRegion(),
            'version'		=> '2006-03-01'
        ]);
    }

    // use S3 stream wrapper to create bucket
    public function createBucket($bucket, $context) {
        if ($context) {
            $context = stream_context_create($context);
        }
        $this->client->registerStreamWrapper();
        mkdir('s3://'.$bucket, $context);

        $this->bucket = $bucket;
    }

    // use S3 stream wrapper delete bucket
    public function deleteBucket($bucket) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();

        return rmdir('s3://'.$bucket);

        $this->bucket = null;
    }

    // use S3 stream wrapper to write to bucket path
    public function write($key, $data) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->streamWrite($key, $data, 'w');
    }

    // use S3 stream wrapper to append to bucket path
    public function append($key, $data) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->streamWrite($key, $data, 'a');
    }

    // use S3 stream wrapper to write/append to bucket path
    private function streamWrite($key, $data, $flag) {
        $this->client->registerStreamWrapper();

        $stream = fopen('s3://'.$this->bucket.'/'.$key, $flag);
        fwrite($stream, $data);
        fclose($stream);
    }

    // https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-stream-wrapper.html
    // public function read() {
    //     $this->client->registerStreamWrapper();
    // }

    // use S3 stream wrapper to return list of files in directory
    public function list($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $iter = Aws\recursive_dir_iterator('s3://'.$this->bucket.'/'.$key);

        return $iter;
    }

    // use S3 stream wrapper to delete object
    public function unlink($key, $context = null) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        if ($context) {
            $context = stream_context_create($context);
        }

        $this->client->registerStreamWrapper();

        unlink('s3://'.$this->bucket'/'.$key, $context);
    }

    // use S3 stream wrapper to get file size
    public function filesize($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();

        return filesize('s3://'.$this->bucket'/'.$key);
    }

    // use S3 stream wrapper to check if key points to file
    public function isFile($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();

        return is_file('s3://'.$this->bucket'/'.$key);
    }

    // use S3 stream wrapper to check if file exists
    public function fileExists($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();
        
        return file_exists('s3://'.$this->bucket'/'.$key);
    }

    // use S3 stream wrapper to rename object
    public function rename($oldkey, $newkey) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();
        
        return rename('s3://'.$this->bucket'/'.$oldkey, 's3://'.$this->bucket'/'.$newkey);
    }

    // retrieve object using S3Client getObject() method
    public function getObject($key, $expiration = '+60 minutes') {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $cmd = $this->client->getCommand('getObject', [
            'Bucket'	=> $this->bucket,
            'Key'		=> $key
        ]);

        $aws_req = $this->client->createPresignedRequest($cmd, $expiration);

        return (string)$aws_req->getUri();
    }

    // upload object using S3Client putObject() method
    public function putObject($key, $filepath) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $cmd = $this->client->putObject([
            'Bucket'	=> $this->bucket,
            'Key'		=> $key,
            'Body'		=> fopen($filepath, 'rb'),
            'ACL'		=> $this->acl
        ]);

        return;
    }

    // delete object using S3Client deleteObject() method
    public function deleteObject($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $cmd = $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key'    => $key
        ]);

        return;
    }

    // return a signature that can be used with ajax upload directly to S3
    public function getUploadSignature($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        // format time
        $dt = new DateTime();
        $exp = new DateTime('+2 hours');
        $dt->setTimezone(new DateTimeZone('UTC'));
        $exp->setTimezone(new DateTimeZone('UTC'));
        $expiration = $exp->format('Y-m-d\TH:i:s\Z');
        $ymd = $dt->format('Ymd');
        $date = $dt->format('Ymd\THis\Z');

        // AWS credentials
        $credential = $this->credentials->getAccessKey()."/".$ymd."/".$this->credentials->getRegion()."/s3/aws4_request";

        $policy = base64_encode(json_encode([
            'expiration'	=> $expiration,
            'conditions'	=> [
                ['acl'			=> $this->acl],
                ['bucket'		=> $this->bucket],
                ['starts-with', '$key', $key],
                ["x-amz-credential" => $credential],
                ["x-amz-algorithm" => "AWS4-HMAC-SHA256"],
                ["x-amz-date" => $date]
            ],
        ]));

        // calculate signature
        $hash1 = hash_hmac(
            'sha256',
            $ymd,
            "AWS4".$this->credentials->getSecretKey(),
            true
        );
        $hash2 = hash_hmac(
            'sha256',
            $this->credentials->getRegion(),
            $hash1,
            true
        );
        $hash3 = hash_hmac(
            'sha256',
            "s3",
            $hash2,
            true
        );
        $signingKey = hash_hmac(
            'sha256',
            "aws4_request",
            $hash3,
            true
        );
        $signature = hash_hmac(
            'sha256',
            $policy,
            $signingKey
        );

        return [
            'access_key'	=> AWS_ACCESS_KEY_ID,
            'policy'		=> $policy,
            'signature'		=> $signature,
            'date'			=> $date,
            'credential'	=> $credential,
        ];
    }
}
?>
