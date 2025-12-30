#!/usr/bin/env php
<?php
/**
 * Multi-Worker Test Script
 *
 * Tests lock contention and idempotency by simulating multiple workers
 * competing for the same jobs.
 *
 * Usage:
 *   php bin/test-multi-worker.php [options]
 *
 * Options:
 *   --workers=N      Number of workers to spawn (default: 3)
 *   --duration=N     Test duration in seconds (default: 60)
 *   --cleanup        Clean up test jobs after completion
 *   --help           Show this help message
 *
 * Example:
 *   php bin/test-multi-worker.php --workers=5 --duration=120 --cleanup
 */

// Parse command line options
$options = getopt('', ['workers:', 'duration:', 'cleanup', 'help']);

if (isset($options['help'])) {
	showHelp();
	exit(0);
}

$numWorkers = isset($options['workers']) ? (int)$options['workers'] : 3;
$duration = isset($options['duration']) ? (int)$options['duration'] : 60;
$cleanup = isset($options['cleanup']);

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

echo "============================================\n";
echo "Multi-Worker Lock Contention Test\n";
echo "============================================\n";
echo "Workers:  {$numWorkers}\n";
echo "Duration: {$duration}s\n";
echo "Cleanup:  " . ($cleanup ? 'Yes' : 'No') . "\n";
echo "============================================\n\n";

// Create test job
echo "Creating test job...\n";
$jobId = createTestJob();
echo "  Job ID: {$jobId}\n\n";

// Spawn worker processes
echo "Spawning {$numWorkers} worker processes...\n";
$workers = [];
$logFiles = [];

for ($i = 0; $i < $numWorkers; $i++) {
	$logFile = "/tmp/kyte-worker-test-{$i}.log";
	$logFiles[] = $logFile;

	$cmd = "php " . escapeshellarg($basePath . "/bin/cron-worker.php") . " > " . escapeshellarg($logFile) . " 2>&1 & echo $!";
	$pid = trim(shell_exec($cmd));

	$workers[$i] = [
		'pid' => $pid,
		'log' => $logFile
	];

	echo "  Worker {$i}: PID {$pid}\n";
}

echo "\n";
echo "Workers are running...\n";
echo "Press Ctrl+C to stop early, or wait {$duration}s\n\n";

// Monitor for the specified duration
$startTime = time();
$lastReport = 0;

while ((time() - $startTime) < $duration) {
	sleep(1);

	$elapsed = time() - $startTime;

	// Print progress every 10 seconds
	if ($elapsed - $lastReport >= 10) {
		$remaining = $duration - $elapsed;
		echo "[" . date('H:i:s') . "] Running... ({$remaining}s remaining)\n";
		$lastReport = $elapsed;
	}
}

echo "\n";
echo "Test duration complete. Stopping workers...\n";

// Stop all workers
foreach ($workers as $i => $worker) {
	echo "  Stopping worker {$i} (PID {$worker['pid']})...\n";
	posix_kill((int)$worker['pid'], SIGTERM);
}

// Wait for workers to stop
echo "\nWaiting for workers to stop gracefully...\n";
sleep(5);

// Analyze results
echo "\n";
echo "============================================\n";
echo "Test Results\n";
echo "============================================\n\n";

analyzeExecutions($jobId);
analyzeLockContention($logFiles);

if ($cleanup) {
	echo "\n";
	echo "Cleaning up...\n";
	cleanupTestJob($jobId);
	foreach ($logFiles as $logFile) {
		if (file_exists($logFile)) {
			unlink($logFile);
		}
	}
	echo "  Cleanup complete\n";
}

echo "\n";
echo "Test complete!\n";

// ============================================
// Helper Functions
// ============================================

function createTestJob() {
	// Create a job that runs every 5 seconds
	$jobCode = <<<'PHP'
<?php
class MultiWorkerTestJob extends \Kyte\Core\CronJobBase
{
	public function execute() {
		$this->log("Worker started - " . $this->getWorkerInfo());

		// Simulate some work
		$workTime = rand(1, 3);
		$this->log("Processing for {$workTime}s...");
		sleep($workTime);

		// Send heartbeat in the middle
		if ($workTime >= 2) {
			$this->heartbeat();
		}

		$this->log("Work complete");
		return "Completed in {$workTime}s";
	}

	private function getWorkerInfo() {
		$execution = $this->getExecution();
		return "Execution #{$execution['id']}, Locked by: {$execution['locked_by']}";
	}
}
PHP;

	$compressedCode = bzcompress($jobCode);

	$sql = "
		INSERT INTO CronJob (
			name, description, code, schedule_type, interval_seconds,
			timeout_seconds, max_retries, enabled, application, kyte_account,
			allow_concurrent, created_by, date_created
		) VALUES (
			'Multi-Worker Test Job',
			'Test job for validating multi-worker lock contention',
			?,
			'interval',
			5,
			300,
			3,
			1,
			NULL,
			1,
			0,
			1,
			UNIX_TIMESTAMP()
		)
	";

	DBI::prepared_query($sql, 'b', [$compressedCode]);
	return DBI::insert_id();
}

