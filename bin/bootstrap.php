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

// Verify required configuration
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
	die("ERROR: Database configuration missing. Please check config.php.\n");
}

// Export globals for scripts to use
$GLOBALS['PROJECT_ROOT'] = $projectRoot;
$GLOBALS['CONFIG_PATH'] = $configPath;
