<?php

namespace Kyte\Mvc\Controller;

class ControllerFunctionController extends ModelController
{
    public function hook_init() {
        $this->allowableActions = ['new', 'get', 'delete'];
    }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // get the function and populate the type
                $func = new \Kyte\Core\ModelObject(constant("Function"));
                if (!$func->retrieve("id", $r['function'])) {
                    throw new \Exception("Unable to find specified function.");
                }
                $r['type'] = $func->type;

                break;        

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
            case 'delete':
                $ctrl = new \Kyte\Core\ModelObject(constant("Controller"));
                if (!$ctrl->retrieve("id", $o->controller)) {
                    throw new \Exception("Unable to find specified controller.");
                }

                if ($method == 'delete') { $r = false; $o->delete(); }

                $functions = [];

                // check if model is specified
                if (!empty($ctrl->dataModel)) {
                    $functions[] = ControllerController::generateShipyardInit($ctrl->dataModel);
                }

                // regenerate code base with new name and/or model
                ControllerController::prepareFunctionStatements($ctrl->id, $functions);

                // update code base and save to file
                ControllerController::generateCodeBase($ctrl->name.'Controller', $functions);

                break;
            
            default:
                break;
        }
    }
}
