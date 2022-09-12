<?php

namespace Kyte\Mvc\Controller;

class DataModelController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // create new table with basic kyte info

                break;

            case 'update':
                // alter table <old_table_name> rename to <new_table_name>

                break;                

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                // drop table <table_name>
                $tbl_sql = "DROP TABLE `{$o->name}`;";
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}

?>
