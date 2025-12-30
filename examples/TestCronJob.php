<?php
/**
 * Test Cron Job
 *
 * Simple test job that logs a message and succeeds.
 * Used by bin/test-cron.php for basic functionality testing.
 */

class TestCronJob extends \Kyte\Core\CronJobBase
{
	public function execute()
	{
		$this->log("Test job started");
		$this->log("Current time: " . date('Y-m-d H:i:s'));

		// Get execution info
		$execution = $this->getExecution();
		$this->log("Execution ID: " . $execution['id']);
		$this->log("Scheduled time: " . date('Y-m-d H:i:s', $execution['scheduled_time']));

		// Simulate some work
		sleep(2);

		// Send a heartbeat to extend our lease
		$this->heartbeat();

		// More work
		sleep(2);

		$this->log("Test job completed successfully");

		return "Test job finished at " . date('Y-m-d H:i:s');
	}
}
