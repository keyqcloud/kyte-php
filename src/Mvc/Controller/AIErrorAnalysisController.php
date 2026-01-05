<?php

namespace Kyte\Mvc\Controller;

use Kyte\Core\ModelObject;
use Kyte\Core\Model;
use Kyte\AI\AIErrorFixApplier;
use Kyte\AI\AIErrorAnalyzer;

/**
 * AI Error Analysis Controller
 *
 * Manages AI error analysis records and fix application.
 *
 * Custom Actions:
 * - applyFix: Apply suggested fix to function
 * - rejectFix: Mark fix as rejected
 * - reanalyze: Queue error for re-analysis
 * - rollback: Rollback applied fix to previous version
 * - getByError: Get analysis for specific error
 * - getPending: Get all pending fix suggestions
 * - getApplied: Get all applied fixes
 */
class AIErrorAnalysisController extends ModelController
{

	public function hook_init() {
		$this->model = AIErrorAnalysis;
		$this->allowableActions = ['get', 'delete'];
		$this->requireAuth = true;
		$this->requireAccount = true;
		$this->getFKTables = true; // Load foreign key data (KyteError now uses kyte_account)
		$this->getExternalTables = false;
	}

	/**
	 * Override get() to handle GET custom actions
	 *
	 * URL: GET /AIErrorAnalysis/getByError/123
	 * URL: GET /AIErrorAnalysis/getPending/7
	 * URL: GET /AIErrorAnalysis/getApplied/7
	 */
	public function get($field, $value)
	{

		error_log("AIErrorAnalysisController::get() called with field=$field, value=$value");
		
		// Check if this is a custom action
		if ($field === 'getByError' && $value) {
			return $this->getByError();
		}
		if ($field === 'getPending' && $value) {
			return $this->getPending();
		}
		if ($field === 'getApplied' && $value) {
			return $this->getApplied();
		}

		// Normal get operation
		parent::get($field, $value);
	}

	/**
	 * Override update() to handle PUT custom actions
	 *
	 * URL: PUT /AIErrorAnalysis/applyFix/123
	 * URL: PUT /AIErrorAnalysis/rejectFix/123
	 * URL: PUT /AIErrorAnalysis/reanalyze/123
	 * URL: PUT /AIErrorAnalysis/rollback/123
	 */
	public function update($field, $value, $data)
	{
		$action = $field;
		$analysisId = $value;

		if ($action && $analysisId) {
			switch ($action) {
				case 'applyFix':
					return $this->applyFix();
				case 'rejectFix':
					return $this->rejectFix();
				case 'reanalyze':
					return $this->reanalyze();
				case 'rollback':
					return $this->rollback();
			}
		}

		// Prevent normal updates
		throw new \Exception("Analysis records cannot be updated directly. Use custom actions (applyFix, rejectFix, etc.)");
	}

	/**
	 * Enhance get responses with additional context
	 */
	public function hook_process_get_response(&$r) {
		// Preserve raw Unix timestamps for JavaScript (before they get converted to formatted dates)
		// This allows reliable timezone-independent date handling in the frontend
		$dateFields = ['date_created', 'date_modified', 'date_deleted', 'queued_at',
		               'processing_started_at', 'processing_completed_at', 'applied_at'];

		foreach ($dateFields as $field) {
			if (isset($r[$field]) && is_numeric($r[$field])) {
				// Store raw Unix timestamp with _raw suffix for JavaScript
				$r[$field . '_raw'] = (int)$r[$field];
			}
		}

		// Add human-readable timestamps for display
		if (isset($r['queued_at']) && is_numeric($r['queued_at'])) {
			$r['queued_at_formatted'] = date('Y-m-d H:i:s', $r['queued_at']);
		}
		if (isset($r['applied_at']) && is_numeric($r['applied_at'])) {
			$r['applied_at_formatted'] = date('Y-m-d H:i:s', $r['applied_at']);
		}

		// Add status badge info for frontend
		$r['status_badge'] = $this->getStatusBadge($r);
	}

