<?php

namespace Kyte\AI;

use Kyte\Core\CronJobBase;
use Kyte\Core\DBI;
use Kyte\Core\ModelObject;
use Kyte\Core\Model;

/**
 * AI Error Correction Cron Job
 *
 * Processes errors from KyteError table and creates AI analysis tasks.
 * This job runs periodically (recommended: every 5-15 minutes) and:
 *
 * 1. Finds new errors that haven't been analyzed
 * 2. Checks AI configuration for each application
 * 3. Applies rate limiting and cooldown periods
 * 4. Deduplicates errors by signature
 * 5. Queues eligible errors for AI analysis
 * 6. Processes queued analyses in batches
 *
 * Schedule: interval (300-900 seconds) or cron expression
 */
class AIErrorCorrectionJob extends CronJobBase
{
	private $processedCount = 0;
	private $queuedCount = 0;
	private $analyzedCount = 0;
	private $skippedCount = 0;

	public function execute()
	{
		$this->log("=== AI Error Correction Job Started ===");
		$startTime = microtime(true);

		try {
			// Phase 1: Queue new errors for analysis
			$this->log("Phase 1: Queueing new errors...");
			$this->queueNewErrors();

			// Phase 2: Process queued analyses
			$this->log("Phase 2: Processing queued analyses...");
			$this->processQueuedAnalyses();

			$duration = round((microtime(true) - $startTime) * 1000);

			$this->log("=== Job Completed Successfully ===");
			$this->log("Duration: {$duration}ms");
			$this->log("Errors Processed: {$this->processedCount}");
			$this->log("Errors Queued: {$this->queuedCount}");
			$this->log("Analyses Completed: {$this->analyzedCount}");
			$this->log("Errors Skipped: {$this->skippedCount}");

			return json_encode([
				'success' => true,
				'duration_ms' => $duration,
				'processed' => $this->processedCount,
				'queued' => $this->queuedCount,
				'analyzed' => $this->analyzedCount,
				'skipped' => $this->skippedCount
			]);

		} catch (\Exception $e) {
			$this->log("ERROR: " . $e->getMessage());
			$this->log($e->getTraceAsString());
			throw $e;
		}
	}

	/**
	 * Queue new errors for analysis
	 */
	private function queueNewErrors()
	{
		// Find applications with AI Error Correction enabled
		$sql = "
			SELECT c.*, a.identifier as app_identifier
			FROM AIErrorCorrectionConfig c
			JOIN Application a ON c.application = a.id
			WHERE c.enabled = 1
			AND c.deleted = 0
			AND a.deleted = 0
		";

		$configs = DBI::query($sql);

		if (empty($configs)) {
			$this->log("No applications with AI Error Correction enabled");
			return;
		}

		$this->log("Found " . count($configs) . " enabled configurations");

		foreach ($configs as $config) {
			try {
				$this->processApplicationErrors($config);
			} catch (\Exception $e) {
				$this->log("Error processing app #{$config['application']}: " . $e->getMessage());
				// Continue with other applications
			}
		}
	}

