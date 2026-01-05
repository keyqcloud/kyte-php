#!/usr/bin/env php
<?php
/**
 * Register AI Error Correction Cron Job
 *
 * This script registers the AI Error Correction job in the CronJob table
 * so it can be executed by the cron worker daemon.
 *
 * Usage:
 *   php bin/register-ai-error-correction-job.php
 *
 * The job will be registered to run every 5 minutes (300 seconds).
 * You can modify the schedule after creation via Kyte Shipyard.
 */

// Load shared bootstrap
require_once __DIR__ . '/bootstrap.php';

use Kyte\Core\DBI;
use Kyte\Core\ModelObject;

echo "============================================\n";
echo "AI Error Correction Job Registration\n";
echo "============================================\n\n";

try {
	// Read the job class file
	$jobFile = dirname(__DIR__) . '/src/AI/AIErrorCorrectionJob.php';

	if (!file_exists($jobFile)) {
		throw new \Exception("Job file not found: {$jobFile}");
	}

	$code = file_get_contents($jobFile);
	echo "✓ Job code loaded (" . strlen($code) . " bytes)\n";

	// Compress the code
	$compressed = bzcompress($code);
	echo "✓ Code compressed (" . strlen($compressed) . " bytes)\n";

	// Check if job already exists
	$sql = "SELECT id FROM CronJob WHERE name = ? AND application IS NULL AND deleted = 0";
	$existing = DBI::prepared_query($sql, 's', ['AI Error Correction']);

	if (!empty($existing)) {
		echo "\n⚠ Job already exists (ID: {$existing[0]['id']})\n";
		echo "Would you like to update it? (y/n): ";
		$handle = fopen("php://stdin", "r");
		$response = trim(fgets($handle));
		fclose($handle);

		if (strtolower($response) !== 'y') {
			echo "Cancelled.\n";
			exit(0);
		}

		// Update existing job
		$sql = "
			UPDATE CronJob
			SET code = ?,
				date_modified = UNIX_TIMESTAMP()
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'si', [$compressed, $existing[0]['id']]);
		echo "✓ Job updated (ID: {$existing[0]['id']})\n";

	} else {
		// Get default account (first account in system)
		$sql = "SELECT id FROM KyteAccount WHERE deleted = 0 ORDER BY id ASC LIMIT 1";
		$account = DBI::query($sql);

		if (empty($account)) {
			throw new \Exception("No accounts found in system");
		}

		$accountId = $account[0]['id'];
		echo "✓ Using account ID: {$accountId}\n";

		// Insert new job
		$sql = "
			INSERT INTO CronJob (
				name, description, code, schedule_type, interval_seconds,
				enabled, timeout_seconds, allow_concurrent, max_retries,
				retry_strategy, retry_delay_seconds, notify_on_failure,
				notify_after_failures, notify_on_dead_letter,
				kyte_account, application, date_created
			) VALUES (
				?, ?, ?, 'interval', 300,
				1, 600, 0, 3,
				'exponential', 60, 0,
				3, 0,
				?, NULL, UNIX_TIMESTAMP()
			)
		";

		$jobName = 'AI Error Correction';
		$jobDescription = 'Processes errors from KyteError table and creates AI analysis tasks. Queues new errors and processes queued analyses using AWS Bedrock.';

		DBI::prepared_query($sql, 'sssi', [
			$jobName,
			$jobDescription,
			$compressed,
			$accountId
		]);

		$jobId = DBI::$dbConn->insert_id;
		echo "✓ Job created (ID: {$jobId})\n";
	}

	echo "\n============================================\n";
	echo "Registration Complete!\n";
	echo "============================================\n\n";

	echo "The job is configured to run every 5 minutes.\n";
	echo "Make sure the cron worker daemon is running:\n\n";
	echo "  php bin/cron-worker.php\n\n";

	echo "You can view and manage the job in Kyte Shipyard:\n";
	echo "  System > Cron Jobs\n\n";

} catch (\Exception $e) {
	echo "\n❌ ERROR: " . $e->getMessage() . "\n";
	echo $e->getTraceAsString() . "\n";
	exit(1);
}
