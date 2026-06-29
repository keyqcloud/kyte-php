<?php

namespace Kyte\Mvc\Controller;

use Kyte\Core\DBI;

/**
 * Enqueues an update of the install's Kyte Shipyard dashboard to the latest
 * published build. The heavy lifting (download/extract/upload/CloudFront
 * invalidate) runs out-of-band in ShipyardUpdateWorker (a cron job) — NOT in this
 * request — because it routinely exceeds the ~100s Cloudflare non-enterprise
 * request ceiling (and ALB idle timeouts) on a real build. See KYTE-#201.
 *
 * Flow of `new`:
 *   1. Validate input + Shipyard config.
 *   2. Fast inline CDN version check (~100ms): if already current, return
 *      "up to date" immediately — no row, no worker.
 *   3. Request dedup: if a pending/running KyteShipyardUpdate already exists,
 *      return it instead of enqueueing a duplicate.
 *   4. Otherwise create a KyteShipyardUpdate row (status=pending) and return it.
 *      The dashboard polls GET on this model (by id) for live status.
 *
 * Previously this published `current_version` to SNS_KYTE_SHIPYARD_UPDATE and the
 * kyte-lambda-update-shipyard Lambda did the work (with two prod bugs — see
 * ShipyardUpdateWorker). The SNS topic + Lambda are decommissioned in the final
 * #201 step.
 */
class KyteShipyardUpdateController extends ModelController
{
    private const CHANGELOG_URL = 'https://cdn.keyqcloud.com/kyte/shipyard/archive/CHANGELOG.md';

    public function hook_init() {
        $this->allowableActions = ['new', 'get'];
    }

    public function new($data)
    {
        if (!in_array('new', $this->allowableActions)) {
            return;
        }

        if (!isset($data['current_version'])) {
            throw new \Exception('Missing current_version');
        }
        if (!defined('KYTE_SHIPYARD_S3') || !defined('KYTE_SHIPYARD_CF') || !KYTE_SHIPYARD_S3 || !KYTE_SHIPYARD_CF) {
            throw new \Exception('Shipyard update is not configured: define KYTE_SHIPYARD_S3 and KYTE_SHIPYARD_CF in config.');
        }

        $currentVersion = $data['current_version'];

        // Cheap, synchronous: only enqueue heavy work when actually behind.
        $latestVersion = $this->getLatestVersion();
        if ($latestVersion !== null && $latestVersion === $currentVersion) {
            $this->response['data'] = [[
                'updated'         => false,
                'status'          => 'up_to_date',
                'current_version' => $currentVersion,
                'latest_version'  => $latestVersion,
                'message'         => "Shipyard is up to date ({$currentVersion}).",
            ]];
            return;
        }

        // Request dedup (install-wide; one Shipyard per install): don't queue a
        // second update while one is already in flight. Execution dedup is the
        // worker's job (lease lock + guarded pending->running claim).
        $inflight = DBI::prepared_query(
            "SELECT id, status, requested_version FROM KyteShipyardUpdate WHERE status IN ('pending','running') AND deleted = 0 ORDER BY id DESC LIMIT 1",
            '',
            []
        );
        if (!empty($inflight)) {
            $this->response['data'] = [[
                'updated'        => false,
                'status'         => $inflight[0]['status'],
                'id'             => (int) $inflight[0]['id'],
                'latest_version' => $latestVersion,
                'message'        => 'A Shipyard update is already in progress.',
            ]];
            return;
        }

        // Enqueue. Mirror ModelController::new's account/audit stamping since we
        // build the row directly rather than delegating to the base action.
        $row = [
            'current_version'   => $currentVersion,
            'requested_version' => $latestVersion,
            'status'            => 'pending',
            'message'           => 'Queued for the Shipyard update worker.',
        ];
        if ($this->requireAccount && isset($this->api->account->id)) {
            $row['kyte_account'] = $this->api->account->id;
        }
        if (isset($this->api->user->id)) {
            $row['created_by'] = $this->api->user->id;
        }

        $obj = new \Kyte\Core\ModelObject(KyteShipyardUpdate);
        if (!$obj->create($row)) {
            throw new \Exception('Failed to queue Shipyard update.');
        }

        $ret = $this->getObject($obj);
        $ret['latest_version'] = $latestVersion;
        $ret['message']        = "Shipyard update to {$latestVersion} queued.";
        $this->response['data'] = [$ret];
    }

    /**
     * Fetch the CDN changelog and return the first `## X.Y.Z` version, or null.
     * Best-effort: on failure we return null and let the request enqueue anyway
     * (the worker re-checks authoritatively before deploying).
     */
    private function getLatestVersion(): ?string
    {
        $ch = curl_init(self::CHANGELOG_URL);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            error_log("Shipyard update: changelog version check failed (status {$status}).");
            return null;
        }
        if (preg_match('/##\s*(\d+\.\d+\.\d+)/', $body, $m)) {
            return $m[1];
        }
        return null;
    }
}
