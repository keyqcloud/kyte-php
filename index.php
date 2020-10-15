<?php

/*******************************************/
/************* CORS VALIDATION *************/
/*******************************************/

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

/*******************************************/
/*********** END CORS VALIDATION ***********/
/*******************************************/

// initialize api
require_once __DIR__.'/initializer.php';

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

// initialie empty array for response data
//
// return json form:
// {
// 	token: ‘TRANSACTION_TOKEN’,
// 	session: ‘SESSION_TOKEN’,
// 	error: ‘ERROR_MESSAGE’,
// 	model: ‘MyModel’,
// 	transaction: ‘PUT’,
// 	txTimestamp: ‘Thu, 30 Apr 2020 07:11:46 GMT’,
// 	data: {}
// }
$response = [];
$response['engine_version'] = \Kyte\Version::get();
$response['framework_version'] = Version::get();
$response['session'] = '0';
$response['token'] = "0";	// default to public token
$response['uid'] = "0";
$response['error'] = '';
$response['model'] = '';
$response['transaction'] = $request;
$now = new DateTime();
$now->setTimezone(new DateTimeZone('UTC'));    // Another way
$response['txTimestamp'] = $now->format('U');

$contentType = '';
if (array_key_exists('CONTENT_TYPE', $_SERVER)) {
    $contentType = $_SERVER['CONTENT_TYPE'];
}

// URL format
// https://uri-to-api-endpoint/ {signature} / {identity string} / {model} [ / {field} / {value} ]
 
