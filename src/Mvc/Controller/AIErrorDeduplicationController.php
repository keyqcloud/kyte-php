<?php

namespace Kyte\Mvc\Controller;

use Kyte\Core\ModelObject;
use Kyte\Core\Model;

/**
 * AI Error Deduplication Controller
 *
 * Manages deduplicated error signature tracking.
 *
 * Custom Actions:
 * - markResolved: Mark error signature as resolved
 * - markUnresolved: Mark error signature as unresolved
 * - resetCooldown: Reset cooldown to allow immediate re-analysis
 * - getTopErrors: Get most frequent error signatures
 * - getUnresolved: Get unresolved errors
 */
class AIErrorDeduplicationController extends ModelController
{
	public $model = 'AIErrorDeduplication';
	protected $allowableActions = ['get', 'delete', 'markResolved', 'markUnresolved', 'resetCooldown', 'getTopErrors', 'getUnresolved'];
	protected $requireAuth = true;
	protected $requireAccount = true;
	protected $getFKTables = false;

	/**
	 * Prevent direct modification of deduplication records
	 * These are managed automatically by AIErrorCorrection system
	 */
	public function hook_preprocess($data) {
		if ($this->api->request === 'PUT' || $this->api->request === 'POST') {
			throw new \Exception("Deduplication records are managed automatically. Use custom actions to modify.");
		}
		return $data;
	}

	/**
	 * Custom Action: Mark Resolved
	 *
	 * URL: /AIErrorDeduplication/markResolved/id/{dedup_id}
	 *
	 * Marks an error signature as resolved (user confirmed fix worked)
	 */
	public function markResolved() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Deduplication ID required");
		}

		$dedup = new ModelObject(constant($this->model));
		if (!$dedup->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Error signature not found");
		}

		$dedup->save([
			'is_resolved' => 1,
			'resolved_at' => time(),
			'resolved_by' => $this->user->id ?? null
		], $this->user);

		return $this->success([
			'message' => 'Error signature marked as resolved',
			'deduplication' => $dedup->getAllParams()
		]);
	}

	/**
	 * Custom Action: Mark Unresolved
	 *
	 * URL: /AIErrorDeduplication/markUnresolved/id/{dedup_id}
	 *
	 * Marks an error signature as unresolved (error recurred)
	 */
	public function markUnresolved() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Deduplication ID required");
		}

		$dedup = new ModelObject(constant($this->model));
		if (!$dedup->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Error signature not found");
		}

		$dedup->save([
			'is_resolved' => 0,
			'resolved_at' => null,
			'resolved_by' => null
		], $this->user);

		return $this->success([
			'message' => 'Error signature marked as unresolved',
			'deduplication' => $dedup->getAllParams()
		]);
	}

	/**
	 * Custom Action: Reset Cooldown
	 *
	 * URL: /AIErrorDeduplication/resetCooldown/id/{dedup_id}
	 *
	 * Resets last_analyzed timestamp to allow immediate re-analysis
	 */
	public function resetCooldown() {
		if (!isset($this->api->field) || !isset($this->api->value)) {
			return $this->error("Deduplication ID required");
		}

		$dedup = new ModelObject(constant($this->model));
		if (!$dedup->retrieve($this->api->field, $this->api->value, [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		])) {
			return $this->error("Error signature not found");
		}

		$dedup->save([
			'last_analyzed' => null
		], $this->user);

		return $this->success([
			'message' => 'Cooldown reset - error can be re-analyzed immediately',
			'deduplication' => $dedup->getAllParams()
		]);
	}

	/**
	 * Custom Action: Get Top Errors
	 *
	 * URL: /AIErrorDeduplication/getTopErrors/application/{application_id}
	 *
	 * Returns most frequently occurring error signatures
	 */
	public function getTopErrors() {
		$applicationId = null;
		$limit = 10;

		// Check if application specified
		if (isset($this->api->field) && $this->api->field === 'application' && isset($this->api->value)) {
			$applicationId = $this->api->value;
		}

		// Check for limit in query params
		if (isset($_GET['limit'])) {
			$limit = min(100, max(1, intval($_GET['limit'])));
		}

		$conditions = [
			['field' => 'kyte_account', 'value' => $this->api->account->id]
		];

		if ($applicationId !== null) {
			$conditions[] = ['field' => 'application', 'value' => $applicationId];
		}

		$model = new Model(constant($this->model));
		$model->retrieve('deleted', 0, false, $conditions, false,
			[['field' => 'occurrence_count', 'direction' => 'DESC']], $limit);

		$topErrors = [];
		foreach ($model->objects as $dedup) {
			$data = $dedup->getAllParams();

			// Add human-readable timestamps
			$data['first_seen_formatted'] = date('Y-m-d H:i:s', $dedup->first_seen);
			$data['last_seen_formatted'] = date('Y-m-d H:i:s', $dedup->last_seen);
			if ($dedup->last_analyzed) {
				$data['last_analyzed_formatted'] = date('Y-m-d H:i:s', $dedup->last_analyzed);
			}

			$topErrors[] = $data;
		}

		return $this->success([
			'count' => count($topErrors),
			'errors' => $topErrors
		]);
	}

	/**
	 * Custom Action: Get Unresolved Errors
	 *
	 * URL: /AIErrorDeduplication/getUnresolved/application/{application_id}
	 *
	 * Returns unresolved error signatures, sorted by most recent occurrence
	 */
	public function getUnresolved() {
		$applicationId = null;
		$limit = 50;

		// Check if application specified
		if (isset($this->api->field) && $this->api->field === 'application' && isset($this->api->value)) {
			$applicationId = $this->api->value;
		}

		// Check for limit in query params
		if (isset($_GET['limit'])) {
			$limit = min(100, max(1, intval($_GET['limit'])));
		}

		$conditions = [
			['field' => 'kyte_account', 'value' => $this->api->account->id],
			['field' => 'is_resolved', 'value' => 0]
		];

		if ($applicationId !== null) {
			$conditions[] = ['field' => 'application', 'value' => $applicationId];
		}

		$model = new Model(constant($this->model));
		$model->retrieve('deleted', 0, false, $conditions, false,
			[['field' => 'last_seen', 'direction' => 'DESC']], $limit);

		$unresolved = [];
		foreach ($model->objects as $dedup) {
			$data = $dedup->getAllParams();

			// Add human-readable timestamps
			$data['first_seen_formatted'] = date('Y-m-d H:i:s', $dedup->first_seen);
			$data['last_seen_formatted'] = date('Y-m-d H:i:s', $dedup->last_seen);
			if ($dedup->last_analyzed) {
				$data['last_analyzed_formatted'] = date('Y-m-d H:i:s', $dedup->last_analyzed);
			}

			// Calculate time since last seen
			$timeSince = time() - $dedup->last_seen;
			$data['time_since_last_seen'] = $this->formatTimeSince($timeSince);

			$unresolved[] = $data;
		}

		return $this->success([
			'count' => count($unresolved),
			'errors' => $unresolved
		]);
	}

	/**
	 * Format time elapsed in human-readable format
	 */
	private function formatTimeSince($seconds) {
		if ($seconds < 60) {
			return $seconds . ' seconds ago';
		} elseif ($seconds < 3600) {
			$minutes = floor($seconds / 60);
			return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
		} elseif ($seconds < 86400) {
			$hours = floor($seconds / 3600);
			return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
		} else {
			$days = floor($seconds / 86400);
			return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
		}
	}
}
