<?php

namespace Kyte\Mvc\Controller;

class DataStoreController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                // get AWS credentials
                $credentials = new \Kyte\Aws\Credentials($o->region);

                // create s3 bucket for site data
                $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_.-]/', '-', $o->name).'-'.uniqid());
                $s3 = new \Kyte\Aws\S3($credentials, $bucketName, 'private');
                try {
                    $s3->createBucket();
                } catch(\Exception $e) {
                    $o->delete();
                    throw $e;
                }
                $o->save([
                    'bucketname' => $bucketName,
                ]);

                // get CORS data and create entries
                $has_cors = false;
                $cors = [];
                if (isset($d['cors']) && is_array($d['cors'])) {
                    for ($i = 0; $i < count($d['cors']); $i++) {
                        if (isset($d['cors'][$i]['AllowedHeaders'], $d['cors'][$i]['AllowedMethods'], $d['cors'][$i]['AllowedOrigins'])) {
                            if (is_array($d['cors'][$i]['AllowedHeaders']) && is_array($d['cors'][$i]['AllowedMethods']) && is_array($d['cors'][$i]['AllowedOrigins'])) {
                                $cors[] = [
                                    'AllowedHeaders'    =>  $d['cors'][$i]['AllowedHeaders'],
                                    'AllowedMethods'    =>  $d['cors'][$i]['AllowedMethods'],
                                    'AllowedOrigins'    =>  $d['cors'][$i]['AllowedOrigins'],
                                ];
                                // store cors in db
                                $dsc = new \Kyte\Core\ModelObject(DataStoreCORS);
                                if (!$dsc->create([
                                    'allowedHeaders' => implode(',',$d['cors'][$i]['AllowedHeaders']),
                                    'allowedMethods' => implode(',',$d['cors'][$i]['AllowedMethods']),
                                    'allowedOrigins' => implode(',',$d['cors'][$i]['AllowedOrigins']),
                                    'datastore' => $o->id,
                                    'created_by' => $this->account->id,
                                    'kyte_account' => $this->account->id,
                                ])) {
                                    throw new \Exception("CRITICAL ERROR: Unable to create CORS policy. Contact support.");
                                }

                                // toggle flag
                                $has_cors = true;
                            }
                        }
                    }
                }

                // enable cors for upload
                if ($has_cors) {
                    $s3->enableCors($cors);
                }

                $r['bucketname'] = $bucketName;

                break;
            case 'delete':
                // // get AWS credentials
                // $credentials = new \Kyte\Aws\Credentials('us-east-1');

                // // disable distribution
                // $cf = new \Kyte\Aws\CloudFront($credentials, $o->cfDistribution);
                // $cf->disable();

                // delete distribution
                // $cf->delete();

                // // delete acm certificate
                // $acm = new \Kyte\Aws\Acm($credentials, $o->AcmArn);
                // $acm->delete();

                // delete s3 bucket
                // $s3 = new \Kyte\Aws\S3($credentials, $o->s3bucket, 'public');
                // $s3->emptyBucket(); <- create method
                // $s3->deleteBucket();

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
