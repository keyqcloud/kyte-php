<?php

namespace Kyte\Mvc\Controller;

use Kyte\Core\ModelObject;

/**
 * AI Error Correction Configuration Controller
 *
 * Manages per-application AI error correction settings.
 *
 * Custom Actions:
 * - enable: Enable AI error correction for application
 * - disable: Disable AI error correction for application
 * - resetStats: Reset statistics counters
 * - getStats: Get current statistics
 */
class AIErrorCorrectionConfigController extends ModelController
{
	public function hook_init() {
		// Initialization logic if needed
		$this->allowableActions = ['new', 'update', 'get', 'delete', 'enable', 'disable', 'resetStats', 'getStats'];
		$this->requireAuth = true;
		$this->requireAccount = true;
		$this->checkExisting = ['application']; // One config per application
		$this->getFKTables = true;
	}

	/**
	 * Validate configuration values before save
	 */
	public function hook_preprocess($method, &$r, &$o = null) {
		// Only validate on POST and PUT
		if ($method !== 'POST' && $method !== 'PUT') {
			return;
		}

		// Validate confidence threshold
		if (isset($r['auto_fix_min_confidence'])) {
			$confidence = floatval($r['auto_fix_min_confidence']);
			if ($confidence < 0 || $confidence > 100) {
				throw new \Exception("Auto-fix minimum confidence must be between 0 and 100");
			}
		}

		// Validate rate limits
		if (isset($r['max_analyses_per_hour']) && $r['max_analyses_per_hour'] < 1) {
			throw new \Exception("Max analyses per hour must be at least 1");
		}

		if (isset($r['max_analyses_per_day']) && $r['max_analyses_per_day'] < 1) {
			throw new \Exception("Max analyses per day must be at least 1");
		}

		if (isset($r['max_monthly_cost_usd']) && $r['max_monthly_cost_usd'] < 0) {
			throw new \Exception("Max monthly cost cannot be negative");
		}

		// Validate cooldown
		if (isset($r['cooldown_minutes']) && $r['cooldown_minutes'] < 0) {
			throw new \Exception("Cooldown minutes cannot be negative");
		}

		// Validate loop detection settings
		if (isset($r['max_fix_attempts']) && $r['max_fix_attempts'] < 1) {
			throw new \Exception("Max fix attempts must be at least 1");
		}

		if (isset($r['loop_detection_window_minutes']) && $r['loop_detection_window_minutes'] < 1) {
			throw new \Exception("Loop detection window must be at least 1 minute");
		}

		// Validate batch settings
		if (isset($r['batch_size']) && ($r['batch_size'] < 1 || $r['batch_size'] > 100)) {
			throw new \Exception("Batch size must be between 1 and 100");
		}

		if (isset($r['max_concurrent_bedrock_calls']) && ($r['max_concurrent_bedrock_calls'] < 1 || $r['max_concurrent_bedrock_calls'] > 10)) {
			throw new \Exception("Max concurrent Bedrock calls must be between 1 and 10");
		}
	}

	/**
	 * Custom Action: Enable AI Error Correction
	 *
	 * URL: /AIErrorCorrectionConfig/enable/{config_id}
	 */
	public function enable() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Config ID required");
		}

		$config = new ModelObject($this->model);
		if (!$config->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Configuration not found");
		}

		$config->save(['enabled' => 1], $this->user);

		return $this->success([
			'message' => 'AI Error Correction enabled',
			'config' => $config->getAllParams()
		]);
	}

	/**
	 * Custom Action: Disable AI Error Correction
	 *
	 * URL: /AIErrorCorrectionConfig/disable/{config_id}
	 */
	public function disable() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Config ID required");
		}

		$config = new ModelObject($this->model);
		if (!$config->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Configuration not found");
		}

		$config->save([
			'enabled' => 0,
			'auto_fix_enabled' => 0 // Also disable auto-fix
		], $this->user);

		return $this->success([
			'message' => 'AI Error Correction disabled',
			'config' => $config->getAllParams()
		]);
	}

	/**
	 * Custom Action: Reset Statistics
	 *
	 * URL: /AIErrorCorrectionConfig/resetStats/{config_id}
	 */
	public function resetStats() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Config ID required");
		}

		$config = new ModelObject($this->model);
		if (!$config->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Configuration not found");
		}

		$config->save([
			'total_analyses' => 0,
			'total_fixes_applied' => 0,
			'total_successful_fixes' => 0,
			'total_failed_fixes' => 0,
			'total_cost_usd' => 0.00,
			'last_analysis_date' => null
		], $this->user);

		return $this->success([
			'message' => 'Statistics reset',
			'config' => $config->getAllParams()
		]);
	}

	/**
	 * Custom Action: Get Statistics
	 *
	 * URL: /AIErrorCorrectionConfig/getStats/{application_id}
	 *
	 * Returns aggregated statistics for an application
	 */
	public function getStats() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Application ID required");
		}

		// Get config
		$config = new ModelObject($this->model);
		if (!$config->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			// Return default stats if no config exists
			return $this->success([
				'config_exists' => false,
				'enabled' => false,
				'stats' => [
					'total_analyses' => 0,
					'total_fixes_applied' => 0,
					'total_successful_fixes' => 0,
					'total_failed_fixes' => 0,
					'total_cost_usd' => '0.00',
					'success_rate' => 0,
					'avg_confidence' => 0
				]
			]);
		}

		// Calculate success rate
		$successRate = 0;
		if ($config->total_fixes_applied > 0) {
			$successRate = round(($config->total_successful_fixes / $config->total_fixes_applied) * 100, 2);
		}

		// Get average confidence from recent analyses
		$avgConfidence = $this->getAverageConfidence($config->application);

		return $this->success([
			'config_exists' => true,
			'enabled' => $config->enabled == 1,
			'auto_fix_enabled' => $config->auto_fix_enabled == 1,
			'stats' => [
				'total_analyses' => $config->total_analyses,
				'total_fixes_applied' => $config->total_fixes_applied,
				'total_successful_fixes' => $config->total_successful_fixes,
				'total_failed_fixes' => $config->total_failed_fixes,
				'total_cost_usd' => number_format($config->total_cost_usd, 2),
				'success_rate' => $successRate,
				'avg_confidence' => $avgConfidence,
				'last_analysis_date' => $config->last_analysis_date
			],
			'config' => $config->getAllParams()
		]);
	}

	/**
	 * Calculate average confidence score from recent analyses
	 */
	private function getAverageConfidence($applicationId) {
		$model = new \Kyte\Core\Model(AIErrorAnalysis);
		$model->retrieve('application', $applicationId, false, [
			['field' => 'fix_confidence', 'value' => null, 'operator' => 'IS NOT NULL'],
			['field' => 'analysis_status', 'value' => 'completed']
		], false, [['field' => 'date_created', 'direction' => 'DESC']], 100);

		if ($model->count() === 0) {
			return 0;
		}

		$total = 0;
		foreach ($model->objects as $analysis) {
			$total += floatval($analysis->fix_confidence);
		}

		return round($total / $model->count(), 2);
	}
}
