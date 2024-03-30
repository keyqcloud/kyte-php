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

                $model_definition = \Kyte\Mvc\Controller\DataModelController::generateModelDef($tbl->name, $tbl->id);;
                $tbl->save([
                    'model_definition' => json_encode($model_definition)
                ]);

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

                // check if encryption property changed
                if ($o->encrypt != $r['encrypt']) {
                    error_log("loading app models....");
                    // load app specific models
                    \Kyte\Core\Api::loadAppModels($app);

                    error_log("creating model instance for {$tbl->name}");
                    // specify model of this wrapper controller
                    $appModel = \Kyte\Core\Model(constant($tbl->name));
                    // grab all entries including deleted ones
                    $appModel->retrieve(null, null, false, null, true);

                    if ($r['encrypt']) {
                        error_log("Encrypting.....");
                        // encrypt column
                        foreach($appModel->objects as $item) {
                            $value = $item->{$r['name']};
                            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                            $key = base64_decode($app->cipher_key);
                            $cipher = sodium_crypto_secretbox($value, $nonce, $key);
                            $item->save([
                                $r['name'] => base64_encode($nonce . $cipher),
                            ]);
                            // log what is being encrypted
                            error_log("Encrypted value for {$item->id} in table {$tbl->name}");
                        }
                    } else {
                        error_log("Decrypting.....");
                        // decrypt column
                        foreach($appModel->objects as $item) {
                            $value = base64_decode($item->{$r['name']});
                            $nonce = substr($value, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                            $cipher = substr($value, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                            $key = base64_decode($app->cipher_key);
                            $value = sodium_crypto_secretbox_open($cipher, $nonce, $key);
                            if ($value !== false) {
                                $item->save([
                                    $r['name'] => $value,
                                ]);
                                // log what is being decrypted
                                error_log("Decrypted value for {$item->id} in table {$tbl->name}");
                            } else {
                                error_log("Failed to decrypt value for {$item->id} in table {$tbl->name}");
                            }
                        }
                    }
                }
                // return to kyte db
                \Kyte\Core\Api::dbswitch();

                $model_definition = \Kyte\Mvc\Controller\DataModelController::generateModelDef($tbl->name, $tbl->id);;
                $tbl->save([
                    'model_definition' => json_encode($model_definition)
                ]);
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

                $model_definition = \Kyte\Mvc\Controller\DataModelController::generateModelDef($tbl->name, $tbl->id);;
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
