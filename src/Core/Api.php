<?php

namespace Kyte\Core;

/*
 * Class Session
 *
 * @package Kyte
 *
 */

class Api
{
	private $key = null;
	private $account;
	private $session;
	private $user;
	private $request;
	private $contentType;
	private $signature = null;
	private $utcDate = null;
	private $appId = null;
	private $model;
	private $field = null;
	private $value = null;
	private $data;
	private $page_size;
	private $page_total;
	private $page_num = 1;
	private $total_count;
	private $total_filtered;
	private $syntax_error = null;

	private $response = [];
	

	// override parent constriuctor
	public function __construct() {
		// initialize api
		$this->bootstrap();

		// instantiate an API key object
		$this->key = new \Kyte\Core\ModelObject(APIKey);
		$this->session = new \Kyte\Session\SessionManager(Session, User, USERNAME_FIELD, PASSWORD_FIELD, ALLOW_MULTILOGON, SESSION_TIMEOUT);
		$this->account = new \Kyte\Core\ModelObject(Account);
		$this->user = new \Kyte\Core\ModelObject(User);
	}

	// if origin is left null then origin validation is skipped
	public function init($public_key)
	{
		if (isset($public_key)) {
			if (!$this->key->retrieve('public_key', $public_key)) throw new \Exception("API key not found.");
			return true;
		} else {
			throw new \Exception("API key is required.");
			return false;
		}
	}

	public static function addPrimaryKey(&$modeldef) {
		$modeldef['struct']['id'] = [
			'type'		=> 'i',
			'required'	=> true,
			'pk'		=> true,
			'size'		=> 11,
			'date'		=> false,
		];
	}

	public static function addKyteAttributes(&$modeldef) {
		$modeldef['struct']['kyte_account'] = [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Account',
				'field'	=> 'id',
			],
		];

		// audit attributes

