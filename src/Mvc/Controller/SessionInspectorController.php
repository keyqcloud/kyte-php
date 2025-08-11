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
    // public function hook_process_get_response(&$r) {}
    // public function hook_preprocess($method, &$r, &$o = null) {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        switch ($method) {
            case 'get':
                if ($field == 'app_idx') {
                    $this->api->app = new \Kyte\Core\ModelObject(Application);
                    if (!$this->api->app->retrieve('id', $value)) {
                        throw new \Exception("Failed to retrieve application id.");
                    }
                    $field = 'appIdentifier';
                    $value = $this->api->app->identifier;

                    // load app models
                    \Kyte\Core\Api::loadAppModels($this->api->app);
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

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                if (strlen($o->appIdentifier) > 0 && $this->api->app !== null) {
                    if (defined($this->api->app->user_model)) {
                        \Kyte\Core\Api::dbappconnect($this->api->app->db_name, $this->api->app->db_username, $this->api->app->db_password);
                        $user = new \Kyte\Core\ModelObject(constant($this->api->app->user_model));
                        if ($user->retrieve('id', $o->uid)) {
                            $r['user_email'] = $user->{$this->api->app->username_colname};
                        } else {
                            $r['user_email'] = '[failed to retrieve user]';
                        }
                    } else {
                        // skip this since we are not going to resolve app-space user model
                        $r['user_email'] = '';
                    }
                } else if (strlen($o->appIdentifier) == 0) {
                    $user = new \Kyte\Core\ModelObject(KyteUser);
                    if ($user->retrieve('id', $o->uid)) {
                        $r['user_email'] = $user->email;
                    } else {
                        $r['user_email'] = '[failed to retrieve]';
                    }
                } else {
                    $r['user_email'] = '';
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
