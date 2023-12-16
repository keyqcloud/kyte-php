<?php

namespace Kyte\Mvc\Controller;

class ControllerController extends ModelController
{
    // public function hook_init() {
    //     $this->checkExisting = 'name';
    // }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $existingController = new \Kyte\Core\ModelObject(constant('Controller'));
                if ($existingController->retrieve('name', $r['name'], [['field' => 'application', 'value' => $r['application']]])) {
                    throw new \Exception("Controller name already exists in application scope.");
                }
                // check if new name is unique
                if (class_exists('\\Kyte\Mvc\\Controller\\'.$r['name'].'Controller')) {
                    throw new \Exception("Controller name already in use by Kyte core API.");
                }
                if (class_exists($r['name'].'Controller')){
                    throw new \Exception("Custom controller name already in use.");
                }
                break;

            case 'update':
                if ($o->name != $r['name']) {
                    $existingController = new \Kyte\Core\ModelObject(constant('Controller'));
                    if ($existingController->retrieve('name', $r['name'], [['field' => 'application', 'value' => $r['application']]])) {
                        throw new \Exception("New controller name already exists in application scope.");
                    }
                    // check if new name is unique
                    if (class_exists('\\Kyte\Mvc\\Controller\\'.$r['name'].'Controller')) {
                        throw new \Exception("Controller name already in use by Kyte core API.");
                    }
                    if (class_exists($r['name'].'Controller')){
                        throw new \Exception("Custom controller name already in use.");
                    }
                }
                break;                

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
            case 'update':
                // update code base and save to file
                self::generateCodeBase($o);
                break;
            case 'delete':
                // delete corresponding function association
                $fs = new \Kyte\Core\Model(constant("Function"));
                $fs->retrieve("controller", $o->id);
                foreach($fs->objects as $f) {
                    $f->delete();
                }
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}

    public static function generateCodeBase($controller) {
        $functions = [];

        // check if model is specified, if so generate shipyard_init()
        if (!empty($controller->dataModel)) {
            $model = new \Kyte\Core\ModelObject(DataModel);
            if (!$model->retrieve("id", $controller->dataModel)) {
                throw new \Exception("Unable to find specified data model.");
            }
            $functions[] = "\tpublic function shipyard_init() {\r\n\t\t\$this->model = {$model->name};\r\n\t}\r\n";
        }

        $fs = new \Kyte\Core\Model(constant("Function"));
        $fs->retrieve("controller", $controller->id);
        foreach($fs->objects as $f) {
            $functions[] = bzdecompress($f->code);
        }

        $code = "class {$controller->name}Controller extends \Kyte\Mvc\Controller\ModelController\r\n{\r\n";

        if (is_array($functions)) {
            foreach($functions as $function) {
                $code .= "\r\n$function\r\n";
            }
        }

        $code .= "}\r\n";

        $controller->save([
            'code' => bzcompress($code, 9),
        ]);
    }
}
