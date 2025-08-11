<?php

namespace Kyte\Mvc\Controller;

class FunctionController extends ModelController
{
    // public function hook_init() {}

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $switch_stmt = "\tswitch (\$method) {\r\n\t\tcase 'new':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'update':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'get':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'delete':\r\n\t\t\tbreak;\r\n\r\n\t\tdefault:\r\n\t\t\tbreak;\r\t}\r\n";
                $code = '';
                if ($r['type'] == 'hook_init') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Hook of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function hook_init() {\r\n\t\r\n}\r\n";
                }
                if ($r['type'] == 'hook_auth') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Hook of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function hook_auth() {\r\n\t\r\n}\r\n";
                }
                if ($r['type'] == 'hook_prequery') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Hook of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function hook_prequery(\$method, &\$field, &\$value, &\$conditions, &\$all, &\$order) {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_preprocess') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Hook of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function hook_preprocess(\$method, &\$r, &\$o = null) {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_response_data') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Hook of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function hook_response_data(\$method, \$o, &\$r = null, &\$d = null) {\r\n$switch_stmt}\r\n";
                }
                if ($r['type'] == 'hook_process_get_response') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Hook of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function hook_process_get_response(&\$r) {\r\n\r\n}\r\n";
                }
                if ($r['type'] == 'new') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Override of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function new(\$data) {\r\n\r\n}\r\n";
                }
                if ($r['type'] == 'update') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Override of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function update(\$field, \$value, \$data) {\r\n\r\n}\r\n";
                }
                if ($r['type'] == 'get') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Override of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function get(\$field, \$value) {\r\n\r\n}\r\n";
                }
                if ($r['type'] == 'delete') {
                    $f = new \Kyte\Core\Model(constant("Function"));
                    $f->retrieve('controller', $r['controller'], false, [['field' => 'type', 'value' => $r['type']]]);
                    if ($f->count() > 0) {
                        throw new \Exception('Override of type '.$r['type'].' already exists for this controller.');
                    }
                    $code = "public function delete(\$field, \$value) {\r\n\r\n}\r\n";
                }
                $r['code'] = bzcompress($code, 9);
                break;
            case 'update':
                $r['code'] = bzcompress($r['code'], 9);
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
            case 'get':
                $r['code'] = bzdecompress($r['code']);
                break;
            case 'update':
                $ctrl = new \Kyte\Core\ModelObject(constant("Controller"));
                if (!$ctrl->retrieve("id", $o->controller)) {
                    throw new \Exception("Unable to find specified controller.");
                }

                // update code base and save to file
                ControllerController::generateCodeBase($ctrl);

                $r['code'] = bzdecompress($r['code']);
                break;
            case 'delete':                
                $ctrl = new \Kyte\Core\ModelObject(constant("Controller"));
                if (!$ctrl->retrieve("id", $o->controller)) {
                    throw new \Exception("Unable to find specified controller.");
                }

                // update code base and save to file
                ControllerController::generateCodeBase($ctrl);
                break;            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
