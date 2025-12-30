#!/usr/bin/env php
<?php
/**
 * Retry Logic Test Script
 *
 * Tests retry logic, exponential backoff, dead letter queue,
 * and notification system.
 *
 * Usage:
 *   php bin/test-retry.php [action] [options]
 *
 * Actions:
 *   create          Create test jobs with different retry strategies
 *   status          Check status of test jobs
 *   dlq             Show jobs in dead letter queue
 *   recover [id]    Recover job from dead letter queue
 *   cleanup         Remove all test jobs
 *
 * Options:
 *   --webhook=URL   Slack webhook for notification testing
 */

// Determine the base path
$basePath = dirname(dirname(__FILE__));

// Load autoloader
if (file_exists($basePath . '/../../autoload.php')) {
	require_once $basePath . '/../../autoload.php';
} elseif (file_exists($basePath . '/vendor/autoload.php')) {
	require_once $basePath . '/vendor/autoload.php';
} else {
	die("ERROR: Composer autoloader not found. Please run 'composer install'.\n");
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
	die("ERROR: Kyte configuration file not found.\n");
}

require_once $configPath;

use Kyte\Core\DBI;

// Parse command line
$action = $argv[1] ?? 'help';
$arg = $argv[2] ?? null;

// Parse options
$webhook = null;
foreach ($argv as $opt) {
	if (strpos($opt, '--webhook=') === 0) {
		$webhook = substr($opt, 10);
	}
}

switch ($action) {
	case 'create':
		createTestJobs($webhook);
		break;

	case 'status':
		showJobStatus();
		break;

	case 'dlq':
		showDeadLetterQueue();
		break;

	case 'recover':
		if (!$arg) {
			echo "ERROR: Missing job ID\n";
			echo "Usage: php bin/test-retry.php recover [job_id]\n";
			exit(1);
		}
		recoverFromDLQ((int)$arg);
		break;

	case 'cleanup':
		cleanup();
		break;

	case 'help':
	default:
		showHelp();
		break;
}

// ============================================
// Commands
// ============================================

function createTestJobs($webhook) {
	echo "Creating Test Jobs for Retry Testing\n";
	echo "=====================================\n\n";

	$jobs = [];

	// Job 1: Exponential backoff (default)
	echo "Creating Job 1: Exponential Backoff Strategy...\n";
	$jobs[] = createFailingJob(
		'Retry Test - Exponential',
		'exponential',
		null,
		3,
		$webhook
	);

	// Job 2: Fixed delay
	echo "Creating Job 2: Fixed Delay Strategy (30s)...\n";
	$jobs[] = createFailingJob(
		'Retry Test - Fixed',
		'fixed',
		30,
		3,
		$webhook
	);

	// Job 3: Immediate retry
	echo "Creating Job 3: Immediate Retry Strategy...\n";
	$jobs[] = createFailingJob(
		'Retry Test - Immediate',
		'immediate',
		null,
		2,
		$webhook
	);

	// Job 4: No retries (will go straight to DLQ)
	echo "Creating Job 4: No Retries (DLQ Test)...\n";
	$jobs[] = createFailingJob(
		'Retry Test - No Retries',
		'exponential',
		null,
		0,
		$webhook
	);

	echo "\n";
	echo "✓ Created " . count($jobs) . " test jobs\n\n";

	echo "Job IDs:\n";
	foreach ($jobs as $i => $jobId) {
		echo "  Job " . ($i + 1) . ": #{$jobId}\n";
	}

	echo "\n";
	echo "Next steps:\n";
	echo "  1. Start worker: php bin/cron-worker.php\n";
	echo "  2. Watch retries in action (jobs will fail intentionally)\n";
	echo "  3. Check status: php bin/test-retry.php status\n";
	echo "  4. View DLQ: php bin/test-retry.php dlq\n";
	echo "\n";
	echo "Expected behavior:\n";
	echo "  - Job 1: Retries at 1m, 2m, 4m intervals (exponential)\n";
	echo "  - Job 2: Retries at 30s intervals (fixed)\n";
	echo "  - Job 3: Retries immediately\n";
	echo "  - Job 4: Goes to DLQ after first failure (no retries)\n";
	echo "\n";

	if ($webhook) {
		echo "📣 Slack notifications will be sent to configured webhook\n\n";
	}
}

