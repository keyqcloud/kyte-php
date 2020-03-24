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

// Access-Control headers are received during OPTIONS requests
if ($request == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");         

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

    exit(0);
}

// read in data and parse into array
parse_str(file_get_contents("php://input"), $data);

// initialie empty array for response data
$response = [];

/* parse URI        ** remember to add the following in .htaccess 'FallbackResource /index.php'
 * URL formats:
 * POST   /{token}/{key}/{signature}/{time}/{model}
 * PUT   /{token}/{key}/{signature}/{time}/{model}/{field}/{value}
 * GET  /{token}/{key}/{signature}/{time}/{model}/{field}/{value}
 * DELETE   /{token}/{key}/{signature}/{time}/{model}/{field}/{value}
 */
// Trim leading slash(es)
$path = ltrim($_SERVER['REQUEST_URI'], '/');
// Split path on slashes
$elements = explode('/', $path);

try {
    // minimum of 4 params is required - return version number is not met
    if(count($elements) < 5) $response['version'] = \Kyte\ApplicationVersion::get();
    // if there are elements then process api request based on request type
    else {

        $api = new \Kyte\API(APIKey);
        // init new api with key
        $api->init($elements[1]);

        // check if signature is valid - signature and signature datetime
        $date = new DateTime($elements[3], new DateTimeZone('UTC'));
        $api->validate($elements[2], $date->format('U'));

        // if the above checks don't trigger an exception, then allow cross origin
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
        header("Content-Type: application/json; charset=utf-8");

        $session = new \Kyte\SessionManager(Session);

        $controller = class_exists(static::$element[4]) ? static::$element[4] : ModelController;
        if (class_exists(static::$element[4]))
        {
            $object = new MyClass();
        }

        switch ($request) {
            case 'POST':
                // new  :   {model}, {data}
                $response = $controller::new($element[4], $data, APP_DATE_FORMAT);
                break;

            case 'PUT':
                // update   :   {model}, {field}, {value}, {data}
                $response = $controller::update($element[4], $element[5], $element[6], $data, APP_DATE_FORMAT);
                break;

            case 'GET':
                // get  :   {model}, {field}, {value}
                $response = $controller::get($element[4], $element[5], $element[5], APP_DATE_FORMAT);
                break;

            case 'DELETE':
                // delete   :   {model}, {field}, {value}
                $response = $controller::delete($element[4], $element[5], $element[5], APP_DATE_FORMAT);
                break;
            
            default:
                throw new Exception("[ERROR] Unknown HTTP request type: $request.");
                break;
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
