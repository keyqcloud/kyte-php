#!/usr/bin/env php
<?php
/**
 * Register Shipyard Update Cron Job
 *
 * Registers ShipyardUpdateWorker in the CronJob table so the cron worker daemon
 * drains pending KyteShipyardUpdate requests (KYTE-#201). The dashboard enqueues
 * a request; this job performs the download/extract/upload/CloudFront-invalidate
 * out-of-band (off the Cloudflare/ALB request path).
 *
 * Prereqs:
 *   - Run migrations/4.12.0_shipyard_update.sql (creates KyteShipyardUpdate).
 *   - Define KYTE_SHIPYARD_S3 and KYTE_SHIPYARD_CF in config.php.
 *
 * Usage:
 *   php bin/register-shipyard-update-job.php
 *
 * Registered with interval=60s, allow_concurrent=0 (lease lock → single runner),
 * timeout=600s (heartbeat extends it during large uploads). Editable afterward in
 * Kyte Shipyard › System › Cron Jobs.
 */

require_once __DIR__ . '/bootstrap.php';

use Kyte\Core\DBI;

echo "============================================\n";
echo "Shipyard Update Job Registration\n";
echo "============================================\n\n";

try {
	$jobFile = dirname(__DIR__) . '/src/Cron/ShipyardUpdateWorker.php';

	if (!file_exists($jobFile)) {
		throw new \Exception("Job file not found: {$jobFile}");
	}

	$code = file_get_contents($jobFile);
	echo "✓ Job code loaded (" . strlen($code) . " bytes)\n";

	$compressed = bzcompress($code);
	echo "✓ Code compressed (" . strlen($compressed) . " bytes)\n";

	$jobName        = 'ShipyardUpdateWorker';
	$jobDescription = 'Drains pending KyteShipyardUpdate requests: downloads the latest Shipyard build, uploads it to the Shipyard S3 bucket, and invalidates the Shipyard CloudFront distribution. Replaces the kyte-lambda-update-shipyard Lambda.';

	// System-level job: application IS NULL, kyte_account = 0.
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
		// interval=60s, enabled, timeout=600s, allow_concurrent=0, low retries
		// (a failed update is recorded on the request row, not retried as a job).
		$sql = "
			INSERT INTO CronJob (
				name, description, code, schedule_type, interval_seconds,
				enabled, timeout_seconds, allow_concurrent, max_retries,
				retry_strategy, retry_delay_seconds, notify_on_failure,
				notify_after_failures, notify_on_dead_letter,
				kyte_account, application, date_created
			) VALUES (
				?, ?, ?, 'interval', 60,
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

	echo "The job runs every 60 seconds. Ensure the cron worker daemon is running:\n\n";
	echo "  php bin/cron-worker.php\n\n";
	echo "Set KYTE_SHIPYARD_S3 and KYTE_SHIPYARD_CF in config.php if you haven't yet.\n";
	echo "Manage the job in Kyte Shipyard: System > Cron Jobs\n\n";

} catch (\Exception $e) {
	echo "\n❌ ERROR: " . $e->getMessage() . "\n";
	echo $e->getTraceAsString() . "\n";
	exit(1);
}