function createFailingJob($name, $strategy, $delay, $maxRetries, $webhook) {
	// Job code that always fails
	$jobCode = <<<'PHP'
<?php
class RetryTestJob extends \Kyte\Core\CronJobBase
{
	public function execute() {
		$this->log("Job started - will fail intentionally");
		$this->log("Testing retry logic and dead letter queue");

		// Get execution info
		$execution = $this->getExecution();
		$retryCount = $execution['retry_count'] ?? 0;

		$this->log("Retry count: {$retryCount}");

		// Intentionally throw exception
		throw new \Exception("Intentional failure for retry testing (retry #{$retryCount})");
	}
}
PHP;

	$compressedCode = bzcompress($jobCode);

	$sql = "
		INSERT INTO CronJob (
			name, description, code, schedule_type, interval_seconds,
			timeout_seconds, max_retries, retry_strategy, retry_delay_seconds,
			enabled, application, kyte_account,
			notify_on_failure, notify_after_failures, notify_on_dead_letter,
			slack_webhook,
			created_by, date_created
		) VALUES (
			?, 'Test job for retry logic and DLQ', ?, 'interval', 60,
			300, ?, ?, ?,
			1, NULL, 1,
			1, 1, 1,
			?,
			1, UNIX_TIMESTAMP()
		)
	";

	DBI::prepared_query($sql, 'sbisis', [
		$name,
		$compressedCode,
		$maxRetries,
		$strategy,
		$delay,
		$webhook
	]);

	$jobId = DBI::insert_id();
	echo "  Created job #{$jobId}: {$name}\n";
	echo "    Strategy: {$strategy}\n";
	echo "    Max Retries: {$maxRetries}\n";
	if ($delay) {
		echo "    Delay: {$delay}s\n";
	}
	echo "\n";

	return $jobId;
}

function showJobStatus() {
	echo "Retry Test Job Status\n";
	echo "=====================\n\n";

	$sql = "
		SELECT id, name, max_retries, retry_strategy, consecutive_failures,
		       in_dead_letter_queue, enabled
		FROM CronJob
		WHERE name LIKE 'Retry Test%'
		AND deleted = 0
		ORDER BY id ASC
	";

	$jobs = DBI::query($sql);

	if (empty($jobs)) {
		echo "No test jobs found. Run 'php bin/test-retry.php create' first.\n";
		return;
	}

	foreach ($jobs as $job) {
		echo "Job #{$job['id']}: {$job['name']}\n";
		echo "  Strategy: {$job['retry_strategy']}\n";
		echo "  Max Retries: {$job['max_retries']}\n";
		echo "  Consecutive Failures: {$job['consecutive_failures']}\n";
		echo "  Status: " . ($job['in_dead_letter_queue'] ? "❌ In DLQ" : ($job['enabled'] ? "✓ Active" : "⏸ Disabled")) . "\n";

		// Get executions
		$sql = "
			SELECT id, status, is_retry, retry_count, scheduled_time,
			       started_at, completed_at, error
			FROM CronJobExecution
			WHERE cron_job = ?
			ORDER BY date_created ASC
		";

		$executions = DBI::prepared_query($sql, 'i', [$job['id']]);

		if (!empty($executions)) {
			echo "\n  Execution History:\n";
			foreach ($executions as $exec) {
				$retryLabel = $exec['is_retry'] ? " (Retry #{$exec['retry_count']})" : " (Initial)";
				$statusIcon = match($exec['status']) {
					'completed' => '✓',
					'failed' => '✗',
					'running' => '▶',
					'pending' => '⏳',
					default => '?'
				};

				echo "    {$statusIcon} Execution #{$exec['id']}{$retryLabel}: {$exec['status']}\n";

				if ($exec['started_at']) {
					echo "      Started: " . date('Y-m-d H:i:s', $exec['started_at']) . "\n";
				}

				if ($exec['status'] == 'failed' && $exec['error']) {
					$error = substr($exec['error'], 0, 80);
					echo "      Error: {$error}...\n";
				}

				if ($exec['status'] == 'pending') {
					$waitTime = $exec['scheduled_time'] - time();
					if ($waitTime > 0) {
						echo "      Scheduled in: {$waitTime}s\n";
					} else {
						echo "      Ready to run\n";
					}
				}
			}
		}

		echo "\n";
	}
}

