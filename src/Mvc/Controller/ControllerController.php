<?php

namespace Kyte\Mvc\Controller;

class ControllerController extends ModelController
{
    public function hook_init() {
        $this->checkExisting = 'name';
    }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // check if new name is unique
                if (class_exists('\\Kyte\Mvc\\Controller\\'.$r['name'].'Controller')) {
                    throw new \Exception("Controller name already in use by Kyte core API.");
                }
                if (class_exists($r['name'].'Controller')){
                    throw new \Exception("Custom controller name already in use.");
                }

                $functions = [];
                // check if model is specified
                if (!empty($r['dataModel'])) {
                    $functions[] = self::generateShipyardInit($r['dataModel']);
                }

                // create code base and save to file
                self::generateCodeBase($r['name'].'Controller', $functions);

                break;

            case 'update':
                if ($o->name != $r['name']) {
                    // check if new name is unique
                    if (class_exists('\\Kyte\Mvc\\Controller\\'.$r['name'].'Controller')) {
                        throw new \Exception("Controller name already in use by Kyte core API.");
                    }
                    if (class_exists($r['name'].'Controller')){
                        throw new \Exception("Custom controller name already in use.");
                    }
                }

                $functions = [];

                // check if model is specified
                if (!empty($r['dataModel'])) {
                    $functions[] = self::generateShipyardInit($r['dataModel']);
                }

                // regenerate code base with new name and/or model
                self::prepareFunctionStatements($o->id, $functions);

                // update code base and save to file
                self::generateCodeBase($r['name'].'Controller', $functions);

                if ($o->name != $r['name']) {
                    // remove old definition
                    if (!unlink("/var/www/html/app/controllers/{$o->name}Controller.php")) {
                        error_log("Failed to clean up old model /var/www/html/app/controllers/{$o->name}Controller.php");
                    }
                }

                break;                

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                // delete corresponding function association
                $fs = new \Kyte\Core\Model(ControllerFunction);
                $fs->retrieve("controller", $o->id);
                foreach($fs->objects as $fc) {
                    $fc->delete();
                }

                if (!unlink("/var/www/html/app/controllers/{$o->name}Controller.php")) {
                    error_log("Failed to clean up old model /var/www/html/app/controllers/{$o->name}Controller.php");
                }

                // delete associated settings
                $settings = new \Kyte\Core\Model(ControllerSetting);
                $settings->retrieve("controller", $o->id);
                foreach($settings->objects as $setting) {
                    $setting->delete();
                }
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}

    public static function generateShipyardInit($model_idx) {
        if (empty($model_idx)) {
            throw new \Exception("Model id cannot be empty.");
        }
        $model = new \Kyte\Core\ModelObject(DataModel);
        if (!$model->retrieve("id", $model_idx)) {
            throw new \Exception("Unable to find specified data model.");
        }

        return "\tpublic function shipyard_init() {\r\n\t\t\$this->model = {$model->name};\r\n\t}\r\n";
    }

    public static function prepareFunctionStatements($controller_idx, &$functions) {
        if (empty($controller_idx)) {
            throw new \Exception("Controller id cannot be empty.");
        }

        $fs = new \Kyte\Core\Model(ControllerFunction);
        $fs->retrieve("controller", $controller_idx);
        foreach($fs->objects as $fc) {
            $f = new \Kyte\Core\ModelObject(constant("Function"));
            if (!$f->retrieve("id", $fc->function)) {
                throw new \Exception("Unable to find associated function.");
            }
            $functions[] = $f->code;
        }
    }

    public static function generateCodeBase($controller_name, $functions = null) {
        if (empty($controller_name)) {
            throw new \Exception("Controller name cannot be empty.");
        }

        $code = "<?php\r\n\r\nclass $controller_name extends \Kyte\Mvc\Controller\ModelController\r\n{\r\n";

        if (is_array($functions)) {
            foreach($functions as $function) {
                $code .= "\r\n$function\r\n";
            }
        }

        $code .= "}\r\n";

        if (file_put_contents("/var/www/html/app/controllers/{$controller_name}Controller.php", $code) === false) {
            throw new \Exception("Failed to create controller code! Squawk 7700!");
        }
    }
}
