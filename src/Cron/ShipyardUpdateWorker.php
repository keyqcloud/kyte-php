<?php

namespace Kyte\Cron;

use Kyte\Core\CronJobBase;
use Kyte\Core\DBI;

/**
 * ShipyardUpdateWorker
 *
 * Performs the install's Kyte Shipyard dashboard self-update out-of-band, draining
 * pending rows from the KyteShipyardUpdate table. Replaces the SNS ->
 * kyte-lambda-update-shipyard Lambda (KYTE-#201).
 *
 * Why a cron job and not a synchronous controller action: the work (download +
 * extract + upload N files + CloudFront invalidation) routinely exceeds the
 * ~100s Cloudflare non-enterprise request ceiling (and ALB idle timeouts), so it
 * must run off the request path.
 *
 * Idempotency — two layers:
 *   1. Request dedup is enforced by the controller (it won't enqueue a second row
 *      while one is pending/running).
 *   2. Execution dedup here: the job is registered with allow_concurrent=0 (the
 *      CronWorker lease lock keeps a single instance running), and each row is
 *      claimed with a guarded `pending -> running` UPDATE — only the worker whose
 *      affected_rows() == 1 owns the row, so even a double-tick can't double-run.
 *
 * Bugs the old Lambda had, fixed inherently here: it crashed uploading .map/dir
 * entries (boto3 ContentType=None) and invalidated a stale/nonexistent
 * distribution from an env var. We set an explicit per-extension Content-Type
 * (omitting unknown types rather than crashing) and invalidate the distribution
 * from config (KYTE_SHIPYARD_CF).
 */
class ShipyardUpdateWorker extends CronJobBase
{
	private const CHANGELOG_URL = 'https://cdn.keyqcloud.com/kyte/shipyard/archive/CHANGELOG.md';
	private const ZIP_URL       = 'https://cdn.keyqcloud.com/kyte/shipyard/stable/kyte-shipyard.zip';

	/**
	 * Content-Type by file extension. The Shipyard is a static SPA: JS must be
	 * served as application/javascript and CSS as text/css. Unknown extensions
	 * (e.g. .map) return null and S3::write() lets S3 use its default — never a
	 * hard failure (the Lambda's ContentType=None crash).
	 */
	private const CONTENT_TYPES = [
		'html'  => 'text/html; charset=utf-8',
		'htm'   => 'text/html; charset=utf-8',
		'js'    => 'application/javascript',
		'mjs'   => 'application/javascript',
		'css'   => 'text/css',
		'json'  => 'application/json',
		'map'   => 'application/json',
		'svg'   => 'image/svg+xml',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'ico'   => 'image/x-icon',
		'webp'  => 'image/webp',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'eot'   => 'application/vnd.ms-fontobject',
		'txt'   => 'text/plain; charset=utf-8',
		'xml'   => 'application/xml',
		'wasm'  => 'application/wasm',
	];

	public function execute()
	{
		// Claim the oldest pending request, if any. One per tick keeps each run
		// bounded; there is at most one Shipyard per install so this is plenty.
		$row = DBI::prepared_query(
			"SELECT * FROM KyteShipyardUpdate WHERE status = 'pending' AND deleted = 0 ORDER BY date_created ASC, id ASC LIMIT 1",
			'',
			[]
		);
		if (empty($row)) {
			return json_encode(['claimed' => false, 'message' => 'No pending Shipyard updates.']);
		}
		$row = $row[0];
		$id  = (int) $row['id'];

		// Guarded pending -> running. Only the worker that flips it owns the row.
		DBI::prepared_query(
			"UPDATE KyteShipyardUpdate SET status = 'running', started_at = UNIX_TIMESTAMP(), date_modified = UNIX_TIMESTAMP() WHERE id = ? AND status = 'pending'",
			'i',
			[$id]
		);
		if (DBI::affected_rows() !== 1) {
			$this->log("Row #{$id} was claimed by another worker; skipping.");
			return json_encode(['claimed' => false, 'message' => 'Already claimed.']);
		}
		$this->log("Claimed Shipyard update request #{$id}.");

		try {
			$result = $this->process($row);
			$this->finish($id, 'complete', $result['message'], $result);
			return json_encode(['claimed' => true, 'id' => $id] + $result);
		} catch (\Throwable $e) {
			$this->log("Shipyard update #{$id} failed: " . $e->getMessage());
			$this->finish($id, 'failed', $e->getMessage(), []);
			// Do not rethrow: the failure is recorded on the row, and rethrowing
			// would push the cron JOB itself toward retries/DLQ for what is really
			// per-request data. The user can re-trigger from the dashboard.
			return json_encode(['claimed' => true, 'id' => $id, 'status' => 'failed', 'message' => $e->getMessage()]);
		}
	}

