<?php

namespace Kyte\Mvc\Controller;

class ModelAttributeController extends ModelController
{
    public function hook_init() {
        $this->checkExisting = 'name';
    }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // get table
                $tbl = new \Kyte\Core\ModelObject(DataModel);
                if (!$tbl->retrieve('id', $r['dataModel'])) {
                    throw new \Exception("Unable to find associated data model.");
                }

                $updatedModel = constant($tbl->name);

                // although we check existing...let's make sure that the def doesn't have it
                if (array_key_exists($r['name'], $updatedModel['struct'])) {
                    throw new \Exception("Whoops, looks like the attribute name is already defined in the model although not found in the database. Contact a DB admin ASAP!");
                }

                $attrs = [
                    'type'      => $r['type'] == 'date' ? 'i' : $r['type'],
                    'date'      => $r['type'] == 'date',
                    'required'  => $r['required'] == 1,
                ];

                // size
                if (!empty($r['size'])) {
                    $attrs['size'] = $r['size'];
                }

                // unsigned
                if ($r['unsigned'] == 1) {
                    $attrs['unsigned'] = true;
                }

                // protected
                if ($r['protected'] == 1) {
                    $attrs['protected'] = true;
                }

                // defaults
                if (strlen($r['defaults']) > 0) {
                    $attrs['default'] = $r['defaults'];
                }

                // foreign key
                if (!empty($r['foreignKeyModel']) && !empty($r['foreignKeyAttribute'])) {

                    // get table and attribute info
                    $fk_tbl = new \Kyte\Core\ModelObject(DataModel);
                    if (!$fk_tbl->retrieve('id', $r['foreignKeyModel'])) {
                        throw new \Exception("Unable to find data model for foreign key definition");
                    }
                    $fk_attr = new \Kyte\Core\ModelObject(ModelAttribute);
                    if (!$fk_attr->retrieve('id', $r['foreignKeyAttribute'])) {
                        throw new \Exception("Unable to find attribute for data model {$fk_tbl->name} for foreign key definition");
                    }

                    $attrs['fk'] = [
                        'model' => $fk_tbl->name,
                        'field' => $fk_attr->name,
                    ];
                }

                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::addColumn($tbl->name, $r['name'], $attrs)) {
                    throw new \Exception("Failed to create column {$r['name']} in table {$tbl->name}...");
                }

                $updatedModel['struct'][$r['name']] = $attrs;

                if (file_put_contents("/var/www/html/app/models/{$tbl->name}.php", "<?php\n\${$r['name']} = " . var_export($updatedModel, true) . ";") === false) {
                    throw new \Exception("Failed to undo rename...we are in big trouble! Squawk 7700!");
                }

                break;

            case 'update':
                $tbl = new \Kyte\Core\ModelObject(DataModel);
                if (!$tbl->retrieve('id', $o->dataModel)) {
                    throw new \Exception("Unable to find associated data model.");
                }

                $updatedModel = constant($tbl->name);

                // although we check existing...let's make sure that the def doesn't have it
                if (array_key_exists($r['name'], $updatedModel['struct'])) {
                    throw new \Exception("Whoops, looks like the attribute name is already defined in the model although not found in the database. Contact a DB admin ASAP!");
                }

                $attrs = [
                    'type'      => $r['type'] == 'date' ? 'i' : $r['type'],
                    'date'      => $r['type'] == 'date',
                    'required'  => $r['required'] == 1,
                ];

                // size
                if (!empty($r['size'])) {
                    $attrs['size'] = $r['size'];
                }

                // unsigned
                if ($r['unsigned'] == 1) {
                    $attrs['unsigned'] = true;
                }

                // protected
                if ($r['protected'] == 1) {
                    $attrs['protected'] = true;
                }

                // defaults
                if (strlen($r['defaults']) > 0) {
                    $attrs['default'] = $r['defaults'];
                }

                // foreign key
                if (!empty($r['foreignKeyModel']) && !empty($r['foreignKeyAttribute'])) {

                    // get table and attribute info
                    $fk_tbl = new \Kyte\Core\ModelObject(DataModel);
                    if (!$fk_tbl->retrieve('id', $r['foreignKeyModel'])) {
                        throw new \Exception("Unable to find data model for foreign key definition");
                    }
                    $fk_attr = new \Kyte\Core\ModelObject(ModelAttribute);
                    if (!$fk_attr->retrieve('id', $r['foreignKeyAttribute'])) {
                        throw new \Exception("Unable to find attribute for data model {$fk_tbl->name} for foreign key definition");
                    }

                    $attrs['fk'] = [
                        'model' => $fk_tbl->name,
                        'field' => $fk_attr->name,
                    ];
                }

                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::changeColumn($tbl->name, $o->name, $r['name'], $attrs)) {
                    throw new \Exception("Failed to change column {$o->name} to {$r['name']} in table {$tbl->name}...");
                }

                // unset original definition
                unset($updatedModel['struct'][$o->name]);
                // add new definition
                $updatedModel['struct'][$r['name']] = $attrs;

                if (file_put_contents("/var/www/html/app/models/{$tbl->name}.php", "<?php\n\${$r['name']} = " . var_export($updatedModel, true) . ";") === false) {
                    throw new \Exception("Failed to undo rename...we are in big trouble! Squawk 7700!");
                }
                break;                

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                // TODO: consider situation where there are external tables and foreign keys

                $tbl = new \Kyte\Core\ModelObject(DataModel);
                if (!$tbl->retrieve('id', $o->dataModel)) {
                    throw new \Exception("Unable to find associated data model.");
                }

                // drop table <table_name>
                if (!\Kyte\Core\DBI::dropColumn($tbl->name, $o->name)) {
                    throw new \Exception("Failed to drop column {$o->name} from table {$tbl->name}");
                }

                $updatedModel = constant($tbl->name);
                unset($updatedModel['struct'][$o->name]);

                if (file_put_contents("/var/www/html/app/models/{$tbl->name}.php", "<?php\n\${$o->name} = " . var_export($updatedModel, true) . ";") === false) {
                    throw new \Exception("Failed to undo rename...we are in big trouble! Squawk 7700!");
                }
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}

?>
