<?php
namespace Kyte\Cron;

use Kyte\Core\DBI;

/**
 * CronVersionControl
 *
 * Manages version control for cron job code with SHA256 content deduplication,
 * reference counting, rollback capability, and change tracking.
 *
 * Features:
 * - Automatic version creation when job code changes
 * - SHA256 content hashing with deduplication
 * - Reference counting for shared content
 * - Version rollback capability
 * - JSON-encoded change diffs
 * - Version history viewing
 */
class CronVersionControl
{
    /**
     * Create a new version of a cron job
     *
     * @param int $jobId The cron job ID
     * @param string $newCode The new job code (uncompressed)
     * @param int|null $userId The user making the change (optional)
     * @return array|false Version record if created, false if no change
     */
    public function createVersion($jobId, $newCode, $userId = null)
    {
        // Get current job to compare
        $sql = "SELECT id, code FROM CronJob WHERE id = ?";
        $job = DBI::prepared_query($sql, 'i', [$jobId]);

        if (empty($job)) {
            throw new \Exception("Cron job not found: {$jobId}");
        }

        $currentCompressedCode = $job[0]['code'];
        $currentCode = !empty($currentCompressedCode) ? bzdecompress($currentCompressedCode) : '';

        // Check if code actually changed
        if ($currentCode === $newCode) {
            return false; // No change, no version needed
        }

        // Compress new code
        $compressedCode = bzcompress($newCode, 9);

        // Calculate SHA256 hash of compressed code
        $contentHash = hash('sha256', $compressedCode);

        // Get next version number
        $sql = "SELECT MAX(version_number) as max_version FROM KyteCronJobVersion WHERE cron_job = ?";
        $result = DBI::prepared_query($sql, 'i', [$jobId]);
        $nextVersion = isset($result[0]['max_version']) ? $result[0]['max_version'] + 1 : 1;

        // Check if content already exists (deduplication)
        $contentId = $this->getOrCreateContent($contentHash, $compressedCode);

        // Calculate diff from previous version
        $diff = $this->calculateDiff($currentCode, $newCode);
        $diffJson = json_encode($diff);

        // Unmark previous version as current
        $sql = "UPDATE KyteCronJobVersion SET is_current = 0 WHERE cron_job = ? AND is_current = 1";
        DBI::prepared_query($sql, 'i', [$jobId]);

        // Create new version record
        $now = time();
        $sql = "INSERT INTO KyteCronJobVersion (
            cron_job, version_number, code, content_hash, is_current,
            changes_from_previous, created_by, date_created
        ) VALUES (?, ?, ?, ?, 1, ?, ?, ?)";

        DBI::prepared_query($sql, 'iibssii', [
            $jobId,
            $nextVersion,
            $compressedCode,
            $contentHash,
            $diffJson,
            $userId,
            $now
        ]);

        $versionId = DBI::insert_id();

        // Update job with new code
        $sql = "UPDATE CronJob SET code = ?, modified_by = ?, date_modified = ? WHERE id = ?";
        DBI::prepared_query($sql, 'biii', [$compressedCode, $userId, $now, $jobId]);

        echo "[" . date('Y-m-d H:i:s') . "] Created version {$nextVersion} for job {$jobId} (content hash: " . substr($contentHash, 0, 12) . "...)\n";

