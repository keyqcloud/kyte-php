<?php
namespace Kyte\Aws;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

class S3 extends Client
{
    private $bucket;
    private $acl;

    public function __construct($credentials, $bucket, $acl = 'private') {
        $this->credentials = $credentials;
        $this->bucket = $bucket;
        $this->acl = $acl;

        $this->client = new S3Client([
            'credentials'	=> $this->credentials->getCredentials(),
            'region'		=> $this->credentials->getRegion(),
            'version'		=> '2006-03-01'
        ]);
    }

    // create bucket
    public function createBucket() {
        try {
            $this->client->createBucket([
                'ACL' => $this->acl, // 'private|public-read|public-read-write|authenticated-read'
                'Bucket' => $this->bucket, // REQUIRED
                'CreateBucketConfiguration' => [
                    'LocationConstraint' => $this->credentials->getRegion() //'ap-northeast-1|ap-southeast-2|ap-southeast-1|cn-north-1|eu-central-1|eu-west-1|us-east-1|us-west-1|us-west-2|sa-east-1'
                ]
            ]);

            return true;
        } catch (\AwsException $e) {
			throw $e;
			return false;
		}

        // if ($context) {
        //     $context = stream_context_create($context);
        // }
        // $this->client->registerStreamWrapper();
        // mkdir('s3://'.$bucket, $context);
    }

    public function createWebsite($indexDoc = 'index.html', $errorDoc = 'error.html') {
        try {
            $result = $this->client->putBucketWebsite([
                'Bucket' => $this->bucket, // REQUIRED
                'WebsiteConfiguration' => [ // REQUIRED
                    'ErrorDocument' => [
                        'Key' => $errorDoc, // REQUIRED
                    ],
                    'IndexDocument' => [
                        'Suffix' => $indexDoc, // REQUIRED
                    ],
                ],
            ]);
        } catch(\AwsException $e) {
            throw new \Exception("Unable to create website");
            return false;
        }

        return true;
    }

    public function deleteWebsite() {
        try {
            $result = $this->client->deleteBucketWebsite([
                'Bucket' => $this->bucket, // REQUIRED
            ]);
        } catch (\AwsException $e) {
            throw new \Exception("Unable to delete website");
            return false;
        }
        
        return true;
    }

    public function enablePublicAccess() {
        try {
            $this->enablePolicy('{"Version": "2012-10-17", "Statement": [{ "Sid": "PublicReadForGetBucketObject","Effect": "Allow","Principal": "*", "Action": "s3:GetObject", "Resource": "arn:aws:s3:::'.$this->bucket.'/*" } ]}');
        } catch (\AwsException $e) {
            throw new \Exception("Unable to apply public access policy");
            return false;
        }
        return true;
    }

    public function enablePolicy($policy) {
        $result = $this->client->putBucketPolicy([
            'Bucket' => $this->bucket,
            'Policy' => $policy,
        ]);
    }

    public function deletePolicy() {
        try {
            $result = $this->client->deleteBucketPolicy([
                'Bucket' => $this->bucket, // REQUIRED
            ]);
        } catch(\AwsException $e) {
            throw new \Exception("Unable to delete bucket policy");
            return false;
        }
        
        return true;
    }

    public function enableCors($rules) {
        // example rules:
        // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-s3-2006-03-01.html#putbucketcors
        $result = $this->client->putBucketCors([
            'Bucket' => $this->bucket,
            'CORSConfiguration' => [
                'CORSRules' => $rules,
            ],
        ]);
    }

    public function deleteCors() {
        $result = $this->client->deleteBucketCors([
            'Bucket' => $this->bucket, // REQUIRED
        ]);
    }

    public function enableVersioning() {
        try {
            $result = $this->client->putBucketVersioning([
                'Bucket' => $this->bucket,
                'VersioningConfiguration' => [
                    // 'MFADelete' => 'Disabled',
                    'Status' => 'Enabled',
                ],
            ]);
        } catch(\AwsException $e) {
            throw new \Exception("Unable to enable versioning");
            return false;
        }

        return true;
    }

    public function suspendVersioning() {
        try {
            $result = $this->client->putBucketVersioning([
                'Bucket' => $this->bucket,
                'VersioningConfiguration' => [
                    // 'MFADelete' => 'Disabled',
                    'Status' => 'Suspended',
                ],
            ]);
        } catch(\AwsException $e) {
            throw new \Exception("Unable to suspend versioning");
            return false;
        }

        return true;
    }