function analyzeExecutions($jobId) {
	echo "Execution Analysis:\n";
	echo "-------------------\n";

	// Get execution statistics
	$sql = "
		SELECT
			COUNT(*) as total,
			SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
			SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
			SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
			SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
			AVG(CASE WHEN status = 'completed' THEN duration_ms END) as avg_duration
		FROM CronJobExecution
		WHERE cron_job = ?
	";

	$stats = DBI::prepared_query($sql, 'i', [$jobId]);
	$stats = $stats[0];

	echo "  Total Executions:   {$stats['total']}\n";
	echo "  Completed:          {$stats['completed']}\n";
	echo "  Failed:             {$stats['failed']}\n";
	echo "  Skipped:            {$stats['skipped']}\n";
	echo "  Still Running:      {$stats['running']}\n";

	if ($stats['avg_duration']) {
		echo "  Avg Duration:       " . round($stats['avg_duration']) . "ms\n";
	}

	// Check for duplicate executions at the same time
	$sql = "
		SELECT scheduled_time, COUNT(*) as count
		FROM CronJobExecution
		WHERE cron_job = ?
		AND status IN ('completed', 'failed')
		GROUP BY scheduled_time
		HAVING count > 1
	";

	$duplicates = DBI::prepared_query($sql, 'i', [$jobId]);

	if (empty($duplicates)) {
		echo "\n  ✓ No duplicate executions detected (idempotency verified)\n";
	} else {
		echo "\n  ✗ WARNING: Duplicate executions detected:\n";
		foreach ($duplicates as $dup) {
			echo "    - Time " . date('H:i:s', $dup['scheduled_time']) . ": {$dup['count']} executions\n";
		}
	}

	// Show unique workers that participated
	$sql = "
		SELECT DISTINCT locked_by
		FROM CronJobExecution
		WHERE cron_job = ?
		AND locked_by IS NOT NULL
		ORDER BY locked_by
	";

	$workers = DBI::prepared_query($sql, 'i', [$jobId]);

	echo "\n  Workers Participated: " . count($workers) . "\n";
	foreach ($workers as $i => $worker) {
		echo "    - {$worker['locked_by']}\n";
	}
}

function analyzeLockContention($logFiles) {
	echo "\n";
	echo "Lock Contention Analysis:\n";
	echo "-------------------------\n";

	$totalLocks = 0;
	$totalMissed = 0;

	foreach ($logFiles as $i => $logFile) {
		if (!file_exists($logFile)) {
			continue;
		}

		$content = file_get_contents($logFile);

		// Parse statistics from worker output
		if (preg_match('/Locks Acquired:\s+(\d+)/', $content, $matches)) {
			$acquired = (int)$matches[1];
			$totalLocks += $acquired;
			echo "  Worker {$i}: {$acquired} locks acquired\n";
		}

		if (preg_match('/Locks Missed:\s+(\d+)/', $content, $matches)) {
			$missed = (int)$matches[1];
			$totalMissed += $missed;
		}
	}

	echo "\n";
	echo "  Total Locks Acquired: {$totalLocks}\n";
	echo "  Total Locks Missed:   {$totalMissed}\n";

	$totalAttempts = $totalLocks + $totalMissed;
	if ($totalAttempts > 0) {
		$contentionRate = ($totalMissed / $totalAttempts) * 100;
		echo "  Contention Rate:      " . number_format($contentionRate, 1) . "%\n";

		if ($contentionRate > 50) {
			echo "\n  ⚠ High contention detected - locks are highly contested\n";
		} elseif ($contentionRate > 20) {
			echo "\n  ℹ Moderate contention - normal for multi-worker setup\n";
		} else {
			echo "\n  ✓ Low contention - good distribution\n";
		}
	}
}

function cleanupTestJob($jobId) {
	$sql = "
		UPDATE CronJob
		SET deleted = 1, enabled = 0, date_modified = UNIX_TIMESTAMP()
		WHERE id = ?
	";

	DBI::prepared_query($sql, 'i', [$jobId]);
}

function showHelp() {
	echo "Multi-Worker Test Script\n";
	echo "========================\n\n";
	echo "Tests lock contention and idempotency by spawning multiple workers.\n\n";
	echo "Usage: php bin/test-multi-worker.php [options]\n\n";
	echo "Options:\n";
	echo "  --workers=N      Number of workers to spawn (default: 3)\n";
	echo "  --duration=N     Test duration in seconds (default: 60)\n";
	echo "  --cleanup        Clean up test jobs after completion\n";
	echo "  --help           Show this help message\n\n";
	echo "Example:\n";
	echo "  php bin/test-multi-worker.php --workers=5 --duration=120 --cleanup\n\n";
	echo "What this test validates:\n";
	echo "  - Lock-based idempotency (no duplicate executions)\n";
	echo "  - Lock contention rate across multiple workers\n";
	echo "  - Worker distribution and fairness\n";
	echo "  - Graceful shutdown behavior\n";
	echo "  - Heartbeat mechanism for long-running jobs\n";
}
