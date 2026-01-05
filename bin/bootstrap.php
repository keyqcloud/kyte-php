<?php
/**
 * Shared Bootstrap for Kyte CLI Scripts
 *
 * This file provides common setup for all bin/ scripts:
 * - Loads Composer autoloader
 * - Finds project root
 * - Loads .env if available
 * - Loads config.php
 *
 * Usage in bin scripts:
 *   require_once __DIR__ . '/bootstrap.php';
 */

// Determine the base path (vendor/keyqcloud/kyte-php)
$basePath = dirname(__DIR__);

// Find project root and load autoloader
// Kyte is at: /project/vendor/keyqcloud/kyte-php
// Project root is: /project (3 levels up)
$projectRoot = null;

if (file_exists($basePath . '/../../autoload.php')) {
	// Installed as vendor package - go up 3 levels to project root
	$projectRoot = realpath($basePath . '/../../..');
	require_once $projectRoot . '/vendor/autoload.php';
} elseif (file_exists($basePath . '/vendor/autoload.php')) {
	// Standalone installation (for development)
	$projectRoot = $basePath;
	require_once $basePath . '/vendor/autoload.php';
} else {
	die("ERROR: Composer autoloader not found. Please run 'composer install'.\n");
}

// Load environment variables if .env exists
if (class_exists('\Dotenv\Dotenv')) {
	if (file_exists($projectRoot . '/.env')) {
		$dotenv = \Dotenv\Dotenv::createImmutable($projectRoot);
		$dotenv->load();
	}
}

// Set default $_SERVER variables for CLI context
// Many config.php files reference $_SERVER['SERVER_NAME'] which doesn't exist in CLI
if (php_sapi_name() === 'cli' || php_sapi_name() === 'phpdbg') {
	if (!isset($_SERVER['SERVER_NAME'])) {
		$_SERVER['SERVER_NAME'] = 'localhost';
	}
	if (!isset($_SERVER['HTTP_HOST'])) {
		$_SERVER['HTTP_HOST'] = 'localhost';
	}
	if (!isset($_SERVER['REQUEST_URI'])) {
		$_SERVER['REQUEST_URI'] = '/';
	}
	if (!isset($_SERVER['REMOTE_ADDR'])) {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}
}

// Load Kyte configuration
// Standard Kyte setup has config.php at project root (same directory as index.php)
$configPath = null;
$searchPaths = [
	$projectRoot . '/config.php',              // Standard: /var/www/html/config.php
	$projectRoot . '/config/config.php',       // Alternative: /var/www/html/config/config.php
];

foreach ($searchPaths as $path) {
	if (file_exists($path)) {
		$configPath = $path;
		break;
	}
}

if ($configPath === null) {
	echo "ERROR: Kyte configuration file not found.\n";
	echo "Searched paths:\n";
	foreach ($searchPaths as $path) {
		echo "  - $path\n";
	}
	echo "\nPlease ensure config.php exists at your project root.\n";
	die();
}

require_once $configPath;

// Support both old and new naming conventions for database constants
// New style: KYTE_DB_* (preferred)
// Old style: DB_* (legacy)
if (defined('KYTE_DB_HOST') && !defined('DB_HOST')) {
	define('DB_HOST', KYTE_DB_HOST);
}
if (defined('KYTE_DB_DATABASE') && !defined('DB_NAME')) {
	define('DB_NAME', KYTE_DB_DATABASE);
}
if (defined('KYTE_DB_USERNAME') && !defined('DB_USER')) {
	define('DB_USER', KYTE_DB_USERNAME);
}
if (defined('KYTE_DB_PASSWORD') && !defined('DB_PASS')) {
	define('DB_PASS', KYTE_DB_PASSWORD);
}
if (defined('KYTE_DB_CHARSET') && !defined('DB_CHARSET')) {
	define('DB_CHARSET', KYTE_DB_CHARSET);
}

