<?php

namespace Kyte\Mvc\Controller;

class FunctionController extends ModelController
{
    public function hook_init() {
        $this->allowableActions = ['new', 'update', 'get', 'delete'];
    }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $switch_stmt = "\tswitch (\$method) {\r\n\t\tcase 'new':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'update':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'get':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'delete':\r\n\t\t\tbreak;\r\n\r\n\t\tdefault:\r\n\t\t\tbreak;\r\n\t}\r\n";

                if ($r['type'] == 'hook_init') {
                    $r['code'] = "public function hook_init() {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_auth') {
                    $r['code'] = "public function hook_auth() {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_prequery') {
                    $r['code'] = "public function hook_prequery(\$method, &\$field, &\$value, &\$conditions, &\$all, &\$order) {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_preprocess') {
                    $r['code'] = "public function hook_preprocess(\$method, &\$r, &\$o = null) {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_response_data') {
                    $r['code'] = "public function hook_response_data(\$method, \$o, &\$r = null, &\$d = null) {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_process_get_response') {
                    $r['code'] = "public function hook_process_get_response(&\$r) {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'new') {
                    $r['code'] = "public function new(\$data) {\r\n\r\n}\r\n";
                }
                if ($r['type'] == 'update') {
                    $r['code'] = "public function update(\$field, \$value, \$data) {\r\n\r\n}\r\n";
                }
                if ($r['type'] == 'get') {
                    $r['code'] = "public function get(\$field, \$value) {\r\n\r\n}\r\n";
                }
                if ($r['type'] == 'delete') {
                    $r['code'] = "public function delete(\$field, \$value) {\r\n\r\n}\r\n";
                }
                break;         

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
