<?php

namespace Kyte\Cron;

use Kyte\Core\Api;
use Kyte\Core\Model;
use Kyte\Core\ModelObject;
use Kyte\Core\DBI;

/**
 * Cron Worker Daemon
 *
 * Continuously polls database for due jobs and executes them
 * with lease-based locking for idempotency in multi-server setups.
 *
 * Phase 1: Basic execution with locking (no retries, dependencies, or notifications yet)
 */
class CronWorker
{
	private $serverId;
	private $leaseDuration = 300; // 5 minutes
	private $pollInterval = 10;   // Check every 10 seconds
	private $maxJobsPerCycle = 10;
	private $running = true;
	private $activeExecution = null; // Track currently running job
	private $shutdownGracePeriod = 30; // Seconds to wait for job completion on shutdown

	// Phase 2: Lock contention metrics
	private $stats = [
		'cycles' => 0,
		'jobs_executed' => 0,
		'jobs_completed' => 0,
		'jobs_failed' => 0,
		'locks_acquired' => 0,
		'locks_missed' => 0,
		'leases_expired' => 0,
		'jobs_skipped' => 0,
	];

	public function __construct() {
		$this->serverId = gethostname() . ':' . getmypid();
	}

	/**
	 * Start the worker daemon
	 */
	public function start() {
		echo "[" . date('Y-m-d H:i:s') . "] Kyte Cron Worker started\n";
		echo "Server ID: {$this->serverId}\n";
		echo "Lease Duration: {$this->leaseDuration}s\n";
		echo "Poll Interval: {$this->pollInterval}s\n";
		echo "Shutdown Grace Period: {$this->shutdownGracePeriod}s\n";
		echo "---\n";

		// Handle graceful shutdown
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
			pcntl_signal(SIGINT, [$this, 'handleShutdown']);
		}

		while ($this->running) {
			if (function_exists('pcntl_signal_dispatch')) {
				pcntl_signal_dispatch(); // Process signals
			}

			try {
				$this->processCycle();
				$this->stats['cycles']++;
			} catch (\Exception $e) {
				$this->logError("Worker cycle error: " . $e->getMessage());
				$this->logError($e->getTraceAsString());
			}

			sleep($this->pollInterval);
		}

		// Wait for active job to complete
		$this->waitForActiveJob();

		// Print statistics
		$this->printStatistics();

