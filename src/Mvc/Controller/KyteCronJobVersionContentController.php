<?php

namespace Kyte\Mvc\Controller;

use Kyte\Cron\CronJobManager;
use Kyte\Core\Api;
use Kyte\Core\DBI;

/**
 * Controller for KyteCronJobVersionContent model
 *
 * Provides read-only access to deduplicated content storage with
 * reference tracking and statistics.
 */
class KyteCronJobVersionContentController extends ModelController
{
    private $cronJobManager;

    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
        // Content records should not be directly modified by users
        $this->allowableActions = ['get'];
        $this->cronJobManager = new CronJobManager(Api::getInstance());
    }

    /**
     * Override get() to handle custom actions via URL path routing
     *
     * Routes:
     * - GET /KyteCronJobVersionContent/stats
     * - GET /KyteCronJobVersionContent/find?hash=abc123
     * - GET /KyteCronJobVersionContent/orphaned
     */
    public function get($field, $value)
    {
        // Check if this is a custom action (no ID needed)
        $action = $field;

        if ($action && !$value) {
            switch ($action) {
                case 'stats':
                    return $this->handleStats();

                case 'find':
                    return $this->handleFind();

                case 'orphaned':
                    return $this->handleOrphaned();

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
                // Add human-readable hash
                if (!empty($r['content_hash'])) {
                    $r['hash_short'] = substr($r['content_hash'], 0, 12) . '...';
                }

                // Decompress code if requested
                if (isset($_GET['include_code']) && $_GET['include_code']) {
                    $this->decompressCode($r);
                } else {
                    // Don't include large code field by default
                    unset($r['code']);
                    $r['code_size_bytes'] = !empty($o->code) ? strlen($o->code) : 0;
                }

                // Add versions using this content
                if (!empty($r['content_hash'])) {
                    $r['versions'] = $this->getVersionsUsingContent($r['content_hash']);
                }

                // Add deduplication savings
                if (!empty($r['reference_count']) && $r['reference_count'] > 1) {
                    $savedCount = $r['reference_count'] - 1;
                    $r['deduplication_savings'] = [
                        'saved_references' => $savedCount,
                        'percentage' => round((1 - (1 / $r['reference_count'])) * 100, 2)
                    ];
                }
                break;

            default:
                break;
        }
    }

    /**
     * Decompress code in response
     */
    private function decompressCode(array &$r): void
    {
        if (!empty($r['code']) && is_string($r['code'])) {
            $decompressed = @bzdecompress($r['code']);
            $r['code'] = $decompressed !== false ? $decompressed : '';
            $r['code_decompressed'] = true;
        }
    }

    /**
     * Get versions using this content
     */
    private function getVersionsUsingContent(string $contentHash, int $limit = 20): array
    {
        $sql = "
            SELECT
                v.id,
                v.cron_job,
                v.version_number,
                v.is_current,
                v.date_created,
                j.name as job_name
            FROM KyteCronJobVersion v
            JOIN CronJob j ON v.cron_job = j.id
            WHERE v.content_hash = ? AND v.deleted = 0
            ORDER BY v.date_created DESC
            LIMIT ?
        ";

        return DBI::prepared_query($sql, 'si', [$contentHash, $limit]);
    }

    /**
     * Handle: Get deduplication statistics
     *
     * GET /KyteCronJobVersionContent/stats
     */
    private function handleStats(): void
    {
        $stats = $this->cronJobManager->getContentStats();

        if (empty($stats)) {
            $this->respond(['error' => 'No statistics available'], 404);
            return;
        }

        // Calculate additional metrics
        $totalRecords = (int)$stats['total_content_records'];
        $totalReferences = (int)$stats['total_references'];

        if ($totalRecords > 0) {
            $savedRecords = $totalReferences - $totalRecords;
            $deduplicationRate = round((1 - ($totalRecords / $totalReferences)) * 100, 2);

            $stats['deduplication'] = [
                'saved_records' => $savedRecords,
                'deduplication_rate' => $deduplicationRate,
                'efficiency_description' => $this->getEfficiencyDescription($deduplicationRate)
            ];
        }

        // Get top deduplicated content
        $sql = "
            SELECT
                content_hash,
                reference_count,
                date_created,
                LENGTH(code) as size_bytes
            FROM KyteCronJobVersionContent
            ORDER BY reference_count DESC
            LIMIT 10
        ";

        $topDeduplicated = DBI::query($sql);

        // Add version info for each
        foreach ($topDeduplicated as &$content) {
            $content['hash_short'] = substr($content['content_hash'], 0, 12) . '...';
            $content['versions'] = $this->getVersionsUsingContent($content['content_hash'], 5);
        }

        $stats['top_deduplicated'] = $topDeduplicated;

        $this->respond($stats);
    }

    /**
     * Get efficiency description
     */
    private function getEfficiencyDescription(float $rate): string
    {
        if ($rate >= 70) {
            return 'Excellent - High deduplication achieving significant storage savings';
        } elseif ($rate >= 50) {
            return 'Good - Moderate deduplication providing noticeable savings';
        } elseif ($rate >= 30) {
            return 'Fair - Some deduplication occurring';
        } else {
            return 'Low - Limited deduplication, consider if jobs share similar code';
        }
    }

    /**
     * Handle: Find content by hash
     *
     * GET /KyteCronJobVersionContent/find?hash=abc123...
     */
    private function handleFind(): void
    {
        $hash = $_GET['hash'] ?? null;

        if (!$hash) {
            $this->respond(['error' => 'hash query parameter is required'], 400);
            return;
        }

        // Support partial hash matching
        if (strlen($hash) < 64) {
            // Partial hash - search with LIKE
            $sql = "SELECT * FROM KyteCronJobVersionContent WHERE content_hash LIKE ? LIMIT 10";
            $results = DBI::prepared_query($sql, 's', [$hash . '%']);

            if (empty($results)) {
                $this->respond(['error' => 'No content found matching hash'], 404);
                return;
            }

            // Format results
            foreach ($results as &$content) {
                $content['hash_short'] = substr($content['content_hash'], 0, 12) . '...';
                $content['versions'] = $this->getVersionsUsingContent($content['content_hash']);
                unset($content['code']); // Don't include code in search results
                $content['code_size_bytes'] = !empty($content['code']) ? strlen($content['code']) : 0;
            }

            $this->respond([
                'search_hash' => $hash,
                'matches' => count($results),
                'results' => $results
            ]);

        } else {
            // Full hash - exact match
            $sql = "SELECT * FROM KyteCronJobVersionContent WHERE content_hash = ?";
            $result = DBI::prepared_query($sql, 's', [$hash]);

            if (empty($result)) {
                $this->respond(['error' => 'Content not found'], 404);
                return;
            }

            $content = $result[0];
            $content['hash_short'] = substr($content['content_hash'], 0, 12) . '...';
            $content['versions'] = $this->getVersionsUsingContent($content['content_hash']);

            // Decompress code if requested
            if (isset($_GET['include_code']) && $_GET['include_code']) {
                if (!empty($content['code'])) {
                    $decompressed = @bzdecompress($content['code']);
                    $content['code'] = $decompressed !== false ? $decompressed : '';
                    $content['code_decompressed'] = true;
                }
            } else {
                unset($content['code']);
                $content['code_size_bytes'] = !empty($content['code']) ? strlen($content['code']) : 0;
            }

            $this->respond($content);
        }
    }

    /**
     * Handle: Get orphaned content (reference_count = 0)
     *
     * GET /KyteCronJobVersionContent/orphaned
     */
    private function handleOrphaned(): void
    {
        $sql = "
            SELECT
                content_hash,
                reference_count,
                date_created,
                LENGTH(code) as size_bytes
            FROM KyteCronJobVersionContent
            WHERE reference_count = 0
            ORDER BY date_created DESC
        ";

        $orphaned = DBI::query($sql);

        // Format results
        foreach ($orphaned as &$content) {
            $content['hash_short'] = substr($content['content_hash'], 0, 12) . '...';
        }

        $totalSize = array_sum(array_column($orphaned, 'size_bytes'));

        $this->respond([
            'count' => count($orphaned),
            'total_size_bytes' => $totalSize,
            'total_size_mb' => round($totalSize / 1024 / 1024, 2),
            'orphaned' => $orphaned,
            'note' => 'These records can be safely deleted to free up space'
        ]);
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
