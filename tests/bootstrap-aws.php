<?php

require __DIR__ . '/bootstrap.php';

define('AWS_ACCESS_KEY_ID', getenv('AWS_ACCESS_KEY_ID'));
define('AWS_SECRET_KEY', getenv('AWS_SECRET_KEY'));
define('AWS_PRIVATE_BUCKET_NAME', 'kyte-travisci-test-private-bucket-' . time());
define('AWS_PUBLIC_BUCKET_NAME', 'kyte-travisci-test-public-bucket-' . time());
define('AWS_TEST_SITE_NAME', 'kyte-travisci-test-static-site-bucket-' . time());
