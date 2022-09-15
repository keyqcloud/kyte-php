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
