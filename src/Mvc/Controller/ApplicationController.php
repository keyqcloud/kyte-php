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
                // create new application identifier
                $r['identifier'] = uniqid();
                // create db name
                $r['db_name'] = $r['identifier'].'_'.$this->account->number;

                // TODO: create new user and add privs to isolate db
                // create new username
                $r['db_username'] = $r['db_name'];

                // TODO: create db in different cluster
                // $r['db_host'] = '';

                // create database
                \Kyte\Core\DBI::createDatabase($r['db_name'], $r['db_username'], $r['db_password']);

                // get AWS credentials
                $credentials = new \Kyte\Aws\Credentials('us-east-1');

                // create s3 bucket
                $bucketName = $r['domain'];
                $s3 = new \Kyte\Aws\S3($credentials, $bucketName, 'public');
                $s3->createBucket();
                $s3->createWebsite();
                $s3->enablePublicAccess();
                // $s3->enableVersioning();

                // create acm certificate request
                $acm = new \Kyte\Aws\Acm($credentials);
                $acm->request($r['domain']);

                // create distribution
                $cf = new \Kyte\Aws\CloudFront($credentials);
                // $cf->addOrigin(
                    // ConnectionAttempts
                    // ConnectionTimeout
                    // CustomHeaders
                    // HTTPPort
                    // HTTPSPort
                    // OriginKeepaliveTimeout
                    // OriginProtocolPolicy
                    // OriginReadTimeout
                    // OriginSslProtocols
                    // DomainName
                    // Id
                    // OriginPath
                    // OriginShieldEnabled
                    // OriginShieldRegion
                    // S3OriginAccessIdentity
                // );
                // $cf->create();

                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                // get AWS credentials
                $credentials = new \Kyte\Aws\Credentials('us-east-1');

                // disable distribution
                $cf = new \Kyte\Aws\CloudFront($credentials, $o->cfDistribution);
                // $cf->disable();

                // delete database from cluster
                \Kyte\Core\DBI::query("DROP DATABASE `{$o->db_name}`;");

                // delete distribution
                // $cf->delete();

                // delete acm certificate
                $acm = new \Kyte\Aws\Acm($credentials, $o->AcmArn);
                $acm->delete();

                // delete s3 bucket
                $s3 = new \Kyte\Aws\S3($credentials, $o->s3bucket, 'public');
                // $s3->emptyBucket(); <- create method
                // $s3->deleteBucket();

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}

?>
