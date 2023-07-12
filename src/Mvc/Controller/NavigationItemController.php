<?php

namespace Kyte\Mvc\Controller;

class NavigationItemController extends ModelController
{
    // public function hook_init() {}

    // public function hook_auth() {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        $order = [
            ['field' => 'itemOrder', 'direction' => 'asc'],
        ];
    }

    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                if ($method == 'delete') {
                    $navitem = $this->getObject($o);
                    $r = false;
                    $o->delete(null, null, $this->user->id);
                }
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