    // delete bucket
    public function deleteBucket() {
        try {
            $result = $this->client->deleteBucket([
                'Bucket' => $this->bucket, // REQUIRED
            ]);
    
            $this->bucket = null;

            return true;
        } catch (\AwsException $e) {
			throw $e;
			return false;
		}
    }

    // use S3 stream wrapper to write to bucket path
    public function write($key, $data) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        return $this->streamWrite($key, $data, 'w');
    }

    // use S3 stream wrapper to append to bucket path
    public function append($key, $data) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        return $this->streamWrite($key, $data, 'a');;
    }

    // use S3 stream wrapper to write/append to bucket path
    private function streamWrite($key, $data, $flag) {
        $this->client->registerStreamWrapper();

        $stream = fopen('s3://'.$this->bucket.'/'.$key, $flag);
        fwrite($stream, $data);
        return fclose($stream);
    }

    // https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-stream-wrapper.html
    // public function read() {
    //     $this->client->registerStreamWrapper();
    // }

    // use S3 stream wrapper to return list of files in directory
    // public function list($key) {
    //     // check if bucket exists
    //     if (!$this->bucket) {
    //         throw new \Exception('bucket must be defined');
    //     }

    //     $iter = Aws\recursive_dir_iterator('s3://'.$this->bucket.'/'.$key);

    //     return $iter;
    // }

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

        return unlink('s3://'.$this->bucket.'/'.$key, $context);;
    }

    // use S3 stream wrapper to get file size
    public function filesize($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();

        return filesize('s3://'.$this->bucket.'/'.$key);
    }

    // use S3 stream wrapper to check if key points to file
    public function isFile($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();

        return is_file('s3://'.$this->bucket.'/'.$key);
    }

    // use S3 stream wrapper to check if file exists
    public function fileExists($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();
        
        return file_exists('s3://'.$this->bucket.'/'.$key);
    }

    // use S3 stream wrapper to rename object
    public function rename($oldkey, $newkey) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $this->client->registerStreamWrapper();
        
        return rename('s3://'.$this->bucket.'/'.$oldkey, 's3://'.$this->bucket.'/'.$newkey);
    }

    public function listObjectVersions() {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $result = $this->client->listObjectVersions([
            'Bucket' => $this->bucket, // REQUIRED
        ]);

        return $result;
    }

    public function listObjects() {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $result = $this->client->listObjects([
            'Bucket' => $this->bucket, // REQUIRED
        ]);

        return $result;
    }

    // retrieve object using S3Client getObject() method
    public function getObject($key, $versionId = null, $expiration = '+60 minutes') {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        $params = [
            'Bucket' => $this->bucket,
            'Key'    => $key
        ];

        if ($versionId) {
            $params['VersionId'] = $versionId;
        }
        

        $cmd = $this->client->getCommand('getObject', $params);

        $aws_req = $this->client->createPresignedRequest($cmd, $expiration);

        return (string)$aws_req->getUri();
    }

    // upload object using S3Client putObject() method
    public function putObject($key, $filepath) {
        try {
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
        } catch(\AwsException $e) {
            throw new \Exception("Unable to upload object");
            return false;
        }

        return true;
    }

    // delete object using S3Client deleteObject() method
    public function deleteObject($key, $versionId = null) {
        try {
            // check if bucket exists
            if (!$this->bucket) {
                throw new \Exception('bucket must be defined');
            }

            $params = [
                'Bucket' => $this->bucket,
                'Key'    => $key
            ];

            if ($versionId) {
                $params['VersionId'] = $versionId;
            }

            $cmd = $this->client->deleteObject($params);
        } catch(\AwsException $e) {
            throw new \Exception("Unable to delete object");
            return false;
        }
        
        return true;
    }

    // return a signature that can be used with ajax upload directly to S3
    public function getUploadSignature($key) {
        // check if bucket exists
        if (!$this->bucket) {
            throw new \Exception('bucket must be defined');
        }

        // format time
        $dt = new \DateTime();
        $exp = new \DateTime('+2 hours');
        $dt->setTimezone(new \DateTimeZone('UTC'));
        $exp->setTimezone(new \DateTimeZone('UTC'));
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
            'access_key'	=> $this->credentials->getAccessKey(),
            'policy'		=> $policy,
            'signature'		=> $signature,
            'date'			=> $date,
            'credential'	=> $credential,
        ];
    }
}
