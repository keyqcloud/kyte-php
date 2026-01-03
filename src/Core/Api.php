<?php

namespace Kyte\Core;

/**
 * Class Api
 *
 * This class represents the API and handles the routing of requests.
 *
 * @package Kyte\Core
 */
class Api
{
	/**
     * The application ID.
     *
     * @var string|null
     */
	public $appId = null;

	/**
     * @var \Kyte\Core\ModelObject The KyteAPIKey model object.
     */
    private $key = null;
    
    /**
     * * @var \Kyte\Core\ModelObject The KyteAccount model object.
     */
    public $account = null;
    
    /**
     * * @var \Kyte\Core\ModelObject The Session model object.
     */
    public $session = null;
    
    /**
     * @var \Kyte\Core\ModelObject The User model object.
     */
    public $user = null;
    
    /**
     * @var \Kyte\Core\ModelObject The Application model object.
     */
    public $app = null;

	/**
     * * @var \Kyte\Exception\ErrorHandler Error handler singleton.
     */
    public $errorHandler = null;
    
    /**
     * The API signature.
     *
     * @var string|null
     */
    private $signature = null;
    
    /**
     * The UTC date.
     *
     * @var mixed|null
     */
    private $utcDate = null;
    
    /**
     * The HTTP request model.
     *
     * @var string|null
     */
    public $model;
    
    /**
     * The HTTP request.
     *
     * @var mixed|null
     */
    public $request;
    
    /**
     * The content type.
     *
     * @var mixed|null
     */
    public $contentType;
    
    /**
     * The request data.
     *
     * @var array<string,mixed>|null
     */
    public $data;
    
    /**
     * The field.
     *
     * @var string|null
     */
    public $field = null;
    
    /**
     * The value.
     *
     * @var string|null
     */
    public $value = null;
    
    /**
     * The page size.
     *
     * @var int|null
     */
    public $page_size;
    
    /**
     * The total number of pages.
     *
     * @var int|null
     */
    public $page_total;
    
    /**
     * The current page number.
     *
     * @var int
     */
    public $page_num = 1;
    
    /**
     * The total count.
     *
     * @var int|null
     */
    public $total_count;
    
    /**
     * The total filtered count.
     *
     * @var int|null
     */
    public $total_filtered;
    
    /**
     * The response data.
     *
     * @var array<string,mixed>
     */
    public $response = [];

	/**
     * The default environment constants if not defined already in config.
     *
     * @var array<string,mixed>
     */
    public $defaultEnvironmentConstants = [
		'DEBUG' => false,
		'S3_DEBUG' => false,
		'KYTE_JS_CDN' => 'https://cdn.keyqcloud.com/kyte/js/stable/kyte.js',
		'ALLOW_ENC_HANDOFF' => true,
		'ALLOW_MULTILOGON' => false,
		'ALLOW_SAME_TXTOKEN' => false,
		'SESSION_TIMEOUT' => 3600,
		'SIGNATURE_TIMEOUT' => 3600,
		'USERNAME_FIELD' => 'email',
		'PASSWORD_FIELD' => 'password',
		'VERBOSE_LOG' => false,
		'IS_PRIVATE' => true,
		'RETURN_NO_MODEL' => true,
		'SESSION_RETURN_FK' => true,
		'PAGE_SIZE' => 50,
		'USE_SESSION_MAP' => false,
		'CHECK_SYNTAX_ON_IMPORT' => false,
		'STRICT_TYPING' => true,
		'KYTE_USE_SNS' => false,
	];

	/**
	 * Model definition cache
	 *
	 * @var array
	 */
	private static $modelCache = [];

	/**
	 * Model cache file location
	 *
	 * @var string|null
	 */
	private static $modelCacheFile = null;

	/**
     * Api constructor.
     *
     * Initializes the base framework and sets up the environment constants.
     */
	public function __construct() {
		$this->defineEnvironmentConstants();
		$this->loadModelsAndControllers();
	
		// initialize base framework
		self::dbconnect();

		// if not cli, register error handler and attempt IMDS/IMDSv2 retrieval
		if (php_sapi_name() !== 'cli') {
			// retrieve IMDS data
			$imdsData = \Kyte\Util\IMDS::fetchMetadata();

			$this->response['imds'] = $imdsData;
		}
	}

