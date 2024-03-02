<?php

namespace Kyte\Mvc\Controller;

class DataModelController extends ModelController
{
    // public function hook_init() {}

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

        // password
        if ($o->password == 1) {
            $attrs['password'] = true;
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
                $base_model['struct'][$attr->name] = self::prepareModelDef($attr);
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
                    throw new \Exception("Model name conflicts with existing model or system model name.");
                }

                // check if model already exists for this particular application
                $existModel = new \Kyte\Core\ModelObject(DataModel);
                if ($existModel->retrieve('name', $r['name'], [['field' => 'application', 'value' => $r['application']]])) {
                    throw new \Exception("Model already exists.");
                }

                // create base definition
                $model_definition = self::generateModelDef($r['name']);
                $r['model_definition'] = json_encode($model_definition);

                // switch dbs
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['application'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                \Kyte\Core\Api::dbappconnect($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                \Kyte\Core\Api::dbswitch(true);
                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::createTable($model_definition)) {
                    throw new \Exception("Failed to create table...");
                }

                // return to kyte db
                \Kyte\Core\Api::dbswitch();
                break;

            case 'update':
                // check if model is defined
                if (!defined($o->name)) {
                    throw new \Exception("Unknown model definition");
                }

                if ($o->name != $r['name']) {
                    // check if new name is unique
                    if (defined($r['name'])) {
                        throw new \Exception("New model name conflicts with existing model or system model name.");
                    }

                    // check if model already exists for this particular application
                    $existModel = new \Kyte\Core\ModelObject(DataModel);
                    if ($existModel->retrieve('name', $r['name'], [['field' => 'application', 'value' => $r['application']]])) {
                        throw new \Exception("New model name is already in use.");
                    }

                    // switch dbs
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $o->application)) {
                        throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                    }
                    \Kyte\Core\Api::dbappconnect($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                    \Kyte\Core\Api::dbswitch(true);
                    // alter table <old_table_name> rename to <new_table_name>
                    if (!\Kyte\Core\DBI::renameTable($o->name, $r['name'])) {
                        throw new \Exception("Failed to rename table");
                    }

                    $model_definition = self::generateModelDef($r['name'], $o->id);;
                    $r['model_definition'] = json_encode($model_definition);
                }

                // return to kyte db
                \Kyte\Core\Api::dbswitch();
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
                \Kyte\Core\Api::dbappconnect($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                \Kyte\Core\Api::dbswitch(true);

                // drop table <table_name>
                if (!\Kyte\Core\DBI::dropTable($o->name)) {
                    throw new \Exception("Failed to drop table");
                }
                
                // return to kyte db
                \Kyte\Core\Api::dbswitch();
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