// Verify required configuration (check both naming conventions)
$hasOldStyle = defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER');
$hasNewStyle = defined('KYTE_DB_HOST') && defined('KYTE_DB_DATABASE') && defined('KYTE_DB_USERNAME');

if (!$hasOldStyle && !$hasNewStyle) {
	die("ERROR: Database configuration missing. Please check config.php.\n" .
		"Required constants: DB_HOST/KYTE_DB_HOST, DB_NAME/KYTE_DB_DATABASE, DB_USER/KYTE_DB_USERNAME\n");
}

// Initialize DBI with database credentials
// This is normally done by Api::__construct(), but CLI scripts bypass that
$dbUser = defined('DB_USER') ? DB_USER : KYTE_DB_USERNAME;
$dbPass = defined('DB_PASS') ? DB_PASS : KYTE_DB_PASSWORD;
$dbHost = defined('DB_HOST') ? DB_HOST : KYTE_DB_HOST;
$dbName = defined('DB_NAME') ? DB_NAME : KYTE_DB_DATABASE;
$dbCharset = defined('DB_CHARSET') ? DB_CHARSET : (defined('KYTE_DB_CHARSET') ? KYTE_DB_CHARSET : 'utf8mb4');
$dbEngine = 'InnoDB'; // Default engine

\Kyte\Core\DBI::dbInit($dbUser, $dbPass, $dbHost, $dbName, $dbCharset, $dbEngine);

// Load Cron Job model definitions for CLI context
// These need to be available as constants for the cron worker
$modelBasePath = $basePath . '/src/Mvc/Model';
require_once $modelBasePath . '/CronJob.php';
require_once $modelBasePath . '/CronJobExecution.php';
require_once $modelBasePath . '/CronJobFunction.php';
require_once $modelBasePath . '/CronJobFunctionContent.php';
require_once $modelBasePath . '/CronJobFunctionVersion.php';

// Define model constants if not already defined
if (!defined('CronJob')) define('CronJob', $CronJob);
if (!defined('CronJobExecution')) define('CronJobExecution', $CronJobExecution);
if (!defined('CronJobFunction')) define('CronJobFunction', $CronJobFunction);
if (!defined('CronJobFunctionContent')) define('CronJobFunctionContent', $CronJobFunctionContent);
if (!defined('CronJobFunctionVersion')) define('CronJobFunctionVersion', $CronJobFunctionVersion);

// Load AI Error Correction model definitions for CLI context
// Required by AIErrorCorrectionJob and AIErrorAnalyzer
require_once $modelBasePath . '/KyteError.php';
require_once $modelBasePath . '/AIErrorAnalysis.php';
require_once $modelBasePath . '/AIErrorDeduplication.php';
require_once $modelBasePath . '/AIErrorCorrectionConfig.php';
require_once $modelBasePath . '/Application.php';

// Add primary keys to models (required for ModelObject to work correctly)
\Kyte\Core\Api::addPrimaryKey($KyteError);
\Kyte\Core\Api::addPrimaryKey($AIErrorAnalysis);
\Kyte\Core\Api::addPrimaryKey($AIErrorDeduplication);
\Kyte\Core\Api::addPrimaryKey($AIErrorCorrectionConfig);
\Kyte\Core\Api::addPrimaryKey($Application);

if (!defined('KyteError')) define('KyteError', $KyteError);
if (!defined('AIErrorAnalysis')) define('AIErrorAnalysis', $AIErrorAnalysis);
if (!defined('AIErrorDeduplication')) define('AIErrorDeduplication', $AIErrorDeduplication);
if (!defined('AIErrorCorrectionConfig')) define('AIErrorCorrectionConfig', $AIErrorCorrectionConfig);
if (!defined('Application')) define('Application', $Application);

// Export globals for scripts to use
$GLOBALS['PROJECT_ROOT'] = $projectRoot;
$GLOBALS['CONFIG_PATH'] = $configPath;
