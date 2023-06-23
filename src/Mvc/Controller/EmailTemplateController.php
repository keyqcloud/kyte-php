<?php

namespace Kyte\Mvc\Controller;

class EmailTemplate extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['identifier'] = $bucketName = strtolower(preg_replace('/[^A-Za-z0-9_.-\/]/', '_', $r['identifier']));
                break;

            default:
                break;
        }
    }

    // public function hook_response_data($method, $o, &$r = null, &$d = null) {}

    // public function hook_process_get_response(&$r) {}
}
