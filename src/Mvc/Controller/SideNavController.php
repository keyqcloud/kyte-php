<?php

namespace Kyte\Mvc\Controller;

class SideNavController extends ModelController
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
                // update pages with navigation
                $credential = new \Kyte\Aws\Credentials($nav['site']['region']);
                $s3 = new \Kyte\Aws\S3($credential, $nav['site']['s3BucketName']);

                $pages = new \Kyte\Core\Model(Page);
                $pages->retrieve("side_navigation", $nav['id']);
                // todo...somehow update to use obfuscated...
                $apiKey = new \Kyte\Core\ModelObject(APIKey);
                if (!$apiKey->retrieve('kyte_account', $this->account->id)) {
                    throw new \Exception("Unable to find key");
                }
                foreach($pages->objects as $page) {
                    $p = $this->getObject($page);
                    $kyte_connect = "let endpoint = 'https://".API_URL."';var k = new Kyte(endpoint, '".$apiKey->public_key."', '".$apiKey->identifier."', '".$this->account->number."', '".$p['site']['application']['identifier']."');k.init();\n\n";
                    // compile html file
                    $data = \Kyte\Mvc\Controller\PageController::createHtml($p, $kyte_connect);
                    // write to file
                    $s3->write($page->s3key, $data);
                }

                // invalidate CF
                $cf = new \Kyte\Aws\CloudFront($credential);
                $invalidationPaths = ['/*'];
                $cf->createInvalidation($nav['site']['cfDistributionId'], $invalidationPaths);
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