	/**
     * Defines the environment constants.
     *
     * This method defines various constants required by the API if they are not already defined.
     */
	private function defineEnvironmentConstants() {
		foreach ($this->defaultEnvironmentConstants as $key => $value) {
			// check if each key is defined, and if not define key as value
			if (!defined($key)) {
				define($key, $value);
				error_log("$key constant not defined...using defaults ($value)");
			}
		}
		
		// determine localizations for non-cli requests
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
	}

	/**
     * Defines the environment constants for an application.
     *
     * This method defines various constants required by an application.
     */
	private function defineAppEnvironmentConstants($app) {
		$models = new \Kyte\Core\Model(KyteEnvironmentVariable);
		$models->retrieve('application', $app->id);
		$envVars = [];
		foreach($models->objects as $object) {
			$envVars[$object->key] = $object->value;
		}
		define("KYTE_APP_ENV", $envVars);
	}

	/**
     * Defines the datastore environment constants for an application.
     *
     * This method defines various constants required by an application.
     */
	private function defineAppDataStore($app) {
		$models = new \Kyte\Core\Model(DataStore);
		$models->retrieve('application', $app->id);
		$envVars = [];
		foreach($models->objects as $object) {
			$envVars[$object->name] = [
				"bucket" => $object->bucketname,
				"region" => $object->region,
			];
		}
		define("KYTE_APP_DATASTORE", $envVars);
	}

	/**
     * Adds a primary key to the model definition.
     *
     * @param array $modeldef The model definition to add the primary key to.
     */
	public static function addPrimaryKey(&$modeldef) {
		$modeldef['struct']['id'] = [
			'type'		=> 'i',
			'required'	=> true,
			'pk'		=> true,
			'size'		=> 11,
			'date'		=> false,
		];
	}

