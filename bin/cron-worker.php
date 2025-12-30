#!/usr/bin/env php
<?php
/**
 * Kyte Cron Worker Daemon
 *
 * This script starts the cron worker daemon that continuously polls the database
 * for scheduled jobs and executes them with lease-based locking for idempotency
 * in multi-server environments.
 *
 * Usage:
 *   php bin/cron-worker.php
 *
 * Run as systemd service (recommended for production):
 *   systemctl start kyte-cron-worker
 *
 * Run in background with nohup (development):
 *   nohup php bin/cron-worker.php > /var/log/kyte-cron.log 2>&1 &
 *
 * Run in screen/tmux (development):
 *   screen -S kyte-cron
 *   php bin/cron-worker.php
 *   # Ctrl+A, D to detach
 *
 * Stop worker:
 *   kill -TERM <pid>  # Graceful shutdown
 *   kill -INT <pid>   # Interrupt (Ctrl+C)
 */

// Determine the base path
$basePath = dirname(dirname(__FILE__));

// When installed via Composer, find the project root
// Kyte is at: /project/vendor/keyqcloud/kyte-php
// Project root is: /project
$projectRoot = null;
if (file_exists($basePath . '/../../autoload.php')) {
	// Installed as vendor package - go up to project root
	$projectRoot = realpath($basePath . '/../..');
	require_once $projectRoot . '/vendor/autoload.php';
} elseif (file_exists($basePath . '/vendor/autoload.php')) {
	// Standalone installation
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
	die("ERROR: Database configuration missing. Please check config/config.php.\n");
}

echo "============================================\n";
echo "Kyte Cron Worker Daemon\n";
echo "============================================\n";
echo "Project Root: {$projectRoot}\n";
echo "Config File:  {$configPath}\n";
echo "Database:     " . DB_HOST . "/" . DB_NAME . "\n";
echo "PHP Version:  " . PHP_VERSION . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n";
echo "============================================\n\n";

// Start the worker
use Kyte\Cron\CronWorker;

try {
	$worker = new CronWorker();
	$worker->start();
} catch (\Exception $e) {
	echo "FATAL ERROR: " . $e->getMessage() . "\n";
	echo $e->getTraceAsString() . "\n";
	exit(1);
}
