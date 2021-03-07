<?php
    /* LOG OUTPUT */
    define('VERBOSE_LOG', false);

    // only execute if called from web
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        /* LOCALIZATION SUPPORT */
        // default to English
        $lang = 'en';
        // determine browser local
        if (array_key_exists('HTTP_ACCEPT_LANGUAGE', $_SERVER)) {
            $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
        }
        
        // supported languages - add additional language support here
        $acceptLang = ['ja', 'en']; 
        define('APP_LANG', in_array($lang, $acceptLang) ? $lang : 'en');
    }

    /* load classes from composer */
    require __DIR__ . '/vendor/autoload.php';
    
    // include library
    foreach (glob(__DIR__ . "/lib/*.php") as $filename) {
        require_once($filename) ;
    }

	// include any utility scripts
	foreach (glob(__DIR__ . "/util/*.php") as $filename) {
        require_once($filename) ;
    }

    // load base controller
    require __DIR__ . '/builtin/controllers/ModelController.php';

    function addPrimaryKey(&$modeldef) {
        $modeldef['struct']['id'] = [
            'type'		=> 'i',
            'required'	=> true,
            'pk'		=> true,
            'size'		=> 11,
            'date'		=> false,
        ];
    }

    function addKyteAttributes(&$modeldef) {
        $modeldef['struct']['kyte_account'] = [
            'type'		=> 'i',
            'required'	=> true,
            'size'		=> 11,
            'unsigned'	=> true,
            'date'		=> false,
        ];

        // audit attributes

        $modeldef['struct']['date_created'] = [
            'type'		=> 'i',
            'required'	=> false,
            'date'		=> true,
        ];

        $modeldef['struct']['date_modified'] = [
            'type'		=> 'i',
            'required'	=> false,
            'date'		=> true,
        ];

        $modeldef['struct']['date_deleted'] = [
            'type'		=> 'i',
            'required'	=> false,
            'date'		=> true,
        ];

        $modeldef['struct']['deleted'] = [
            'type'		=> 'i',
            'required'	=> false,
            'size'		=> 1,
            'unsigned'	=> true,
            'default'	=> 0,
            'date'		=> false,
        ];
    }

    // list of models
    $models = [];
    
    /* Load user-defined files first in case there are overrides */
    if ( file_exists( __DIR__ . "/app/" ) && is_dir( __DIR__ . "/app/" ) ) {

        // load user defined models and controllers (allow override of builtin)
        if ( file_exists( __DIR__ . "/app/models/" ) && is_dir( __DIR__ . "/app/models/" ) ) {    
            foreach (glob(__DIR__ . "/app/models/*.php") as $filename) {
                require_once($filename);
                $model_name = substr($filename, 0, strrpos($filename, "."));
                $model_name = str_replace(__DIR__ . '/app/models/','',$model_name);
                if (!in_array($model_name, $models)) {
                    $models[] = $model_name;
                }
                if (VERBOSE_LOG) {
                    error_log("Loading user defined model $model_name");
                    error_log("Checking if user defined model has been defined...".(isset($$model_name) ? 'defined!' : 'UNDEFINED!'));
                }
                addPrimaryKey($$model_name);
                addKyteAttributes($$model_name);
                define($model_name, $$model_name);
            }
        }

        // load user-defined controllers
        if ( file_exists( __DIR__ . "/app/controllers/" ) && is_dir( __DIR__ . "/app/controllers/" ) ) {
            foreach (glob(__DIR__ . "/app/controllers/*.php") as $filename) {
                $controller_name = substr($filename, 0, strrpos($filename, "."));
                $controller_name = str_replace(__DIR__ . '/app/controllers/','',$controller_name);
                require_once($filename);
                if (VERBOSE_LOG) {
                    error_log("Checking if user defined controller has been defined...".(class_exists($controller_name) ? 'defined!' : 'UNDEFINED!'));
                }
            }
        }      
    } 

	// include built-in models being used by app
    foreach (glob(__DIR__ . "/builtin/models/*.php") as $filename) {
        $model_name = substr($filename, 0, strrpos($filename, "."));
        $model_name = str_replace(__DIR__ . '/builtin/models/','',$model_name);
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
                error_log("Checking if model has been defined...".(isset($$model_name) ? 'defined!' : 'UNDEFINED!'));
            }
            addPrimaryKey($$model_name);
            define($model_name, $$model_name);
        }
    }

    define('KYTE_MODELS', $models);
    
    // include any built-in controllers
	foreach (glob(__DIR__ . "/builtin/controllers/*.php") as $filename) {
        $controller_name = substr($filename, 0, strrpos($filename, "."));
        $controller_name = str_replace(__DIR__ . '/builtin/controllers/','',$controller_name);
        if (class_exists($controller_name)) {
            if (VERBOSE_LOG) {
                error_log("Skipping controller $filename as already defined...");
            }
        } else {
            require_once($filename);
            if (VERBOSE_LOG) {
                error_log("Checking if controller has been defined...".(class_exists($controller_name) ? 'defined!' : 'UNDEFINED!'));
            }
        }
    }

    // define list of models
    define('KYTE_MODELS', $models);

    require_once __DIR__.'/config.php';

    // initialize base framework
    \Kyte\Mail::setSendGridAPIKey(KYTE_SENDGRID_API);
    \Kyte\DBI::setDbUser(KYTE_DB_USERNAME);
	\Kyte\DBI::setDbPassword(KYTE_DB_PASSWORD);
	\Kyte\DBI::setDbHost(KYTE_DB_HOST);
	\Kyte\DBI::setDbName(KYTE_DB_DATABASE);
    \Kyte\DBI::setCharset(KYTE_DB_CHARSET);
    
?>
