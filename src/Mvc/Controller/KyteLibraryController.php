<?php

namespace Kyte\Mvc\Controller;

class KyteLibraryController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                break;
            case 'new':
            case 'update':
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                // get s3 bucket
                $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
                $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);

                $pages = new \Kyte\Core\Model(KytePage);
                $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $r['site']['id']]]);

                // iterate through each page
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

                // invalidate CF
                $invalidationPaths = ['/*'];
                if (KYTE_USE_SNS) {
                    $credential = new \Kyte\Aws\Credentials(SNS_REGION);
                    $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
                    $sns->publish([
                        'action' => 'cf_invalidate',
                        'site_id' => $r['site']['id'],
                        'cf_id' => $r['site']['cfDistributionId'],
                        'cf_invalidation_paths' => $invalidationPaths,
                        'caller_id' => time(),
                    ]);
                } else {
                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $cf->createInvalidation($r['site']['cfDistributionId'], $invalidationPaths);
                }
                break;

            case 'delete':
                // check if s3 file exists and delete
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $d['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                // delete file
                $d = $this->getObject($o);
                $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
                $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);
                
                $pages = new \Kyte\Core\Model(KytePage);
                $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $r['site']['id']]]);
                
                // iterate through each page
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

                // invalidate CF
                $invalidationPaths = ['/*'];
                if (KYTE_USE_SNS) {
                    $credential = new \Kyte\Aws\Credentials(SNS_REGION);
                    $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
                    $sns->publish([
                        'action' => 'cf_invalidate',
                        'site_id' => $d['site']['id'],
                        'cf_id' => $d['site']['cfDistributionId'],
                        'cf_invalidation_paths' => $invalidationPaths,
                        'caller_id' => time(),
                    ]);
                } else {
                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $cf->createInvalidation($d['site']['cfDistributionId'], $invalidationPaths);
                }
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