	/**
     * Adds audit attributes to the model definition.
     *
     * @param array $modeldef The model definition to add the audit attributes to.
     */
	public static function addKyteAttributes(&$modeldef) {
		$modeldef['struct']['created_by'] = [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		];

		$modeldef['struct']['date_created'] = [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		];

		$modeldef['struct']['modified_by'] = [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		];

		$modeldef['struct']['date_modified'] = [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		];

		$modeldef['struct']['deleted_by'] = [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
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

	/**
     * Sets database credentials to the default database.
     */
	public static function dbconnect() {
		if (\Kyte\Core\DBI::$dbUser == KYTE_DB_USERNAME && \Kyte\Core\DBI::$dbName == KYTE_DB_DATABASE && \Kyte\Core\DBI::$dbHost == KYTE_DB_HOST) {
				return;
		}

		\Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
		\Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
		\Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
		\Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
		\Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);
	}

	/**
     * Sets database credentials to the specified app database.
     *
     * @param string $database The name of the database to connect to.
     * @param string $username The username for the database connection.
     * @param string $password The password for the database connection.
     * @param string|null $host The host for the database connection. Defaults to the value of KYTE_DB_HOST.
     * @param string|null $charset The charset for the database connection. Defaults to the value of KYTE_DB_CHARSET.
     * @throws \Exception If the database parameters are not provided.
     */
	public static function dbappconnect($database, $username, $password, $host = KYTE_DB_HOST, $charset = KYTE_DB_CHARSET) {
		if ($database == null || $username == null || $password == null) {
			throw new \Exception("Database parameters must be provided. Database: $database\tUsername: $username\tPassword: $password");
		}
		if ($host == null) {
			$host = KYTE_DB_HOST;
		}
		//
		if (\Kyte\Core\DBI::$dbUser == $username && \Kyte\Core\DBI::$dbName == $database && \Kyte\Core\DBI::$dbHost == $host) {
			return;
		}

		\Kyte\Core\DBI::setDbNameApp($database);
		\Kyte\Core\DBI::setDbUserApp($username);
		\Kyte\Core\DBI::setDbPasswordApp($password);
		\Kyte\Core\DBI::setDbHostApp($host);
		\Kyte\Core\DBI::setCharset($charset);
	}

	/**
     * Switches the database connection to the app database.
     *
     * @param bool $useApp If true, the app database will be used. Otherwise, the default database will be used.
     */
	public static function dbswitch($useApp = false) {
		\Kyte\Core\DBI::$useAppDB = $useApp;
	}

	/**
	 * Load app specific models with caching support.
	 *
	 * This method loads app specific models. When caching is enabled, it will:
	 * 1. Check memory cache first
	 * 2. Check file cache (if configured)
	 * 3. Load from DB and update caches
	 *
	 * @param object $app Application object
	 * @return void
	 */
	public static function loadAppModels($app) {
		$cacheKey = "app_{$app->id}";

		// Check memory cache first
		if (isset(self::$modelCache[$cacheKey])) {
			foreach (self::$modelCache[$cacheKey] as $name => $def) {
				if (!defined($name)) {
					define($name, $def);
				}
			}
			return;
		}

		// Check file cache (if configured)
		if (self::$modelCacheFile && file_exists(self::$modelCacheFile)) {
			$cacheJson = file_get_contents(self::$modelCacheFile);
			$cache = json_decode($cacheJson, true);
			if ($cache && isset($cache[$cacheKey]) &&
				$cache[$cacheKey]['timestamp'] > time() - 3600) {  // 1 hour TTL
				self::$modelCache[$cacheKey] = $cache[$cacheKey]['models'];
				foreach ($cache[$cacheKey]['models'] as $name => $def) {
					if (!defined($name)) {
						define($name, $def);
					}
				}
				return;
			}
		}

		// Load from DB (existing code)
		$models = new \Kyte\Core\Model(DataModel);
		$models->retrieve('application', $app->id);
		$modelDefs = [];

		foreach($models->objects as $object) {
			$model_definition = json_decode($object->model_definition, true);
			$model_definition['appId'] = $app->identifier;
			$modelDefs[$model_definition['name']] = $model_definition;
			define($model_definition['name'], $model_definition);
		}

		// Update caches
		self::$modelCache[$cacheKey] = $modelDefs;
		if (self::$modelCacheFile) {
			self::updateModelCache($cacheKey, $modelDefs);
		}
	}

	/**
	 * Set model cache file location
	 *
	 * @param string $path Cache file path
	 * @return void
	 */
	public static function setModelCacheFile($path) {
		self::$modelCacheFile = $path;
	}

	/**
	 * Update model cache file
	 *
	 * @param string $cacheKey Cache key
	 * @param array $modelDefs Model definitions
	 * @return void
	 */
	private static function updateModelCache($cacheKey, $modelDefs) {
		$cache = [];
		if (file_exists(self::$modelCacheFile)) {
			$cacheJson = file_get_contents(self::$modelCacheFile);
			$cache = json_decode($cacheJson, true) ?: [];
		}

		$cache[$cacheKey] = [
			'timestamp' => time(),
			'models' => $modelDefs
		];

		file_put_contents(
			self::$modelCacheFile,
			json_encode($cache, JSON_PRETTY_PRINT)
		);
	}

	/**
	 * Clear model cache
	 *
	 * @param int|null $appId Application ID (null = clear all)
	 * @return void
	 */
	public static function clearModelCache($appId = null) {
		if ($appId === null) {
			self::$modelCache = [];
			if (self::$modelCacheFile && file_exists(self::$modelCacheFile)) {
				unlink(self::$modelCacheFile);
			}
		} else {
			$cacheKey = "app_$appId";
			unset(self::$modelCache[$cacheKey]);

			// Update file cache
			if (self::$modelCacheFile && file_exists(self::$modelCacheFile)) {
				$cacheJson = file_get_contents(self::$modelCacheFile);
				$cache = json_decode($cacheJson, true) ?: [];
				unset($cache[$cacheKey]);
				file_put_contents(
					self::$modelCacheFile,
					json_encode($cache, JSON_PRETTY_PRINT)
				);
			}
		}
	}

	/**
	 * Load an app specific controller.
	 *
	 * This method loads an app specific controller.
	 *
	 * @return void
	 */
	public static function loadAppController($app, $controller_name) {
		/* USER DEFINED CONTROLLER */
		// Load user-defined controllers
		$controller = new \Kyte\Core\ModelObject(constant("Controller"));
		if ($controller->retrieve("name", $controller_name, [["field" => "application", "value" => $app->id]])) {
			$code = bzdecompress($controller->code);
			eval($code);
		}
	}

	/**
	 * Load all app specific controllers.
	 *
	 * This method loads all app specific controllers.
	 *
	 * @return void
	 */
	public static function loadAppControllers($app) {
		/* USER DEFINED CONTROLLER */
		// Load user-defined controllers
		$controllers = new \Kyte\Core\Model(constant("Controller"));
		$controllers->retrieve("application", $app->id);
		foreach($controllers->objects as $object) {
			$code = bzdecompress($object->code);
			eval($code);
		}
	}

	/**
	 * Load models and controllers.
	 *
	 * This method loads built-in models and controllers.
	 * It imports the model files and adds them to the list of models.
	 *
	 * @return void
	 */
	private function loadModelsAndControllers()
	{
		/* BUILTIN DEFINED MODELS */
		// Import builtin models first, but don't define them yet in case there are user overrides and changes
		$kyte_models = [];
		foreach (glob(__DIR__ . "/../Mvc/Model/*.php") as $filename) {
			$model_name = basename($filename, '.php');
			require_once($filename);
			if (VERBOSE_LOG) {
				error_log("Importing builtin model $model_name...");
			}
			self::addPrimaryKey($$model_name);
			// define model constanct
			define($model_name, $$model_name);
			$kyte_models[] = $$model_name;
		}
		define('KYTE_MODELS', $kyte_models);
	}

	/**
     * Routes the API request.
     *
     * This is the main method that handles the routing of the API request.
     */
	public function route() {
		try {
			if (isset($_SERVER['HTTP_X_KYTE_APPID'])) {
				$this->appId = $_SERVER['HTTP_X_KYTE_APPID'];
			}

			// instantiate an API key object
			$this->key = new \Kyte\Core\ModelObject(KyteAPIKey);

			// prepare response
			// return json format:
			// {
			// 	token: ‘TRANSACTION_TOKEN’,
			// 	session: ‘SESSION_TOKEN’,
			// 	error: ‘ERROR_MESSAGE’,
			// 	model: ‘MyModel’,
			// 	transaction: ‘PUT’,
			// 	txTimestamp: ‘Thu, 30 Apr 2020 07:11:46 GMT’,
			// 	data: {}
			// }
			$this->response['session'] = '0';
			$this->response['token'] = '0';	// default to public token
			$this->response['uid'] = '0';
			$now = new \DateTime();
			$now->setTimezone(new \DateTimeZone('UTC'));    // Another way
			$this->response['txTimestamp'] = $now->format('U');

			// check if request is application level
			if ($this->appId != null) {
				// retrieve application information
				$this->app = new \Kyte\Core\ModelObject(Application);
				if (!$this->app->retrieve('identifier', $this->appId)) {
					throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch for app ID {$this->appId}.");
				}

				// load app sepcific env vars
				self::defineAppEnvironmentConstants($this->app);

				// load app specific data stores
				self::defineAppDataStore($this->app);

				// load app specific models
				self::loadAppModels($this->app);
				
				self::dbappconnect($this->app->db_name, $this->app->db_username, $this->app->db_password);
			}
			
			// next determine session by checking if app requires app-level user table
			if ($this->appId != null && $this->app->user_model != null && $this->app->username_colname != null && $this->app->password_colname != null) {
				// create a session instance for in app scope
				$this->session = new \Kyte\Session\SessionManager(Session, constant($this->app->user_model), $this->app->username_colname, $this->app->password_colname, $this->appId, ALLOW_MULTILOGON, SESSION_TIMEOUT);
				$this->user = new \Kyte\Core\ModelObject(constant($this->app->user_model));
			} else {
				// if no app id is found, or app-level user tbl is not defined then 
				// create a session instance, and default to Kyte
				$this->session = new \Kyte\Session\SessionManager(Session, KyteUser, USERNAME_FIELD, PASSWORD_FIELD, null, ALLOW_MULTILOGON, SESSION_TIMEOUT);
				$this->user = new \Kyte\Core\ModelObject(KyteUser);
			}

			$this->account = new \Kyte\Core\ModelObject(KyteAccount);

			// if minimum count of elements exist, then process api request based on request type
			if ($this->validateRequest()) {
				// register error handler
				if (defined('USE_KYTE_ERROR_HANDLER') && USE_KYTE_ERROR_HANDLER) {
					if ($this->errorHandler == null) {
						$this->errorHandler = \Kyte\Exception\ErrorHandler::getInstance($this);
					}
					$this->errorHandler->register();

				// Initialize Logger API
				if (defined('KYTE_LOGGER_ENABLED') && KYTE_LOGGER_ENABLED) {
					\Kyte\Core\Logger::init($this);
				}
				}

				if ($this->appId != null) {
					self::loadAppController($this->app, $this->model);
				}

				if (class_exists('\\Kyte\Mvc\\Controller\\'.$this->model.'Controller')) {
					$controllerClass = '\\Kyte\Mvc\\Controller\\'.$this->model.'Controller';
				} else {
					$controllerClass = class_exists($this->model.'Controller') ? $this->model.'Controller' : '\\Kyte\\Mvc\\Controller\\ModelController';
				}
				// create new controller with model, app date format (i.e. Ymd), and new transaction token (to be verified again if private api)
				$controller = new $controllerClass(defined($this->model) ? constant($this->model) : null, $this, APP_DATE_FORMAT, $this->response);
				if (!$controller) throw new \Exception("[ERROR] Unable to create controller for model: $controllerClass.");

				switch ($this->request) {
					case 'POST':
						// post data = data
						// new  :   {data}
						$controller->new($this->data);
						break;

					case 'PUT':
						// post data = data
						// update   :   {field}, {value}, {data}
						$controller->update($this->field, $this->value, $this->data);
						break;

					case 'GET':
						// get  :   {field}, {value}
						$controller->get($this->field, $this->value);
						break;

					case 'DELETE':
						// delete   :   {field}, {value}
						$controller->delete($this->field, $this->value);
						break;
					
					default:
						throw new \Exception("[ERROR] Unknown HTTP request type: $this->request.");
						break;
				}

				// return back to regular error reporting
				if (defined('USE_KYTE_ERROR_HANDLER') && USE_KYTE_ERROR_HANDLER) {
					if ($this->errorHandler != null) {
						$this->errorHandler->unregister();
					}
				}

				// as a safety, make sure we are back on the main db
				self::dbconnect();

			} else {
				// If a post request is made to the api endpoint with no signature, identity string, or model being passed then generate a new signature based on the post data
				// format of the data being passed should be:
				// {
				//     key: ‘public_key’,
				//     identifier: ‘api_key_identifier’,
				//     token: ‘transaction_token’,
				//     time: ‘Thu, 30 Apr 2020 07:11:46 GMT’
				// }
					
				$this->generateSignature();
			}

		} catch (\Kyte\Exception\SessionException $e) {
			http_response_code(403);
			$this->response['error'] = $e->getMessage();
			$this->response = ['response_code' => 403] + $this->response;
			if (defined('LOG_RESPONSE')) {
				error_log(json_encode($this->response, JSON_PRETTY_PRINT));
			}
			echo json_encode($this->response);
			exit(0);
		} catch (\Exception $e) {
			http_response_code(400);
			$this->response = ['response_code' => 400] + $this->response;
			$this->response['error'] = $e->getMessage();
			if (defined('LOG_RESPONSE')) {
				error_log(json_encode($this->response, JSON_PRETTY_PRINT));
			}
			echo json_encode($this->response);
			exit(0);
		}

		// return pagination
		$this->response['page_size'] = $this->page_size;
		$this->response['page_total'] = $this->page_total;
		$this->response['page_num'] = $this->page_num;
		$this->response['total_count'] = $this->total_count;
		$this->response['total_filtered'] = $this->total_filtered;

		// Add performance monitoring (opt-in via DEBUG_PERFORMANCE constant)
		if (defined('DEBUG_PERFORMANCE') && DEBUG_PERFORMANCE) {
			$queryLog = \Kyte\Core\DBI::getQueryLog();
			$cacheStats = \Kyte\Core\DBI::getCacheStats();

			$totalQueries = count($queryLog);
			$dbTime = 0;
			foreach ($queryLog as $log) {
				if (isset($log['execution_time'])) {
					$dbTime += $log['execution_time'];
				}
			}

			$this->response['_performance'] = [
				'total_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2),
				'db_queries' => $totalQueries,
				'db_time' => round($dbTime, 2),
				'memory_peak' => memory_get_peak_usage(true),
				'memory_current' => memory_get_usage(true),
				'cache' => [
					'hits' => $cacheStats['hits'],
					'misses' => $cacheStats['misses'],
					'size' => $cacheStats['size'],
					'hit_rate' => $totalQueries > 0 ?
						round(($cacheStats['hits'] / max(1, $cacheStats['hits'] + $cacheStats['misses'])) * 100, 2) . '%' :
						'0%'
				]
			];
		}

		// return response data
		$this->response = ['response_code' => 200] + $this->response;

		// TEMPORARY DEBUG - Remove after testing
		error_log("=== API RESPONSE DEBUG ===");
		error_log("Response keys: " . implode(', ', array_keys($this->response)));
		error_log("Data count: " . (isset($this->response['data']) ? count($this->response['data']) : 'NO DATA'));
		error_log("JSON encode result length: " . strlen(json_encode($this->response)));

		if (defined('LOG_RESPONSE')) {
			error_log(json_encode($this->response, JSON_PRETTY_PRINT));
		}
		echo json_encode($this->response);
	}

	/**
	 * Enables Cross-Origin Resource Sharing (CORS) and returns the request method.
	 *
	 * @return string The request method.
	 */
	private function cors()
	{
		// Get the origin of the requester
		$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : $_SERVER['REMOTE_ADDR']);

		header("Access-Control-Allow-Origin: $origin");
		header('Access-Control-Allow-Credentials: true');
		header("Content-Type: application/json; charset=utf-8");

		// Get the request method
		$requestMethod = $_SERVER['REQUEST_METHOD'];

		// Access-Control headers are received during OPTIONS requests
		if ($requestMethod === 'OPTIONS') {
			// $accessControlRequestMethod = isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']) ? $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] : '';
			$accessControlRequestHeaders = isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']) ? $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] : '';

			header("Access-Control-Allow-Methods: GET, PUT, POST, DELETE, HEAD, OPTION");
			header("Access-Control-Allow-Headers: $accessControlRequestHeaders");

			exit(0);
		}

		return $requestMethod;
	}

