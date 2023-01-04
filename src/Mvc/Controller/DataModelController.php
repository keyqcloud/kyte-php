<?php

namespace Kyte\Mvc\Controller;

class DataModelController extends ModelController
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
                if (defined($r['name'])) {
                    throw new \Exception("Model name is already used");
                }

                // create base definition
                $base_model = [ 'name' => $r['name'], 'struct' => [] ];
                \Kyte\Core\Api::addPrimaryKey($base_model);
                \Kyte\Core\Api::addKyteAttributes($base_model);

                // create new roles
                $roles = new \Kyte\Core\Model(Role);
                $roles->retrieve();
                foreach ($roles->objects as $role) {
                    foreach (['new', 'update', 'get', 'delete'] as $actionType) {
                        $permission = new \Kyte\Core\ModelObject(Permission);
                        if (!$permission->create([
                            'role'  => $role->id,
                            'model' => $r['name'],
                            'action' => $actionType,
                            'kyte_account' => $role->kyte_account,
                        ])) {
                            throw new \Exception("Failed to create permissions for new model! Squawk 7700!");
                        }
                    }
                }

                // switch dbs and create table
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['application'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                \Kyte\Core\Api::dbswitch($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::createTable($base_model)) {
                    throw new \Exception("Failed to create table...");
                }

                if (file_put_contents("/var/www/html/app/models/{$r['name']}.php", "<?php\n\${$r['name']} = " . var_export($base_model, true) . ";\r\n") === false) {
                    if (!\Kyte\Core\DBI::dropTable($r['name'])) {
                        throw new \Exception("Failed to undo create table...we are in big trouble! Squawk 7700!");
                    }
                }

                // return to kyte db
                \Kyte\Core\Api::dbconnect();
                break;

            case 'update':
                // check if model is defined
                if (!defined($o->name)) {
                    throw new \Exception("Unknown model definition");
                }

                $updatedModel = constant($o->name);

                if ($o->name != $r['name']) {
                    // check if new name is unique
                    if (defined($r['name'])) {
                        throw new \Exception("New model name is already used");
                    }

                    // update permissions
                    $perms = new \Kyte\Core\Model(Permission);
                    $perms->retrieve("model", $o->name);
                    foreach($perms->objects as $perm) {
                        $perm->save([ "model" => $r['name'] ]);
                    }

                    // switch dbs and create table
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $o->application)) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                    }
                    \Kyte\Core\Api::dbswitch($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                    // alter table <old_table_name> rename to <new_table_name>
                    if (!\Kyte\Core\DBI::renameTable($o->name, $r['name'])) {
                        throw new \Exception("Failed to rename table");
                    }

                    $updatedModel['name'] = $r['name'];

                    // remove old definition
                    if (!unlink("/var/www/html/app/models/{$o->name}.php")) {
                        error_log("Failed to clean up old model /var/www/html/app/models/{$o->name}.php");
                    }
                }

                // update model definition
                if (file_put_contents("/var/www/html/app/models/{$r['name']}.php", "<?php\n\${$r['name']} = " . var_export($updatedModel, true) . ";") === false) {
                    throw new \Exception("Unable to update model definition! Squawk 7700!");
                }

                // return to kyte db
                \Kyte\Core\Api::dbconnect();
                break;                

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                // delete model attributes
                $attrs = new \Kyte\Core\Model(ModelAttribute);
                $attrs->retrieve("dataModel", $o->id);
                foreach($attrs->objects as $attr) {
                    $attr->delete();
                }

                // delete perms
                $perms = new \Kyte\Core\Model(Permission);
                $perms->retrieve("model", $o->name);
                foreach($perms->objects as $perm) {
                    $perm->delete();
                }

                // delete controllers and remove function association
                // $controllers = new \Kyte\Core\Model(Controller);
                // $controllers->retrieve('dataModel', $o->id);
                // foreach($controllers->objects as $controller) {
                //     $ctrl = new ControllerController(Controller, APP_DATE_FORMAT, $this->account, $this->session, $this->user, $this->response, $this->page_size, $this->page_total, $this->page_num, $this->total_count, $this->total_filtered);

                //     $ctrl->delete('id', $controller->id);
                // }

                // TODO: consider situation where there are external tables and foreign keys

                // switch dbs and create table
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $o->application)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                \Kyte\Core\Api::dbswitch($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);

                // drop table <table_name>
                if (!\Kyte\Core\DBI::dropTable($o->name)) {
                    throw new \Exception("Failed to drop table");
                }

                if (!unlink("/var/www/html/app/models/{$o->name}.php")) {
                    error_log("Failed to clean up old model /var/www/html/app/models/{$o->name}.php");
                }
                
                // return to kyte db
                \Kyte\Core\Api::dbconnect();
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
