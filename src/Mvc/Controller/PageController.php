<?php

namespace Kyte\Mvc\Controller;

class PageController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['s3key'] = $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_.-]/', '-', $r['name']).'-'.$r['s3key']);
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                // get api key info
                $apikey = new \Kyte\Core\ModelObject(APIKey);
                if (!$app->retrieve('kyte_account', $this->account->id)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find api information.");
                }

                // get app identifier
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['site']['application'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                $r['api_public_key'] = $apikey->public_key;
                $r['api_identifier'] = $apikey->identifier;
                $r['account_number'] = $this->account->number;
                $r['application_identifier'] = $app->identifier;
                break;

            case 'update':
                if ($d['state'] == 1) {
                    // publish file to s3
                    $credential = new \Kyte\Aws\Credentials($r['site']['region']);
                    $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);

                    // compile html file
                    $data = '';
                    // write to file
                    $s3->write($o->s3key, $data);

                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $cf->createInvalidation($r['site']['cfDistributionId']);
                }
                break;

            case 'delete':
                // check if s3 file exists and delete
                if ($o->state > 0) {
                    // delete file
                    $d = $this->getObject($o);
                    $credential = new \Kyte\Aws\Credentials($d['site']['region']);
                    $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);
                    if (!empty($o->s3key)) {
                        $s3->unlink($o->s3key);
                    }
                }

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