	/**
	* Safely decodes a URL-encoded string, handling cases of multiple encoding.
	*
	* This method repeatedly applies urldecode() until no further changes occur,
	* which handles browser double-encoding issues (e.g., kenneth%2540getpage.co -> kenneth@getpage.co).
	* Limited to 3 iterations to prevent infinite loops and performance issues.
	*
	* @param string|null $str The URL-encoded string to decode
	* @return string|null The fully decoded string, or null if input was null
	*/
	private function safeUrlDecode($str) {
		if (!$str) return $str;
		
		$previous = '';
		$current = $str;
		$maxIterations = 3; // Safety limit
		$iterations = 0;
		
		while ($previous !== $current && $iterations < $maxIterations) {
			$previous = $current;
			$current = urldecode($current);
			$iterations++;
		}
		
		return $current;
	}

	/**
	 * Validates the request, handles CORS, and parses the request data.
	 *
	 * @return bool True if the request is valid, false otherwise.
	 * @throws \Exception If API information for the account is not found.
	 */
	private function validateRequest()
	{
		// CORS Validation
		$this->request = $this->cors();

		$this->contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';

		// if content type is JSON, then parse JSON
		if (strpos($this->contentType, 'json') !== false) {
			$this->data = json_decode(file_get_contents("php://input"), true);
		} else {
			// read in data and parse into an array
			parse_str(file_get_contents("php://input"), $this->data);
		}

		if (VERBOSE_LOG > 0) {
			error_log(print_r($this->data, true));
		}

		if (IS_PRIVATE) {
			$this->signature = isset($_SERVER['HTTP_X_KYTE_SIGNATURE']) ? $_SERVER['HTTP_X_KYTE_SIGNATURE'] : null;
			if (!$this->signature) {
				return false;
			}
		}

		$this->parseIdentityString(isset($_SERVER['HTTP_X_KYTE_IDENTITY']) ? $_SERVER['HTTP_X_KYTE_IDENTITY'] : null);
		if (!$this->account) {
			return false;
		}

		// set page size
		$this->page_size = isset($_SERVER['HTTP_X_KYTE_PAGE_SIZE']) ? intval($_SERVER['HTTP_X_KYTE_PAGE_SIZE']) : PAGE_SIZE;
		// get page num from header
		$this->page_num = isset($_SERVER['HTTP_X_KYTE_PAGE_IDX']) ? intval($_SERVER['HTTP_X_KYTE_PAGE_IDX']) : 0;

		// datatables specific
		$this->response['draw'] = isset($_SERVER['HTTP_X_KYTE_DRAW']) ? intval($_SERVER['HTTP_X_KYTE_DRAW']) : 0;

		$this->response['CONTENT_TYPE'] = $this->contentType;
		$this->response['transaction'] = $this->request;
		$this->response['engine_version'] = \Kyte\Core\Version::get();

		// * URL format - root endpoint
		// https://uri-to-api-endpoint / {model} [ / {field} / {value} ]
		//
		/* parse URI        ** remember to add the following in .htaccess 'FallbackResource /index.php'
		* URL formats:
		* POST     /{model} + data
		* PUT      /{model}/{field}/{value} + data
		* GET      /{model}/{field}/{value}
		* DELETE   /{model}/{field}/{value}
		*/

		// Strip query string and trim leading slash(es)
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$path = ltrim($path, '/');

		$elements = explode('/', $path);

		if (count($elements) >= 1) {
			$this->model = $elements[0];
			$this->field = isset($elements[1]) ? $this->safeUrlDecode($elements[1]) : null;
			$this->value = isset($elements[2]) ? $this->safeUrlDecode($elements[2]) : null;

			// Debug logging
			if (VERBOSE_LOG) {
				error_log("Raw URL element[2]: " . (isset($elements[2]) ? $elements[2] : 'null'));
				error_log("Decoded value: " . ($this->value ?? 'null'));
			}

			$this->response['model'] = $this->model;

			// get API associated with the account
			$sub_account_api = new \Kyte\Core\ModelObject(KyteAPIKey);
			if (!$sub_account_api->retrieve('kyte_account', $this->account->id)) {
				throw new \Exception("[ERROR] Unable to find API information for the account");
			}

			// return account information in response - this is required for API handoff between the master account and subaccounts
			$this->response['kyte_api'] = API_URL;
			$this->response['kyte_pub'] = $sub_account_api->public_key;
			$this->response['kyte_num'] = $this->account->number;
			$this->response['kyte_iden'] = $sub_account_api->identifier;
			$this->response['kyte_app_id'] = $this->appId === null ? '' : $this->appId;
			$this->response['account_id'] = $this->account->id;

			// default is always public.
			// this can be bypassed for public APIs but is highly discouraged
			if (IS_PRIVATE) {
				// VERIFY SIGNATURE
				$this->verifySignature();
			}

			return true;
		}

		return false;
	}

