<?php

require __DIR__ . '/../vendor/autoload.php';

// init db
\Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
\Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
\Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
\Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
\Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);

// load models and controllers
// list of models
$models = [];
		
/* Load user-defined files first in case there are overrides */
if ( file_exists( APP_DIR . "/app/" ) && is_dir( APP_DIR . "/app/" ) ) {

    // load user defined models and controllers (allow override of builtin)
    if ( file_exists( APP_DIR . "/app/models/" ) && is_dir( APP_DIR . "/app/models/" ) ) {    
        foreach (glob(APP_DIR . "/app/models/*.php") as $filename) {
            require_once($filename);
            $model_name = substr($filename, 0, strrpos($filename, "."));
            $model_name = str_replace(APP_DIR . '/app/models/','',$model_name);
            if (!in_array($model_name, $models)) {
                $models[] = $model_name;
            }
            if (VERBOSE_LOG) {
                error_log("Loading user defined model $model_name...".(isset($$model_name) ? 'defined!' : 'UNDEFINED!'));
            }
            $this->addPrimaryKey($$model_name);
            $this->addKyteAttributes($$model_name);
            define($model_name, $$model_name);
        }
    }

    // load user-defined controllers
    if ( file_exists( APP_DIR . "/app/controllers/" ) && is_dir( APP_DIR . "/app/controllers/" ) ) {
        foreach (glob(APP_DIR . "/app/controllers/*.php") as $filename) {
            $controller_name = substr($filename, 0, strrpos($filename, "."));
            $controller_name = str_replace(APP_DIR . '/app/controllers/','',$controller_name);
            require_once($filename);
            if (VERBOSE_LOG) {
                error_log("Checking if user defined controller has been defined...".(class_exists($controller_name) ? 'defined!' : 'UNDEFINED!'));
            }
        }
    }      
} 

// include built-in models being used by app
foreach (glob(__DIR__ . "/../Mvc/Model/*.php") as $filename) {
    $model_name = substr($filename, 0, strrpos($filename, "."));
    $model_name = str_replace(__DIR__ . '/../Mvc/Model/','',$model_name);
    if (!in_array($model_name, $models)) {
        $models[] = $model_name;
    }
    if (isset($$model_name)) {
        if (VERBOSE_LOG) {
            error_log("Skipping model $model_name as already defined...");
        }
    } else {
        require_once($filename);
        if (VERBOSE_LOG) {
            error_log("Loading built-in model $model_name...".(isset($$model_name) ? 'defined!' : 'UNDEFINED!'));
        }
        $this->addPrimaryKey($$model_name);
        define($model_name, $$model_name);
    }
}

// define list of models
define('KYTE_MODELS', $models);

?>