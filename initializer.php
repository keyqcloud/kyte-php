<?php

    /* load classes from composer */
	require 'vendor/autoload.php';
	// include any utility scripts
	foreach (glob("util/*.php") as $filename) {
		require_once($filename) ;
    }
    
	// include models being used by app
    foreach (glob("builtin/models/*.php") as $filename) {
        require_once($filename);
        $model_name = substr($filename, 0, strrpos($filename, "."));
        $model_name = str_replace('builtin/models/','',$model_name);
        error_log("Loading model $model_name");
        define($model_name, $$model_name);
    }
    
    // include any controllers
    require 'builtin/controllers/ModelController.php';
	foreach (glob("builtin/controllers/*.php") as $filename) {
        error_log("Loading controller $filename");
        require_once($filename) ;
    }

    /* Load user-defined files */
    if ( file_exists( "app/" ) && is_dir( "app/" ) ) {
        if ( file_exists( "app/models/" ) && is_dir( "app/models/" ) ) {
            // load user defined models and controllers (allow override of builtin)
            foreach (glob("app/models/*.php") as $filename) {
                require_once($filename);
                $model_name = substr($filename, 0, strrpos($filename, "."));
                $model_name = str_replace('app/models/','',$model_name);
                error_log("Loading model $model_name");
                define($model_name, $$model_name);
            }
        }
        if ( file_exists( "app/controllers/" ) && is_dir( "app/controllers/" ) ) {
            // include any controllers
            foreach (glob("app/controllers/*.php") as $filename) {
                error_log("Loading controller $filename");
                require_once($filename) ;
            }
        }      
    } 

    require_once __DIR__.'/config.php';
    
?>