		$modeldef['struct']['created_by'] = [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'User',
				'field'	=> 'id',
			],
		];

		$modeldef['struct']['date_created'] = [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		];

		$modeldef['struct']['modified_by'] = [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'User',
				'field'	=> 'id',
			],
		];

		$modeldef['struct']['date_modified'] = [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		];

		$modeldef['struct']['deleted_by'] = [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'User',
				'field'	=> 'id',
			],
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

	public static function checkSyntax($filename) {
		if (CHECK_SYNTAX_ON_IMPORT) {
			if (strpos(exec("php -l $filename"), "No syntax errors") === false) {
				error_log("Syntax error with $filename. Skipping file.");
				return $filename;
			} else {
				return true;
			}
		} else {
			return true;
		}
	}

	public static function dbconnect() {
		if (\Kyte\Core\DBI::$dbUser == KYTE_DB_USERNAME && \Kyte\Core\DBI::$dbName == KYTE_DB_DATABASE && \Kyte\Core\DBI::$dbHost == KYTE_DB_HOST) {
				return;
		}

		\Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
		\Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
		\Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
		\Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
		\Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);
		// \Kyte\Core\DBI::connect();
	}

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
		// \Kyte\Core\DBI::connectApp();
	}

	public static function dbswitch($useApp = false) {
		\Kyte\Core\DBI::$useAppDB = $useApp;
	}

	private function bootstrap() {
		if (!defined('DEBUG')) {
			define('DEBUG', false);
			error_log('DEBUG constant not defined...using defaults');
		}
		// compatibility for older config files
		if (!defined('ALLOW_ENC_HANDOFF')) {
			define('ALLOW_ENC_HANDOFF', true);
			error_log('ALLOW_ENC_HANDOFF constant not defined...using defaults');
		}
		if (!defined('ALLOW_MULTILOGON')) {
			define('ALLOW_MULTILOGON', false);
			error_log('ALLOW_MULTILOGON constant not defined...using defaults');
		}
		if (!defined('ALLOW_SAME_TXTOKEN')) {
			define('ALLOW_SAME_TXTOKEN', false);
			error_log('ALLOW_SAME_TXTOKEN constant not defined...using defaults');
		}
		if (!defined('SESSION_TIMEOUT')) {
			define('SESSION_TIMEOUT', 3600);
			error_log('SESSION_TIMEOUT constant not defined...using defaults');
		}
		if (!defined('USERNAME_FIELD')) {
			define('USERNAME_FIELD', 'email');
			error_log('USERNAME_FIELD constant not defined...using defaults');
		}
		if (!defined('PASSWORD_FIELD')) {
			define('PASSWORD_FIELD', 'password');
			error_log('PASSWORD_FIELD constant not defined...using defaults');
		}
		if (!defined('VERBOSE_LOG')) {
			define('VERBOSE_LOG', false);
			error_log('VERBOSE_LOG constant not defined...using defaults');
		}
		if (!defined('IS_PRIVATE')) {
			define('IS_PRIVATE', true);
			error_log('IS_PRIVATE constant not defined...using defaults');
		}
		if (!defined('RETURN_NO_MODEL')) {
			define('RETURN_NO_MODEL', true);
			error_log('RETURN_NO_MODEL constant not defined...using defaults');
		}
		if (!defined('SESSION_RETURN_FK')) {
			define('SESSION_RETURN_FK', true);
			error_log('SESSION_RETURN_FK constant not defined...using defaults');
		}
		if (!defined('PAGE_SIZE')) {
			define('PAGE_SIZE', 50);
			error_log('PAGE_SIZE constant not defined...using defaults');
		}
		if (!defined('USE_SESSION_MAP')) {
			define('USE_SESSION_MAP', false);
			error_log('USE_SESSION_MAP constant not defined...using defaults');
		}
		if (!defined('CHECK_SYNTAX_ON_IMPORT')) {
			define('CHECK_SYNTAX_ON_IMPORT', false);
			error_log('CHECK_SYNTAX_ON_IMPORT constant not defined...using defaults');
		}
		if (!defined('STRICT_TYPING')) {
			define('STRICT_TYPING', true);
			error_log('STRICT_TYPING constant not defined...using defaults');
		}

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
	
		$this->loadModelsAndControllers();
	
		// initialize base framework
		self::dbconnect();
	}

	private function loadModelsAndControllers() {
		// list of models
		$models = [];

		/* BUILTIN DEFINED MODELS */
		// import builtin models first, but don't define them yet incase there are user overrides and changes
		foreach (glob(__DIR__ . "/../Mvc/Model/*.php") as $filename) {
			$model_name = substr($filename, 0, strrpos($filename, "."));
			$model_name = str_replace(__DIR__ . '/../Mvc/Model/','',$model_name);

			// check syntax before importing file
			$f = self::checkSyntax($filename);
			if ($f === true) {
				require_once($filename);
				if (VERBOSE_LOG) {
					error_log("Importing builtin model $model_name...");
				}
				self::addPrimaryKey($$model_name);
				$models[$model_name] = $$model_name;
			} else {
				$this->syntax_error = $f;
			}
		}

		if(defined('APP_DIR') && isset($_SERVER['HTTP_X_KYTE_APPID'])) {
			$this->appId = $_SERVER['HTTP_X_KYTE_APPID'];

			// next load user defined models
			// if model already exists, apply changes/overrides
			if ( file_exists( APP_DIR . "/app/" ) && is_dir( APP_DIR . "/app/" ) ) {
		
				/* USER DEFINED MODELS */
				// load user defined models and controllers (allow override of builtin)
				if ( file_exists( APP_DIR . "/app/models/{$this->appId}/" ) && is_dir( APP_DIR . "/app/models/{$this->appId}/" ) ) {    
					foreach (glob(APP_DIR . "/app/models/{$this->appId}/*.php") as $filename) {
						$model_name = substr($filename, 0, strrpos($filename, "."));
						$model_name = str_replace(APP_DIR . "/app/models/{$this->appId}/",'',$model_name);

						if (!array_key_exists($model_name, $models)) {
							// check syntax before importing file
							$f = self::checkSyntax($filename);
							if ($f === true) {
								require_once($filename);
								if (VERBOSE_LOG) {
									error_log("Importing user defined model $model_name...");
								}
								self::addPrimaryKey($$model_name);
								self::addKyteAttributes($$model_name);
								// add app id
								$$model_name['appId'] = $this->appId;
								// add model to list of models
								$models[$model_name] = $$model_name;
							} else {
								$this->syntax_error = $f;
							}
						} else {
							// check syntax before importing file
							$f = self::checkSyntax($filename);
							if ($f === true) {
								require_once($filename);
								// user overrides are specified
								if (VERBOSE_LOG) {
									error_log("Overriding defined model $model_name...");
								}

								// override or add attributes
								foreach($$model_name['struct'] as $key => $value) {
									$models[$model_name]['struct'][$key] = $value;
								}
							} else {
								$this->syntax_error = $f;
							}
						}
					}
				}
		
				/* USER DEFINED CONTROLLER */
				// load user-defined controllers
				if ( file_exists( APP_DIR . "/app/controllers/{$this->appId}/" ) && is_dir( APP_DIR . "/app/controllers/{$this->appId}/" ) ) {
					foreach (glob(APP_DIR . "/app/controllers/{$this->appId}/*.php") as $filename) {
						$controller_name = substr($filename, 0, strrpos($filename, "."));
						$controller_name = str_replace(APP_DIR . "/app/controllers/{$this->appId}/",'',$controller_name);

						// check syntax before importing file
						$f = self::checkSyntax($filename);
						if ($f === true) {
							require_once($filename);
							if (VERBOSE_LOG) {
								error_log("Checking if user defined controller has been defined...".(class_exists($controller_name) ? 'defined!' : 'UNDEFINED!'));
							}
						} else {
							$this->syntax_error = $f;
						}
					}
				}      
			}
		}

		// define all models that were imported
		foreach($models as $model) {
			define($model['name'], $model);
		}
	
		// define list of models
		if (!defined('KYTE_MODELS')) define('KYTE_MODELS', $models);
	}

	// meat of API
	public function route() {
		try {
			// prepare the return response
			$this->prepareResponse();

			// if minimum count of elements exist, then process api request based on request type
			if ($this->isRequest()) {
				if (class_exists('\\Kyte\Mvc\\Controller\\'.$this->model.'Controller')) {
					$controllerClass = '\\Kyte\Mvc\\Controller\\'.$this->model.'Controller';
				} else {
					$controllerClass = class_exists($this->model.'Controller') ? $this->model.'Controller' : '\\Kyte\\Mvc\\Controller\\ModelController';
				}
				// create new controller with model, app date format (i.e. Ymd), and new transaction token (to be verified again if private api)
				$controller = new $controllerClass(defined($this->model) ? constant($this->model) : null, APP_DATE_FORMAT, $this->account, $this->session, $this->user, $this->response, $this->page_size, $this->page_total, $this->page_num, $this->total_count, $this->total_filtered);
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

		// return response data
		$this->response = ['response_code' => 200] + $this->response;
		if (defined('LOG_RESPONSE')) {
			error_log(json_encode($this->response, JSON_PRETTY_PRINT));
		}
		echo json_encode($this->response);
	}

	private function cors() {
		// get origin of requester
		if (array_key_exists('HTTP_ORIGIN', $_SERVER)) {
			$origin = $_SERVER['HTTP_ORIGIN'];
		} else if (array_key_exists('HTTP_REFERER', $_SERVER)) {
			$origin = $_SERVER['HTTP_REFERER'];
		} else {
			$origin = $_SERVER['REMOTE_ADDR'];
		}

		header("Access-Control-Allow-Origin: $origin");
		header('Access-Control-Allow-Credentials: true');
		header("Content-Type: application/json; charset=utf-8");

		// get request type
		$request = $_SERVER['REQUEST_METHOD'];

		// Access-Control headers are received during OPTIONS requests
		if ($request == 'OPTIONS') {

			if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
				header("Access-Control-Allow-Methods: GET, PUT, POST, DELETE, HEAD, OPTION");         

			if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
				header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

			exit(0);
		}

		return $request;
	}

	private function isRequest() {
		// CORS Validation
		$this->request = $this->cors();

		if (array_key_exists('CONTENT_TYPE', $_SERVER)) {
			$this->contentType = $_SERVER['CONTENT_TYPE'];
		}

		// read in data and parse into array
		parse_str(file_get_contents("php://input"), $this->data);

		// if content type is json, then parse json
		$pattern = '/json/';
		if (preg_match($pattern, $this->contentType)) {
			$this->data = json_decode(file_get_contents("php://input"), true);
		}

		if (VERBOSE_LOG > 0) {
			error_log(print_r( $this->data, true ));
		}

		if (IS_PRIVATE) {
			if (isset($_SERVER['HTTP_X_KYTE_SIGNATURE'])) {
				$this->signature = $_SERVER['HTTP_X_KYTE_SIGNATURE'];
			} else return false;
		}
		
		if (isset($_SERVER['HTTP_X_KYTE_IDENTITY'])) {
			$this->parseIdentityString($_SERVER['HTTP_X_KYTE_IDENTITY']);
		} else return false;

		// set page size
		$this->page_size = isset($_SERVER['HTTP_X_KYTE_PAGE_SIZE']) ? intval($_SERVER['HTTP_X_KYTE_PAGE_SIZE']) : PAGE_SIZE;
		// get page num from header
		$this->page_num = isset($_SERVER['HTTP_X_KYTE_PAGE_IDX']) ? intval($_SERVER['HTTP_X_KYTE_PAGE_IDX']) : 0;

		//datatables specific
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

		// Trim leading slash(es)
		$path = ltrim($_SERVER['REQUEST_URI'], '/');

		$elements =  explode('/', $path);

		if (count($elements) >= 1) {
			$this->model = $elements[0];
			$this->field = isset($elements[1]) ? $elements[1] : null;
			$this->value = isset($elements[2]) ? urldecode($elements[2]) : null;
			

			$this->response['model'] = $this->model;

			// get api associated with account
			$sub_account_api = new \Kyte\Core\ModelObject(APIKey);
			if (!$sub_account_api->retrieve('kyte_account', $this->account->id)) {
				throw new \Exception("[ERROR] Unable to find API information for account");
			}

			// return account information in response - this is required for API handoff between master account and subaccounts
			$this->response['kyte_api'] = API_URL;
			$this->response['kyte_pub'] = $sub_account_api->public_key;
			$this->response['kyte_num'] = $this->account->number;
			$this->response['kyte_iden'] = $sub_account_api->identifier;
			$this->response['account_id'] = $this->account->id;

			// default is always public.
			// this can be bypassed for public APIs but is highly discouraged
			if (IS_PRIVATE) {
				// VERIFY SIGNATURE
				$this->verifySignature();
			}

			// if appid is not null, create db connection for app
			if ($this->appId != null) {
				$app = new \Kyte\Core\ModelObject(Application);
				if (!$app->retrieve('identifier', $this->appId)) {
					throw new \Exception("CRITICAL ERROR: Unable to find application and perform context switch for app ID {$this->appId}.");
				}
				self::dbappconnect($app->db_name, $app->db_username, $app->db_password);
			}

			return true;
		}
		
		return false;
	}

	private function parseIdentityString($string) {
		// identity string format
		// PUBLIC_KEY%SESSION_TOKEN%DATE_TIME_GMT%ACCOUNT_NUMBER
		$identity = explode('%', base64_decode(urldecode($string)));
		if (count($identity) != 4) {
			throw new \Kyte\Exception\SessionException("[ERROR] Invalid identity string: $this->request.");
		}
		
		// get UTC date from identity signature
		$this->utcDate = new \DateTime($identity[2], new \DateTimeZone('UTC'));
		
		// check expiration
		if (time() > $this->utcDate->format('U') + (60*30)) {
			throw new \Kyte\Exception\SessionException("API request has expired.");
		}
		
		// initialize API with public key from identity signature
		$this->init($identity[0]);

		// get account number from identity signature
		if (!$this->account->retrieve('number', $identity[3])) {
			throw new \Exception("[ERROR] Unable to find account for {$identity[3]}.");
		}
		
		// if undefined is passed from front end then set to zero
		$identity[1] = $identity[1] == 'undefined' ? "0" : $identity[1];
		
		// get session token from identity signature
		$this->response['session'] = $identity[1];

		// retrieve transaction and user token corresponding to session token
		if ($identity[1] != "0") {
			$session_ret = $this->session->validate($identity[1]);
			$this->response['session'] = $session_ret['sessionToken'];
			$this->response['token'] = $session_ret['txToken'];
			$this->response['uid'] = $session_ret['uid'];
			
			if (!$this->user->retrieve('id', $session_ret['uid'])) {
				throw new \Kyte\Exception\SessionException("Invalid user session.");
			}

			$this->response['name'] = $this->user->name;
			$this->response['email'] = $this->user->email;

			error_log("ACCOUNTS ".$this->account->id." and ".$this->user->kyte_account);

			// check is user has different account
			// get user account
			if ($this->user->kyte_account != $this->account->id) {
				if (!$this->account->retrieve('id', $this->user->kyte_account)) {
					throw new \Exception("Unable to find account associated with user");
				}
			}
		}
	}

	private function verifySignature() {
		$hash1 = hash_hmac('SHA256', $this->response['token'], $this->key->secret_key, true);
		$hash1str = hash_hmac('SHA256', $this->response['token'], $this->key->secret_key, false);

		if (VERBOSE_LOG > 0) error_log("hash1 ".hash_hmac('SHA256', $this->response['token'], $this->key->secret_key));
		
		$hash2 = hash_hmac('SHA256', $this->key->identifier, $hash1, true);
		$hash2str = hash_hmac('SHA256', $this->key->identifier, $hash1, false);
		
		if (VERBOSE_LOG > 0) error_log("hash2 ".hash_hmac('SHA256', $this->key->identifier, $hash1));
		
		$calculated_signature = hash_hmac('SHA256', $this->utcDate->format('U'), $hash2);
		
		if (VERBOSE_LOG > 0) error_log("hash3 $calculated_signature");
		if (VERBOSE_LOG > 0) error_log("epoch ".$this->utcDate->format('U'));

		if ($calculated_signature != $this->signature)
			throw new \Kyte\Exception\SessionException("Calculated signature does not match provided signature.\nCalculated: $hash1str $hash2str $calculated_signature\nProvided: ".$this->signature);
	}

	private function generateSignature() {
		if ($this->request == 'POST' && ALLOW_ENC_HANDOFF && isset($this->data['key'], $this->data['identifier'], $this->data['time'])) {
			// get api key using the public_key and identifier being passed
			$obj = new \Kyte\Core\ModelObject(APIKey);
			if (!$obj->retrieve('public_key', $this->data['key'], [[ 'field' => 'identifier', 'value' => $this->data['identifier'] ]])) {
				throw new \Exception("Invalid API access key");
			}
	
			// get date and convert to php datetime in UTC timezone
			$date = new \DateTime($this->data['time'], new \DateTimeZone('UTC'));

			// if undefined is passed from front end then set to zero
			$this->data['token'] = $this->data['token'] == 'undefined' ? '0' : $this->data['token'];
	
			$hash1 = hash_hmac('SHA256', $this->data['token'], $obj->secret_key, true);
			$hash2 = hash_hmac('SHA256', $this->data['identifier'], $hash1, true);
			$this->response['signature'] = hash_hmac('SHA256', $date->format('U'), $hash2);
		}
	}

	private function prepareResponse() {
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
		$this->response['syntax_error'] = $this->syntax_error;
		$this->response['session'] = '0';
		$this->response['token'] = '0';	// default to public token
		$this->response['uid'] = '0';
		$now = new \DateTime();
		$now->setTimezone(new \DateTimeZone('UTC'));    // Another way
		$this->response['txTimestamp'] = $now->format('U');
	}
}
