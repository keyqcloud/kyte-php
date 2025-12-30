<?php

namespace Kyte\Mvc\Controller;

use Kyte\Cron\CronJobManager;
use Kyte\Core\Api;
use Kyte\Core\DBI;

/**
 * Controller for KyteCronJobVersion model
 *
 * Provides read-only access to job version history with diff viewing
 * and comparison capabilities.
 */
class KyteCronJobVersionController extends ModelController
{
    private $cronJobManager;

    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
        // Version records should not be directly created/updated/deleted by users
        $this->allowableActions = ['get'];
        $this->cronJobManager = new CronJobManager(Api::getInstance());
    }

    /**
     * Override get() to handle custom actions via URL path routing
     *
     * Routes:
     * - GET /KyteCronJobVersion/history/{job_id}?limit=50
     * - GET /KyteCronJobVersion/compare/{job_id}?version1=2&version2=4
     * - GET /KyteCronJobVersion/code/{job_id}?version=3
     */
    public function get($field, $value)
    {
        // Check if this is a custom action
        $action = $field;
        $jobId = $value;

        if ($action && $jobId) {
            switch ($action) {
                case 'history':
                    return $this->handleHistory($jobId);

                case 'compare':
                    return $this->handleCompare($jobId);

                case 'code':
                    return $this->handleCode($jobId);

                default:
                    // Not a recognized action, fall through to normal get
                    break;
            }
        }

        // Normal get operation (by field/value)
        parent::get($field, $value);
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                // Add job information
                if (!empty($r['cron_job'])) {
                    $r['job'] = $this->getJobInfo($r['cron_job']);
                }

                // Parse JSON diff
                if (!empty($r['changes_from_previous'])) {
                    $decoded = json_decode($r['changes_from_previous'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $r['changes_from_previous'] = $decoded;
                        $r['change_summary'] = $this->formatChangeSummary($decoded);
                    }
                }

                // Add created by user info
                if (!empty($r['created_by'])) {
                    $r['created_by_user'] = $this->getUserInfo($r['created_by']);
                }

                // Add version metadata
                $r['can_revert'] = !$r['is_current'];

                // Decompress code if requested
                if (isset($_GET['include_code']) && $_GET['include_code']) {
                    $this->decompressCode($r);
                } else {
                    // Don't include large code field by default
                    unset($r['code']);
                }

                // Add content metadata
                if (!empty($r['content_hash'])) {
                    $r['content_info'] = $this->getContentInfo($r['content_hash']);
                }
                break;

            default:
                break;
        }
    }

    /**
     * Get job information
     */
    private function getJobInfo(int $jobId): array
    {
        $sql = "SELECT id, name, enabled FROM CronJob WHERE id = ?";
        $result = DBI::prepared_query($sql, 'i', [$jobId]);

        if (empty($result)) {
            return ['id' => $jobId, 'name' => 'Unknown'];
        }

        return $result[0];
    }

    /**
     * Get user information
     */
    private function getUserInfo(int $userId): array
    {
        // Try to load user (might not exist in all environments)
        try {
            $sql = "SELECT id, name, username, email FROM KyteUser WHERE id = ?";
            $result = DBI::prepared_query($sql, 'i', [$userId]);

            if (!empty($result)) {
                return $result[0];
            }
        } catch (\Exception $e) {
            // User table might not exist or user not found
        }

        return ['id' => $userId, 'name' => 'Unknown'];
    }

    /**
     * Get content metadata
     */
    private function getContentInfo(string $contentHash): array
    {
        $sql = "SELECT reference_count, date_created FROM KyteCronJobVersionContent WHERE content_hash = ?";
        $result = DBI::prepared_query($sql, 's', [$contentHash]);

        if (empty($result)) {
            return ['hash' => substr($contentHash, 0, 12) . '...', 'references' => 0];
        }

        return [
            'hash' => substr($contentHash, 0, 12) . '...',
            'full_hash' => $contentHash,
            'references' => $result[0]['reference_count'],
            'first_used' => $result[0]['date_created']
        ];
    }

    /**
     * Format change summary for display
     */
    private function formatChangeSummary(array $diff): string
    {
        $parts = [];

        if ($diff['lines_added'] > 0) {
            $parts[] = "+{$diff['lines_added']} lines";
        }

        if ($diff['lines_removed'] > 0) {
            $parts[] = "-{$diff['lines_removed']} lines";
        }

        if ($diff['lines_changed'] > 0) {
            $parts[] = "~{$diff['lines_changed']} lines";
        }

        if (empty($parts)) {
            return 'No changes';
        }

        return implode(', ', $parts) . " ({$diff['total_lines_before']} → {$diff['total_lines_after']} total)";
    }

    /**
     * Decompress code in response
     */
    private function decompressCode(array &$r): void
    {
        if (!empty($r['code']) && is_string($r['code'])) {
            $decompressed = @bzdecompress($r['code']);
            $r['code'] = $decompressed !== false ? $decompressed : '';
        }
    }

    /**
     * Handle: Get version history for a job
     *
     * GET /KyteCronJobVersion/history/{job_id}?limit=50
     */
    private function handleHistory($jobId): void
    {
        if (!$jobId) {
            $this->respond(['error' => 'job_id is required in URL path'], 400);
            return;
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $limit = min($limit, 200); // Max 200

        $versions = $this->cronJobManager->getVersionHistory((int)$jobId, $limit);

        // Format for response
        foreach ($versions as &$version) {
            if (!empty($version['changes_from_previous'])) {
                $version['change_summary'] = $this->formatChangeSummary($version['changes_from_previous']);
            }

            $version['content_info'] = $this->getContentInfo($version['content_hash']);

            // Add user info
            if (!empty($version['created_by'])) {
                $version['created_by_user'] = $this->getUserInfo($version['created_by']);
            }
        }

        $this->respond([
            'job_id' => (int)$jobId,
            'count' => count($versions),
            'versions' => $versions
        ]);
    }

    /**
     * Handle: Compare two versions
     *
     * GET /KyteCronJobVersion/compare/{job_id}?version1=2&version2=4
     */
    private function handleCompare($jobId): void
    {
        if (!$jobId) {
            $this->respond(['error' => 'job_id is required in URL path'], 400);
            return;
        }

        $version1 = $_GET['version1'] ?? null;
        $version2 = $_GET['version2'] ?? null;

        if (!$version1 || !$version2) {
            $this->respond([
                'error' => 'version1 and version2 query parameters are required'
            ], 400);
            return;
        }

        try {
            $comparison = $this->cronJobManager->compareVersions(
                (int)$jobId,
                (int)$version1,
                (int)$version2
            );

            // Add formatted summary
            $comparison['summary'] = $this->formatChangeSummary($comparison['diff']);

            $this->respond($comparison);

        } catch (\Exception $e) {
            $this->respond(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Handle: Get specific version code
     *
     * GET /KyteCronJobVersion/code/{job_id}?version=3
     */
    private function handleCode($jobId): void
    {
        if (!$jobId) {
            $this->respond(['error' => 'job_id is required in URL path'], 400);
            return;
        }

        $versionNumber = $_GET['version'] ?? null;

        if (!$versionNumber) {
            $this->respond([
                'error' => 'version query parameter is required'
            ], 400);
            return;
        }

        try {
            $code = $this->cronJobManager->getVersionCode(
                (int)$jobId,
                (int)$versionNumber
            );

            if ($code === null) {
                $this->respond(['error' => 'Version not found'], 404);
                return;
            }

            $this->respond([
                'job_id' => (int)$jobId,
                'version' => (int)$versionNumber,
                'code' => $code
            ]);

        } catch (\Exception $e) {
            $this->respond(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Helper to send API response and exit
     * Prevents parent method execution after custom action
     */
    private function respond(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        $this->response = array_merge($this->response, $data);
        echo json_encode($this->response);
        exit(0);
    }
}