	/**
	 * Process errors for a single application
	 */
	private function processApplicationErrors($config)
	{
		$appId = $config['application'];
		$this->log("Processing app #{$appId} ({$config['app_identifier']})");

		// Check rate limits
		if (!$this->checkRateLimits($config)) {
			$this->log("  Rate limit exceeded for app #{$appId}");
			return;
		}

		// Find unanalyzed errors for this application
		// Exclude system errors (app_id NULL or empty string)
		$sql = "
			SELECT e.*
			FROM KyteError e
			LEFT JOIN AIErrorAnalysis a ON e.id = a.error_id
			WHERE e.app_id = ?
			AND e.app_id IS NOT NULL
			AND e.app_id != ''
			AND e.deleted = 0
			AND a.id IS NULL
		";

		// Filter by error types if configured
		if (!$config['include_warnings']) {
			$sql .= " AND e.log_level NOT IN ('warning', 'notice', 'deprecated')";
		}

		$sql .= " ORDER BY e.date_created DESC LIMIT 100";

		$errors = DBI::prepared_query($sql, 's', [$config['app_identifier']]);

		if (empty($errors)) {
			$this->log("  No new errors to process");
			return;
		}

		$this->log("  Found " . count($errors) . " unanalyzed errors");

		foreach ($errors as $error) {
			$this->processedCount++;

			$dedupId = $this->shouldAnalyzeError($error, $config);
			if ($dedupId !== false) {
				$this->queueErrorForAnalysis($error, $config);
				$this->queuedCount++;

				// Update last_analyzed timestamp AFTER successful queueing
				$sql = "
					UPDATE AIErrorDeduplication
					SET last_analyzed = UNIX_TIMESTAMP()
					WHERE id = ?
				";
				DBI::prepared_query($sql, 'i', [$dedupId]);
			} else {
				$this->skippedCount++;
			}

			// Send heartbeat every 10 errors to keep job alive
			if ($this->processedCount % 10 == 0) {
				$this->heartbeat();
			}
		}
	}

	/**
	 * Check if error should be analyzed based on deduplication and cooldown
	 *
	 * @return int|false Deduplication record ID if should analyze, false otherwise
	 */
	private function shouldAnalyzeError($error, $config)
	{
		// Generate error signature for deduplication
		$signature = $this->generateErrorSignature($error);

		// Check if this error signature exists
		$sql = "
			SELECT * FROM AIErrorDeduplication
			WHERE application = ?
			AND error_signature = ?
			AND deleted = 0
		";

		$dedup = DBI::prepared_query($sql, 'is', [$config['application'], $signature]);

		if (empty($dedup)) {
			// New error signature - create deduplication record
			// Extract controller name using same logic as identifyControllerAndFunction
			$controllerName = '';
			$filePath = $error['file'] ?? '';

			// Pattern 1: eval()'d code pattern (typical for Kyte dynamic controllers)
			if (preg_match('/Api\.php\(\d+\)\s*:\s*eval/', $filePath)) {
				// Use the model name from error to find controller
				$modelName = $error['model'] ?? '';
				if ($modelName) {
					$controllerName = $modelName . 'Controller';
				}
			}
			// Pattern 2: Direct controller file path
			elseif (preg_match('/([A-Z][a-zA-Z0-9]+Controller)\.php/', $filePath, $matches)) {
				$controllerName = $matches[1];
			}
			// Pattern 3: Generic PHP file
			elseif (preg_match('/\/([^\/]+)\.php$/', $filePath, $matches)) {
				$controllerName = $matches[1];
			}

			if (!$controllerName) {
				$controllerName = 'Unknown';
			} else {
				// Verify controller name exists in database, try without 'Controller' suffix if needed
				$sql = "SELECT id FROM Controller WHERE name = ? AND application = ? AND deleted = 0 LIMIT 1";
				$checkController = DBI::prepared_query($sql, 'si', [$controllerName, $config['application']]);

				if (empty($checkController) && substr($controllerName, -10) === 'Controller') {
					$controllerNameWithoutSuffix = substr($controllerName, 0, -10);
					$checkController = DBI::prepared_query($sql, 'si', [$controllerNameWithoutSuffix, $config['application']]);
					if (!empty($checkController)) {
						$controllerName = $controllerNameWithoutSuffix;
					}
				}
			}

			$sql = "
				INSERT INTO AIErrorDeduplication (
					application, kyte_account, error_signature, controller_name, error_message, error_file,
					error_line, first_seen, last_seen, occurrence_count
				) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
			";

			DBI::prepared_query($sql, 'iisssssii', [
				$config['application'],
				$config['kyte_account'],
				$signature,
				$controllerName,
				$error['message'],
				$error['file'] ?? '',
				$error['line'] ?? 0,
				$error['date_created'],
				$error['date_created']
			]);

			return DBI::insert_id(); // Return new dedup record ID
		}

		$dedup = $dedup[0];

		// Update occurrence count and last seen
		$sql = "
			UPDATE AIErrorDeduplication
			SET occurrence_count = occurrence_count + 1,
				last_seen = ?
			WHERE id = ?
		";

		DBI::prepared_query($sql, 'ii', [$error['date_created'], $dedup['id']]);

		// Check if resolved
		if ($dedup['is_resolved']) {
			$this->log("  Error signature already resolved (#{$dedup['id']})");
			return false;
		}

		// Check cooldown period
		if ($dedup['last_analyzed']) {
			$timeSinceAnalysis = time() - $dedup['last_analyzed'];
			$cooldownSeconds = $config['cooldown_minutes'] * 60;

			if ($timeSinceAnalysis < $cooldownSeconds) {
				$this->log("  Error in cooldown period (#{$dedup['id']})");
				return false;
			}
		}

		return $dedup['id']; // Return dedup ID to update after successful queueing
	}

