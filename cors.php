<?php

require_once __DIR__.'/initializer.php';

if (array_key_exists('HTTP_ORIGIN', $_SERVER)) {
    $origin = $_SERVER['HTTP_ORIGIN'];
} else if (array_key_exists('HTTP_REFERER', $_SERVER)) {
    $origin = $_SERVER['HTTP_REFERER'];
} else {
    $origin = $_SERVER['REMOTE_ADDR'];
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: POST, OPTIONS");         

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");

    exit(0);
}

try {
	foreach (['kyte-time', 'kyte-access-key'] as $param) {
		if (!isset($_POST[$param]))
			throw new Exception("$origin: API request is missing $param");
	}

	$api = new \Kyte\API;

	$api->init($_POST['kyte-access-key']);

	header("Access-Control-Allow-Origin: $origin");
	header('Access-Control-Allow-Credentials: true');
	header("Content-Type: application/json; charset=utf-8");
	
} catch (Exception $e) {
	error_log($e->getMessage());
	http_response_code(400);
	echo json_encode(['error' => $e->getMessage()]);
	die();
}

?>
