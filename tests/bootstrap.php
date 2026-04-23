<?php

require __DIR__ . '/../vendor/autoload.php';

if (!defined('KYTE_DB_HOST'))     define('KYTE_DB_HOST',     getenv('KYTE_DB_HOST')     ?: 'localhost');
if (!defined('KYTE_DB_USERNAME')) define('KYTE_DB_USERNAME', getenv('KYTE_DB_USERNAME') ?: 'root');
if (!defined('KYTE_DB_PASSWORD')) define('KYTE_DB_PASSWORD', getenv('KYTE_DB_PASSWORD') ?: '');
if (!defined('KYTE_DB_DATABASE')) define('KYTE_DB_DATABASE', getenv('KYTE_DB_DATABASE') ?: 'kytedev');
if (!defined('KYTE_DB_CHARSET'))  define('KYTE_DB_CHARSET',  getenv('KYTE_DB_CHARSET')  ?: 'utf8');

\Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
\Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
\Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
\Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
\Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);
