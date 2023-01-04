<?php

namespace Kyte\Mvc\Controller;

class DataModelController extends ModelController
{
    public function hook_init() {
        $this->checkExisting = 'name';
    }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public static function prepareModelDef($o) {
        $attrs = [
            'type'      => $o->type == 'date' ? 'i' : $o->type,
            'date'      => $o->type == 'date',
            'required'  => $o->required == 1,
        ];

        // size
        if (!empty($o->size)) {
            $attrs['size'] = $o->size;
        }

        // unsigned
        if ($o->unsigned == 1) {
            $attrs['unsigned'] = true;
        }

        // protected
        if ($o->protected == 1) {
            $attrs['protected'] = true;
        }

        // defaults
        if (strlen($o->defaults) > 0) {
            $attrs['default'] = $o->defaults;
        }

        // foreign key
        // if (!empty($r['foreignKeyModel']) && !empty($r['foreignKeyAttribute'])) {
        if (!empty($o->foreignKeyModel)) {

            // get table and attribute info
            $fk_tbl = new \Kyte\Core\ModelObject(DataModel);
            if (!$fk_tbl->retrieve('id', $o->foreignKeyModel)) {
                throw new \Exception("Unable to find data model for foreign key definition");
            }
            // $fk_attr = new \Kyte\Core\ModelObject(ModelAttribute);
            // if (!$fk_attr->retrieve('id', $r['foreignKeyAttribute'])) {
            //     throw new \Exception("Unable to find attribute for data model {$fk_tbl->name} for foreign key definition");
            // }

            $attrs['fk'] = [
                'model' => $fk_tbl->name,
                // 'field' => $fk_attr->name,
                'field' => 'id',
            ];
        }

        return $attrs;
    }

    public static function generateModelDef($name, $id = null) {
        $base_model = [ 'name' => $name, 'struct' => [] ];

        if ($id != null) {
            // iterate through attributes
            $attrs = new \Kyte\Core\Model(ModelAttribute);
            $attrs->retrieve("dataModel", $id);
            foreach($attrs->objects as $attr) {
                $base_model['struct'][] = self::prepareModelDef($attr);
            }
        }

        \Kyte\Core\Api::addPrimaryKey($base_model);
        \Kyte\Core\Api::addKyteAttributes($base_model);

        return $base_model;
    }

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // check if new name is unique
                if (defined($r['name'])) {
                    throw new \Exception("Model name is already used");
                }

                // create base definition
                $r['model_definition'] = self::generateModelDef($r['name']);

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

                // switch dbs
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['application'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                \Kyte\Core\Api::dbswitch($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::createTable($base_model)) {
                    throw new \Exception("Failed to create table...");
                }

                // return to kyte db
                \Kyte\Core\Api::dbconnect();
                break;

            case 'update':
                // check if model is defined
                if (!defined($o->name)) {
                    throw new \Exception("Unknown model definition");
                }

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

                    // switch dbs
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $o->application)) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                    }
                    \Kyte\Core\Api::dbswitch($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                    // alter table <old_table_name> rename to <new_table_name>
                    if (!\Kyte\Core\DBI::renameTable($o->name, $r['name'])) {
                        throw new \Exception("Failed to rename table");
                    }

                    $r['model_definition'] = self::generateModelDef($r['name'], $o->id);
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

                // switch dbs
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $o->application)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                \Kyte\Core\Api::dbswitch($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);

                // drop table <table_name>
                if (!\Kyte\Core\DBI::dropTable($o->name)) {
                    throw new \Exception("Failed to drop table");
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
