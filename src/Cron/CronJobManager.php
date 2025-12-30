<?php
namespace Kyte\Cron;

use Kyte\Core\Api;
use Kyte\Core\DBI;
use Kyte\Core\Model;
use Kyte\Core\ModelObject;
use Kyte\Cron\CronVersionControl;

/**
 * CronJobManager
 *
 * High-level interface for managing cron jobs with automatic version control.
 * Use this class instead of direct Model operations to ensure versions are created.
 *
 * Features:
 * - Automatic version creation on code changes
 * - Built-in validation
 * - Rollback support
 * - Version history access
 */
class CronJobManager
{
    private $versionControl;
    private $api;

    public function __construct(Api $api = null)
    {
        $this->api = $api ?? Api::getInstance();
        $this->versionControl = new CronVersionControl();
    }

    /**
     * Create a new cron job with initial version
     *
     * @param array $data Job data (name, code, schedule_type, etc.)
     * @return ModelObject Created job object
     */
    public function createJob(array $data)
    {
        // Validate required fields
        if (empty($data['name'])) {
            throw new \Exception("Job name is required");
        }

        if (empty($data['code'])) {
            throw new \Exception("Job code is required");
        }

        // Extract code before creating job
        $code = $data['code'];
        unset($data['code']); // Will be set with compression

        // Compress code
        $compressedCode = bzcompress($code, 9);
        $data['code'] = $compressedCode;

        // Create job record
        $job = Model::create('CronJob', $data);

        if (!$job) {
            throw new \Exception("Failed to create cron job");
        }

        $jobId = $job->id;

        // Create initial version (version 1)
        try {
            $this->versionControl->createVersion($jobId, $code, $data['created_by'] ?? null);
            echo "[" . date('Y-m-d H:i:s') . "] Created job {$jobId}: {$data['name']}\n";
        } catch (\Exception $e) {
            // Rollback job creation if versioning fails
            Model::delete('CronJob', $jobId);
            throw new \Exception("Failed to create initial version: " . $e->getMessage());
        }

        return $job;
    }

    /**
     * Update cron job code (automatically creates version)
     *
     * @param int $jobId The job ID
     * @param string $newCode New job code (uncompressed)
     * @param int|null $userId User making the change
     * @return array|false Version info if code changed, false if no change
     */
    public function updateCode($jobId, $newCode, $userId = null)
    {
        // Validate job exists
        $job = Model::one('CronJob', $jobId);
        if (!$job) {
            throw new \Exception("Cron job not found: {$jobId}");
        }

        // Create version (this also updates the job)
        $version = $this->versionControl->createVersion($jobId, $newCode, $userId);

        if ($version === false) {
            echo "[" . date('Y-m-d H:i:s') . "] No code changes for job {$jobId}\n";
            return false;
        }

        echo "[" . date('Y-m-d H:i:s') . "] Updated job {$jobId} (version {$version['version_number']})\n";

        return $version;
    }

    /**
     * Update cron job properties (without code changes)
     *
     * @param int $jobId The job ID
     * @param array $data Fields to update (excluding 'code')
     * @return ModelObject Updated job object
     */
    public function updateJob($jobId, array $data)
    {
        // Prevent code updates through this method
        if (isset($data['code'])) {
            throw new \Exception("Use updateCode() method to update job code");
        }

        $job = Model::one('CronJob', $jobId);
        if (!$job) {
            throw new \Exception("Cron job not found: {$jobId}");
        }

        // Update job properties
        foreach ($data as $field => $value) {
            $job->$field = $value;
        }

        $job->save();

        echo "[" . date('Y-m-d H:i:s') . "] Updated job {$jobId} properties\n";

        return $job;
    }

    /**
     * Rollback job to previous version
     *
     * @param int $jobId The job ID
     * @param int $versionNumber Version to rollback to
     * @param int|null $userId User performing rollback
     * @return array Rollback result
     */
    public function rollback($jobId, $versionNumber, $userId = null)
    {
        return $this->versionControl->rollback($jobId, $versionNumber, $userId);
    }

    /**
     * Get version history for a job
     *
     * @param int $jobId The job ID
     * @param int $limit Maximum versions to return
     * @return array Version history
     */
    public function getVersionHistory($jobId, $limit = 50)
    {
        return $this->versionControl->getVersionHistory($jobId, $limit);
    }

    /**
     * Get specific version code
     *
     * @param int $jobId The job ID
     * @param int $versionNumber The version number
     * @return string|null Decompressed code
     */
    public function getVersionCode($jobId, $versionNumber)
    {
        return $this->versionControl->getVersionCode($jobId, $versionNumber);
    }

    /**
     * Compare two versions
     *
     * @param int $jobId The job ID
     * @param int $version1 First version number
     * @param int $version2 Second version number
     * @return array Comparison result with diff
     */
    public function compareVersions($jobId, $version1, $version2)
    {
        return $this->versionControl->compareVersions($jobId, $version1, $version2);
    }

    /**
     * Delete a cron job (soft delete)
     *
     * @param int $jobId The job ID
     * @param int|null $userId User performing deletion
     * @return bool Success
     */
    public function deleteJob($jobId, $userId = null)
    {
        $job = Model::one('CronJob', $jobId);
        if (!$job) {
            throw new \Exception("Cron job not found: {$jobId}");
        }

        // Disable job first
        $job->enabled = 0;
        $job->deleted = 1;
        $job->deleted_by = $userId;
        $job->date_deleted = time();
        $job->save();

        echo "[" . date('Y-m-d H:i:s') . "] Deleted job {$jobId}\n";

        return true;
    }

    /**
     * Validate cron job code syntax
     *
     * @param string $code PHP code to validate
     * @return array Validation result ['valid' => bool, 'error' => string|null]
     */
    public function validateCode($code)
    {
        // Check if code has class definition
        if (!preg_match('/class\s+\w+\s+extends\s+\\\\?Kyte\\\\Core\\\\CronJobBase/', $code)) {
            return [
                'valid' => false,
                'error' => 'Code must contain a class that extends Kyte\Core\CronJobBase'
            ];
        }

        // Check if execute() method exists
        if (!preg_match('/public\s+function\s+execute\s*\(/', $code)) {
            return [
                'valid' => false,
                'error' => 'Class must implement public function execute()'
            ];
        }

        // Syntax check (PHP lint)
        $tempFile = tempnam(sys_get_temp_dir(), 'cron_validate_');
        file_put_contents($tempFile, '<?php ' . $code);

        $output = [];
        $return = 0;
        exec('php -l ' . escapeshellarg($tempFile) . ' 2>&1', $output, $return);

        unlink($tempFile);

        if ($return !== 0) {
            return [
                'valid' => false,
                'error' => 'PHP syntax error: ' . implode("\n", $output)
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Prune old versions for a job
     *
     * @param int $jobId The job ID
     * @param int $keepCount Number of versions to keep
     * @return int Number of versions deleted
     */
    public function pruneVersions($jobId, $keepCount = 10)
    {
        return $this->versionControl->pruneVersions($jobId, $keepCount);
    }

    /**
     * Get content deduplication statistics
     *
     * @return array Statistics
     */
    public function getContentStats()
    {
        return $this->versionControl->getContentStats();
    }
}
