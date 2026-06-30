<?php

namespace Kyte\Mvc\Controller;

class DataModelController extends ModelController
{
    // public function hook_init() {}

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public static function prepareModelDef($o) {
        // Optional flags/values may be absent when the caller sends a partial
        // attribute payload (e.g. the MCP schema tools, which only set the
        // fields they care about). Read them defensively so a missing property
        // doesn't emit an undefined-property warning or a null-to-strlen
        // deprecation; the dashboard form still sends the full set.
        $attrs = [
            'type'      => $o->type == 'date' ? 'i' : $o->type,
            'date'      => $o->type == 'date',
            'required'  => isset($o->required) && $o->required == 1,
        ];

        // size
        if (!empty($o->size)) {
            $attrs['size'] = $o->size;
        }

        // decimal precision/scale — only meaningful for the `d` type, where
        // DBI::buildFieldDefinition needs both to emit decimal(precision,scale).
        if ($o->type == 'd') {
            if (!isset($o->precision) || !isset($o->scale) || $o->precision === '' || $o->scale === '') {
                throw new \Exception("Decimal attribute requires both precision and scale.");
            }
            $attrs['precision'] = $o->precision;
            $attrs['scale'] = $o->scale;
        }

        // unsigned
        if (!empty($o->unsigned)) {
            $attrs['unsigned'] = true;
        }

        // protected
        if (!empty($o->protected)) {
            $attrs['protected'] = true;
        }

        // password
        if (!empty($o->password)) {
            $attrs['password'] = true;
        }

        // defaults
        if (isset($o->defaults) && strlen((string)$o->defaults) > 0) {
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

                // Refuse to alter a kyte-locked model (system/critical model).
                // Guard before any DB switch or DDL so a locked row never
                // reaches the app database.
                if ((int)$o->kyte_locked === 1) {
                    throw new \Exception("Model '{$o->name}' is locked and cannot be modified.");
                }

                // Only run the table-rename path when the request actually
                // carries a (different) name. A metadata-only partial PUT —
                // e.g. the Settings tab toggling `sensitive` — omits `name`,
                // so `$o->name != $r['name']` would be true against a null and
                // wrongly trigger renameTable($o->name, null), which throws
                // "New table name cannot be empty" and aborts the save before
                // `sensitive` is ever persisted.
                if (isset($r['name']) && $o->name != $r['name']) {
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
                // Refuse to drop a kyte-locked model (system/critical model).
                // Guard before any cascade delete, DB switch, or DROP TABLE.
                if ((int)$o->kyte_locked === 1) {
                    throw new \Exception("Model '{$o->name}' is locked and cannot be deleted.");
                }

                // FK-dependency guard: refuse to drop a model that another
                // model's attribute still references via foreignKeyModel.
                // Account-scoped; the model's own attributes are excluded since
                // they are deleted along with it.
                $dependents = new \Kyte\Core\Model(ModelAttribute);
                $dependents->retrieve('foreignKeyModel', $o->id, false, [['field' => 'kyte_account', 'value' => $this->api->account->id]]);
                $blockingNames = [];
                foreach ($dependents->objects as $dep) {
                    if ($dep->dataModel == $o->id) {
                        continue;
                    }
                    $depModel = new \Kyte\Core\ModelObject(DataModel);
                    $depModelName = $depModel->retrieve('id', $dep->dataModel) ? $depModel->name : "model #{$dep->dataModel}";
                    $blockingNames[] = "{$depModelName}.{$dep->name}";
                }
                if (!empty($blockingNames)) {
                    throw new \Exception("Cannot delete model '{$o->name}': it is referenced by foreign key(s) " . implode(', ', $blockingNames) . ". Remove the referencing attribute(s) first.");
                }

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

                // Cross-model foreign-key references are guarded above; any
                // remaining external-table cleanup is the caller's responsibility.

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

                // Invalidate the cached model struct for this app — the model is
                // gone, so the next request must not load it from the stale cache.
                \Kyte\Core\Api::clearModelCache($o->application);
                break;

            case 'new':
            case 'update':
                // A create or rename changed this app's model set / a struct name;
                // flush the cached struct so the next request loads fresh rather
                // than serving the stale cache for up to its TTL.
                \Kyte\Core\Api::clearModelCache($o->application);
                break;

            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
