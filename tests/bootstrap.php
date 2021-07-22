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

?>