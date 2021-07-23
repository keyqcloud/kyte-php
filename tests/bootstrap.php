<?php

require __DIR__ . '/../vendor/autoload.php';

// init db
\Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
\Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
\Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
\Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
\Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);

// get AWS Keys from env
define('AWS_ACCESS_KEY_ID', getenv('AWS_ACCESS_KEY_ID'));
define('AWS_SECRET_KEY', getenv('AWS_SECRET_KEY'));
define('AWS_KMS_KEYID', getenv('AWS_KMS_KEYID'));
define('AWS_PRIVATE_BUCKET_NAME', 'kyte-travisci-test-bucket-'.time());

// create mock apikey and account
require_once(__DIR__ . '/../src/Mvc/Model/APIKey.php');
require_once(__DIR__ . '/../src/Mvc/Model/Account.php');

$mockAPI = $APIKey;
$mockAccount = $Account;
// add primary keys
\Kyte\Core\Api::addPrimaryKey($mockAPI);
\Kyte\Core\Api::addPrimaryKey($mockAccount);

\Kyte\Core\DBI::createTable($mockAPI);
\Kyte\Core\DBI::createTable($mockAccount);

// create dummy key
$model = new \Kyte\Core\ModelObject($mockAPI);

$model->create([
    'identifier' => 'FOO',
    'public_key' => 'BAR',
    'secret_key' => 'BAZ',
    'epoch' => 0,
    'kyte_account' => 1,
]);

$model = new \Kyte\Core\ModelObject($mockAccount);

$model->create([
    'name' => 'FOO',
    'number' => 'BAR',
]);

?>