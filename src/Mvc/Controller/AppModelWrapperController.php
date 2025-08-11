<?php

namespace Kyte\Mvc\Controller;

class AppModelWrapperController extends ModelController
{
    public function hook_init()
    {
        if (!isset($_SERVER['HTTP_X_KYTE_APP_ID'])) {
            throw new \Exception('Application ID must be provided.');
        }
        
        if (!isset($_SERVER['HTTP_X_KYTE_APP_MODEL'])) {
            throw new \Exception('Application model name must be provided.');
        }

        $this->api->app = new \Kyte\Core\ModelObject(Application);
        if (!$this->api->app->retrieve('id', $_SERVER['HTTP_X_KYTE_APP_ID'])) {
            throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch for app ID {$_SERVER['HTTP_X_KYTE_APP_ID']}.");
        }

        $appModel = new \Kyte\Core\ModelObject(DataModel);
        if (!$appModel->retrieve('application', $this->api->app->id, [['field' => 'name', 'value' => $_SERVER['HTTP_X_KYTE_APP_MODEL']]])) {
            throw new \Exception('Unable to find model for specified application.');
        }

        $this->requireAccount = false;

        // load app specific models
        \Kyte\Core\Api::loadAppModels($this->api->app);

        // specify model of this wrapper controller
        $this->model = constant($appModel->name);

        \Kyte\Core\Api::dbappconnect($this->api->app->db_name, $this->api->app->db_username, $this->api->app->db_password);
    }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    // public function hook_preprocess($method, &$r, &$o = null) {}

    // public function hook_response_data($method, $o, &$r = null, &$d = null) {}

    // public function hook_process_get_response(&$r) {}
}
