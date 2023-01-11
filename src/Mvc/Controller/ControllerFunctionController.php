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
                if ($method == 'delete') { $r = false; $o->delete(); }

                $functions = [];

                // check if model is specified
                if (!empty($r['controller']['dataModel'])) {
                    $functions[] = ControllerController::generateShipyardInit($r['controller']['dataModel']);
                }

                // regenerate code base with new name and/or model
                ControllerController::prepareFunctionStatements($r['controller']['id'], $functions);

                // update code base and save to file
                ControllerController::generateCodeBase($r['controller']['application']['identifier'], $r['controller']['name'].'Controller', $functions);

                break;
            
            default:
                break;
        }
    }
}