	/**
	 * Get status badge configuration for frontend
	 */
	private function getStatusBadge($analysis) {
		$status = $analysis['analysis_status'] ?? 'queued';
		$fixStatus = $analysis['fix_status'] ?? 'suggested';

		$badges = [
			'queued' => ['color' => 'warning', 'icon' => 'clock', 'text' => 'Queued'],
			'processing' => ['color' => 'info', 'icon' => 'spinner', 'text' => 'Processing'],
			'completed' => ['color' => 'success', 'icon' => 'check', 'text' => 'Completed'],
			'failed' => ['color' => 'danger', 'icon' => 'times', 'text' => 'Failed']
		];

		$badge = $badges[$status] ?? ['color' => 'secondary', 'icon' => 'question', 'text' => 'Unknown'];

		// Override with fix status if applicable
		if ($status === 'completed') {
			if ($fixStatus === 'applied_auto' || $fixStatus === 'applied_manual') {
				$badge = ['color' => 'primary', 'icon' => 'check-circle', 'text' => 'Fix Applied'];
			} elseif ($fixStatus === 'rejected') {
				$badge = ['color' => 'dark', 'icon' => 'ban', 'text' => 'Rejected'];
			} elseif (isset($analysis['is_fixable']) && $analysis['is_fixable'] == 0) {
				$badge = ['color' => 'secondary', 'icon' => 'info-circle', 'text' => 'Not Fixable'];
			}
		}

		return $badge;
	}

