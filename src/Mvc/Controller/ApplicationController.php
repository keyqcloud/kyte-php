<?php

namespace Kyte\Mvc\Controller;

class ApplicationController extends ModelController
{
    // public function hook_init() {}
    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                // create new application identifier
                $r['identifier'] = uniqid();
                // create db name
                $r['db_name'] = $r['identifier'].'_'.$this->account->number;

                // TODO: create new user and add privs to isolate db
                // create new username
                $r['db_username'] = $r['db_name'];
                // create new password
                $str = '';
                $charset = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ#!@';
                $max = mb_strlen($charset, '8bit') - 1;
                for ($i = 0; $i < 24; ++$i) {
                    $str .= $charset[random_int(0, $max)];
                }
                $r['db_password'] = $str;

                // TODO: create db in different cluster
                // $r['db_host'] = '';

                // create database
                \Kyte\Core\DBI::query("CREATE DATABASE `{$r['db_name']}`;");

                // add user to database
                \Kyte\Core\DBI::query("CREATE USER '{$r['db_username']}'@'localhost' IDENTIFIED BY '{$str}';");

                // set privs
                \Kyte\Core\DBI::query("GRANT ALL PRIVILEGES ON `{$r['db_name']}`.* TO '{$r['db_username']}'@'localhost';");
                \Kyte\Core\DBI::query("FLUSH PRIVILEGES;");

                break;
            
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'delete':
                // delete database from cluster
                \Kyte\Core\DBI::query("DROP DATABASE `{$o->db_name}`;");
                break;
            
            default:
                break;
        }
    }

    // public function hook_process_get_response(&$r) {}
}

?>
