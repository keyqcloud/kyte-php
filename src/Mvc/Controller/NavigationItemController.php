<?php

namespace Kyte\Mvc\Controller;

class NavigationItemController extends ModelController
{
    // public function hook_init() {}

    // public function hook_auth() {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        $order = [
            ['field' => 'center', 'direction' => 'desc'],
            ['field' => 'itemOrder', 'direction' => 'asc'],
        ];
    }

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'update':
                if ($r['parentItem'] == $o->parentItem) {
                    $r['parentItem'] = 0;   // let's soft fail if the parent item is the same as self to avoid recurssion
                    error_log('Prevent potential recurssion for menu item '.$o->parentItem);
                }
                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
            case 'new':
            case 'update':
                if (isset($r['page'], $r['page']['header'], $r['page']['footer'])) {
                    $r['page']['header'] = '';
                    $r['page']['footer'] = '';
                }
                break;
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