	/**
	 * Custom Action: Apply Fix
	 *
	 * URL: /AIErrorAnalysis/applyFix/id/{analysis_id}
	 */
	public function applyFix() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Analysis ID required");
		}

		$analysis = new ModelObject($this->model);
		if (!$analysis->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Analysis not found");
		}

		// Validate state
		if ($analysis->analysis_status !== 'completed') {
			return $this->error("Analysis must be completed before applying fix");
		}

		if ($analysis->is_fixable != 1) {
			return $this->error("This error is not fixable");
		}

		if ($analysis->fix_status === 'applied_manual' || $analysis->fix_status === 'applied_auto') {
			return $this->error("Fix has already been applied");
		}

		// Apply the fix
		try {
			$applier = new AIErrorFixApplier($this->api);
			$success = $applier->apply($analysis, $this->user->id);

			if ($success) {
				return $this->success([
					'message' => 'Fix applied successfully',
					'analysis' => $analysis->getAllParams()
				]);
			} else {
				return $this->error("Failed to apply fix. Check logs for details.");
			}
		} catch (\Exception $e) {
			return $this->error("Error applying fix: " . $e->getMessage());
		}
	}

	/**
	 * Custom Action: Reject Fix
	 *
	 * URL: /AIErrorAnalysis/rejectFix/id/{analysis_id}
	 */
	public function rejectFix() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Analysis ID required");
		}

		$analysis = new ModelObject($this->model);
		if (!$analysis->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Analysis not found");
		}

		// Validate state
		if ($analysis->fix_status === 'applied_manual' || $analysis->fix_status === 'applied_auto') {
			return $this->error("Cannot reject a fix that has already been applied. Use rollback instead.");
		}

		$analysis->save([
			'fix_status' => 'rejected'
		], $this->user);

		return $this->success([
			'message' => 'Fix rejected',
			'analysis' => $analysis->getAllParams()
		]);
	}

	/**
	 * Custom Action: Reanalyze
	 *
	 * URL: /AIErrorAnalysis/reanalyze/id/{analysis_id}
	 *
	 * Queues the error for re-analysis (useful if first analysis failed or was rejected)
	 */
	public function reanalyze() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Analysis ID required");
		}

		$analysis = new ModelObject($this->model);
		if (!$analysis->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Analysis not found");
		}

		// Check if already queued or processing
		if ($analysis->analysis_status === 'queued' || $analysis->analysis_status === 'processing') {
			return $this->error("Analysis is already queued or in progress");
		}

		// Reset to queued state
		$analysis->save([
			'analysis_status' => 'queued',
			'analysis_stage' => 'pending',
			'processing_started_at' => null,
			'processing_completed_at' => null,
			'retry_count' => $analysis->retry_count + 1,
			'queued_at' => time()
		], $this->user);

		return $this->success([
			'message' => 'Analysis queued for reprocessing',
			'analysis' => $analysis->getAllParams()
		]);
	}

	/**
	 * Custom Action: Rollback Fix
	 *
	 * URL: /AIErrorAnalysis/rollback/id/{analysis_id}
	 *
	 * Rolls back an applied fix to the previous function version
	 */
	public function rollback() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Analysis ID required");
		}

		$analysis = new ModelObject($this->model);
		if (!$analysis->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Analysis not found");
		}

		// Validate that fix was applied
		if ($analysis->fix_status !== 'applied_manual' && $analysis->fix_status !== 'applied_auto') {
			return $this->error("Cannot rollback - fix was not applied");
		}

		// Perform rollback
		try {
			$applier = new AIErrorFixApplier($this->api);
			$success = $applier->rollback($analysis);

			if ($success) {
				return $this->success([
					'message' => 'Fix rolled back successfully',
					'analysis' => $analysis->getAllParams()
				]);
			} else {
				return $this->error("Failed to rollback fix. Check logs for details.");
			}
		} catch (\Exception $e) {
			return $this->error("Error rolling back fix: " . $e->getMessage());
		}
	}

	/**
	 * Custom Action: Get Analysis by Error ID
	 *
	 * URL: /AIErrorAnalysis/getByError/error_id/{error_id}
	 */
	public function getByError() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Error ID required");
		}

		$model = new Model($this->model);
		$model->retrieve($this->api->field, $this->api->value, false, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		], false, [['field' => 'date_created', 'direction' => 'DESC']]);

		if ($model->count() === 0) {
			return $this->success([
				'exists' => false,
				'message' => 'No analysis found for this error'
			]);
		}

		$analysis = $model->objects[0];
		$response = $analysis->getAllParams();
		$this->hook_process_get_response($response);

		return $this->success([
			'exists' => true,
			'analysis' => $response
		]);
	}

	/**
	 * Custom Action: Get Pending Fixes
	 *
	 * URL: /AIErrorAnalysis/getPending/application/{application_id}
	 */
	public function getPending() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Application ID required");
		}

		$model = new Model($this->model);
		$model->retrieve($this->api->field, $this->api->value, false, [
			['field' => 'kyte_account', 'value' => $this->api->account->id],
			['field' => 'analysis_status', 'value' => 'completed'],
			['field' => 'fix_status', 'value' => 'suggested'],
			['field' => 'is_fixable', 'value' => 1]
		], false, [['field' => 'fix_confidence', 'direction' => 'DESC']]);

		$pending = [];
		foreach ($model->objects as $analysis) {
			$data = $analysis->getAllParams();
			$this->hook_process_get_response($data);
			$pending[] = $data;
		}

		return $this->success([
			'count' => count($pending),
			'analyses' => $pending
		]);
	}

	/**
	 * Custom Action: Get Applied Fixes
	 *
	 * URL: /AIErrorAnalysis/getApplied/application/{application_id}
	 */
	public function getApplied() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Application ID required");
		}

		$model = new Model($this->model);
		$model->retrieve($this->api->field, $this->api->value, false, [
			['field' => 'kyte_account', 'value' => $this->api->account->id],
			['field' => 'fix_status', 'value' => ['applied_manual', 'applied_auto'], 'operator' => 'IN']
		], false, [['field' => 'applied_at', 'direction' => 'DESC']], 50);

		$applied = [];
		foreach ($model->objects as $analysis) {
			$data = $analysis->getAllParams();
			$this->hook_process_get_response($data);
			$applied[] = $data;
		}

		return $this->success([
			'count' => count($applied),
			'analyses' => $applied
		]);
	}
}