        return [
            'version_id' => $versionId,
            'version_number' => $nextVersion,
            'content_hash' => $contentHash,
            'content_id' => $contentId,
            'diff' => $diff
        ];
    }

    /**
     * Get or create content record with reference counting
     *
     * @param string $contentHash SHA256 hash
     * @param string $compressedCode Bzip2 compressed code
     * @return int Content ID
     */
    private function getOrCreateContent($contentHash, $compressedCode)
    {
        // Check if content exists
        $sql = "SELECT id, reference_count FROM KyteCronJobVersionContent WHERE content_hash = ?";
        $result = DBI::prepared_query($sql, 's', [$contentHash]);

        if (!empty($result)) {
            // Content exists, increment reference count
            $contentId = $result[0]['id'];
            $newCount = $result[0]['reference_count'] + 1;

            $sql = "UPDATE KyteCronJobVersionContent SET reference_count = ? WHERE id = ?";
            DBI::prepared_query($sql, 'ii', [$newCount, $contentId]);

            echo "[" . date('Y-m-d H:i:s') . "] Content deduplicated (hash: " . substr($contentHash, 0, 12) . "..., refs: {$newCount})\n";

            return $contentId;
        }

        // Create new content record
        $now = time();
        $sql = "INSERT INTO KyteCronJobVersionContent (
            content_hash, code, reference_count, date_created
        ) VALUES (?, ?, 1, ?)";

        DBI::prepared_query($sql, 'sbi', [$contentHash, $compressedCode, $now]);

        $contentId = DBI::insert_id();

        echo "[" . date('Y-m-d H:i:s') . "] New content created (hash: " . substr($contentHash, 0, 12) . "..., id: {$contentId})\n";

        return $contentId;
    }

    /**
     * Calculate diff between two code versions
     *
     * @param string $oldCode Previous code
     * @param string $newCode New code
     * @return array Diff information
     */
    private function calculateDiff($oldCode, $newCode)
    {
        $oldLines = explode("\n", $oldCode);
        $newLines = explode("\n", $newCode);

        $diff = [
            'lines_added' => 0,
            'lines_removed' => 0,
            'lines_changed' => 0,
            'total_lines_before' => count($oldLines),
            'total_lines_after' => count($newLines),
            'changes' => []
        ];

        // Simple line-by-line diff
        $maxLines = max(count($oldLines), count($newLines));

        for ($i = 0; $i < $maxLines; $i++) {
            $oldLine = isset($oldLines[$i]) ? $oldLines[$i] : null;
            $newLine = isset($newLines[$i]) ? $newLines[$i] : null;

            if ($oldLine === null && $newLine !== null) {
                $diff['lines_added']++;
                if (count($diff['changes']) < 50) { // Limit stored changes
                    $diff['changes'][] = ['type' => 'added', 'line' => $i + 1, 'content' => $newLine];
                }
            } elseif ($oldLine !== null && $newLine === null) {
                $diff['lines_removed']++;
                if (count($diff['changes']) < 50) {
                    $diff['changes'][] = ['type' => 'removed', 'line' => $i + 1, 'content' => $oldLine];
                }
            } elseif ($oldLine !== $newLine) {
                $diff['lines_changed']++;
                if (count($diff['changes']) < 50) {
                    $diff['changes'][] = [
                        'type' => 'changed',
                        'line' => $i + 1,
                        'old' => $oldLine,
                        'new' => $newLine
                    ];
                }
            }
        }

        return $diff;
    }

    /**
     * Rollback job to a specific version
     *
     * @param int $jobId The cron job ID
     * @param int $versionNumber The version number to rollback to
     * @param int|null $userId The user performing the rollback
     * @return array Rollback result
     */
    public function rollback($jobId, $versionNumber, $userId = null)
    {
        // Get target version
        $sql = "SELECT id, version_number, code, content_hash FROM KyteCronJobVersion
                WHERE cron_job = ? AND version_number = ? AND deleted = 0";
        $version = DBI::prepared_query($sql, 'ii', [$jobId, $versionNumber]);

        if (empty($version)) {
            throw new \Exception("Version {$versionNumber} not found for job {$jobId}");
        }

        $targetVersion = $version[0];

        // Get current version for comparison
        $sql = "SELECT version_number FROM KyteCronJobVersion
                WHERE cron_job = ? AND is_current = 1";
        $current = DBI::prepared_query($sql, 'i', [$jobId]);
        $currentVersionNumber = !empty($current) ? $current[0]['version_number'] : null;

        if ($currentVersionNumber === $versionNumber) {
            return ['status' => 'no_change', 'message' => 'Already at version ' . $versionNumber];
        }

        // Unmark current version
        $sql = "UPDATE KyteCronJobVersion SET is_current = 0 WHERE cron_job = ? AND is_current = 1";
        DBI::prepared_query($sql, 'i', [$jobId]);

        // Mark target version as current
        $sql = "UPDATE KyteCronJobVersion SET is_current = 1 WHERE id = ?";
        DBI::prepared_query($sql, 'i', [$targetVersion['id']]);

        // Update job code
        $now = time();
        $sql = "UPDATE CronJob SET code = ?, modified_by = ?, date_modified = ? WHERE id = ?";
        DBI::prepared_query($sql, 'biii', [$targetVersion['code'], $userId, $now, $jobId]);

        echo "[" . date('Y-m-d H:i:s') . "] Rolled back job {$jobId} from version {$currentVersionNumber} to version {$versionNumber}\n";

        return [
            'status' => 'success',
            'from_version' => $currentVersionNumber,
            'to_version' => $versionNumber,
            'message' => "Rolled back to version {$versionNumber}"
        ];
    }

    /**
     * Get version history for a job
     *
     * @param int $jobId The cron job ID
     * @param int $limit Maximum versions to return (default 50)
     * @return array Version history
     */
    public function getVersionHistory($jobId, $limit = 50)
    {
        $sql = "SELECT
                    v.id,
                    v.version_number,
                    v.content_hash,
                    v.is_current,
                    v.changes_from_previous,
                    v.created_by,
                    v.date_created,
                    c.reference_count
                FROM KyteCronJobVersion v
                LEFT JOIN KyteCronJobVersionContent c ON v.content_hash = c.content_hash
                WHERE v.cron_job = ? AND v.deleted = 0
                ORDER BY v.version_number DESC
                LIMIT ?";

        $versions = DBI::prepared_query($sql, 'ii', [$jobId, $limit]);

        if (empty($versions)) {
            return [];
        }

        // Parse JSON diffs
        foreach ($versions as &$version) {
            if (!empty($version['changes_from_previous'])) {
                $version['changes_from_previous'] = json_decode($version['changes_from_previous'], true);
            }
        }

        return $versions;
    }

    /**
     * Get specific version code
     *
     * @param int $jobId The cron job ID
     * @param int $versionNumber The version number
     * @return string|null Decompressed code or null if not found
     */
    public function getVersionCode($jobId, $versionNumber)
    {
        $sql = "SELECT code FROM KyteCronJobVersion
                WHERE cron_job = ? AND version_number = ? AND deleted = 0";
        $result = DBI::prepared_query($sql, 'ii', [$jobId, $versionNumber]);

        if (empty($result)) {
            return null;
        }

        $compressedCode = $result[0]['code'];
        return !empty($compressedCode) ? bzdecompress($compressedCode) : '';
    }

    /**
     * Compare two versions
     *
     * @param int $jobId The cron job ID
     * @param int $version1 First version number
     * @param int $version2 Second version number
     * @return array Comparison result with diff
     */
    public function compareVersions($jobId, $version1, $version2)
    {
        $code1 = $this->getVersionCode($jobId, $version1);
        $code2 = $this->getVersionCode($jobId, $version2);

        if ($code1 === null) {
            throw new \Exception("Version {$version1} not found");
        }
        if ($code2 === null) {
            throw new \Exception("Version {$version2} not found");
        }

        $diff = $this->calculateDiff($code1, $code2);

        return [
            'version_1' => $version1,
            'version_2' => $version2,
            'diff' => $diff,
            'code_1' => $code1,
            'code_2' => $code2
        ];
    }

    /**
     * Delete old versions (reduce reference counts)
     *
     * @param int $jobId The cron job ID
     * @param int $keepCount Number of versions to keep (default 10)
     * @return int Number of versions deleted
     */
    public function pruneVersions($jobId, $keepCount = 10)
    {
        // Get versions to delete (keep current + N most recent)
        $sql = "SELECT id, content_hash FROM KyteCronJobVersion
                WHERE cron_job = ? AND is_current = 0 AND deleted = 0
                ORDER BY version_number DESC
                LIMIT ?, 999999";

        $versions = DBI::prepared_query($sql, 'ii', [$jobId, $keepCount]);

        if (empty($versions)) {
            return 0;
        }

        $deletedCount = 0;

        foreach ($versions as $version) {
            // Decrement reference count
            $this->decrementReferenceCount($version['content_hash']);

            // Soft delete version
            $sql = "UPDATE KyteCronJobVersion SET deleted = 1 WHERE id = ?";
            DBI::prepared_query($sql, 'i', [$version['id']]);

            $deletedCount++;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Pruned {$deletedCount} old versions for job {$jobId}\n";

        return $deletedCount;
    }

    /**
     * Decrement reference count for content (and delete if reaches 0)
     *
     * @param string $contentHash SHA256 hash
     */
    private function decrementReferenceCount($contentHash)
    {
        $sql = "SELECT id, reference_count FROM KyteCronJobVersionContent WHERE content_hash = ?";
        $result = DBI::prepared_query($sql, 's', [$contentHash]);

        if (empty($result)) {
            return;
        }

        $content = $result[0];
        $newCount = $content['reference_count'] - 1;

        if ($newCount <= 0) {
            // Delete content record when no more references
            $sql = "DELETE FROM KyteCronJobVersionContent WHERE id = ?";
            DBI::prepared_query($sql, 'i', [$content['id']]);

            echo "[" . date('Y-m-d H:i:s') . "] Deleted unused content (hash: " . substr($contentHash, 0, 12) . "...)\n";
        } else {
            // Update reference count
            $sql = "UPDATE KyteCronJobVersionContent SET reference_count = ? WHERE id = ?";
            DBI::prepared_query($sql, 'ii', [$newCount, $content['id']]);
        }
    }

    /**
     * Get content deduplication statistics
     *
     * @return array Statistics
     */
    public function getContentStats()
    {
        $sql = "SELECT
                    COUNT(*) as total_content_records,
                    SUM(reference_count) as total_references,
                    AVG(reference_count) as avg_references,
                    MAX(reference_count) as max_references,
                    SUM(LENGTH(code)) as total_storage_bytes
                FROM KyteCronJobVersionContent";

        $stats = DBI::query($sql);

        if (!empty($stats)) {
            $stats[0]['total_storage_mb'] = round($stats[0]['total_storage_bytes'] / 1024 / 1024, 2);
            return $stats[0];
        }

        return [];
    }
}