	/**
	 * Generate unique signature for error deduplication
	 */
	private function generateErrorSignature($error)
	{
		// Combine error type, message (normalized), file, and line
		$message = $error['message'];

		// Normalize message (remove variable values, IDs, etc.)
		$message = preg_replace('/\d+/', 'N', $message); // Replace numbers
		$message = preg_replace('/[\'"][^\'\"]*[\'"]/', 'STR', $message); // Replace strings
		$message = preg_replace('/0x[a-f0-9]+/i', 'HEX', $message); // Replace hex values

		$sigParts = [
			$error['log_level'] ?? 'error',
			$message,
			$error['file'] ?? '',
			$error['line'] ?? 0
		];

		return hash('sha256', implode('|', $sigParts));
	}

	/**
	 * Queue error for AI analysis
	 */
	private function queueErrorForAnalysis($error, $config)
	{
		// Generate error signature
		$signature = $this->generateErrorSignature($error);

		// Try to identify controller and function from error
		$controllerInfo = $this->identifyControllerAndFunction($error, $config['application']);

		// DEBUG: Log what we're about to insert
		error_log("DEBUG queueErrorForAnalysis - controllerInfo: " . print_r($controllerInfo, true));
		error_log("DEBUG - controller_id: " . var_export($controllerInfo['controller_id'], true));
		error_log("DEBUG - controller_name: " . var_export($controllerInfo['controller_name'], true));
		error_log("DEBUG - function_id: " . var_export($controllerInfo['function_id'], true));

		$sql = "
			INSERT INTO AIErrorAnalysis (
				error_id, error_signature, application, kyte_account,
				controller_id, controller_name, function_id, function_name, function_type,
				analysis_status, analysis_stage, queued_at, date_created
			) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued', 'pending', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
		";

		DBI::prepared_query($sql, 'isiisisss', [
			$error['id'],
			$signature,
			$config['application'],
			$config['kyte_account'],
			$controllerInfo['controller_id'],
			$controllerInfo['controller_name'],
			$controllerInfo['function_id'],
			$controllerInfo['function_name'],
			$controllerInfo['function_type']
		]);

		$this->log("  Queued error #{$error['id']} for analysis (Controller: {$controllerInfo['controller_name']}, Function: {$controllerInfo['function_name']})");
	}

