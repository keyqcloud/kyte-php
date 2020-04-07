<?php

require_once __DIR__.'/initializer.php';

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

// Access-Control headers are received during OPTIONS requests
if ($request == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");         

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

    exit(0);
}

header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header("Content-Type: application/json; charset=utf-8");

// initialie empty array for response data
$response = [];

try {
    // read in data and parse into array
    parse_str(file_get_contents("php://input"), $data);

    /* parse URI        ** remember to add the following in .htaccess 'FallbackResource /index.php'
    * URL formats:
    * POST     /{token}/{key}/{signature}/{time}/{model}
    * PUT      /{token}/{key}/{signature}/{time}/{model}/{field}/{value} + data
    * GET      /{token}/{key}/{signature}/{time}/{model}/{field}/{value}
    * DELETE   /{token}/{key}/{signature}/{time}/{model}/{field}/{value}
    */
    // Trim leading slash(es)
    $path = ltrim($_SERVER['REQUEST_URI'], '/');
    // if api is in subdir, remove from path request
    $path = str_replace(API_BASE,'', $path);
    // Split path on slashes
    $elements = explode('/', $path);
    //** change element split method to allow for fewer uri fields for some requests */

    error_log("Access from $origin with element count of ".count($elements));

    // if there are elements then process api request based on request type
    if (count($elements) > 4) {

        $api = new \Kyte\API(APIKey);
        // init new api with key
        $api->init($elements[1]);

        // check if signature is valid - signature and signature datetime
        $date = new DateTime(urldecode($elements[3]), new DateTimeZone('UTC'));
        $api->validate($elements[2], $date->format('U'));

        // initialize controller for model or view ("abstract" controller)
        $controllerClass = class_exists($elements[4].'Controller') ? $elements[4].'Controller' : 'ModelController';
        error_log("Controller $controllerClass instantiated...");
        $controller = new $controllerClass(${$elements[4]}, APP_DATE_FORMAT, $elements[0]);
        if (!$controller) throw new Exception("[ERROR] Unable to create controller for model: $controllerClass.");

        switch ($request) {
            case 'POST':
                // new  :   {data}
                $response = $controller->new($data);
                break;

            case 'PUT':
                // update   :   {field}, {value}, {data}
                $response = $controller->update((isset($elements[5]) ? $elements[5] : null), (isset($elements[6]) ? $elements[6] : null), $data);
                break;

            case 'GET':
                // get  :   {field}, {value}
                $response = $controller->get((isset($elements[5]) ? $elements[5] : null), (isset($elements[6]) ? $elements[6] : null));
                break;

            case 'DELETE':
                // delete   :   {field}, {value}
                $response = $controller->delete((isset($elements[5]) ? $elements[5] : null), (isset($elements[6]) ? $elements[6] : null));
                break;
            
            default:
                throw new Exception("[ERROR] Unknown HTTP request type: $request.");
                break;
        }

    } else {
        // If minimum params are not passed, then generate signature and return
        if(count($elements) == 3) {
            /* GET     /{key}/{time}/{identifier} */
            $obj = new \Kyte\ModelObject(APIKey);
            if ($obj->retrieve('public_key', $elements[0])) {
            } else throw new Exception("Invalid API access key");
    
            $date = new DateTime(urldecode($elements[1]), new DateTimeZone('UTC'));
    
            $hash1 = hash_hmac('SHA256', $date->format('U'), $obj->getParam('secret_key'), true);
            $hash2 = hash_hmac('SHA256', $elements[2], $hash1, true);
            $response['signature'] = hash_hmac('SHA256', $elements[0], $hash2);
            $time = time();
            $exp_time = $time+(60*60);
            $response['token'] = hash_hmac('SHA256', 'anon-'.$time, $exp_time);
        } else {
            $response['version'] = \Kyte\ApplicationVersion::get();
        }
    }

} catch (Exception $e) {
	error_log($e->getMessage());
	http_response_code(400);
	echo json_encode(['status' => 400, 'error' => $e->getMessage()]);
	exit(0);
}

// return response data
echo json_encode($response);

?>
