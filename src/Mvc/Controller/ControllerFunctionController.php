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
                    throw new \Exception("Unable to find specified data model.");
                }
                $r['type'] = $func->type;

                break;        

            default:
                break;
        }
    }
}