	/**
	 * Identify controller and function from error details
	 *
	 * @param array $error Error record
	 * @param int $appId Application ID
	 * @return array Controller and function info
	 */
	private function identifyControllerAndFunction($error, $appId)
	{
		$result = [
			'controller_id' => null,
			'controller_name' => '',
			'function_id' => null,
			'function_name' => '',
			'function_type' => ''
		];

		// Extract controller name from file path
		$filePath = $error['file'] ?? '';
		$controllerName = '';

		// Pattern 1: eval()'d code pattern (typical for Kyte dynamic controllers)
		// e.g., "/var/www/html/vendor/keyqcloud/kyte-php/src/Core/Api.php(533) : eval()'d code"
		if (preg_match('/Api\.php\(\d+\)\s*:\s*eval/', $filePath)) {
			// This is dynamically loaded controller code
			// Use the model name from error to find controller
			$modelName = $error['model'] ?? '';
			if ($modelName) {
				$controllerName = $modelName . 'Controller';
			}
		}
		// Pattern 2: Direct controller file path
		// e.g., "Controller/UserController.php" -> "UserController"
		elseif (preg_match('/([A-Z][a-zA-Z0-9]+Controller)\.php/', $filePath, $matches)) {
			$controllerName = $matches[1];
		}
		// Pattern 3: Generic PHP file
		elseif (preg_match('/\/([^\/]+)\.php$/', $filePath, $matches)) {
			$controllerName = $matches[1] . 'Controller';
		}

		if (!$controllerName) {
			$this->log("  WARNING: Could not determine controller name from file: {$filePath}");
			return $result;
		}

		$result['controller_name'] = $controllerName;

		// Look up controller in database
		$sql = "
			SELECT id FROM Controller
			WHERE name = ?
			AND application = ?
			AND deleted = 0
			LIMIT 1
		";

		$controllers = DBI::prepared_query($sql, 'si', [$controllerName, $appId]);

		// If not found and name ends with 'Controller', try without suffix
		if (empty($controllers) && substr($controllerName, -10) === 'Controller') {
			$controllerNameWithoutSuffix = substr($controllerName, 0, -10);
			$controllers = DBI::prepared_query($sql, 'si', [$controllerNameWithoutSuffix, $appId]);
			if (!empty($controllers)) {
				$controllerName = $controllerNameWithoutSuffix; // Update for logging
			}
		}

		if (empty($controllers)) {
			$this->log("  WARNING: Controller '{$controllerName}' not found in database");
			return $result;
		}

		$result['controller_id'] = $controllers[0]['id'];
		$result['controller_name'] = $controllerName;

		// Try to identify function from stack trace
		$trace = $error['trace'] ?? '';
		$functionName = '';

		// Parse stack trace to find function name
		// Example: "#0 /path/file.php(15): HelloWorldController->helloWorld()"
		// Try with controller name as-is
		if (preg_match('/' . preg_quote($controllerName, '/') . '->([a-zA-Z_][a-zA-Z0-9_]*)\(/', $trace, $matches)) {
			$functionName = $matches[1];
		}
		// If not found, try with "Controller" suffix added (trace might have full class name)
		elseif (preg_match('/' . preg_quote($controllerName . 'Controller', '/') . '->([a-zA-Z_][a-zA-Z0-9_]*)\(/', $trace, $matches)) {
			$functionName = $matches[1];
		}

		if (!$functionName) {
			$this->log("  WARNING: Could not determine function name from trace");
			return $result;
		}

		$result['function_name'] = $functionName;

		// Look up function in database
		$sql = "
			SELECT id, type FROM `Function`
			WHERE name = ?
			AND controller = ?
			AND deleted = 0
			LIMIT 1
		";

		$functions = DBI::prepared_query($sql, 'si', [$functionName, $result['controller_id']]);
		if (empty($functions)) {
			$this->log("  WARNING: Function '{$functionName}' not found in database for controller #{$result['controller_id']}");
			return $result;
		}

		$result['function_id'] = $functions[0]['id'];
		$result['function_type'] = $functions[0]['type'];

		return $result;
	}

	/**
	 * Process queued analyses using AIErrorAnalyzer
	 */
	private function processQueuedAnalyses()
	{
		// Get enabled configurations with their limits
		$sql = "
			SELECT c.*, a.identifier as app_identifier
			FROM AIErrorCorrectionConfig c
			JOIN Application a ON c.application = a.id
			WHERE c.enabled = 1
			AND c.deleted = 0
		";

		$configs = DBI::query($sql);

		if (empty($configs)) {
			$this->log("No enabled configurations");
			return;
		}

		foreach ($configs as $config) {
			try {
				$this->processConfigAnalyses($config);
			} catch (\Exception $e) {
				$this->log("Error processing analyses for app #{$config['application']}: " . $e->getMessage());
			}
		}
	}

