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