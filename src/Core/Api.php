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
	private function init($public_key)
	{
		if (isset($public_key)) {
			if (!$this->key->retrieve('public_key', $public_key)) throw new \Exception("API key not found.");
		} else throw new \Exception("API key is required.");
	}

	private function addPrimaryKey(&$modeldef) {
		$modeldef['struct']['id'] = [
			'type'		=> 'i',
			'required'	=> true,
			'pk'		=> true,
			'size'		=> 11,
			'date'		=> false,
		];
	}

	private function addKyteAttributes(&$modeldef) {
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

	private function bootstrap() {
		// compatibility for older config files
		if (!defined('ALLOW_ENC_HANDOFF')) {
			define('ALLOW_ENC_HANDOFF', true);
			error_log('ALLOW_ENC_HANDOFF contant not defined...using defaults');
		}
		if (!defined('ALLOW_MULTILOGON')) {
			define('ALLOW_MULTILOGON', false);
			error_log('ALLOW_MULTILOGON contant not defined...using defaults');
		}
		if (!defined('ALLOW_SAME_TXTOKEN')) {
			define('ALLOW_SAME_TXTOKEN', false);
			error_log('ALLOW_SAME_TXTOKEN contant not defined...using defaults');
		}
		if (!defined('SESSION_TIMEOUT')) {
			define('SESSION_TIMEOUT', 3600);
			error_log('SESSION_TIMEOUT contant not defined...using defaults');
		}
		if (!defined('USERNAME_FIELD')) {
			define('USERNAME_FIELD', 'email');
			error_log('USERNAME_FIELD contant not defined...using defaults');
		}
		if (!defined('PASSWORD_FIELD')) {
			define('PASSWORD_FIELD', 'password');
			error_log('PASSWORD_FIELD contant not defined...using defaults');
		}
		if (!defined('VERBOSE_LOG')) {
			define('VERBOSE_LOG', false);
			error_log('VERBOSE_LOG contant not defined...using defaults');
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
	
		// initialize base framework
		\Kyte\SendGrid\Mail::setSendGridAPIKey(KYTE_SENDGRID_API);
		\Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
		\Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
		\Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
		\Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
		\Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);
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

		if (isset($_SERVER['HTTP_X_KYTE_SIGNATURE'], $_SERVER['HTTP_X_KYTE_IDENTITY'])) {
			$this->signature = $_SERVER['HTTP_X_KYTE_SIGNATURE'];
			$this->parseIdentityString($_SERVER['HTTP_X_KYTE_IDENTITY']);
		} else return false;

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

		// * URL format - root endpoint
		// https://uri-to-api-endpoint / {model} [ / {field} / {value} ]
		//
		/* parse URI        ** remember to add the following in .htaccess 'FallbackResource /index.php'
		* URL formats:
		* POST     /{model}
		* PUT      /{model}/{field}/{value} + data
		* GET      /{model}/{field}/{value}
		* DELETE   /{model}/{field}/{value}
		*/

		// Trim leading slash(es)
		$path = ltrim($_SERVER['REQUEST_URI'], '/');

		$elements =  explode('/', $path);

		$this->prepareResponse();

		if (count($elements) >= 1) {
			// check if app id exists
			$this->appId = base64_decode(urldecode($elements[2]));
			
			$this->model = $elements[0];
			$this->field = isset($elements[1]) ? $elements[1] : null;
			$this->value = isset($elements[2]) ? urldecode($elements[2]) : null;

			// get api associated with account
			$sub_account_api = new \Kyte\Core\ModelObject(APIKey);
			if (!$sub_account_api->retrieve('kyte_account', $this->account->id)) {
				throw new \Exception("[ERROR] Unable to find API information for account");
			}

			// return account information in response - this is required for API handoff between master account and subaccounts
			$this->response['kyte_pub'] = $sub_account_api->public_key;
			$this->response['kyte_num'] = $this->account->number;
			$this->response['kyte_iden'] = $sub_account_api->identifier;

			// VERIFY SIGNATURE
			$this->verifySignature();

			return true;
		}
		
		return false;
	}

	private function parseIdentityString($string) {
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
		$identity[1] = $identity[1] == 'undefined' ? 0 : $identity[1];
		
		// get session token from identity signature
		$this->response['session'] = $identity[1];

		// retrieve transaction and user token corresponding to session token
		if ($identity[1]) {
			$session_ret = $this->session->validate($identity[1]);
			$this->response['token'] = $session_ret['txToken'];
			$this->response['uid'] = $session_ret['uid'];
			
			if (!$this->user->retrieve('id', $session_ret['uid'])) {
				throw new \Kyte\Exception\SessionException("Invalid user session.");
			}
			$this->response['sessionPermission'] = $this->user->role;

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
		$hash1_debug = hash_hmac('SHA256', $this->response['token'], $this->key->secret_key);
		$hash2 = hash_hmac('SHA256', $this->key->identifier, $hash1, true);
		$hash2_debug = hash_hmac('SHA256', $this->key->identifier, $hash1);
		$calculated_signature = hash_hmac('SHA256', $this->utcDate->format('U'), $hash2);

		if ($calculated_signature != $this->signature)
			throw new \Kyte\Exception\SessionException("Calculated signature does not match provided signature.");
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
			$this->data['token'] = $this->data['token'] == 'undefined' ? 0 : $this->data['token'];
	
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
		$this->response['CONTENT_TYPE'] = $this->contentType;
		$this->response['transaction'] = $this->request;
		$this->response['engine_version'] = \Kyte\Core\Version::get();
		$this->response['model'] = $this->model;
		
		$this->response['session'] = '0';
		$this->response['token'] = 0;	// default to public token
		$this->response['uid'] = 0;
		$this->response['sessionPermission'] = 0;
		$now = new \DateTime();
		$now->setTimezone(new \DateTimeZone('UTC'));    // Another way
		$this->response['txTimestamp'] = $now->format('U');
	}

	// meat of API
	public function route() {
		try {			
			// if minimum count of elements exist, then process api request based on request type
			if ($this->isRequest()) {

				if ($this->appId) {
					// retrieve model definition
					//
					// switch DBI to client database
					//
					$controller = new \Kyte\Client\ModelController();
				} else {
					// initialize controller for model or view ("abstract" controller)
					if (class_exists('\\Kyte\Mvc\\Controller\\'.$this->model.'Controller')) {
						$controllerClass = '\\Kyte\Mvc\\Controller\\'.$this->model.'Controller';
					} else {
						$controllerClass = class_exists($this->model.'Controller') ? $this->model.'Controller' : '\\Kyte\\Mvc\\Controller\\ModelController';
					}
					// create new controller with model, app date format (i.e. Ymd), and new transaction token (to be verified again if private api)
					$controller = new $controllerClass(defined($this->model) ? constant($this->model) : null, APP_DATE_FORMAT, $this->account, $this->session, $this->user, $this->response);
					if (!$controller) throw new \Exception("[ERROR] Unable to create controller for model: $controllerClass.");
				}

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
			echo json_encode($this->response);
			exit(0);
		} catch (\Exception $e) {
			http_response_code(400);
			$this->response['error'] = $e->getMessage();
			echo json_encode($this->response);
			exit(0);
		}

		// return response data
		echo json_encode($this->response);
	}
}

?>