	/**
	 * Do the actual update for a claimed row. Returns a result array (message +
	 * counters) on success; throws on hard failure.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function process($row): array
	{
		if (!defined('KYTE_SHIPYARD_S3') || !defined('KYTE_SHIPYARD_CF') || !KYTE_SHIPYARD_S3 || !KYTE_SHIPYARD_CF) {
			throw new \Exception('Shipyard update is not configured: define KYTE_SHIPYARD_S3 and KYTE_SHIPYARD_CF in config.');
		}
		if (!class_exists('\ZipArchive')) {
			throw new \Exception('Shipyard update requires the PHP zip extension (ext-zip / ZipArchive).');
		}

		$currentVersion = $row['current_version'];

		$latestVersion = $this->getLatestVersion();
		if ($latestVersion === null) {
			throw new \Exception('Unable to determine the latest Shipyard version from CHANGELOG.');
		}

		// Re-check at run time: the request may have raced another update, or the
		// dashboard was already current. Record the deployed version and stop.
		if ($latestVersion === $currentVersion) {
			return [
				'updated'                => false,
				'deployed_version'       => $latestVersion,
				'files_uploaded'         => 0,
				'files_failed'           => 0,
				'cloudfront_invalidated' => false,
				'message'                => "Shipyard already up to date ({$latestVersion}); nothing to deploy.",
			];
		}

		$tmpDir = $this->downloadAndExtract();

		try {
			$region     = defined('KYTE_SHIPYARD_REGION') ? KYTE_SHIPYARD_REGION : 'us-east-1';
			$credential = new \Kyte\Aws\Credentials($region);
			$s3         = new \Kyte\Aws\S3($credential, KYTE_SHIPYARD_S3);

			$uploaded = 0;
			$failed   = 0;
			$i        = 0;
			foreach ($this->iterateFiles($tmpDir) as $key => $absolutePath) {
				try {
					$ext         = strtolower(pathinfo($key, PATHINFO_EXTENSION));
					$contentType = self::CONTENT_TYPES[$ext] ?? null;
					$s3->write($key, file_get_contents($absolutePath), $contentType);
					$uploaded++;
				} catch (\Throwable $e) {
					$failed++;
					$this->log("Failed to upload {$key}: " . $e->getMessage());
				}
				// Extend the lease periodically so a large build isn't flagged as
				// a timed-out execution mid-upload.
				if ((++$i % 25) === 0) {
					$this->heartbeat();
				}
			}

			$invalidated = false;
			try {
				$cf = new \Kyte\Aws\CloudFront($credential);
				$cf->createInvalidation(KYTE_SHIPYARD_CF, ['/*']);
				$invalidated = true;
			} catch (\Throwable $e) {
				$this->log("CloudFront invalidation failed (best-effort): " . $e->getMessage());
			}

			return [
				'updated'                => true,
				'deployed_version'       => $latestVersion,
				'files_uploaded'         => $uploaded,
				'files_failed'           => $failed,
				'cloudfront_invalidated' => $invalidated,
				'message'                => "Shipyard updated to {$latestVersion} ({$uploaded} file(s) uploaded"
					. ($failed ? ", {$failed} failed" : '') . ')'
					. ($invalidated ? '.' : '; CloudFront invalidation failed (will catch up at TTL).'),
			];
		} finally {
			$this->deleteDir($tmpDir);
		}
	}

	/**
	 * Write the terminal state back to the row.
	 *
	 * @param array<string,mixed> $result
	 */
	private function finish(int $id, string $status, string $message, array $result): void
	{
		$sql = "
			UPDATE KyteShipyardUpdate
			SET status = ?,
				message = ?,
				deployed_version = COALESCE(?, deployed_version),
				files_uploaded = ?,
				files_failed = ?,
				cloudfront_invalidated = ?,
				finished_at = UNIX_TIMESTAMP(),
				date_modified = UNIX_TIMESTAMP()
			WHERE id = ?
		";
		DBI::prepared_query($sql, 'sssiiii', [
			$status,
			$message,
			$result['deployed_version'] ?? null,
			(int) ($result['files_uploaded'] ?? 0),
			(int) ($result['files_failed'] ?? 0),
			!empty($result['cloudfront_invalidated']) ? 1 : 0,
			$id,
		]);
		$this->log("Shipyard update #{$id} -> {$status}: {$message}");
	}

