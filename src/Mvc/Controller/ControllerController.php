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
                    $model = new \Kyte\Core\ModelObject(DataModel);
                    if (!$model->retrieve("id", $r['dataModel'])) {
                        throw new \Exception("Unable to find specified data model.");
                    }

                    $functions[] = "\tpublic function shipyard_init() {\r\n$\t\$this->model = {$model->name};\r\n}\r\n";
                }

                // create code base
                $code = self::generateCodeBase($r['name'].'Controller', $functions);

                if (file_put_contents("/var/www/html/app/controllers/{$r['name']}Controller.php", $code) === false) {
                    throw new \Exception("Failed to create controller code! Squawk 7700!");
                }

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
                    $model = new \Kyte\Core\ModelObject(DataModel);
                    if (!$model->retrieve("id", $r['dataModel'])) {
                        throw new \Exception("Unable to find specified data model.");
                    }

                    $functions[] = "\tpublic function shipyard_init() {\r\n\t\t\$this->model = {$model->name};\r\n\t}\r\n";
                }

                // regenerate code base with new name and/or model
                $fs = new \Kyte\Core\Model(ControllerFunction);
                $fs->retrieve("controller", $o->id);
                foreach($fs->objects as $fc) {
                    $f = new \Kyte\Core\ModelObject(constant("Function"));
                    if (!$f->retrieve("id", $fc->function)) {
                        throw new \Exception("Unable to find associated function.");
                    }
                    $functions[] = $f->code;
                }

                // update code base
                $code = self::generateCodeBase($r['name'].'Controller', $functions);

                if ($o->name != $r['name']) {
                    // remove old definition
                    if (!unlink("/var/www/html/app/models/{$o->name}.php")) {
                        error_log("Failed to clean up old model /var/www/html/app/models/{$o->name}.php");
                    }
                }

                if (file_put_contents("/var/www/html/app/controllers/{$r['name']}Controller.php", $code) === false) {
                    throw new \Exception("Failed to create controller code! Squawk 7700!");
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

        return $code;
    }
}
