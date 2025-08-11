<?php

namespace Kyte\Mvc\Controller;

class ModelAttributeController extends ModelController
{
    // public function hook_init() {}

    // public function hook_auth() {}

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        switch ($method) {
            case 'get':
                $order = [ ['field' => 'id', 'direction' => 'asc' ] ];
				break;

			default:
				break;
		}
	}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // get table
                $tbl = new \Kyte\Core\ModelObject(DataModel);
                if (!$tbl->retrieve('id', $r['dataModel'])) {
                    throw new \Exception("Unable to find associated data model.");
                }

                $attrs = \Kyte\Mvc\Controller\DataModelController::prepareModelDef((object)$r);

                // switch dbs
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $tbl->application)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                
                \Kyte\Core\Api::dbappconnect($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                \Kyte\Core\Api::dbswitch(true);

                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::addColumn($tbl->name, $r['name'], $attrs)) {
                    throw new \Exception("Failed to create column {$r['name']} in table {$tbl->name}...");
                }

                // return to kyte db
                \Kyte\Core\Api::dbswitch();

                break;

            case 'update':
                $tbl = new \Kyte\Core\ModelObject(DataModel);
                if (!$tbl->retrieve('id', $o->dataModel)) {
                    throw new \Exception("Unable to find associated data model.");
                }

                $attrs = \Kyte\Mvc\Controller\DataModelController::prepareModelDef((object)$r);
                
                // switch dbs
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $tbl->application)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                
                \Kyte\Core\Api::dbappconnect($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                \Kyte\Core\Api::dbswitch(true);

                // create new table with basic kyte info
                if (!\Kyte\Core\DBI::changeColumn($tbl->name, $o->name, $r['name'], $attrs)) {
                    throw new \Exception("Failed to change column {$o->name} to {$r['name']} in table {$tbl->name}...");
                }
                // return to kyte db
                \Kyte\Core\Api::dbswitch();
                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        $tbl = new \Kyte\Core\ModelObject(DataModel);
        if (!$tbl->retrieve('id', $o->dataModel)) {
            throw new \Exception("Unable to find associated data model.");
        }

        switch ($method) {
            case 'delete':
                // TODO: consider situation where there are external tables and foreign keys
                $d = false; // set auto delete external tables to false since there are none.
                // switch dbs
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $tbl->application)) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch.");
                }
                
                \Kyte\Core\Api::dbappconnect($app->db_name, $app->db_username, $app->db_password, $app->db_host ? $app->db_host : null);
                \Kyte\Core\Api::dbswitch(true);
                
                // drop table <table_name>
                if (!\Kyte\Core\DBI::dropColumn($tbl->name, $o->name)) {
                    throw new \Exception("Failed to drop column {$o->name} from table {$tbl->name}");
                }
                // return to kyte db
                \Kyte\Core\Api::dbswitch();

                $o->delete(); // redundant but required to ensure generateModelDef updates the definition correctly
                // TODO: consider finding a way to optimize this so that delete is naturally called by delete()

            case 'new':
            case 'update':
                $model_definition = \Kyte\Mvc\Controller\DataModelController::generateModelDef($tbl->name, $tbl->id);
                $tbl->save([
                    'model_definition' => json_encode($model_definition)
                ]);
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}
