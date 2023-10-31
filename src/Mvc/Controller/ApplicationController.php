<?php

namespace Kyte\Mvc\Controller;

class ApplicationController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // check aws creds and add if not present
                if (!isset($r['aws_public_key'], $r['aws_private_key'], $r['aws_username'])) {
                    throw new \Exception('AWS Access and Secret key are required along with the username associated with the credential.');
                }
                $aws = new \Kyte\Core\ModelObject(KyteAWSKey);
                if ($aws->retrieve('private_key', $r['aws_private_key'], [['field'=>'public_key', 'value'=>$r['aws_public_key']]])) {
                    $r['aws_key'] = $aws->id;
                } else {
                    if ($aws->create([
                        'private_key' => $r['aws_private_key'],
                        'public_key' => $r['aws_public_key'],
                        'username' => $r['aws_username'],
                        'created_by' => $this->user->id,
                        'kyte_account' => $this->account->id,
                    ])) {
                        $r['aws_key'] = $aws->id;
                    } else {
                        throw new \Exception("Unable to create new AWS credentials.");
                    }
                }

                // create new application identifier
                $r['identifier'] = uniqid();
                // create db name
                $r['db_name'] = $r['identifier'].'_'.$this->account->number;

                // TODO: create new user and add privs to isolate db
                // create new username
                $r['db_username'] = 'db'.$r['identifier'];

                // TODO: create db in different cluster
                // $r['db_host'] = '';

                // create a bucket for storing logs
                // get AWS credential - default to us-east-1
                $region = 'us-east-1';
                $credentials = new \Kyte\Aws\Credentials($region, $aws->aws_public_key, $aws->aws_private_key);

                // create s3 bucket for site data
                $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_-]/', '-', $r['name']).'-logs-'.$r['identifier'].'-'.time());
                $r['s3LogBucketName'] = $bucketName;
                $r['s3LogBucketRegion'] = $region;

                $s3 = new \Kyte\Aws\S3($credentials, $bucketName);
                try {
                    $s3->createBucket();
                } catch(\Exception $e) {
                    throw new \Exception("Unable to create new bucket for logs.");
                }

                // TODO: create SNS topics for logger and each log type - provide option for subscribing to different log events

                // create database
                \Kyte\Core\DBI::createDatabase($r['db_name'], $r['db_username'], $r['db_password']);

                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                // // get AWS credentials
                // $credentials = new \Kyte\Aws\Credentials('us-east-1');

                // // disable distribution
                // $cf = new \Kyte\Aws\CloudFront($credentials, $o->cfDistribution);
                // $cf->disable();

                // // delete distribution
                // $cf->delete();

                // delete database from cluster
                \Kyte\Core\DBI::query("DROP DATABASE `{$o->db_name}`;");

                // // delete acm certificate
                // $acm = new \Kyte\Aws\Acm($credentials, $o->AcmArn);
                // $acm->delete();

                // // delete s3 bucket
                // $s3 = new \Kyte\Aws\S3($credentials, $o->s3bucket, 'public');
                // // $s3->emptyBucket(); <- create method
                // // $s3->deleteBucket();

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
