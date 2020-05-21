<?php

/* CORS VALIDATION */
// get origin of requester
if (array_key_exists('HTTP_ORIGIN', $_SERVER)) {
    $origin = $_SERVER['HTTP_ORIGIN'];
} else if (array_key_exists('HTTP_REFERER', $_SERVER)) {
    $origin = $_SERVER['HTTP_REFERER'];
} else {
    $origin = $_SERVER['REMOTE_ADDR'];
}

// get request type
$request = $_SERVER['REQUEST_METHOD'];

error_log("Access from $origin for request method $request");

header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header("Content-Type: application/json; charset=utf-8");

// Access-Control headers are received during OPTIONS requests
if ($request == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, PUT, POST, DELETE, HEAD, OPTION");         

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

    exit(0);
}

require_once __DIR__.'/initializer.php';

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
$response['version'] = \Kyte\ApplicationVersion::get();
$response['token'] = '';
$response['session'] = '';
$response['error'] = '';
$response['model'] = '';
$response['transaction'] = $request;
$now = new DateTime();
$now->setTimezone(new DateTimeZone('UTC'));    // Another way
$response['txTimestamp'] = $now->format('U');
$contentType = $_SERVER['CONTENT_TYPE'];
// URL format
// https://uri-to-api-endpoint/ {signature} / {identity string} / {model} [ / {field} / {value} ]
 

try {
    // read in data and parse into array
    parse_str(file_get_contents("php://input"), $data);
    error_log($contentType);
    // if data is empty on post or put then maybe it's json, parse json
    if ($contentType == 'application/json') {
        $data = json_decode(file_get_contents("php://input"), true);
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

    error_log("Access from $origin with element count of ".count($elements));

    // if there are elements then process api request based on request type
    if (count($elements) >= 3) {
        // model
        $response['model'] = $elements[2];

        // get the identity string and verify
        $idenstr = base64_decode(urldecode($elements[1]));
        // identity string format:
        // public_key:session_token:UTC date format
        $iden = explode('%', $idenstr);
        error_log('identity: '.$idenstr);
        if (count($iden) != 3) {
            throw new Exception("[ERROR] Invalid identity string: $request.");
        }

        // session token
        $response['session'] = $iden[1];

        $api = new \Kyte\API(APIKey);
        // init new api with key
        $api->init($iden[0]);

        // check if signature is valid - signature and signature datetime
        $date = new DateTime(urldecode($iden[2]), new DateTimeZone('UTC'));

        $txToken = 0;	// default to public token

        // if undefined is passed from front end then set to zero
        $iden[1] = $iden[1] == 'undefined' ? 0 : $iden[1];

        // if sessionToken is not 0, then private API access
        if ($iden[1] != 0) {
            // retrieve transaction token corresponding to session token
            $sessionObj = new \Kyte\ModelObject(Session);
            if ($sessionObj->retrieve('sessionToken', $iden[1])) {
                $txToken = $sessionObj->getParam('txToken');
            }
        }

		// calculate hash based on provided information
        $hash1 = hash_hmac('SHA256', $txToken, $api->key->getParam('secret_key'), true);
        error_log('hash1: '.hash_hmac('SHA256', $txToken, $api->key->getParam('secret_key')));
        $hash2 = hash_hmac('SHA256', $api->key->getParam('identifier'), $hash1, true);
        error_log('hash2: '.hash_hmac('SHA256', $api->key->getParam('identifier'), $hash1));
        $calculated_signature = hash_hmac('SHA256', $date->format('U'), $hash2);
        error_log('epoch: '.$date->format('U'));

        error_log('signature: '.$elements[0]);
        error_log('calculated: '.$calculated_signature);

        if ($calculated_signature != $elements[0])
            throw new \Exception("Calculated signature does not match provided signature.");
				
        if (time() > $date->format('U') + (60*30)) {
            throw new \Exception("API request has expired.");
        }

        // update token string
        if ($iden[1] == 0) {
            $response['token'] = 0; // if public api access, set token to 0
        } else {
            // if all looks good, then generate new txToken
            $session = new \Kyte\SessionManager(Session, Account);
            $session_ret = $session->validate($txToken, $iden[1]);
            $response['token'] = $session_ret['txToken'];
        }

        // initialize controller for model or view ("abstract" controller)
        $controllerClass = class_exists($elements[2].'Controller') ? $elements[2].'Controller' : 'ModelController';
        error_log("Controller $controllerClass instantiated...");
        // create new controller with model, app date format (i.e. Ymd), and new transaction token (to be verified again if private api)
        $controller = new $controllerClass(isset(${$elements[2]}) ? ${$elements[2]} : null, APP_DATE_FORMAT, $response['token'], $iden[1]);
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
            
        if(count($elements) == 1) {
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

                error_log('token: '.$data['token'].'; identifier: '.$data['identifier'].'; date: '.$data['time']);
        
                $hash1 = hash_hmac('SHA256', $data['token'], $obj->getParam('secret_key'), true);
                $hash2 = hash_hmac('SHA256', $data['identifier'], $hash1, true);
                $response['signature'] = hash_hmac('SHA256', $date->format('U'), $hash2);
            }
        }
    }

} catch (Exception $e) {
	error_log($e->getMessage());
    http_response_code(400);
    $response['error'] = $e->getMessage();
	echo json_encode($response);
	exit(0);
}

// return response data
echo json_encode($response);

?>
