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
                if ($aws->retrieve('private_key', $r['aws_private_key'], [['field'=>'public_key', 'value'=>$r['aws_public_key']], ['field' => 'kyte_account', 'value' => $this->user->kyte_account]])) {
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

                // create database
                \Kyte\Core\DBI::createDatabase($r['db_name'], $r['db_username'], $r['db_password']);

                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'update':
                if (isset($d['republish_kyte_connect']) && $d['republish_kyte_connect'] == 1) {
                    $sites = new \Kyte\Core\Model(KyteSite);
                    $sites->retrieve('application', $o->id);
                    foreach($sites->objects as $site) {
                        $credential = new \Kyte\Aws\Credentials($site->region, $app->aws_public_key, $app->aws_private_key);
                        $s3 = new \Kyte\Aws\S3($credential, $site->s3BucketName);

                        $pages = new \Kyte\Core\Model(KytePage);
                        $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $site->id]]);

                        foreach($pages->objects as $page) {
                            $params = $this->getObject($page);
                            $pd = new \Kyte\Core\ModelObject(KytePageData);
                            if (!$pd->retrieve('page', $page->id)) {
                                throw new \Exception("CRITICAL ERROR: Unable to find page data.");
                            }

                            $params['html'] = bzdecompress($pd->html);
                            $params['stylesheet'] = bzdecompress($pd->stylesheet);
                            $params['javascript'] = bzdecompress($pd->javascript);
                            $params['javascript_obfuscated'] = bzdecompress($pd->javascript_obfuscated);
                            // footers and headers
                            if ($params['footer'] && isset($params['footer']['html'], $params['footer']['stylesheet'], $params['footer']['javascript'], $params['footer']['javascript_obfuscated'], $params['footer']['block_layout'])) {
                                $params['footer']['html'] = bzdecompress($params['footer']['html']);
                                $params['footer']['stylesheet'] = bzdecompress($params['footer']['stylesheet']);
                                $params['footer']['javascript'] = bzdecompress($params['footer']['javascript']);
                                $params['footer']['javascript_obfuscated'] = bzdecompress($params['footer']['javascript_obfuscated']);
                                $params['footer']['block_layout'] = bzdecompress($params['footer']['block_layout']);
                            }
                            if ($params['header'] && isset($params['header']['html'], $params['header']['stylesheet'], $params['header']['javascript'], $params['header']['javascript_obfuscated'], $params['header']['block_layout'])) {
                                $params['header']['html'] = bzdecompress($params['header']['html']);
                                $params['header']['stylesheet'] = bzdecompress($params['header']['stylesheet']);
                                $params['header']['javascript'] = bzdecompress($params['header']['javascript']);
                                $params['header']['javascript_obfuscated'] = bzdecompress($params['header']['javascript_obfuscated']);
                                $params['header']['block_layout'] = bzdecompress($params['header']['block_layout']);
                            }
                            // compile html file
                            $data = \Kyte\Mvc\Controller\KytePageController::createHtml($params);
                            // write to file
                            $s3->write($page->s3key, $data);
                        }
                    }

                    // invalidate CF
                    $invalidationPaths = ['/*'];
                    if (KYTE_USE_SNS) {
                        $credential = new \Kyte\Aws\Credentials(SNS_REGION);
                        $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
                        $sns->publish([
                            'action' => 'cf_invalidate',
                            'site_id' => $site->id,
                            'cf_id' => $rsite->cfDistributionId,
                            'cf_invalidation_paths' => $invalidationPaths,
                            'caller_id' => time(),
                        ]);
                    } else {
                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($site->cfDistributionId, $invalidationPaths);
                    }
                }
                break;
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
