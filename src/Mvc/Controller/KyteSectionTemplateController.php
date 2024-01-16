<?php

namespace Kyte\Mvc\Controller;

class KyteSectionTemplateController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }

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
                $r['html'] = bzdecompress($r['html']);
                $r['stylesheet'] = bzdecompress($r['stylesheet']);
                $r['javascript'] = bzdecompress($r['javascript']);
                $r['javascript_obfuscated'] = bzdecompress($r['javascript_obfuscated']);
                $r['block_layout'] = bzdecompress($r['block_layout']);
                break;

            case 'delete':
                break;
            
            default:
                break;
        }
    }
}
