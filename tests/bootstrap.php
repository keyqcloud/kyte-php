<?php

require __DIR__ . '/../vendor/autoload.php';

\Kyte\Core\DBI::setDbUser(KYTE_DB_USERNAME);
\Kyte\Core\DBI::setDbPassword(KYTE_DB_PASSWORD);
\Kyte\Core\DBI::setDbHost(KYTE_DB_HOST);
\Kyte\Core\DBI::setDbName(KYTE_DB_DATABASE);
\Kyte\Core\DBI::setCharset(KYTE_DB_CHARSET);

?>