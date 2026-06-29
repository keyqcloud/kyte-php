#!/usr/bin/env php
<?php
/**
 * Register Site Provisioning Cron Job
 *
 * Registers SiteProvisioningWorker in the CronJob table so the cron worker daemon
 * provisions/deprovisions KyteSite AWS resources (KYTE-#201 #3). Replaces the
 * kyte-lambda-site-management + kyte-lambda-database-transaction Lambdas.
 *
 * Prereqs:
 *   - Run migrations/4.13.0_site_provisioning.sql (KyteSite provisioning columns).
 *   - kyte-cron-worker daemon running (created during the v4.12.0 rollout).
 *
 * Usage:
 *   php bin/register-site-provisioning-job.php
 *
 * Registered with interval=30s, allow_concurrent=0 (lease lock → single runner),
 * timeout=600s (heartbeat extends it across the multi-minute CloudFront polls).
 */

require_once __DIR__ . '/bootstrap.php';

use Kyte\Core\DBI;

echo "============================================\n";
echo "Site Provisioning Job Registration\n";
echo "============================================\n\n";

try {
	$jobFile = dirname(__DIR__) . '/src/Cron/SiteProvisioningWorker.php';

	if (!file_exists($jobFile)) {
		throw new \Exception("Job file not found: {$jobFile}");
	}

	$code = file_get_contents($jobFile);
	echo "✓ Job code loaded (" . strlen($code) . " bytes)\n";

	$compressed = bzcompress($code);
	echo "✓ Code compressed (" . strlen($compressed) . " bytes)\n";

	$jobName        = 'SiteProvisioningWorker';
	$jobDescription = 'Provisions/deprovisions KyteSite AWS resources (2 S3 buckets + 2 CloudFront distributions per site, + ACM certs on delete) by advancing creating/deleting sites each tick. Replaces kyte-lambda-site-management and kyte-lambda-database-transaction.';

	$sql = "SELECT id FROM CronJob WHERE name = ? AND application IS NULL AND (kyte_account = 0 OR kyte_account = 1) AND deleted = 0";
	$existing = DBI::prepared_query($sql, 's', [$jobName]);

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

		$sql = "
			UPDATE CronJob
			SET code = ?,
				description = ?,
				kyte_account = 0,
				date_modified = UNIX_TIMESTAMP()
			WHERE id = ?
		";
		DBI::prepared_query($sql, 'ssi', [$compressed, $jobDescription, $existing[0]['id']]);
		echo "✓ Job updated (ID: {$existing[0]['id']})\n";

	} else {
		// interval=30s, enabled, timeout=600s, allow_concurrent=0, low retries
		// (per-site failures are recorded on the KyteSite row, not retried as a job).
		$sql = "
			INSERT INTO CronJob (
				name, description, code, schedule_type, interval_seconds,
				enabled, timeout_seconds, allow_concurrent, max_retries,
				retry_strategy, retry_delay_seconds, notify_on_failure,
				notify_after_failures, notify_on_dead_letter,
				kyte_account, application, date_created
			) VALUES (
				?, ?, ?, 'interval', 30,
				1, 600, 0, 1,
				'exponential', 60, 0,
				3, 0,
				0, NULL, UNIX_TIMESTAMP()
			)
		";
		DBI::prepared_query($sql, 'sss', [$jobName, $jobDescription, $compressed]);

		$jobId = DBI::insert_id();
		echo "✓ Job created (ID: {$jobId})\n";
	}

	echo "\n============================================\n";
	echo "Registration Complete!\n";
	echo "============================================\n\n";

	echo "The job runs every 30 seconds. Ensure the cron worker daemon is running:\n\n";
	echo "  systemctl status kyte-cron-worker\n\n";
	echo "Run migrations/4.13.0_site_provisioning.sql first if you haven't.\n";
	echo "Manage the job in Kyte Shipyard: System > Cron Jobs\n\n";

} catch (\Exception $e) {
	echo "\n❌ ERROR: " . $e->getMessage() . "\n";
	echo $e->getTraceAsString() . "\n";
	exit(1);
}