function showDeadLetterQueue() {
	echo "Dead Letter Queue\n";
	echo "=================\n\n";

	$sql = "
		SELECT id, name, dead_letter_reason, dead_letter_since,
		       consecutive_failures, max_retries
		FROM CronJob
		WHERE in_dead_letter_queue = 1
		ORDER BY dead_letter_since DESC
	";

	$jobs = DBI::query($sql);

	if (empty($jobs)) {
		echo "✓ No jobs in dead letter queue\n";
		return;
	}

	echo "Found " . count($jobs) . " job(s) in DLQ:\n\n";

	foreach ($jobs as $job) {
		$since = date('Y-m-d H:i:s', $job['dead_letter_since']);
		$duration = formatDuration(time() - $job['dead_letter_since']);

		echo "💀 Job #{$job['id']}: {$job['name']}\n";
		echo "   Added to DLQ: {$since} ({$duration} ago)\n";
		echo "   Consecutive Failures: {$job['consecutive_failures']}\n";
		echo "   Max Retries: {$job['max_retries']}\n";
		echo "   Reason: {$job['dead_letter_reason']}\n";
		echo "\n";
		echo "   To recover: php bin/test-retry.php recover {$job['id']}\n";
		echo "\n";
	}
}

function recoverFromDLQ($jobId) {
	echo "Recovering Job from Dead Letter Queue\n";
	echo "======================================\n\n";

	// Get job details
	$sql = "SELECT * FROM CronJob WHERE id = ? AND in_dead_letter_queue = 1";
	$job = DBI::prepared_query($sql, 'i', [$jobId]);

	if (empty($job)) {
		echo "ERROR: Job #{$jobId} not found in DLQ\n";
		exit(1);
	}

	$job = $job[0];

	echo "Job: {$job['name']}\n";
	echo "In DLQ since: " . date('Y-m-d H:i:s', $job['dead_letter_since']) . "\n";
	echo "\n";

	// Reset DLQ status and consecutive failures
	$sql = "
		UPDATE CronJob
		SET in_dead_letter_queue = 0,
			dead_letter_reason = NULL,
			dead_letter_since = NULL,
			consecutive_failures = 0,
			enabled = 1,
			date_modified = UNIX_TIMESTAMP()
		WHERE id = ?
	";

	DBI::prepared_query($sql, 'i', [$jobId]);

	echo "✓ Job recovered from DLQ\n";
	echo "  - Dead letter queue status cleared\n";
	echo "  - Consecutive failures reset to 0\n";
	echo "  - Job re-enabled\n";
	echo "\n";
	echo "Job will resume on next worker cycle.\n";
}

function cleanup() {
	echo "Cleaning up test jobs...\n\n";

	$sql = "
		UPDATE CronJob
		SET deleted = 1, enabled = 0, date_modified = UNIX_TIMESTAMP()
		WHERE name LIKE 'Retry Test%'
		AND deleted = 0
	";

	DBI::query($sql);
	$count = DBI::affected_rows();

	echo "✓ Deleted {$count} test job(s)\n";
}

function formatDuration($seconds) {
	if ($seconds < 60) {
		return "{$seconds}s";
	} elseif ($seconds < 3600) {
		$min = floor($seconds / 60);
		return "{$min}m";
	} else {
		$hours = floor($seconds / 3600);
		$min = floor(($seconds % 3600) / 60);
		return "{$hours}h {$min}m";
	}
}

function showHelp() {
	echo "Retry Logic Test Script\n";
	echo "=======================\n\n";
	echo "Tests Phase 3 retry logic, exponential backoff, and dead letter queue.\n\n";
	echo "Usage: php bin/test-retry.php [action] [options]\n\n";
	echo "Actions:\n";
	echo "  create          Create test jobs with different retry strategies\n";
	echo "  status          Check status of test jobs and executions\n";
	echo "  dlq             Show jobs in dead letter queue\n";
	echo "  recover [id]    Recover job from dead letter queue\n";
	echo "  cleanup         Remove all test jobs\n";
	echo "  help            Show this help message\n\n";
	echo "Options:\n";
	echo "  --webhook=URL   Slack webhook URL for notification testing\n\n";
	echo "Example workflow:\n";
	echo "  1. php bin/test-retry.php create --webhook=https://hooks.slack.com/...\n";
	echo "  2. php bin/cron-worker.php  # In separate terminal\n";
	echo "  3. php bin/test-retry.php status  # Watch retries\n";
	echo "  4. php bin/test-retry.php dlq  # Check DLQ after max retries\n";
	echo "  5. php bin/test-retry.php recover 123  # Recover job from DLQ\n";
	echo "  6. php bin/test-retry.php cleanup  # Remove test jobs\n";
}