	/**
	 * Process analyses for a single configuration
	 */
	private function processConfigAnalyses($config)
	{
		$appId = $config['application'];
		$batchSize = $config['batch_size'];

		// Get queued analyses for this application
		$sql = "
			SELECT *
			FROM AIErrorAnalysis
			WHERE application = ?
			AND analysis_status = 'queued'
			AND deleted = 0
			ORDER BY date_created ASC
			LIMIT ?
		";

		$analyses = DBI::prepared_query($sql, 'ii', [$appId, $batchSize]);

		if (empty($analyses)) {
			return;
		}

		$this->log("Processing " . count($analyses) . " queued analyses for app #{$appId}");

		// Create API mock for AIErrorAnalyzer
		$api = $this->createApiContext($config);

		$analyzer = new AIErrorAnalyzer($api);

		foreach ($analyses as $analysisData) {
			try {
				// Load analysis object
				$analysis = new ModelObject(AIErrorAnalysis);
				if (!$analysis->retrieve('id', $analysisData['id'])) {
					$this->log("  Analysis #{$analysisData['id']} not found, skipping");
					continue;
				}

				// Process the analysis
				$analyzer->analyze($analysis);
				$this->analyzedCount++;

				$this->log("  Completed analysis #{$analysis->id}");

			} catch (\Exception $e) {
				$this->log("  Error analyzing #{$analysisData['id']}: " . $e->getMessage());

				// Mark as failed
				$sql = "
					UPDATE AIErrorAnalysis
					SET analysis_status = 'failed',
						last_error = ?,
						processing_completed_at = UNIX_TIMESTAMP()
					WHERE id = ?
				";

				DBI::prepared_query($sql, 'si', [$e->getMessage(), $analysisData['id']]);
			}

			// Send heartbeat every analysis to keep job alive
			$this->heartbeat();
		}
	}

	/**
	 * Check rate limits
	 */
	private function checkRateLimits($config)
	{
		$appId = $config['application'];
		$now = time();

		// Check hourly limit
		$hourAgo = $now - 3600;
		$sql = "
			SELECT COUNT(*) as count
			FROM AIErrorAnalysis
			WHERE application = ?
			AND date_created >= ?
		";

		$result = DBI::prepared_query($sql, 'ii', [$appId, $hourAgo]);
		$hourlyCount = $result[0]['count'];

		if ($hourlyCount >= $config['max_analyses_per_hour']) {
			return false;
		}

		// Check daily limit
		$dayAgo = $now - 86400;
		$result = DBI::prepared_query($sql, 'ii', [$appId, $dayAgo]);
		$dailyCount = $result[0]['count'];

		if ($dailyCount >= $config['max_analyses_per_day']) {
			return false;
		}

		// Check monthly cost limit
		$monthStart = strtotime('first day of this month 00:00:00');
		$sql = "
			SELECT SUM(estimated_cost_usd) as total_cost
			FROM AIErrorAnalysis
			WHERE application = ?
			AND date_created >= ?
		";

		$result = DBI::prepared_query($sql, 'ii', [$appId, $monthStart]);
		$monthlyCost = $result[0]['total_cost'] ?? 0;

		if ($monthlyCost >= $config['max_monthly_cost_usd']) {
			$this->log("  Monthly cost limit reached: $" . $monthlyCost);
			return false;
		}

		return true;
	}

	/**
	 * Create API context for AIErrorAnalyzer
	 */
	private function createApiContext($config)
	{
		// Create minimal API object for AIErrorAnalyzer
		$api = new \stdClass();
		$api->account = new \stdClass();
		$api->account->id = $config['kyte_account'];

		$api->app = new \stdClass();
		$api->app->id = $config['application'];

		return $api;
	}
}
