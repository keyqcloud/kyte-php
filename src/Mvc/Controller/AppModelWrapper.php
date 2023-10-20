<?php

namespace Kyte\Mvc\Controller;

class AppModelWrapperController extends ModelController
{
    public function hook_init() {
        $this->requireRoles = false;
    }

    // public function hook_auth() {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        error_log("Get data info!");
        $appModel = new \Kyte\Core\ModelObject(DataModel);
        if (!$appModel->retrieve('application', $field, [['field' => 'name', 'value' => $value]])) {
            throw new \Exception('Unable to find model for specified application.');
        }

        $this->api->app = new \Kyte\Core\ModelObject(Application);
        if (!$this->api->app->retrieve('identifier', $field)) {
            throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch for app ID {$field}.");
        }

        error_log("Loading new models");
        // load app specific models
        \Kyte\Core\Api::loadAppModelsAndControllers($field);

        // specify model of this wrapper controller
        $this->model = constant($value);

        error_log("PREPARING DB SWITCH!");
        \Kyte\Core\Api::dbappconnect($this->api->app->db_name, $this->api->app->db_username, $this->api->app->db_password);
    }

    // public function hook_preprocess($method, &$r, &$o = null) {}

    // public function hook_response_data($method, $o, &$r = null, &$d = null) {}

    // public function hook_process_get_response(&$r) {}
}
