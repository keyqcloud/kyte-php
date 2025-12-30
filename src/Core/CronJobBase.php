<?php

namespace Kyte\Core;

/**
 * Base class for all cron jobs
 *
 * Provides context and helper methods for cron job execution.
 * All user-defined cron jobs must extend this class and implement the execute() method.
 *
 * Example:
 * ```php
 * class DailyCleanupJob extends \Kyte\Core\CronJobBase
 * {
 *     public function execute() {
 *         // Your job code here
 *         $this->log("Starting cleanup...");
 *         // ... do work ...
 *         return "Cleanup completed";
 *     }
 * }
 * ```
 */
#[\AllowDynamicProperties]
abstract class CronJobBase
{
	/**
	 * @var Api Reference to API context
	 */
	protected $api;

	/**
	 * @var array Job metadata
	 *
	 * [
	 *     'name'    => 'JobName',
	 *     'appId'   => 'app_identifier'  // null for account-level
	 * ]
	 */
	public $kyte_cron_job;

	/**
	 * @var object Current execution record (from CronJobExecution table)
	 */
	protected $execution;

	public function __construct() {
		$this->api = Api::getInstance();
	}

	/**
	 * Main execution method - must be implemented by child class
	 *
	 * @return mixed Return value becomes the execution output (stored in output field)
	 * @throws \Exception Thrown exceptions become execution errors (stored in error field)
	 */
	abstract public function execute();

	/**
	 * Optional: Called before execute() for setup/initialization
	 *
	 * Use this to initialize resources, connect to external APIs, etc.
	 */
	public function setUp() {
		// Override in child class if needed
	}

	/**
	 * Optional: Called after execute() for cleanup
	 *
	 * This is called even if execute() throws an exception.
	 * Use this to close connections, release resources, etc.
	 */
	public function tearDown() {
		// Override in child class if needed
	}

	/**
	 * Set execution context
	 *
	 * Called by CronWorker before job execution
	 *
	 * @param array|object $execution Execution record from CronJobExecution
	 */
	public function setExecution($execution) {
		$this->execution = $execution;
	}

	/**
	 * Get execution context
	 *
	 * @return array|object Current execution record
	 */
	public function getExecution() {
		return $this->execution;
	}

	/**
	 * Log message to execution output
	 *
	 * Messages logged here will be captured and stored in the execution output field.
	 *
	 * @param string $message Message to log
	 */
	protected function log($message) {
		echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
	}

	/**
	 * Get job metadata
	 *
	 * @return array Job metadata array
	 */
	public function getJobMetadata() {
		return $this->kyte_cron_job;
	}

	/**
	 * Extend lease for long-running jobs
	 *
	 * Call this periodically in long-running jobs to prevent the worker
	 * from marking the execution as timed out. This extends the lease by
	 * an additional 5 minutes from the current time.
	 *
	 * Example:
	 * ```php
	 * public function execute() {
	 *     for ($i = 0; $i < 1000; $i++) {
	 *         // Process batch
	 *         if ($i % 100 == 0) {
	 *             $this->heartbeat(); // Extend lease every 100 iterations
	 *         }
	 *     }
	 * }
	 * ```
	 *
	 * @param int $extendSeconds How many seconds to extend (default 300 = 5 minutes)
	 * @return bool True if lease was extended successfully
	 */
	protected function heartbeat($extendSeconds = 300) {
		if (!$this->execution || empty($this->execution['id'])) {
			$this->log("WARNING: Cannot send heartbeat - no execution context");
			return false;
		}

		$executionId = is_array($this->execution) ? $this->execution['id'] : $this->execution->id;
		$newLeaseExpiry = time() + $extendSeconds;

		$sql = "
			UPDATE CronJobExecution
			SET locked_until = ?
			WHERE id = ?
			AND status = 'running'
		";

		try {
			DBI::prepared_query($sql, 'ii', [$newLeaseExpiry, $executionId]);

			if (DBI::affected_rows() > 0) {
				$this->log("Heartbeat sent - lease extended to " . date('Y-m-d H:i:s', $newLeaseExpiry));
				return true;
			} else {
				$this->log("WARNING: Heartbeat failed - execution may have been marked as timeout");
				return false;
			}
		} catch (\Exception $e) {
			$this->log("ERROR: Heartbeat failed - " . $e->getMessage());
			return false;
		}
	}
}
