<?php

    /* load classes from composer */
	require 'vendor/autoload.php';
	// include any utility scripts
	foreach (glob("util/*.php") as $filename) {
		require_once($filename) ;
    }
    
	// include models being used by app
    foreach (glob("models/*.php") as $filename) {
        require_once($filename);
        error_log("Loading model $model_name");
        $model_name = substr($filename, 0, strrpos($filename, "."));
        $model_name = str_replace('models/','',$model_name);
        define($model_name, $$model_name);
    }
    
    // include any controllers
    require 'controllers/ModelController.php';
	foreach (glob("controllers/*.php") as $filename) {
        require_once($filename) ;
        error_log("Loading controller $model_name");
    }

    require_once __DIR__.'/config.php';
    
?>