	/**
	 * Fetch the CDN changelog and return the first `## X.Y.Z` version, or null.
	 */
	private function getLatestVersion(): ?string
	{
		$content = $this->httpGet(self::CHANGELOG_URL);
		if ($content === null) {
			return null;
		}
		if (preg_match('/##\s*(\d+\.\d+\.\d+)/', $content, $m)) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Download the stable zip and extract it to a fresh temp directory (caller
	 * deletes it). Returns the temp directory path.
	 */
	private function downloadAndExtract(): string
	{
		$zipData = $this->httpGet(self::ZIP_URL);
		if ($zipData === null || $zipData === '') {
			throw new \Exception('Failed to download the Shipyard build archive.');
		}

		$tmpDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'kyte-shipyard-' . uniqid('', true);
		if (!mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
			throw new \Exception("Failed to create temp directory for Shipyard update: {$tmpDir}");
		}

		$zipPath = $tmpDir . DIRECTORY_SEPARATOR . 'kyte-shipyard.zip';
		if (file_put_contents($zipPath, $zipData) === false) {
			$this->deleteDir($tmpDir);
			throw new \Exception('Failed to write the Shipyard build archive to disk.');
		}

		$zip = new \ZipArchive();
		if ($zip->open($zipPath) !== true) {
			$this->deleteDir($tmpDir);
			throw new \Exception('Failed to open the Shipyard build archive.');
		}
		if (!$zip->extractTo($tmpDir)) {
			$zip->close();
			$this->deleteDir($tmpDir);
			throw new \Exception('Failed to extract the Shipyard build archive.');
		}
		$zip->close();

		// The archive itself is not an asset to publish.
		@unlink($zipPath);

		return $tmpDir;
	}

	/**
	 * Yield [s3Key => absolutePath] for every file under $dir (recursively),
	 * skipping directories. The S3 key is forward-slashed relative to $dir.
	 *
	 * @return \Generator<string, string>
	 */
	private function iterateFiles(string $dir): \Generator
	{
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		$prefixLen = strlen(rtrim($dir, '/\\')) + 1;
		foreach ($iterator as $file) {
			if ($file->isDir()) {
				continue;
			}
			$absolute = $file->getPathname();
			$key      = str_replace('\\', '/', substr($absolute, $prefixLen));
			yield $key => $absolute;
		}
	}

	/**
	 * Best-effort HTTP GET via curl. Returns the body on HTTP 2xx, else null.
	 */
	private function httpGet(string $url): ?string
	{
		$ch = curl_init($url);
		if ($ch === false) {
			return null;
		}
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 15,
		]);
		$body   = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error  = curl_error($ch);
		curl_close($ch);

		if ($body === false || $status < 200 || $status >= 300) {
			$this->log("GET {$url} failed (status {$status}): {$error}");
			return null;
		}
		return $body;
	}

	/**
	 * Recursively delete a directory tree. Best-effort cleanup of the temp dir.
	 */
	private function deleteDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $file) {
			$file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
		}
		@rmdir($dir);
	}
}
