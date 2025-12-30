#!/usr/bin/env php
<?php
/**
 * Cron Lock Management Utility
 *
 * Inspect and manage cron job locks. Useful for debugging stuck jobs
 * and recovering from worker crashes.
 *
 * Usage:
 *   php bin/cron-locks.php [command] [options]
 *
 * Commands:
 *   list              List all current locks
 *   stale             Show potentially stale locks
 *   clear [exec_id]   Clear a specific lock (force timeout)
 *   clear-all         Clear ALL locks (use with caution)
 *   stats             Show lock statistics
 *
 * Options:
 *   --verbose         Show detailed information
 *   --force           Skip confirmation prompts
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
$command = $argv[1] ?? 'help';
$arg = $argv[2] ?? null;
$options = array_slice($argv, 3);
$verbose = in_array('--verbose', $options);
$force = in_array('--force', $options);

switch ($command) {
	case 'list':
		listLocks($verbose);
		break;

	case 'stale':
		showStaleLocks($verbose);
		break;

	case 'clear':
		if (!$arg) {
			echo "ERROR: Missing execution ID\n";
			echo "Usage: php bin/cron-locks.php clear [execution_id]\n";
			exit(1);
		}
		clearLock((int)$arg, $force);
		break;

	case 'clear-all':
		clearAllLocks($force);
		break;

	case 'stats':
		showStatistics();
		break;

	case 'help':
	default:
		showHelp();
		break;
}

// ============================================
// Commands
// ============================================

function listLocks($verbose) {
	echo "Current Locks\n";
	echo "=============\n\n";

	$sql = "
		SELECT cje.id, cje.cron_job, cje.locked_by, cje.locked_at, cje.locked_until,
		       cj.name as job_name, cj.allow_concurrent
		FROM CronJobExecution cje
		JOIN CronJob cj ON cje.cron_job = cj.id
		WHERE cje.status = 'running'
		ORDER BY cje.locked_at DESC
	";

	$locks = DBI::query($sql);

	if (empty($locks)) {
		echo "No active locks found.\n";
		return;
	}

	echo "Found " . count($locks) . " active lock(s):\n\n";

	foreach ($locks as $lock) {
		$lockedFor = time() - $lock['locked_at'];
		$expiresIn = $lock['locked_until'] - time();
		$isStale = $expiresIn < 0;

		echo "Execution #{$lock['id']}: Job #{$lock['cron_job']} ({$lock['job_name']})\n";
		echo "  Locked by:     {$lock['locked_by']}\n";
		echo "  Locked at:     " . date('Y-m-d H:i:s', $lock['locked_at']) . "\n";
		echo "  Locked for:    " . formatDuration($lockedFor) . "\n";

		if ($isStale) {
			$expiredFor = abs($expiresIn);
			echo "  Status:        ⚠ STALE (expired {$expiredFor}s ago)\n";
		} else {
			echo "  Expires in:    {$expiresIn}s\n";
			echo "  Status:        ✓ Active\n";
		}

		if ($verbose) {
			echo "  Concurrent:    " . ($lock['allow_concurrent'] ? 'Allowed' : 'Disabled') . "\n";
		}

		echo "\n";
	}
}

function showStaleLocks($verbose) {
	echo "Stale Locks\n";
	echo "===========\n\n";

	$sql = "
		SELECT cje.id, cje.cron_job, cje.locked_by, cje.locked_at, cje.locked_until,
		       cj.name as job_name
		FROM CronJobExecution cje
		JOIN CronJob cj ON cje.cron_job = cj.id
		WHERE cje.status = 'running'
		AND cje.locked_until < UNIX_TIMESTAMP()
		ORDER BY cje.locked_until ASC
	";

	$staleLocks = DBI::query($sql);

	if (empty($staleLocks)) {
		echo "✓ No stale locks found.\n";
		echo "\nAll locks are within their lease period.\n";
		return;
	}

	echo "⚠ Found " . count($staleLocks) . " stale lock(s):\n\n";

	foreach ($staleLocks as $lock) {
		$lockedFor = time() - $lock['locked_at'];
		$expiredFor = time() - $lock['locked_until'];

		echo "Execution #{$lock['id']}: Job #{$lock['cron_job']} ({$lock['job_name']})\n";
		echo "  Locked by:     {$lock['locked_by']}\n";
		echo "  Locked at:     " . date('Y-m-d H:i:s', $lock['locked_at']) . "\n";
		echo "  Locked for:    " . formatDuration($lockedFor) . "\n";
		echo "  Expired for:   " . formatDuration($expiredFor) . "\n";

		if ($expiredFor > 600) {
			echo "  ⚠ This lock expired over 10 minutes ago - likely a crashed worker\n";
		}

		if ($verbose) {
			// Check if the worker process is still running
			$parts = explode(':', $lock['locked_by']);
			if (count($parts) == 2) {
				$pid = (int)$parts[1];
				if (posix_getpgid($pid) === false) {
					echo "  ⚠ Worker process (PID {$pid}) is not running\n";
				} else {
					echo "  ✓ Worker process (PID {$pid}) is still running\n";
				}
			}
		}

		echo "\n";
	}

	echo "To clear stale locks, run:\n";
	echo "  php bin/cron-locks.php clear-all\n\n";
	echo "Or clear individual locks:\n";
	echo "  php bin/cron-locks.php clear [execution_id]\n";
}

function clearLock($executionId, $force) {
	echo "Clearing Lock for Execution #{$executionId}\n";
	echo "=========================================\n\n";

	// Get lock details
	$sql = "
		SELECT cje.id, cje.cron_job, cje.locked_by, cje.locked_at,
		       cj.name as job_name
		FROM CronJobExecution cje
		JOIN CronJob cj ON cje.cron_job = cj.id
		WHERE cje.id = ?
		AND cje.status = 'running'
	";

	$lock = DBI::prepared_query($sql, 'i', [$executionId]);

	if (empty($lock)) {
		echo "ERROR: Execution #{$executionId} not found or not running.\n";
		exit(1);
	}

	$lock = $lock[0];

	echo "Job:       #{$lock['cron_job']} ({$lock['job_name']})\n";
	echo "Locked by: {$lock['locked_by']}\n";
	echo "Locked at: " . date('Y-m-d H:i:s', $lock['locked_at']) . "\n";
	echo "\n";

	if (!$force) {
		echo "Are you sure you want to clear this lock? [y/N] ";
		$confirm = trim(fgets(STDIN));
		if (strtolower($confirm) !== 'y') {
			echo "Cancelled.\n";
			exit(0);
		}
	}

	// Clear the lock by marking as timeout
	$sql = "
		UPDATE CronJobExecution
		SET status = 'timeout',
			error = CONCAT(
				COALESCE(error, ''),
				'\\nManually cleared at ', FROM_UNIXTIME(UNIX_TIMESTAMP()),
				'\\nOriginal locked_by: ', locked_by
			)
		WHERE id = ?
	";

	DBI::prepared_query($sql, 'i', [$executionId]);

	echo "✓ Lock cleared successfully.\n";
	echo "\nThe job will be rescheduled on the next worker cycle.\n";
}

function clearAllLocks($force) {
	echo "Clear All Locks\n";
	echo "===============\n\n";

	// Count stale locks
	$sql = "
		SELECT COUNT(*) as count
		FROM CronJobExecution
		WHERE status = 'running'
		AND locked_until < UNIX_TIMESTAMP()
	";

	$staleCount = DBI::query($sql)[0]['count'];

	// Count active locks
	$sql = "
		SELECT COUNT(*) as count
		FROM CronJobExecution
		WHERE status = 'running'
		AND locked_until >= UNIX_TIMESTAMP()
	";

	$activeCount = DBI::query($sql)[0]['count'];

	echo "Stale locks:  {$staleCount}\n";
	echo "Active locks: {$activeCount}\n";
	echo "\n";

	if ($staleCount == 0 && $activeCount == 0) {
		echo "No locks to clear.\n";
		exit(0);
	}

	if ($activeCount > 0) {
		echo "⚠ WARNING: This will clear {$activeCount} ACTIVE lock(s)\n";
		echo "  Active locks may be for jobs that are currently running.\n";
		echo "  This can cause jobs to be marked as timed out even if they complete successfully.\n";
		echo "\n";
	}

	if (!$force) {
		echo "Are you sure you want to clear ALL locks? [y/N] ";
		$confirm = trim(fgets(STDIN));
		if (strtolower($confirm) !== 'y') {
			echo "Cancelled.\n";
			exit(0);
		}
	}

	// Clear all locks
	$sql = "
		UPDATE CronJobExecution
		SET status = 'timeout',
			error = CONCAT(
				COALESCE(error, ''),
				'\\nManually cleared at ', FROM_UNIXTIME(UNIX_TIMESTAMP()),
				'\\nCleared via clear-all command'
			)
		WHERE status = 'running'
	";

	DBI::query($sql);
	$count = DBI::affected_rows();

	echo "✓ Cleared {$count} lock(s).\n";
}

function showStatistics() {
	echo "Lock Statistics\n";
	echo "===============\n\n";

	// Overall stats
	$sql = "
		SELECT
			COUNT(*) as total_executions,
			SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as currently_locked,
			SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END) as total_timeouts,
			SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed
		FROM CronJobExecution
		WHERE date_created > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 24 HOUR))
	";

	$stats = DBI::query($sql)[0];

	echo "Last 24 Hours:\n";
	echo "  Total Executions: {$stats['total_executions']}\n";
	echo "  Completed:        {$stats['total_completed']}\n";
	echo "  Timeouts:         {$stats['total_timeouts']}\n";
	echo "  Currently Locked: {$stats['currently_locked']}\n";

	if ($stats['total_executions'] > 0) {
		$timeoutRate = ($stats['total_timeouts'] / $stats['total_executions']) * 100;
		echo "  Timeout Rate:     " . number_format($timeoutRate, 2) . "%\n";
	}

	// Stale locks
	$sql = "
		SELECT COUNT(*) as count
		FROM CronJobExecution
		WHERE status = 'running'
		AND locked_until < UNIX_TIMESTAMP()
	";

	$staleCount = DBI::query($sql)[0]['count'];

	echo "\n";
	if ($staleCount > 0) {
		echo "⚠ {$staleCount} stale lock(s) detected\n";
		echo "  Run 'php bin/cron-locks.php stale' for details\n";
	} else {
		echo "✓ No stale locks\n";
	}

	// Jobs with frequent timeouts
	echo "\n";
	echo "Jobs with Frequent Timeouts (Last 7 Days):\n";

	$sql = "
		SELECT cj.id, cj.name,
		       COUNT(*) as timeout_count,
		       MAX(cje.date_created) as last_timeout
		FROM CronJobExecution cje
		JOIN CronJob cj ON cje.cron_job = cj.id
		WHERE cje.status = 'timeout'
		AND cje.date_created > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))
		GROUP BY cj.id, cj.name
		HAVING timeout_count > 3
		ORDER BY timeout_count DESC
		LIMIT 10
	";

	$frequentTimeouts = DBI::query($sql);

	if (empty($frequentTimeouts)) {
		echo "  ✓ No jobs with frequent timeouts\n";
	} else {
		foreach ($frequentTimeouts as $job) {
			echo "  - Job #{$job['id']} ({$job['name']}): {$job['timeout_count']} timeouts\n";
			echo "    Last timeout: " . date('Y-m-d H:i:s', $job['last_timeout']) . "\n";
		}

		echo "\n";
		echo "⚠ These jobs may need timeout adjustments or code optimization\n";
	}
}

// ============================================
// Helpers
// ============================================

function formatDuration($seconds) {
	$hours = floor($seconds / 3600);
	$minutes = floor(($seconds % 3600) / 60);
	$secs = $seconds % 60;

	if ($hours > 0) {
		return sprintf("%dh %dm %ds", $hours, $minutes, $secs);
	} elseif ($minutes > 0) {
		return sprintf("%dm %ds", $minutes, $secs);
	} else {
		return sprintf("%ds", $secs);
	}
}

function showHelp() {
	echo "Cron Lock Management Utility\n";
	echo "============================\n\n";
	echo "Inspect and manage cron job locks.\n\n";
	echo "Usage: php bin/cron-locks.php [command] [options]\n\n";
	echo "Commands:\n";
	echo "  list              List all current locks\n";
	echo "  stale             Show potentially stale locks\n";
	echo "  clear [exec_id]   Clear a specific lock (force timeout)\n";
	echo "  clear-all         Clear ALL locks (use with caution)\n";
	echo "  stats             Show lock statistics\n";
	echo "  help              Show this help message\n\n";
	echo "Options:\n";
	echo "  --verbose         Show detailed information\n";
	echo "  --force           Skip confirmation prompts\n\n";
	echo "Examples:\n";
	echo "  php bin/cron-locks.php list\n";
	echo "  php bin/cron-locks.php stale --verbose\n";
	echo "  php bin/cron-locks.php clear 123\n";
	echo "  php bin/cron-locks.php clear-all --force\n";
	echo "  php bin/cron-locks.php stats\n";
}