	/**
	 * Parses the identity string and retrieves relevant information.
	 *
	 * @param string $string The identity string to parse.
	 * @throws \Kyte\Exception\SessionException If the identity string is invalid or the API request has expired.
	 * @throws \Exception If the API key or account information is not found.
	 */
	private function parseIdentityString($string)
	{
		// Identity string format: PUBLIC_KEY%SESSION_TOKEN%DATE_TIME_GMT%ACCOUNT_NUMBER
		$identity = explode('%', base64_decode(urldecode($string)));

		if (count($identity) != 4) {
			throw new \Kyte\Exception\SessionException("[ERROR] Invalid identity string: $this->request.");
		}

		// Get UTC date from identity signature
		$this->utcDate = new \DateTime($identity[2], new \DateTimeZone('UTC'));

		// Check expiration
		if (time() > $this->utcDate->format('U') + SIGNATURE_TIMEOUT) {
			throw new \Kyte\Exception\SessionException("API request has expired.");
		}

		// Check if identity is set and retrieve API key based on the public key
		if (!isset($identity[0])) {
			throw new \Exception("API key is required.");
		}

		if (!$this->key->retrieve('public_key', $identity[0])) {
			throw new \Exception("API key not found.");
		}

		// Get account number from identity signature
		if (!$this->account->retrieve('number', $identity[3])) {
			throw new \Exception("[ERROR] Unable to find account for {$identity[3]}.");
		}

		// If 'undefined' is passed from the front end, set it to zero
		$identity[1] = $identity[1] == 'undefined' ? "0" : $identity[1];

		// Get session token from identity signature
		$this->response['session'] = $identity[1];

		// Retrieve transaction and user token corresponding to the session token
		if ($identity[1] != "0") {
			$session_ret = $this->session->validate($identity[1]);
			$this->response['session'] = $session_ret['session']->sessionToken;
			$this->response['token'] = $session_ret['session']->txToken;

			$this->user = $session_ret['user'];

			$this->response['uid'] = $this->user->id;
			$this->response['name'] = $this->user->name;
			$this->response['email'] = $this->user->email;

			// Check if the user has a different account
			// Get user account
			if ($this->appId === null && $this->user->kyte_account != $this->account->id) {
				if (!$this->account->retrieve('id', $this->user->kyte_account)) {
					throw new \Exception("Unable to find account associated with the user");
				}
			}
		}
	}

