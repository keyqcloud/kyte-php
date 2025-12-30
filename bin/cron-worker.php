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

// Load shared bootstrap (handles autoloader, config, etc.)
require_once __DIR__ . '/bootstrap.php';

$projectRoot = $GLOBALS['PROJECT_ROOT'];
$configPath = $GLOBALS['CONFIG_PATH'];

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
