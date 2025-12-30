#!/usr/bin/env php
<?php
/**
 * Cron System Test Script
 *
 * This script tests the basic cron job functionality by:
 * 1. Creating a test job in the database
 * 2. Waiting for the worker to execute it
 * 3. Checking the execution results
 *
 * Usage:
 *   php bin/test-cron.php [action]
 *
 * Actions:
 *   create   - Create a test job
 *   status   - Check test job status
 *   clean    - Remove test jobs
 */

// Determine the base path
$basePath = dirname(dirname(__FILE__));

// Load autoloader
if (file_exists($basePath . '/../../autoload.php')) {
	// Installed as vendor package
	require_once $basePath . '/../../autoload.php';
} elseif (file_exists($basePath . '/vendor/autoload.php')) {
	// Standalone installation
	require_once $basePath . '/vendor/autoload.php';
} else {
	die("ERROR: Composer autoloader not found. Please run 'composer install'.\n");
}

// Load environment variables if .env exists
if (class_exists('\Dotenv\Dotenv')) {
	$envPath = $basePath;
	for ($i = 0; $i < 5; $i++) {
		if (file_exists($envPath . '/.env')) {
			$dotenv = \Dotenv\Dotenv::createImmutable($envPath);
			$dotenv->load();
			break;
		}
		$envPath = dirname($envPath);
	}
}

// Load Kyte configuration
$configPath = null;
$searchPaths = [
	$basePath . '/config/config.php',
	$basePath . '/../../config/config.php',
	dirname(dirname($basePath)) . '/config/config.php',
];

foreach ($searchPaths as $path) {
	if (file_exists($path)) {
		$configPath = $path;
		break;
	}
}

if ($configPath === null) {
	die("ERROR: Kyte configuration file not found. Please ensure config/config.php exists.\n");
}

require_once $configPath;

// Verify database configuration
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
	die("ERROR: Database configuration missing. Please check config/config.php.\n");
}

use Kyte\Core\DBI;
use Kyte\Core\Model;

// Get action from command line
$action = $argv[1] ?? 'help';

switch ($action) {
	case 'create':
		createTestJob();
		break;

	case 'status':
		checkJobStatus();
		break;

	case 'clean':
		cleanupTestJobs();
		break;

	case 'help':
	default:
		showHelp();
		break;
}

/**
 * Create a test cron job
 */
function createTestJob() {
	echo "Creating test cron job...\n\n";

	// Load the test job code
	$jobCode = file_get_contents(dirname(__DIR__) . '/examples/TestCronJob.php');

	// Compress the code
	$compressedCode = bzcompress($jobCode);

	// Create job record
	$sql = "
		INSERT INTO CronJob (
			name, description, code, schedule_type, interval_seconds,
			timeout_seconds, max_retries, enabled, application, kyte_account,
			created_by, date_created
		) VALUES (
			'Test Job',
			'Test cron job for validating basic functionality',
			?,
			'interval',
			60,
			300,
			3,
			1,
			NULL,
			1,
			1,
			UNIX_TIMESTAMP()
		)
	";

	DBI::prepared_query($sql, 'b', [$compressedCode]);
	$jobId = DBI::insert_id();

	echo "✓ Test job created with ID: {$jobId}\n";
	echo "\n";
	echo "Job Details:\n";
	echo "  Name: Test Job\n";
	echo "  Schedule: Every 60 seconds (interval)\n";
	echo "  Timeout: 300 seconds\n";
	echo "  Max Retries: 3\n";
	echo "  Status: Enabled\n";
	echo "\n";
	echo "Next steps:\n";
	echo "  1. Start the cron worker: php bin/cron-worker.php\n";
	echo "  2. Check job status: php bin/test-cron.php status\n";
	echo "\n";
}

/**
 * Check test job execution status
 */
function checkJobStatus() {
	echo "Checking test job status...\n\n";

	// Find test jobs
	$sql = "
		SELECT id, name, enabled, date_created, date_modified
		FROM CronJob
		WHERE name = 'Test Job'
		AND deleted = 0
		ORDER BY date_created DESC
	";

	$jobs = DBI::query($sql);

	if (empty($jobs)) {
		echo "No test jobs found. Run 'php bin/test-cron.php create' first.\n";
		return;
	}

	foreach ($jobs as $job) {
		echo "Job #{$job['id']}: {$job['name']}\n";
		echo "  Status: " . ($job['enabled'] ? "Enabled" : "Disabled") . "\n";
		echo "  Created: " . date('Y-m-d H:i:s', $job['date_created']) . "\n";
		echo "\n";

		// Get executions
		$sql = "
			SELECT id, status, scheduled_time, started_at, completed_at,
			       duration_ms, exit_code, output, error
			FROM CronJobExecution
			WHERE cron_job = ?
			ORDER BY scheduled_time DESC
			LIMIT 5
		";

		$executions = DBI::prepared_query($sql, 'i', [$job['id']]);

		if (empty($executions)) {
			echo "  No executions yet. Waiting for worker to pick up job...\n";
		} else {
			echo "  Recent Executions:\n";
			foreach ($executions as $exec) {
				echo "    Execution #{$exec['id']}:\n";
				echo "      Status: {$exec['status']}\n";
				echo "      Scheduled: " . date('Y-m-d H:i:s', $exec['scheduled_time']) . "\n";

				if ($exec['started_at']) {
					echo "      Started: " . date('Y-m-d H:i:s', $exec['started_at']) . "\n";
				}

				if ($exec['completed_at']) {
					echo "      Completed: " . date('Y-m-d H:i:s', $exec['completed_at']) . "\n";
					echo "      Duration: " . round($exec['duration_ms']) . "ms\n";
					echo "      Exit Code: {$exec['exit_code']}\n";
				}

				if ($exec['output']) {
					echo "      Output:\n";
					$lines = explode("\n", trim($exec['output']));
					foreach ($lines as $line) {
						echo "        " . $line . "\n";
					}
				}

				if ($exec['error']) {
					echo "      Error: {$exec['error']}\n";
				}

				echo "\n";
			}
		}
	}
}

/**
 * Clean up test jobs
 */
function cleanupTestJobs() {
	echo "Cleaning up test jobs...\n\n";

	// Soft delete test jobs
	$sql = "
		UPDATE CronJob
		SET deleted = 1, enabled = 0, date_modified = UNIX_TIMESTAMP()
		WHERE name = 'Test Job'
		AND deleted = 0
	";

	DBI::query($sql);
	$count = DBI::affected_rows();

	echo "✓ Deleted {$count} test job(s)\n";
	echo "\n";
	echo "Note: Execution history is preserved. Use direct SQL to permanently delete.\n";
}

/**
 * Show help
 */
function showHelp() {
	echo "Kyte Cron System Test Script\n";
	echo "============================\n\n";
	echo "Usage: php bin/test-cron.php [action]\n\n";
	echo "Actions:\n";
	echo "  create   Create a test job that runs every 60 seconds\n";
	echo "  status   Check test job execution status\n";
	echo "  clean    Remove test jobs from database\n";
	echo "  help     Show this help message\n";
	echo "\n";
	echo "Testing workflow:\n";
	echo "  1. Create test job:   php bin/test-cron.php create\n";
	echo "  2. Start worker:      php bin/cron-worker.php\n";
	echo "  3. Check status:      php bin/test-cron.php status\n";
	echo "  4. Clean up:          php bin/test-cron.php clean\n";
	echo "\n";
}