	/**
	 * Verify the signature of the API response.
	 *
	 * This method verifies the signature of the API response based on the provided token, API key, and identifier.
	 * It compares the calculated signature with the provided signature and throws an exception if they don't match.
	 *
	 * @throws \Kyte\Exception\SessionException If the calculated signature does not match the provided signature.
	 */
	private function verifySignature()
	{
		$token = $this->response['token'];
		$secretKey = $this->key->secret_key;
		$identifier = $this->key->identifier;

		$hash1 = hash_hmac('SHA256', $token, $secretKey, true);
		$hash1str = hash_hmac('SHA256', $token, $secretKey, false);

		if (VERBOSE_LOG > 0) {
			error_log("hash1 " . hash_hmac('SHA256', $token, $secretKey));
		}

		$hash2 = hash_hmac('SHA256', $identifier, $hash1, true);
		$hash2str = hash_hmac('SHA256', $identifier, $hash1, false);

		if (VERBOSE_LOG > 0) {
			error_log("hash2 " . hash_hmac('SHA256', $identifier, $hash1));
		}

		$calculated_signature = hash_hmac('SHA256', $this->utcDate->format('U'), $hash2);

		if (VERBOSE_LOG > 0) {
			error_log("hash3 $calculated_signature");
			error_log("epoch " . $this->utcDate->format('U'));
		}

		if ($calculated_signature != $this->signature) {
			throw new \Kyte\Exception\SessionException("Calculated signature does not match provided signature.\nCalculated: $hash1str $hash2str $calculated_signature\nProvided: " . $this->signature);
		}
	}