		echo "[" . date('Y-m-d H:i:s') . "] Worker stopped\n";
	}

	/**
	 * Handle shutdown signal
	 */
	public function handleShutdown($signal) {
		echo "\n[" . date('Y-m-d H:i:s') . "] Received shutdown signal ({$signal})\n";
		$this->running = false;
	}

	/**
	 * Process one cycle: find due jobs and execute
	 */
	private function processCycle() {
		// 1. Clean up expired leases
		$this->cleanupExpiredLeases();

		// 2. Schedule new executions for due jobs
		$this->scheduleDueJobs();

		// 3. Find and execute pending jobs
		$executions = $this->findPendingExecutions();

		foreach ($executions as $execution) {
			try {
				$this->processExecution($execution);
			} catch (\Exception $e) {
				$this->logError("Execution error: " . $e->getMessage());
			}
		}
	}

	/**
	 * Clean up leases that have expired
	 *
	 * Phase 2: Enhanced with detailed logging and stale lock detection
	 */
	private function cleanupExpiredLeases() {
		// First, identify the expired leases before updating
		$sql = "
			SELECT cje.id, cje.cron_job, cje.locked_by, cje.locked_at, cje.locked_until,
			       cj.name as job_name
			FROM CronJobExecution cje
			JOIN CronJob cj ON cje.cron_job = cj.id
			WHERE cje.status = 'running'
			AND cje.locked_until < UNIX_TIMESTAMP()
		";

		$expiredLeases = DBI::query($sql);

		if (empty($expiredLeases)) {
			return; // No expired leases
		}

		$this->stats['leases_expired'] += count($expiredLeases);

		// Log details about each expired lease
		echo "[" . date('Y-m-d H:i:s') . "] Found " . count($expiredLeases) . " expired lease(s):\n";

		foreach ($expiredLeases as $lease) {
			$lockedDuration = time() - $lease['locked_at'];
			$expiredFor = time() - $lease['locked_until'];

			echo "  - Job #{$lease['cron_job']} ({$lease['job_name']})\n";
			echo "    Execution: #{$lease['id']}\n";
			echo "    Locked by: {$lease['locked_by']}\n";
			echo "    Locked for: " . gmdate('H:i:s', $lockedDuration) . "\n";
			echo "    Expired {$expiredFor}s ago\n";

			// Warn if lease expired long ago (possible worker crash)
			if ($expiredFor > 300) { // 5 minutes
				echo "    ⚠ WARNING: Lease expired long ago - possible worker crash\n";
			}
		}

		// Now update all expired leases
		$sql = "
			UPDATE CronJobExecution
			SET status = 'timeout',
				error = CONCAT(
					COALESCE(error, ''),
					'\\nLease expired at ', FROM_UNIXTIME(locked_until),
					'\\nLocked by: ', locked_by,
					'\\nLocked duration: ', (locked_until - locked_at), ' seconds'
				)
			WHERE status = 'running'
			AND locked_until < UNIX_TIMESTAMP()
		";

		DBI::query($sql);

		// Schedule next executions for timed-out jobs
		foreach ($expiredLeases as $lease) {
			try {
				// Get full job details
				$sql = "SELECT * FROM CronJob WHERE id = ?";
				$job = DBI::prepared_query($sql, 'i', [$lease['cron_job']]);

				if (!empty($job)) {
					$nextRun = $this->calculateNextRun($job[0]);
					$this->createExecution($job[0], $nextRun);
					echo "  ✓ Scheduled next execution for job #{$lease['cron_job']}\n";
				}
			} catch (\Exception $e) {
				$this->logError("Error scheduling next run after timeout: " . $e->getMessage());
			}
		}
	}

	/**
	 * Schedule new executions for jobs that are due
	 *
	 * Phase 4: Enhanced with dependency checking
	 */
	private function scheduleDueJobs() {
		// Find enabled jobs that don't have a pending execution scheduled
		$sql = "
			SELECT cj.*
			FROM CronJob cj
			WHERE cj.enabled = 1
			AND cj.deleted = 0
			AND cj.in_dead_letter_queue = 0
			AND NOT EXISTS (
				SELECT 1 FROM CronJobExecution cje
				WHERE cje.cron_job = cj.id
				AND cje.status IN ('pending', 'running')
			)
		";

		$jobs = DBI::query($sql);

		foreach ($jobs as $job) {
			try {
				// Phase 4: Check if job has dependency
				if (!empty($job['depends_on_job'])) {
					if (!$this->checkDependencyMet($job)) {
						// Dependency not met, skip scheduling
						continue;
					}
				}

				$nextRun = $this->calculateNextRun($job);

				if ($nextRun <= time()) {
					$this->createExecution($job, $nextRun);
				}
			} catch (\Exception $e) {
				$this->logError("Error scheduling job #{$job['id']}: " . $e->getMessage());
			}
		}
	}

	/**
	 * Find pending executions that are ready to run
	 */
	private function findPendingExecutions() {
		$sql = "
			SELECT cje.*, cj.code, cj.timeout_seconds, cj.allow_concurrent, cj.name as job_name
			FROM CronJobExecution cje
			JOIN CronJob cj ON cje.cron_job = cj.id
			WHERE cje.next_run_time <= UNIX_TIMESTAMP()
			AND cje.status = 'pending'
			AND cj.enabled = 1
			AND cj.deleted = 0
			AND cj.in_dead_letter_queue = 0
			ORDER BY cje.next_run_time ASC
			LIMIT " . $this->maxJobsPerCycle . "
		";

		return DBI::query($sql);
	}

	/**
	 * Process a single execution
	 *
	 * Phase 4: Enhanced with dependency checking before execution
	 */
	private function processExecution($execution) {
		// Phase 4: Get full job details to check dependencies
		$sql = "SELECT * FROM CronJob WHERE id = ?";
		$job = DBI::prepared_query($sql, 'i', [$execution['cron_job']]);

		if (empty($job)) {
			$this->logError("Job #{$execution['cron_job']} not found");
			return;
		}

		$job = $job[0];

		// Phase 4: Check if job has dependency
		if (!empty($job['depends_on_job'])) {
			if (!$this->checkDependencyMet($job)) {
				$this->skipExecution($execution['id'], "Dependency not met: parent job #{$job['depends_on_job']} has not completed successfully");
				return;
			}
		}

		// Check concurrent execution
		if (!$this->checkConcurrency($execution)) {
			$this->skipExecution($execution['id'], "Job already running (concurrent execution disabled)");
			return;
		}

		// Try to acquire lock
		if (!$this->acquireLock($execution['id'])) {
			return; // Another worker got it
		}

		// Execute the job
		$this->executeJob($execution);
	}

	/**
	 * Check if concurrent execution is allowed
	 */
	private function checkConcurrency($execution) {
		if ($execution['allow_concurrent'] == 1) {
			return true; // Concurrent execution allowed
		}

		// Check if another execution of this job is running
		$sql = "
			SELECT COUNT(*) as count
			FROM CronJobExecution
			WHERE cron_job = ?
			AND status = 'running'
			AND id != ?
		";

		$result = DBI::prepared_query($sql, 'ii', [$execution['cron_job'], $execution['id']]);

		return $result[0]['count'] == 0;
	}

	/**
	 * Acquire lease-based lock on execution
	 */
	private function acquireLock($executionId) {
		$leaseExpiry = time() + $this->leaseDuration;

		$sql = "
			UPDATE CronJobExecution
			SET status = 'running',
				locked_by = ?,
				locked_at = UNIX_TIMESTAMP(),
				locked_until = ?,
				started_at = UNIX_TIMESTAMP()
			WHERE id = ?
			AND status = 'pending'
		";

		DBI::prepared_query($sql, 'sii', [$this->serverId, $leaseExpiry, $executionId]);

		$acquired = DBI::affected_rows() > 0;

		// Track lock contention metrics
		if ($acquired) {
			$this->stats['locks_acquired']++;
		} else {
			$this->stats['locks_missed']++;
		}

		return $acquired;
	}

	/**
	 * Execute the job code
	 */
	private function executeJob($execution) {
		$startTime = microtime(true);
		$startMemory = memory_get_peak_usage(true);

		// Track active execution for graceful shutdown
		$this->activeExecution = $execution;
		$this->stats['jobs_executed']++;

		echo "[" . date('Y-m-d H:i:s') . "] Executing job #{$execution['cron_job']} ({$execution['job_name']}) - execution #{$execution['id']}\n";

		try {
			// Decompress code
			$code = bzdecompress($execution['code']);
			if ($code === false) {
				throw new \Exception("Failed to decompress job code");
			}

			// Set timeout
			set_time_limit($execution['timeout_seconds']);

			// Capture output
			ob_start();

			// Evaluate job code - this defines the class
			eval($code);

			// Extract class name from code
			$className = $this->extractClassName($code);

			if (!class_exists($className)) {
				throw new \Exception("Job class {$className} not found after eval");
			}

			// Instantiate and execute job
			$job = new $className();
			$job->setExecution($execution);

			// Run job lifecycle
			$job->setUp();
			$result = $job->execute();
			$job->tearDown();

			// Capture output
			$output = ob_get_clean();

			// Add result to output if it's a string
			if (is_string($result)) {
				$output .= "\n" . $result;
			}

			// Calculate metrics
			$duration = (microtime(true) - $startTime) * 1000; // ms
			$memoryPeak = (memory_get_peak_usage(true) - $startMemory) / 1024 / 1024; // MB

			// Mark as completed
			$this->completeExecution($execution, $output, $duration, $memoryPeak);
			$this->stats['jobs_completed']++;

			echo "[" . date('Y-m-d H:i:s') . "] Job #{$execution['cron_job']} completed in " . round($duration) . "ms\n";

		} catch (\Exception $e) {
			ob_end_clean();

			$duration = (microtime(true) - $startTime) * 1000;
			$memoryPeak = (memory_get_peak_usage(true) - $startMemory) / 1024 / 1024;

			$this->failExecution($execution, $e, $duration, $memoryPeak);
			$this->stats['jobs_failed']++;

			echo "[" . date('Y-m-d H:i:s') . "] Job #{$execution['cron_job']} failed: {$e->getMessage()}\n";
		} finally {
			// Clear active execution
			$this->activeExecution = null;
		}
	}

	/**
	 * Mark execution as completed
	 */
	private function completeExecution($execution, $output, $duration, $memoryPeak) {
		$sql = "
			UPDATE CronJobExecution
			SET status = 'completed',
				completed_at = UNIX_TIMESTAMP(),
				duration_ms = ?,
				memory_peak_mb = ?,
				output = ?,
				exit_code = 0
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'idsi', [$duration, $memoryPeak, $output, $execution['id']]);

		// Phase 3: Reset consecutive failures on success
		$this->resetConsecutiveFailures($execution['cron_job']);

		// Schedule next execution
		try {
			$nextRun = $this->calculateNextRun($execution);
			$this->createExecution(['id' => $execution['cron_job'], 'application' => $execution['application'], 'kyte_account' => $execution['kyte_account']], $nextRun);
		} catch (\Exception $e) {
			$this->logError("Error scheduling next run for job #{$execution['cron_job']}: " . $e->getMessage());
		}
	}

	/**
	 * Mark execution as failed
	 *
	 * Phase 3: Enhanced with retry logic, dead letter queue, and notifications
	 */
	private function failExecution($execution, $exception, $duration, $memoryPeak) {
		$error = $exception->getMessage();
		$stackTrace = $exception->getTraceAsString();

		// Get current retry count
		$retryCount = isset($execution['retry_count']) ? $execution['retry_count'] : 0;

		$sql = "
			UPDATE CronJobExecution
			SET status = 'failed',
				completed_at = UNIX_TIMESTAMP(),
				duration_ms = ?,
				memory_peak_mb = ?,
				error = ?,
				stack_trace = ?,
				exit_code = 1
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'idssi', [$duration, $memoryPeak, $error, $stackTrace, $execution['id']]);

		// Phase 3: Handle retries and dead letter queue
		$this->handleFailureRetry($execution, $error);
	}

	/**
	 * Mark execution as skipped
	 */
	private function skipExecution($executionId, $reason) {
		$sql = "
			UPDATE CronJobExecution
			SET status = 'skipped',
				skipped_reason = ?,
				completed_at = UNIX_TIMESTAMP()
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'si', [$reason, $executionId]);
		$this->stats['jobs_skipped']++;

		echo "[" . date('Y-m-d H:i:s') . "] Execution #{$executionId} skipped: {$reason}\n";
	}

	/**
	 * Calculate next run time based on schedule
	 *
	 * Phase 4: Now supports daily, weekly, and monthly schedules with timezone support
	 */
	private function calculateNextRun($job) {
		switch ($job['schedule_type']) {
			case 'cron':
				if (empty($job['cron_expression'])) {
					throw new \Exception("Cron expression is required for cron schedule type");
				}
				$cron = new \Cron\CronExpression($job['cron_expression']);
				return $cron->getNextRunDate()->getTimestamp();

			case 'interval':
				if (empty($job['interval_seconds'])) {
					throw new \Exception("Interval seconds is required for interval schedule type");
				}
				return time() + $job['interval_seconds'];

			case 'daily':
				return $this->calculateDailyNextRun($job);

			case 'weekly':
				return $this->calculateWeeklyNextRun($job);

			case 'monthly':
				return $this->calculateMonthlyNextRun($job);

			default:
				throw new \Exception("Unknown schedule type: {$job['schedule_type']}");
		}
	}

	/**
	 * Phase 4: Calculate next run for daily schedule
	 *
	 * Runs at specific time each day (e.g., 2:00 AM daily)
	 */
	private function calculateDailyNextRun($job) {
		if (empty($job['time_of_day'])) {
			throw new \Exception("time_of_day is required for daily schedule type");
		}

		$timezone = $job['timezone'] ?? 'UTC';

		try {
			$tz = new \DateTimeZone($timezone);
		} catch (\Exception $e) {
			throw new \Exception("Invalid timezone: {$timezone}");
		}

		// Current time in job's timezone
		$now = new \DateTime('now', $tz);

		// Parse time_of_day (format: HH:MM:SS)
		$timeParts = explode(':', $job['time_of_day']);
		if (count($timeParts) < 2) {
			throw new \Exception("Invalid time_of_day format. Expected HH:MM or HH:MM:SS");
		}

		$hour = (int)$timeParts[0];
		$minute = (int)$timeParts[1];
		$second = isset($timeParts[2]) ? (int)$timeParts[2] : 0;

		// Create next run time for today
		$nextRun = clone $now;
		$nextRun->setTime($hour, $minute, $second);

		// If time has already passed today, schedule for tomorrow
		if ($nextRun <= $now) {
			$nextRun->modify('+1 day');
		}

		return $nextRun->getTimestamp();
	}

	/**
	 * Phase 4: Calculate next run for weekly schedule
	 *
	 * Runs at specific time on specific day each week (e.g., Mondays at 2:00 AM)
	 */
	private function calculateWeeklyNextRun($job) {
		if (empty($job['time_of_day'])) {
			throw new \Exception("time_of_day is required for weekly schedule type");
		}

		if (!isset($job['day_of_week'])) {
			throw new \Exception("day_of_week is required for weekly schedule type (0=Sunday, 6=Saturday)");
		}

		$timezone = $job['timezone'] ?? 'UTC';

		try {
			$tz = new \DateTimeZone($timezone);
		} catch (\Exception $e) {
			throw new \Exception("Invalid timezone: {$timezone}");
		}

		// Current time in job's timezone
		$now = new \DateTime('now', $tz);

		// Parse time_of_day
		$timeParts = explode(':', $job['time_of_day']);
		if (count($timeParts) < 2) {
			throw new \Exception("Invalid time_of_day format. Expected HH:MM or HH:MM:SS");
		}

		$hour = (int)$timeParts[0];
		$minute = (int)$timeParts[1];
		$second = isset($timeParts[2]) ? (int)$timeParts[2] : 0;

		$targetDayOfWeek = (int)$job['day_of_week']; // 0=Sunday, 6=Saturday
		$currentDayOfWeek = (int)$now->format('w');

		// Calculate days until target day
		$daysUntil = ($targetDayOfWeek - $currentDayOfWeek + 7) % 7;

		// Create next run time
		$nextRun = clone $now;
		$nextRun->modify("+{$daysUntil} days");
		$nextRun->setTime($hour, $minute, $second);

		// If it's the same day but time has passed, schedule for next week
		if ($daysUntil === 0 && $nextRun <= $now) {
			$nextRun->modify('+7 days');
		}

		return $nextRun->getTimestamp();
	}

	/**
	 * Phase 4: Calculate next run for monthly schedule
	 *
	 * Runs at specific time on specific day each month (e.g., 1st of month at 2:00 AM)
	 */
	private function calculateMonthlyNextRun($job) {
		if (empty($job['time_of_day'])) {
			throw new \Exception("time_of_day is required for monthly schedule type");
		}

		if (empty($job['day_of_month'])) {
			throw new \Exception("day_of_month is required for monthly schedule type (1-31)");
		}

		$timezone = $job['timezone'] ?? 'UTC';

		try {
			$tz = new \DateTimeZone($timezone);
		} catch (\Exception $e) {
			throw new \Exception("Invalid timezone: {$timezone}");
		}

		// Current time in job's timezone
		$now = new \DateTime('now', $tz);

		// Parse time_of_day
		$timeParts = explode(':', $job['time_of_day']);
		if (count($timeParts) < 2) {
			throw new \Exception("Invalid time_of_day format. Expected HH:MM or HH:MM:SS");
		}

		$hour = (int)$timeParts[0];
		$minute = (int)$timeParts[1];
		$second = isset($timeParts[2]) ? (int)$timeParts[2] : 0;

		$targetDay = (int)$job['day_of_month'];

		if ($targetDay < 1 || $targetDay > 31) {
			throw new \Exception("day_of_month must be between 1 and 31");
		}

		// Try this month first
		$nextRun = clone $now;
		$nextRun->setDate((int)$nextRun->format('Y'), (int)$nextRun->format('m'), min($targetDay, (int)$nextRun->format('t')));
		$nextRun->setTime($hour, $minute, $second);

		// If this month's date has passed, or day doesn't exist in this month, go to next month
		if ($nextRun <= $now || (int)$nextRun->format('d') !== $targetDay) {
			$nextRun->modify('first day of next month');
			$nextRun->setDate((int)$nextRun->format('Y'), (int)$nextRun->format('m'), min($targetDay, (int)$nextRun->format('t')));
			$nextRun->setTime($hour, $minute, $second);
		}

		return $nextRun->getTimestamp();
	}

	/**
	 * Create new execution record
	 */
	private function createExecution($job, $scheduledTime) {
		$sql = "
			INSERT INTO CronJobExecution (
				cron_job, scheduled_time, next_run_time, status, application, kyte_account, date_created
			) VALUES (?, ?, ?, 'pending', ?, ?, UNIX_TIMESTAMP())
		";

		DBI::prepared_query($sql, 'iiiii', [
			$job['id'],
			$scheduledTime,
			$scheduledTime,
			$job['application'] ?? null,
			$job['kyte_account']
		]);
	}

	/**
	 * Extract class name from evaluated code
	 */
	private function extractClassName($code) {
		// Simple regex to find class name
		if (preg_match('/class\s+(\w+)\s+extends/', $code, $matches)) {
			return $matches[1];
		}
		throw new \Exception("Could not extract class name from job code");
	}

	/**
	 * Log error message
	 */
	private function logError($message) {
		error_log("[CronWorker] " . $message);
		echo "[" . date('Y-m-d H:i:s') . "] ERROR: {$message}\n";
	}

	/**
	 * Phase 2: Wait for active job to complete on shutdown
	 *
	 * Provides graceful shutdown by waiting up to shutdownGracePeriod seconds
	 * for the currently running job to complete.
	 */
	private function waitForActiveJob() {
		if (!$this->activeExecution) {
			return; // No active job
		}

		echo "[" . date('Y-m-d H:i:s') . "] Waiting for active job to complete (max {$this->shutdownGracePeriod}s)...\n";
		echo "  Job: #{$this->activeExecution['cron_job']} ({$this->activeExecution['job_name']})\n";
		echo "  Execution: #{$this->activeExecution['id']}\n";

		$waitStart = time();
		$warned = false;

		while ($this->activeExecution !== null && (time() - $waitStart) < $this->shutdownGracePeriod) {
			if (!$warned && (time() - $waitStart) > ($this->shutdownGracePeriod / 2)) {
				echo "[" . date('Y-m-d H:i:s') . "] Job still running, waiting...\n";
				$warned = true;
			}
			sleep(1);
		}

		if ($this->activeExecution !== null) {
			echo "[" . date('Y-m-d H:i:s') . "] WARNING: Job did not complete within grace period\n";
			echo "  Job will continue running but may be marked as timeout\n";
		} else {
			echo "[" . date('Y-m-d H:i:s') . "] Active job completed successfully\n";
		}
	}

	/**
	 * Phase 2: Print worker statistics
	 *
	 * Displays performance metrics and lock contention statistics
	 */
	private function printStatistics() {
		echo "\n";
		echo "============================================\n";
		echo "Worker Statistics\n";
		echo "============================================\n";
		echo "Runtime:\n";
		echo "  Cycles:             {$this->stats['cycles']}\n";
		echo "\nJob Execution:\n";
		echo "  Jobs Executed:      {$this->stats['jobs_executed']}\n";
		echo "  Jobs Completed:     {$this->stats['jobs_completed']}\n";
		echo "  Jobs Failed:        {$this->stats['jobs_failed']}\n";
		echo "  Jobs Skipped:       {$this->stats['jobs_skipped']}\n";

		// Calculate success rate
		if ($this->stats['jobs_executed'] > 0) {
			$successRate = ($this->stats['jobs_completed'] / $this->stats['jobs_executed']) * 100;
			echo "  Success Rate:       " . number_format($successRate, 1) . "%\n";
		}

		echo "\nLocking:\n";
		echo "  Locks Acquired:     {$this->stats['locks_acquired']}\n";
		echo "  Locks Missed:       {$this->stats['locks_missed']}\n";
		echo "  Leases Expired:     {$this->stats['leases_expired']}\n";

		// Calculate contention rate
		$totalLockAttempts = $this->stats['locks_acquired'] + $this->stats['locks_missed'];
		if ($totalLockAttempts > 0) {
			$contentionRate = ($this->stats['locks_missed'] / $totalLockAttempts) * 100;
			echo "  Contention Rate:    " . number_format($contentionRate, 1) . "%\n";
		}

		echo "============================================\n\n";
	}

	/**
	 * Phase 3: Handle failure retry logic
	 *
	 * Determines if job should be retried or moved to dead letter queue
	 */
	private function handleFailureRetry($execution, $error) {
		// Get job details with retry configuration
		$sql = "SELECT * FROM CronJob WHERE id = ?";
		$job = DBI::prepared_query($sql, 'i', [$execution['cron_job']]);

		if (empty($job)) {
			$this->logError("Job #{$execution['cron_job']} not found for retry handling");
			return;
		}

		$job = $job[0];
		$retryCount = isset($execution['retry_count']) ? $execution['retry_count'] : 0;
		$maxRetries = $job['max_retries'];

		// Increment consecutive failures
		$this->incrementConsecutiveFailures($job['id']);

		// Check if we should retry
		if ($retryCount < $maxRetries) {
			// Schedule retry
			$retryDelay = $this->calculateRetryDelay($job['retry_strategy'], $retryCount, $job['retry_delay_seconds']);
			$nextRun = time() + $retryDelay;

			echo "[" . date('Y-m-d H:i:s') . "] Scheduling retry #{" . ($retryCount + 1) . "} in {$retryDelay}s\n";

			// Create retry execution
			$this->createRetryExecution($job, $nextRun, $retryCount + 1);

			// Check if we should notify after this failure
			$consecutiveFailures = $this->getConsecutiveFailures($job['id']);
			if ($job['notify_on_failure'] && $consecutiveFailures >= $job['notify_after_failures']) {
				$this->sendFailureNotification($job, $execution, $error, $consecutiveFailures);
			}

		} else {
			// Max retries exceeded - move to dead letter queue
			echo "[" . date('Y-m-d H:i:s') . "] Job #{$job['id']} exceeded max retries - moving to dead letter queue\n";

			$this->moveToDeadLetterQueue($job, $error);

			// Always notify on DLQ
			if ($job['notify_on_dead_letter']) {
				$this->sendDeadLetterNotification($job, $execution, $error);
			}
		}
	}

	/**
	 * Phase 3: Calculate retry delay based on strategy
	 */
	private function calculateRetryDelay($strategy, $retryCount, $fixedDelay) {
		switch ($strategy) {
			case 'immediate':
				return 0; // No delay

			case 'fixed':
				return $fixedDelay ?? 60; // Default 60 seconds

			case 'exponential':
			default:
				// Exponential backoff: 1min, 2min, 4min, 8min, 16min
				return pow(2, $retryCount) * 60; // 60 * 2^retryCount
		}
	}

	/**
	 * Phase 3: Create retry execution record
	 */
	private function createRetryExecution($job, $scheduledTime, $retryCount) {
		$sql = "
			INSERT INTO CronJobExecution (
				cron_job, scheduled_time, next_run_time, status, is_retry, retry_count,
				application, kyte_account, date_created
			) VALUES (?, ?, ?, 'pending', 1, ?, ?, ?, UNIX_TIMESTAMP())
		";

		DBI::prepared_query($sql, 'iiiiii', [
			$job['id'],
			$scheduledTime,
			$scheduledTime,
			$retryCount,
			$job['application'] ?? null,
			$job['kyte_account']
		]);
	}

	/**
	 * Phase 3: Move job to dead letter queue
	 */
	private function moveToDeadLetterQueue($job, $reason) {
		$sql = "
			UPDATE CronJob
			SET in_dead_letter_queue = 1,
				dead_letter_reason = ?,
				dead_letter_since = UNIX_TIMESTAMP(),
				enabled = 0,
				date_modified = UNIX_TIMESTAMP()
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'si', [$reason, $job['id']]);

		echo "[" . date('Y-m-d H:i:s') . "] Job #{$job['id']} ({$job['name']}) moved to dead letter queue\n";
		echo "  Reason: {$reason}\n";
		echo "  Consecutive failures: {$job['consecutive_failures']}\n";
	}

	/**
	 * Phase 3: Increment consecutive failures counter
	 */
	private function incrementConsecutiveFailures($jobId) {
		$sql = "
			UPDATE CronJob
			SET consecutive_failures = consecutive_failures + 1
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'i', [$jobId]);
	}

	/**
	 * Phase 3: Reset consecutive failures counter
	 */
	private function resetConsecutiveFailures($jobId) {
		$sql = "
			UPDATE CronJob
			SET consecutive_failures = 0
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'i', [$jobId]);
	}

	/**
	 * Phase 3: Get consecutive failures count
	 */
	private function getConsecutiveFailures($jobId) {
		$sql = "SELECT consecutive_failures FROM CronJob WHERE id = ?";
		$result = DBI::prepared_query($sql, 'i', [$jobId]);

		return !empty($result) ? $result[0]['consecutive_failures'] : 0;
	}

	/**
	 * Phase 3: Send failure notification
	 */
	private function sendFailureNotification($job, $execution, $error, $consecutiveFailures) {
		$message = "🔴 Cron Job Failure Alert\n\n";
		$message .= "Job: {$job['name']} (#{$job['id']})\n";
		$message .= "Execution: #{$execution['id']}\n";
		$message .= "Consecutive Failures: {$consecutiveFailures}\n";
		$message .= "Error: {$error}\n";
		$message .= "Time: " . date('Y-m-d H:i:s') . "\n";

		// Send to Slack if webhook configured
		$webhook = $job['slack_webhook'] ?? null;
		if ($webhook) {
			$this->sendSlackNotification($webhook, $message, $job, $execution);
		}

		// Send email if configured
		if ($job['notification_email']) {
			$this->sendEmailNotification($job['notification_email'], "Cron Job Failure: {$job['name']}", $message);
		}
	}

	/**
	 * Phase 3: Send dead letter queue notification
	 */
	private function sendDeadLetterNotification($job, $execution, $error) {
		$message = "💀 Cron Job Moved to Dead Letter Queue\n\n";
		$message .= "Job: {$job['name']} (#{$job['id']})\n";
		$message .= "Execution: #{$execution['id']}\n";
		$message .= "Max Retries: {$job['max_retries']}\n";
		$message .= "Final Error: {$error}\n";
		$message .= "Time: " . date('Y-m-d H:i:s') . "\n";
		$message .= "\nAction Required: Job has been disabled. Review and re-enable manually.\n";

		// Send to Slack if webhook configured
		$webhook = $job['slack_webhook'] ?? null;
		if ($webhook) {
			$this->sendSlackNotification($webhook, $message, $job, $execution, 'danger');
		}

		// Send email if configured
		if ($job['notification_email']) {
			$this->sendEmailNotification($job['notification_email'], "URGENT: Cron Job in Dead Letter Queue: {$job['name']}", $message);
		}
	}

	/**
	 * Phase 3: Send Slack notification
	 */
	private function sendSlackNotification($webhook, $message, $job, $execution, $color = 'warning') {
		try {
			$payload = [
				'attachments' => [
					[
						'color' => $color,
						'title' => $job['name'],
						'text' => $message,
						'fields' => [
							[
								'title' => 'Job ID',
								'value' => $job['id'],
								'short' => true
							],
							[
								'title' => 'Execution ID',
								'value' => $execution['id'],
								'short' => true
							],
							[
								'title' => 'Time',
								'value' => date('Y-m-d H:i:s'),
								'short' => true
							]
						],
						'footer' => 'Kyte Cron System',
						'ts' => time()
					]
				]
			];

			$ch = curl_init($webhook);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);

			$result = curl_exec($ch);
			$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

			curl_close($ch);

			if ($httpCode != 200) {
				$this->logError("Slack notification failed: HTTP {$httpCode}");
			} else {
				echo "[" . date('Y-m-d H:i:s') . "] Slack notification sent\n";
			}

		} catch (\Exception $e) {
			$this->logError("Slack notification error: " . $e->getMessage());
		}
	}

	/**
	 * Phase 3: Send email notification
	 */
	private function sendEmailNotification($to, $subject, $message) {
		try {
			// Use PHP mail() function - production should use proper mail service
			$headers = "From: cron@" . gethostname() . "\r\n";
			$headers .= "X-Mailer: Kyte Cron System\r\n";

			if (mail($to, $subject, $message, $headers)) {
				echo "[" . date('Y-m-d H:i:s') . "] Email notification sent to {$to}\n";
			} else {
				$this->logError("Email notification failed to {$to}");
			}

		} catch (\Exception $e) {
			$this->logError("Email notification error: " . $e->getMessage());
		}
	}

	/**
	 * Phase 4: Check if job dependency is met
	 *
	 * For linear chains (A→B→C), checks if parent job has completed successfully.
	 * Returns true if:
	 * - Job has no dependency
	 * - Parent job's most recent execution completed successfully
	 *
	 * @param array $job Job record with depends_on_job field
	 * @return bool True if dependency is met or no dependency exists
	 */
	private function checkDependencyMet($job) {
		if (empty($job['depends_on_job'])) {
			return true; // No dependency
		}

		$parentJobId = $job['depends_on_job'];

		// Check if parent job exists and is enabled
		$sql = "SELECT id, name, enabled FROM CronJob WHERE id = ?";
		$parentJob = DBI::prepared_query($sql, 'i', [$parentJobId]);

		if (empty($parentJob)) {
			$this->logError("Dependency error: Parent job #{$parentJobId} not found for job #{$job['id']}");
			return false;
		}

		$parentJob = $parentJob[0];

		if (!$parentJob['enabled']) {
			echo "[" . date('Y-m-d H:i:s') . "] Dependency not met: Parent job #{$parentJobId} is disabled\n";
			return false;
		}

		// Get most recent execution of parent job
		$sql = "
			SELECT id, status, completed_at
			FROM CronJobExecution
			WHERE cron_job = ?
			ORDER BY date_created DESC
			LIMIT 1
		";

		$parentExecution = DBI::prepared_query($sql, 'i', [$parentJobId]);

		if (empty($parentExecution)) {
			echo "[" . date('Y-m-d H:i:s') . "] Dependency not met: Parent job #{$parentJobId} has no executions yet\n";
			return false;
		}

		$parentExecution = $parentExecution[0];

		// Check if parent completed successfully
		if ($parentExecution['status'] !== 'completed') {
			echo "[" . date('Y-m-d H:i:s') . "] Dependency not met: Parent job #{$parentJobId} status is '{$parentExecution['status']}' (expected 'completed')\n";
			return false;
		}

		echo "[" . date('Y-m-d H:i:s') . "] Dependency met: Parent job #{$parentJobId} ({$parentJob['name']}) completed successfully\n";
		return true;
	}
}
