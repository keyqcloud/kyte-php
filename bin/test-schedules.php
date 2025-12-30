#!/usr/bin/env php
<?php
/**
 * Schedule & Dependency Test Script
 *
 * Tests daily/weekly/monthly schedules with timezones
 * and job dependency chains.
 *
 * Usage:
 *   php bin/test-schedules.php [action]
 *
 * Actions:
 *   create-schedules    Create test jobs with different schedule types
 *   create-chain        Create dependent job chain (A→B→C)
 *   status              Check status of test jobs
 *   next-runs           Show next run times for all schedules
 *   cleanup             Remove all test jobs
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

switch ($action) {
	case 'create-schedules':
		createScheduleTests();
		break;

	case 'create-chain':
		createDependencyChain();
		break;

	case 'status':
		showStatus();
		break;

	case 'next-runs':
		showNextRuns();
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

function createScheduleTests() {
	echo "Creating Schedule Test Jobs\n";
	echo "===========================\n\n";

	$jobs = [];

	// Daily schedule - 2:00 AM UTC
	echo "Creating daily schedule (2:00 AM UTC)...\n";
	$jobs[] = createTestJob(
		'Schedule Test - Daily',
		'daily',
		'02:00:00',
		null,
		null,
		'UTC'
	);

	// Daily schedule - Different timezone
	echo "Creating daily schedule (9:00 AM America/New_York)...\n";
	$jobs[] = createTestJob(
		'Schedule Test - Daily NY',
		'daily',
		'09:00:00',
		null,
		null,
		'America/New_York'
	);

	// Weekly schedule - Mondays at 8:00 AM
	echo "Creating weekly schedule (Mondays at 8:00 AM UTC)...\n";
	$jobs[] = createTestJob(
		'Schedule Test - Weekly',
		'weekly',
		'08:00:00',
		1, // Monday
		null,
		'UTC'
	);

	// Monthly schedule - 1st of month at 3:00 AM
	echo "Creating monthly schedule (1st at 3:00 AM UTC)...\n";
	$jobs[] = createTestJob(
		'Schedule Test - Monthly',
		'monthly',
		'03:00:00',
		null,
		1,
		'UTC'
	);

	// Monthly schedule - Last day handling (31st)
	echo "Creating monthly schedule (31st at 11:00 PM UTC)...\n";
	$jobs[] = createTestJob(
		'Schedule Test - Monthly 31st',
		'monthly',
		'23:00:00',
		null,
		31,
		'UTC'
	);

	echo "\n✓ Created " . count($jobs) . " schedule test jobs\n\n";

	echo "Job IDs:\n";
	foreach ($jobs as $i => $jobId) {
		echo "  Job " . ($i + 1) . ": #{$jobId}\n";
	}

	echo "\n";
	echo "Next steps:\n";
	echo "  1. Check next run times: php bin/test-schedules.php next-runs\n";
	echo "  2. Start worker: php bin/cron-worker.php\n";
	echo "  3. Verify jobs run at scheduled times\n";
	echo "\n";
}

function createDependencyChain() {
	echo "Creating Dependency Chain Test\n";
	echo "==============================\n\n";

	echo "Creating 3-job chain: Job A → Job B → Job C\n\n";

	// Job A (no dependency) - runs every minute for testing
	echo "Creating Job A (independent, runs every minute)...\n";
	$jobA = createTestJob(
		'Dependency Test - Job A',
		'interval',
		null,
		null,
		null,
		'UTC',
		null,
		60 // Every 60 seconds
	);

	// Job B (depends on A) - also every minute
	echo "Creating Job B (depends on A)...\n";
	$jobB = createTestJob(
		'Dependency Test - Job B',
		'interval',
		null,
		null,
		null,
		'UTC',
		$jobA,
		60
	);

	// Job C (depends on B) - also every minute
	echo "Creating Job C (depends on B)...\n";
	$jobC = createTestJob(
		'Dependency Test - Job C',
		'interval',
		null,
		null,
		null,
		'UTC',
		$jobB,
		60
	);

	echo "\n✓ Created dependency chain\n\n";

	echo "Chain structure:\n";
	echo "  Job A (#{$jobA}) - Independent\n";
	echo "    ↓\n";
	echo "  Job B (#{$jobB}) - Depends on A\n";
	echo "    ↓\n";
	echo "  Job C (#{$jobC}) - Depends on B\n";
	echo "\n";

	echo "Expected behavior:\n";
	echo "  1. Job A runs first (no dependency)\n";
	echo "  2. Job B waits for A to complete, then runs\n";
	echo "  3. Job C waits for B to complete, then runs\n";
	echo "  4. Cycle repeats every minute\n";
	echo "\n";

	echo "Next steps:\n";
	echo "  1. Start worker: php bin/cron-worker.php\n";
	echo "  2. Watch logs for dependency checking\n";
	echo "  3. Check status: php bin/test-schedules.php status\n";
	echo "\n";
}

function createTestJob($name, $scheduleType, $timeOfDay, $dayOfWeek, $dayOfMonth, $timezone, $dependsOn = null, $intervalSeconds = null) {
	// Simple job code that logs and completes
	$jobCode = <<<'PHP'
<?php
class ScheduleTestJob extends \Kyte\Core\CronJobBase
{
	public function execute() {
		$this->log("Job started");

		$job = $this->getJobMetadata();
		if (isset($job['name'])) {
			$this->log("Job name: " . $job['name']);
		}

		$execution = $this->getExecution();
		$this->log("Scheduled time: " . date('Y-m-d H:i:s', $execution['scheduled_time']));
		$this->log("Actual run time: " . date('Y-m-d H:i:s'));

		// Simulate some work
		sleep(2);

		$this->log("Job completed successfully");
		return "Completed at " . date('Y-m-d H:i:s');
	}
}
PHP;

	$compressedCode = bzcompress($jobCode);

	$sql = "
		INSERT INTO CronJob (
			name, description, code, schedule_type,
			time_of_day, day_of_week, day_of_month, timezone,
			interval_seconds, depends_on_job,
			timeout_seconds, max_retries, enabled,
			application, kyte_account,
			created_by, date_created
		) VALUES (
			?, 'Phase 4 test job', ?, ?,
			?, ?, ?, ?,
			?, ?,
			300, 3, 1,
			NULL, 1,
			1, UNIX_TIMESTAMP()
		)
	";

	DBI::prepared_query($sql, 'ssssiisii', [
		$name,
		$compressedCode,
		$scheduleType,
		$timeOfDay,
		$dayOfWeek,
		$dayOfMonth,
		$timezone,
		$intervalSeconds,
		$dependsOn
	]);

	$jobId = DBI::insert_id();
	echo "  Created job #{$jobId}: {$name}\n";

	return $jobId;
}

function showStatus() {
	echo "Schedule & Dependency Test Status\n";
	echo "==================================\n\n";

	$sql = "
		SELECT cj.id, cj.name, cj.schedule_type, cj.time_of_day,
		       cj.day_of_week, cj.day_of_month, cj.timezone,
		       cj.interval_seconds, cj.depends_on_job, cj.enabled,
		       parent.name as parent_name
		FROM CronJob cj
		LEFT JOIN CronJob parent ON cj.depends_on_job = parent.id
		WHERE (cj.name LIKE 'Schedule Test%' OR cj.name LIKE 'Dependency Test%')
		AND cj.deleted = 0
		ORDER BY cj.id ASC
	";

	$jobs = DBI::query($sql);

	if (empty($jobs)) {
		echo "No test jobs found. Run 'create-schedules' or 'create-chain' first.\n";
		return;
	}

	foreach ($jobs as $job) {
		echo "Job #{$job['id']}: {$job['name']}\n";
		echo "  Schedule: {$job['schedule_type']}\n";

		if ($job['schedule_type'] === 'daily') {
			echo "    Time: {$job['time_of_day']}\n";
			echo "    Timezone: {$job['timezone']}\n";
		} elseif ($job['schedule_type'] === 'weekly') {
			$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
			echo "    Day: {$days[$job['day_of_week']]}\n";
			echo "    Time: {$job['time_of_day']}\n";
			echo "    Timezone: {$job['timezone']}\n";
		} elseif ($job['schedule_type'] === 'monthly') {
			echo "    Day of month: {$job['day_of_month']}\n";
			echo "    Time: {$job['time_of_day']}\n";
			echo "    Timezone: {$job['timezone']}\n";
		} elseif ($job['schedule_type'] === 'interval') {
			echo "    Interval: {$job['interval_seconds']} seconds\n";
		}

		if ($job['depends_on_job']) {
			echo "  Dependency: Depends on Job #{$job['depends_on_job']} ({$job['parent_name']})\n";
		}

		echo "  Status: " . ($job['enabled'] ? "✓ Enabled" : "⏸ Disabled") . "\n";

		// Get recent executions
		$sql = "
			SELECT id, status, scheduled_time, started_at, completed_at
			FROM CronJobExecution
			WHERE cron_job = ?
			ORDER BY date_created DESC
			LIMIT 3
		";

		$executions = DBI::prepared_query($sql, 'i', [$job['id']]);

		if (!empty($executions)) {
			echo "\n  Recent Executions:\n";
			foreach ($executions as $exec) {
				$statusIcon = match($exec['status']) {
					'completed' => '✓',
					'failed' => '✗',
					'running' => '▶',
					'pending' => '⏳',
					'skipped' => '⏭',
					default => '?'
				};

				echo "    {$statusIcon} Execution #{$exec['id']}: {$exec['status']}\n";
				echo "      Scheduled: " . date('Y-m-d H:i:s', $exec['scheduled_time']) . "\n";

				if ($exec['started_at']) {
					echo "      Started: " . date('Y-m-d H:i:s', $exec['started_at']) . "\n";
				}

				if ($exec['completed_at']) {
					echo "      Completed: " . date('Y-m-d H:i:s', $exec['completed_at']) . "\n";
				}
			}
		}

		echo "\n";
	}
}

function showNextRuns() {
	echo "Next Run Times (Phase 4 Schedule Calculation)\n";
	echo "=============================================\n\n";

	$sql = "
		SELECT id, name, schedule_type, time_of_day, day_of_week,
		       day_of_month, timezone, interval_seconds
		FROM CronJob
		WHERE (name LIKE 'Schedule Test%' OR name LIKE 'Dependency Test%')
		AND deleted = 0
		AND enabled = 1
		ORDER BY id ASC
	";

	$jobs = DBI::query($sql);

	if (empty($jobs)) {
		echo "No test jobs found.\n";
		return;
	}

	$now = time();

	foreach ($jobs as $job) {
		echo "Job #{$job['id']}: {$job['name']}\n";
		echo "  Schedule Type: {$job['schedule_type']}\n";

		try {
			$nextRun = calculateNextRun($job);
			$delay = $nextRun - $now;

			echo "  Next Run: " . date('Y-m-d H:i:s', $nextRun);

			if ($delay > 0) {
				$hours = floor($delay / 3600);
				$minutes = floor(($delay % 3600) / 60);
				$seconds = $delay % 60;

				if ($hours > 0) {
					echo " (in {$hours}h {$minutes}m {$seconds}s)";
				} elseif ($minutes > 0) {
					echo " (in {$minutes}m {$seconds}s)";
				} else {
					echo " (in {$seconds}s)";
				}
			} else {
				echo " (overdue by " . abs($delay) . "s)";
			}

			echo "\n";

			// Show timezone info for time-based schedules
			if (in_array($job['schedule_type'], ['daily', 'weekly', 'monthly'])) {
				$tz = new DateTimeZone($job['timezone'] ?? 'UTC');
				$dt = new DateTime('now', $tz);
				echo "  Current time in {$job['timezone']}: " . $dt->format('Y-m-d H:i:s T') . "\n";
			}

		} catch (Exception $e) {
			echo "  Error: " . $e->getMessage() . "\n";
		}

		echo "\n";
	}
}

function calculateNextRun($job) {
	switch ($job['schedule_type']) {
		case 'interval':
			return time() + $job['interval_seconds'];

		case 'daily':
			return calculateDailyNextRun($job);

		case 'weekly':
			return calculateWeeklyNextRun($job);

		case 'monthly':
			return calculateMonthlyNextRun($job);

		default:
			throw new Exception("Unsupported schedule type for preview: {$job['schedule_type']}");
	}
}

function calculateDailyNextRun($job) {
	$timezone = $job['timezone'] ?? 'UTC';
	$tz = new DateTimeZone($timezone);
	$now = new DateTime('now', $tz);

	$timeParts = explode(':', $job['time_of_day']);
	$hour = (int)$timeParts[0];
	$minute = (int)$timeParts[1];
	$second = isset($timeParts[2]) ? (int)$timeParts[2] : 0;

	$nextRun = clone $now;
	$nextRun->setTime($hour, $minute, $second);

	if ($nextRun <= $now) {
		$nextRun->modify('+1 day');
	}

	return $nextRun->getTimestamp();
}

function calculateWeeklyNextRun($job) {
	$timezone = $job['timezone'] ?? 'UTC';
	$tz = new DateTimeZone($timezone);
	$now = new DateTime('now', $tz);

	$timeParts = explode(':', $job['time_of_day']);
	$hour = (int)$timeParts[0];
	$minute = (int)$timeParts[1];
	$second = isset($timeParts[2]) ? (int)$timeParts[2] : 0;

	$targetDayOfWeek = (int)$job['day_of_week'];
	$currentDayOfWeek = (int)$now->format('w');

	$daysUntil = ($targetDayOfWeek - $currentDayOfWeek + 7) % 7;

	$nextRun = clone $now;
	$nextRun->modify("+{$daysUntil} days");
	$nextRun->setTime($hour, $minute, $second);

	if ($daysUntil === 0 && $nextRun <= $now) {
		$nextRun->modify('+7 days');
	}

	return $nextRun->getTimestamp();
}

function calculateMonthlyNextRun($job) {
	$timezone = $job['timezone'] ?? 'UTC';
	$tz = new DateTimeZone($timezone);
	$now = new DateTime('now', $tz);

	$timeParts = explode(':', $job['time_of_day']);
	$hour = (int)$timeParts[0];
	$minute = (int)$timeParts[1];
	$second = isset($timeParts[2]) ? (int)$timeParts[2] : 0;

	$targetDay = (int)$job['day_of_month'];

	$nextRun = clone $now;
	$nextRun->setDate((int)$nextRun->format('Y'), (int)$nextRun->format('m'), min($targetDay, (int)$nextRun->format('t')));
	$nextRun->setTime($hour, $minute, $second);

	if ($nextRun <= $now || (int)$nextRun->format('d') !== $targetDay) {
		$nextRun->modify('first day of next month');
		$nextRun->setDate((int)$nextRun->format('Y'), (int)$nextRun->format('m'), min($targetDay, (int)$nextRun->format('t')));
		$nextRun->setTime($hour, $minute, $second);
	}

	return $nextRun->getTimestamp();
}

function cleanup() {
	echo "Cleaning up test jobs...\n\n";

	$sql = "
		UPDATE CronJob
		SET deleted = 1, enabled = 0, date_modified = UNIX_TIMESTAMP()
		WHERE (name LIKE 'Schedule Test%' OR name LIKE 'Dependency Test%')
		AND deleted = 0
	";

	DBI::query($sql);
	$count = DBI::affected_rows();

	echo "✓ Deleted {$count} test job(s)\n";
}

function showHelp() {
	echo "Schedule & Dependency Test Script\n";
	echo "==================================\n\n";
	echo "Tests Phase 4 features: daily/weekly/monthly schedules and dependencies.\n\n";
	echo "Usage: php bin/test-schedules.php [action]\n\n";
	echo "Actions:\n";
	echo "  create-schedules    Create test jobs with daily/weekly/monthly schedules\n";
	echo "  create-chain        Create 3-job dependency chain (A→B→C)\n";
	echo "  status              Show status of all test jobs\n";
	echo "  next-runs           Show next run times (tests schedule calculation)\n";
	echo "  cleanup             Remove all test jobs\n";
	echo "  help                Show this help message\n\n";
	echo "Example workflow:\n";
	echo "  1. php bin/test-schedules.php create-schedules\n";
	echo "  2. php bin/test-schedules.php next-runs  # Verify calculations\n";
	echo "  3. php bin/cron-worker.php  # Start worker\n";
	echo "  4. php bin/test-schedules.php status  # Check execution history\n";
	echo "\n";
	echo "Dependency chain testing:\n";
	echo "  1. php bin/test-schedules.php create-chain\n";
	echo "  2. php bin/cron-worker.php  # Watch dependency checking in logs\n";
	echo "  3. php bin/test-schedules.php status  # Verify execution order\n";
}
