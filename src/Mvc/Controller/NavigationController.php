<?php

namespace Kyte\Mvc\Controller;

class NavigationController extends ModelController
{
    // public function hook_init() {}

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'update':
                $nav = $r;
            case 'delete':
                if ($method == 'delete') {
                    $nav = $this->getObject($o);
                    $r = false;
                    $o->delete(null, null, $this->user->id);
                }
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $nav['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }

                // update pages with navigation
                $credential = new \Kyte\Aws\Credentials($nav['site']['region'], $app->aws_public_key, $app->aws_private_key);
                $s3 = new \Kyte\Aws\S3($credential, $nav['site']['s3BucketName']);

                $pages = new \Kyte\Core\Model(KytePage);
                $pages->retrieve("main_navigation", $nav['id'], false, [['field' => 'state', 'value' => 1],['field' => 'site', 'value' => $r['site']['id']]]);

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
                    // compile html file
                    $data = \Kyte\Mvc\Controller\KytePageController::createHtml($params);
                    // write to file
                    $s3->write($page->s3key, $data);
                }

                // create or update sitemap
                $sitemap = \Kyte\Mvc\Controller\KytePageController::updateSitemap($nav['site']['id'], $nav['site']['aliasDomain'] ? $nav['site']['aliasDomain'] : $nav['site']['cfDomain']);
                $s3->write('sitemap.xml', $sitemap);

                // invalidate CF
                $invalidationPaths = ['/*'];
                if (KYTE_USE_SNS) {
                    $credential = new \Kyte\Aws\Credentials(SQS_REGION);
                    $sqs = new \Kyte\Aws\Sqs($credential, SQS_QUEUE_SITE_MANAGEMENT);
                    $sqs->send([
                        'action' => 'cf_invalidate',
                        'site_id' => $nav['site']['id'],
                        'cf_id' => $nav['site']['cfDistributionId'],
                        'cf_invalidation_paths' => $invalidationPaths,
                        'caller_id' => time(),
                    ], $nav['site']['id']);
                } else {
                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $cf->createInvalidation($nav['site']['cfDistributionId'], $invalidationPaths);
                }
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