try {
    // read in data and parse into array
    parse_str(file_get_contents("php://input"), $data);
    $response['CONTENT_TYPE'] = $contentType;
    // if content type is json, then parse json
    $pattern = '/json/';
    if (preg_match($pattern, $contentType)) {
        $data = json_decode(file_get_contents("php://input"), true);
    }

    // setup transaction log
    $log = new \Kyte\ModelObject(SecurityTransactionHistory);
    // create new transaction record and update throughout api call
    if (!$log->create([
        'url_path'      => $_SERVER['REQUEST_URI'],
        'origin'        => $origin,
        'ip_address'    => $_SERVER['REMOTE_ADDR'],
        'user_agent'    => $_SERVER['HTTP_USER_AGENT'],
        'method'        => $request,
        'data'          => print_r($data, true),
    ])) {
        // if log creation fails, don't quit but report in system log
        error_log("[ERROR] Unable to create new transaction log for access from $origin for request method $request");
    }

    /* parse URI        ** remember to add the following in .htaccess 'FallbackResource /index.php'
    * URL formats:
    * POST     /{signature}/{identity string}/{model}
    * PUT      /{signature}/{identity string}/{model}/{field}/{value} + data
    * GET      /{signature}/{identity string}/{model}/{field}/{value}
    * DELETE   /{signature}/{identity string}/{model}/{field}/{value}
    */
    // Trim leading slash(es)
    $path = ltrim($_SERVER['REQUEST_URI'], '/');
    // Split path on slashes
    $elements = explode('/', $path);
    //** change element split method to allow for fewer uri fields for some requests */

    // if there are elements then process api request based on request type
    if (count($elements) >= 3) {
        // model
        $response['model'] = $elements[2];

        // get the identity string and verify
        $idenstr = base64_decode(urldecode($elements[1]));

        // identity string format:
        // public_key%session_token%UTC date format%account #
        $iden = explode('%', $idenstr);

        if (count($iden) != 4) {
            throw new SessionException("[ERROR] Invalid identity string: $request.");
        }

        // #1
        // get UTC date from identity signature
        $date = new DateTime($iden[2], new DateTimeZone('UTC'));
        // check expiration
        if (time() > $date->format('U') + (60*30)) {
            throw new SessionException("API request has expired.");
        }

        // #2
        // initialize API with public key from idneity signature
        $api = new \Kyte\API(APIKey);
        $api->init($iden[0]);

        // #3
        // get account number from identity signature
        $account = new \Kyte\ModelObject(Account);
        if (!$account->retrieve('number', $iden[3])) {
            throw new Exception("[ERROR] Unable to find account for {$iden[3]}.");
        }

        // #4
        // if undefined is passed from front end then set to zero
        $iden[1] = $iden[1] == 'undefined' ? "0" : $iden[1];
        // get session token from identity signature
        $response['session'] = $iden[1];
        // retrieve transaction and user token corresponding to session token
        $session = new SessionManager(Session, User, USERNAME_FIELD, PASSWORD_FIELD, ALLOW_MULTILOGON, SESSION_TIMEOUT);
        $user = new \Kyte\ModelObject(User);
        if ($iden[1]) {
            $session_ret = $session->validate($iden[1]);
            $response['token'] = $session_ret['txToken'];
            $response['uid'] = $session_ret['uid'];
            
            if (!$user->retrieve('id', $session_ret['uid'], [[ 'field' => 'account_id', 'value' => $account->getParam('id')]])) {
                throw new SessionException("Invalid user session.");
            }
        }
        // update log with tx token
        $log->save(['txToken' => $response['token']]);

        /* ********************************** */
        /* **** VERIFY SIGNATURE - START **** */
		// calculate hash based on provided information
        $hash1 = hash_hmac('SHA256', $response['token'], $api->key->getParam('secret_key'), true);
        $hash1_debug = hash_hmac('SHA256', $response['token'], $api->key->getParam('secret_key'));
        $hash2 = hash_hmac('SHA256', $api->key->getParam('identifier'), $hash1, true);
        $hash2_debug = hash_hmac('SHA256', $api->key->getParam('identifier'), $hash1);
        $calculated_signature = hash_hmac('SHA256', $date->format('U'), $hash2);
        // error_log("Time: ".$date->format('U')." ".$iden[2]."\n");
        // error_log("hash1: $hash1_debug\thash2:$hash2_debug\tFinal:$calculated_signature\n");
        // error_log("Client: ".$elements[0]."\n");
        if ($calculated_signature != $elements[0])
            throw new SessionException("Calculated signature does not match provided signature.");
        /* **** VERIFY SIGNATURE - END **** */
        /* ********************************** */

        // initialize controller for model or view ("abstract" controller)
        $controllerClass = class_exists($elements[2].'Controller') ? $elements[2].'Controller' : 'ModelController';
        // create new controller with model, app date format (i.e. Ymd), and new transaction token (to be verified again if private api)
        $controller = new $controllerClass(isset(${$elements[2]}) ? ${$elements[2]} : null, APP_DATE_FORMAT, $account, $session, $user, &$response);
        if (!$controller) throw new Exception("[ERROR] Unable to create controller for model: $controllerClass.");

        switch ($request) {
            case 'POST':
                // post data = data
                // new  :   {data}
                $response['data'] = $controller->new($data);
                break;

            case 'PUT':
                // element[3] = field
                // element[4] = value
                // post data = data
                // update   :   {field}, {value}, {data}
                $response['data'] = $controller->update((isset($elements[3]) ? $elements[3] : null), (isset($elements[4]) ? urldecode($elements[4]) : null), $data);
                break;

            case 'GET':
                // element[3] = field
                // element[4] = value
                // get  :   {field}, {value}
                $response['data'] = $controller->get((isset($elements[3]) ? $elements[3] : null), (isset($elements[4]) ? urldecode($elements[4]) : null));
                break;

            case 'DELETE':
                // element[3] = field
                // element[4] = value
                // delete   :   {field}, {value}
                $response['data'] = $controller->delete((isset($elements[3]) ? $elements[3] : null), (isset($elements[4]) ? urldecode($elements[4]) : null));
                break;
            
            default:
                throw new Exception("[ERROR] Unknown HTTP request type: $request.");
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
            
        if(count($elements) == 1 && ALLOW_ENC_HANDOFF) {
            /* POST REQUEST */
            if ($request == 'POST') {
                // get api key using the public_key and identifier being passed
                $obj = new \Kyte\ModelObject(APIKey);
                if (!$obj->retrieve('public_key', $data['key'], [[ 'field' => 'identifier', 'value' => $data['identifier'] ]])) {
                    throw new Exception("Invalid API access key");
                }
        
                // get date and convert to php datetime in UTC timezone
                $date = new DateTime($data['time'], new DateTimeZone('UTC'));

                // if undefined is passed from front end then set to zero
                $data['token'] = $data['token'] == 'undefined' ? 0 : $data['token'];
        
                $hash1 = hash_hmac('SHA256', $data['token'], $obj->getParam('secret_key'), true);
                $hash2 = hash_hmac('SHA256', $data['identifier'], $hash1, true);
                $response['signature'] = hash_hmac('SHA256', $date->format('U'), $hash2);
            }
        }
    }
} catch (Kyte\SessionException $e) {
    http_response_code(403);
    $response['error'] = $e->getMessage();
    // log return response
    $log->save(['return' => print_r($response, true)]);
	echo json_encode($response);
	exit(0);
} catch (Exception $e) {
    http_response_code(400);
    $response['error'] = $e->getMessage();
    // log return response
    $log->save(['return' => print_r($response, true)]);
	echo json_encode($response);
	exit(0);
}

// log return response
$log->save(['return' => print_r($response, true)]);

// return response data
echo json_encode($response);

?>
