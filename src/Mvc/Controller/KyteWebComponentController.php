<?php

namespace Kyte\Mvc\Controller;

class KyteWebComponentController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
            case 'update':
                $r['html'] = bzcompress($r['html'], 9);
                $r['stylesheet'] = bzcompress($r['stylesheet'], 9);
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
                break;

            case 'delete':
                break;
            
            default:
                break;
        }
    }
}
