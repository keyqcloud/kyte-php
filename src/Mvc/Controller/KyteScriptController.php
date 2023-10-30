<?php

namespace Kyte\Mvc\Controller;

class KyteScriptController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['s3key'] = 'assets/'.$r['script_type'].'/'.strtolower(preg_replace('/[^A-Za-z0-9_.-\/]/', '-', $r['s3key']));
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }
                $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
                $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);
                $r['download_link'] = $s3->getObject($o->s3key);
                break;
            case 'update':
                if ($o->state == 1 && !isset($d['state'])) {
                    $o->save(['state' => 2]);
                }
                if (isset($d['state']) && $d['state'] == 1) {
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $r['site']['application']['id'])) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application.");
                    }

                    // publish file to s3
                    $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);

                    // write script to file
                    $s3->write($o->s3key, $o->obfuscate_js ? $o->content_js_obfuscated : $o->content);

                    $pages = new \Kyte\Core\Model(Page);
                    $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $r['site']['id']]]);

                    // iterate through each page
                    foreach($pages->objects as $page) {
                        $p = $this->getObject($page);
                        // compile html file
                        $data = \Kyte\Mvc\Controller\PageController::createHtml($p);
                        // write to file
                        $s3->write($page->s3key, $data);
                    }

                    // invalidate CF
                    $cf = new \Kyte\Aws\CloudFront($credential);
                    $invalidationPaths = ['/*'];
                    $cf->createInvalidation($r['site']['cfDistributionId'], $invalidationPaths);
                }
                break;

            case 'delete':
                // check if s3 file exists and delete
                if ($o->state > 0) {
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $d['site']['application']['id'])) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application.");
                    }

                    // delete file
                    $d = $this->getObject($o);
                    $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
                    $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);
                    if (!empty($o->s3key)) {
                        // delete s3 file
                        $s3->unlink($o->s3key);

                        $pages = new \Kyte\Core\Model(Page);
                        $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $r['site']['id']]]);

                        // iterate through each page
                        foreach($pages->objects as $page) {
                            $p = $this->getObject($page);
                            // compile html file
                            $data = \Kyte\Mvc\Controller\PageController::createHtml($p);
                            // write to file
                            $s3->write($page->s3key, $data);
                        }

                        // invalidate CF
                        $cf = new \Kyte\Aws\CloudFront($credential);
                        $cf->createInvalidation($d['site']['cfDistributionId'], ['/*']);
                    }
                }

                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
