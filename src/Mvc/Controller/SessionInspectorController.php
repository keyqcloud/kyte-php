<?php

namespace Kyte\Mvc\Controller;

class SessionInspectorController extends ModelController
{
    public function hook_init() {
        $this->requireAccount = false;
        $this->dateformat = 'm/d/Y H:i:s';
        $this->model = Session;
    }
    // public function hook_auth() {}
    // public function hook_response_data($method, $o, &$r = null, &$d = null) {}
    // public function hook_process_get_response(&$r) {}
    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        switch ($method) {
            case 'get':
                if ($field == 'app_idx') {
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $value)) {
                        throw new \Exception("Failed to retrieve application id.");
                    }
                    $field = 'appIdentifier';
                    $value = $app->identifier;
                }
                $query = [];
                if (isset($this->api->appId) && strlen($this->api->appId) > 0) {
                    $query[] = ['field'=>'appIdentifier', 'value'=>$this->api->appId];
                }
                if (count($query) > 0){
                    if (is_array($conditions)) {
                        $conditions = array_merge($conditions, $query); // Add $query to $conditions array
                    } else {
                        $conditions = $query; // Set $conditions to an array containing $query
                    }
                }
                break;
            
            default:
                throw new \Exception("Unauthorized request method made.");
                break;
        }
    }

    /* Only allow retrieval of logs */
    // new
    public function new($data)
    {
        throw new \Exception("Unauthorized request method.");
    }

    // update
    public function update($field, $value, $data)
    {
        throw new \Exception("Unauthorized request method.");
    }

    // get - validate session
    public function delete($field, $value)
    {
        throw new \Exception("Unauthorized request method.");
    }
    
}
