<?php

namespace Kyte\Mvc\Controller;

class KytePageDataController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['html'] = bzcompress($r['html'], 9);
                $r['stylesheet'] = bzcompress($r['stylesheet'], 9);
                $r['javascript'] = bzcompress($r['javascript'], 9);
                $r['javascript_obfuscated'] = bzcompress($r['javascript_obfuscated'], 9);
                $r['block_layout'] = bzcompress($r['block_layout'], 9);
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
            case 'get':
            case 'update':
                if ($r['page']['footer'] && isset($r['page']['footer']['html'], $r['page']['footer']['stylesheet'], $r['page']['footer']['javascript'], $r['page']['footer']['javascript_obfuscated'], $r['page']['footer']['block_layout'])) {
                    $r['page']['footer']['html'] = bzdecompress($r['page']['footer']['html']);
                    $r['page']['footer']['stylesheet'] = bzdecompress($r['page']['footer']['stylesheet']);
                    $r['page']['footer']['javascript'] = bzdecompress($r['page']['footer']['javascript']);
                    $r['page']['footer']['javascript_obfuscated'] = bzdecompress($r['page']['footer']['javascript_obfuscated']);
                    $r['page']['footer']['block_layout'] = bzdecompress($r['page']['footer']['block_layout']);
                }
                if ($r['page']['header'] && isset($r['page']['header']['html'], $r['page']['header']['stylesheet'], $r['page']['header']['javascript'], $r['page']['header']['javascript_obfuscated'], $r['page']['header']['block_layout'])) {
                    $r['page']['header']['html'] = bzdecompress($r['page']['header']['html']);
                    $r['page']['header']['stylesheet'] = bzdecompress($r['page']['header']['stylesheet']);
                    $r['page']['header']['javascript'] = bzdecompress($r['page']['header']['javascript']);
                    $r['page']['header']['javascript_obfuscated'] = bzdecompress($r['page']['header']['javascript_obfuscated']);
                    $r['page']['header']['block_layout'] = bzdecompress($r['page']['header']['block_layout']);
                }
                $r['html'] = bzdecompress($r['html']);
                $r['stylesheet'] = bzdecompress($r['stylesheet']);
                $r['javascript'] = bzdecompress($r['javascript']);
                $r['javascript_obfuscated'] = bzdecompress($r['javascript_obfuscated']);
                $r['block_layout'] = bzdecompress($r['block_layout']);
                // get download link if available
                if (isset($r['page']['s3key'], $r['page']['site']['application']['id'], $r['page']['site']['s3BucketName'])) {
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $r['page']['site']['application']['id'])) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application.");
                    }
                    $credential = new \Kyte\Aws\Credentials($r['page']['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $r['page']['site']['s3BucketName']);
                    $r['download_link'] = $s3->getObject($r['page']['s3key']);
                } else {
                    $r['download_link'] = null;
                }
                break;

            case 'delete':
                $page = new \Kyte\Core\ModelObject(KytePage);
                if (!$page->retrieve('id', $o->page)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find page.");
                }

                $d = $this->getObject($page);

                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $d['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                // check if s3 key exists
                if (!empty($d['s3key'])) {
                    $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);

                    // delete s3 file
                    $s3->unlink($d['s3key']);

                    // create or update sitemap
                    $sitemap = \Kyte\Mvc\Controller\KytePageController::updateSitemap($d['site']['id'], $d['site']['aliasDomain'] ? $d['site']['aliasDomain'] : $d['site']['cfDomain']);
                    $s3->write('sitemap.xml', $sitemap);

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
                }

                $page->delete();
                break;
            
            default:
                break;
        }
    }
}
