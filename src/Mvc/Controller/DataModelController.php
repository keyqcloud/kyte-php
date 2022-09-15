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

                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::createTable($base_model)) {
                    throw new \Exception("Failed to create table...");
                }

                if (file_put_contents("/var/www/html/app/models/{$r['name']}.php", "<?php\n\${$r['name']} = " . var_export($base_model, true) . ";") === false) {
                    if (!\Kyte\Core\DBI::dropTable($r['name'])) {
                        throw new \Exception("Failed to undo create table...we are in big trouble! Squawk 7700!");
                    }
                }

                // create new roles
                $roles = new \Kyte\Core\Model(Role);
                $roles->retrieve("name", "Administrator");
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

                break;

            case 'update':
                // check if model is defined
                if (defined($o->name)) {
                    throw new \Exception("Unknown model definition");
                }

                // check if new name is unique
                if (defined($r['name'])) {
                    throw new \Exception("New model name is already used");
                }

                // alter table <old_table_name> rename to <new_table_name>
                if (!\Kyte\Core\DBI::renameTable($o->name, $r['name'])) {
                    throw new \Exception("Failed to rename table");
                }

                $updatedModel = constant($o->name);
                $updatedModel['name'] = $r['name'];

                if (file_put_contents("/var/www/html/app/models/{$r['name']}.php", "<?php\n\${$r['name']} = " . var_export($updatedModel, true) . ";") === false) {
                    if (!\Kyte\Core\DBI::renameTable($r['name'], $o->name)) {
                        throw new \Exception("Failed to undo rename...we are in big trouble! Squawk 7700!");
                    }
                }
                // remove old definition
                // update model definition
                if (!unlink("/var/www/html/app/models/{$o->name}.php")) {
                    error_log("Failed to clean up old model /var/www/html/app/models/{$o->name}.php");
                }

                // update permissions
                $perms = new \Kyte\Core\Model(Permission);
                $perms->retrieve("model", $o->name);
                foreach($perms->objects as $perm) {
                    $perm->save([ "model" => $r['name'] ]);
                }
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

                // TODO: consider situation where there are external tables and foreign keys

                // drop table <table_name>
                if (!\Kyte\Core\DBI::dropTable($o->name)) {
                    throw new \Exception("Failed to drop table");
                }

                if (!unlink("/var/www/html/app/models/{$o->name}.php")) {
                    error_log("Failed to clean up old model /var/www/html/app/models/{$o->name}.php");
                }

                // delete perms
                $perms = new \Kyte\Core\Model(Permission);
                $perms->retrieve("model", $o->name);
                foreach($perms->objects as $perm) {
                    $perm->delete();
                }
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}

?>