	/**
	 * Generate the signature for the API request.
	 *
	 * This method generates a signature for the API request based on the provided data.
	 * It uses HMAC-SHA256 hashing algorithm with the API key and identifier.
	 *
	 * @throws \Exception If an invalid API access key is detected.
	 */
	private function generateSignature()
	{
		if ($this->request === 'POST' && ALLOW_ENC_HANDOFF && isset($this->data['key'], $this->data['identifier'], $this->data['time'])) {
			$key = $this->data['key'];
			$identifier = $this->data['identifier'];
			$time = $this->data['time'];

			// Retrieve API key using the public_key and identifier being passed
			$obj = new \Kyte\Core\ModelObject(KyteAPIKey);
			if (!$obj->retrieve('public_key', $key, [['field' => 'identifier', 'value' => $identifier]])) {
				throw new \Exception("Invalid API access key");
			}

			// Convert date to PHP DateTime in UTC timezone
			$date = new \DateTime($time, new \DateTimeZone('UTC'));

			// Set token to zero if it's undefined from the front end
			$this->data['token'] = ($this->data['token'] === 'undefined') ? '0' : $this->data['token'];

			$hash1 = hash_hmac('SHA256', $this->data['token'], $obj->secret_key, true);
			$hash2 = hash_hmac('SHA256', $identifier, $hash1, true);
			$this->response['signature'] = hash_hmac('SHA256', $date->format('U'), $hash2);
		}
	}

}