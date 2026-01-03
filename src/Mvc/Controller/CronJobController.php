<?php

namespace Kyte\Mvc\Controller;

// use Kyte\Cron\CronJobManager; // DEPRECATED: Using function-based versioning now
use Kyte\Core\Api;
use Kyte\Core\Model;
use Kyte\Core\ModelObject;
use Kyte\Core\DBI;

/**
 * Controller for CronJob model
 *
 * Handles CRUD operations for cron jobs with automatic version control,
 * manual triggering, DLQ recovery, and validation.
 *
 * Custom Actions:
 * - POST /CronJob/trigger/5 - Manually trigger job execution
 * - POST /CronJob/recover/5 - Recover job from DLQ
 * - POST /CronJob/rollback/5?version=3 - Rollback to specific version
 * - GET /CronJob/stats/5 - Get job statistics
 */
class CronJobController extends ModelController
{
    // private $cronJobManager; // DEPRECATED: Using function-based versioning now

    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }

    /**
     * Get CronJobManager instance (lazy initialization)
     * DEPRECATED: Using function-based versioning - rollback via CronJobFunctionVersion
     */
    // private function getCronJobManager(): CronJobManager
    // {
    //     if ($this->cronJobManager === null) {
    //         $this->cronJobManager = new CronJobManager($this->api);
    //     }
    //     return $this->cronJobManager;
    // }

    /**
     * Override new() to handle POST custom actions
     *
     * URL: POST /CronJob/trigger/5
     * Routing: new($data) with $this->api->field='trigger', $this->api->value='5'
     */
    public function new($data)
    {
        // Check if this is a custom action (POST /CronJob/{action}/{id})
        $action = $this->api->field;
        $jobId = $this->api->value;

        if ($action && $jobId) {
            switch ($action) {
                case 'trigger':
                    return $this->handleTrigger($jobId);

                case 'recover':
                    return $this->handleRecover($jobId);

                case 'rollback':
                    return $this->handleRollback($jobId, $data);

                default:
                    // Not a recognized action, fall through to normal create
                    break;
            }
        }

        // Normal job creation
        parent::new($data);
    }

    /**
     * Override get() to handle GET custom actions
     *
     * URL: GET /CronJob/stats/5
     * Routing: get('stats', '5')
     */
    public function get($field, $value)
    {
        // Check if this is a custom action
        if ($field === 'stats' && $value) {
            return $this->handleStats($value);
        }

        // Normal get operation
        parent::get($field, $value);
    }

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $this->processNewJob($r);
                break;

            case 'update':
                $this->processUpdateJob($r, $o);
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                // Create default functions for new cron jobs
                if ($o && $o->id) {
                    $this->createDefaultFunctions($o->id, $this->api->user ? $this->api->user->id : null);
                }

                // Decompress code for response
                $this->decompressCode($r);

                // Version info now per-function - access via CronJobFunctionVersion API
                // $r['version_info'] = $this->getVersionInfo($o->id);

                // Add execution summary
                $r['execution_summary'] = $this->getExecutionSummary($o->id);
                break;

            case 'get':
                // Decompress code for response
                $this->decompressCode($r);

                // Version info now per-function - access via CronJobFunctionVersion API
                // $r['version_info'] = $this->getVersionInfo($o->id);

                // Add execution summary
                $r['execution_summary'] = $this->getExecutionSummary($o->id);

                // Add dependency information if applicable
                if (!empty($r['depends_on_job'])) {
                    $r['parent_job'] = $this->getParentJobInfo($r['depends_on_job']);
                }

                // Add next run time
                $r['next_run'] = $this->getNextRunTime($o->id);
                break;

            case 'update':
                // Decompress code for response
                $this->decompressCode($r);

                // Add updated version information
                $r['version_info'] = $this->getVersionInfo($o->id);

                // Add execution summary
                $r['execution_summary'] = $this->getExecutionSummary($o->id);
                break;

            default:
                break;
        }
    }

    /**
     * Process new job creation
     */
    private function processNewJob(array &$r): void
    {
        // Don't set code directly - it will be generated from functions
        // Remove code if provided (old API compatibility)
        unset($r['code']);

        // Set default values
        if (!isset($r['enabled'])) {
            $r['enabled'] = 1;
        }

        if (!isset($r['timeout_seconds'])) {
            $r['timeout_seconds'] = 300;
        }

        if (!isset($r['max_retries'])) {
            $r['max_retries'] = 3;
        }

        if (!isset($r['retry_strategy'])) {
            $r['retry_strategy'] = 'exponential';
        }

        // Calculate initial next_run_time
        $r['next_run_time'] = $this->calculateInitialNextRun($r);
    }

    /**
     * Process job update
     */
    private function processUpdateJob(array &$r, $o): void
    {
        // Code updates now handled by CronJobFunctionController
        // Remove code field if provided (old API compatibility)
        unset($r['code']);

        // Recalculate next_run_time if schedule changed
        if (isset($r['schedule_type']) || isset($r['cron_expression']) ||
            isset($r['interval_seconds']) || isset($r['time_of_day']) ||
            isset($r['day_of_week']) || isset($r['day_of_month']) ||
            isset($r['timezone'])) {

            // Merge with existing values
            $scheduleData = array_merge([
                'schedule_type' => $o->schedule_type,
                'cron_expression' => $o->cron_expression,
                'interval_seconds' => $o->interval_seconds,
                'time_of_day' => $o->time_of_day,
                'day_of_week' => $o->day_of_week,
                'day_of_month' => $o->day_of_month,
                'timezone' => $o->timezone,
            ], $r);

            $r['next_run_time'] = $this->calculateInitialNextRun($scheduleData);
        }
    }

    /**
     * Calculate initial next run time based on schedule
     */
    private function calculateInitialNextRun(array $jobData): int
    {
        $now = time();

        switch ($jobData['schedule_type'] ?? 'interval') {
            case 'interval':
                return $now + ($jobData['interval_seconds'] ?? 60);

            case 'daily':
                return $this->calculateDailyNextRun($jobData);

            case 'weekly':
                return $this->calculateWeeklyNextRun($jobData);

            case 'monthly':
                return $this->calculateMonthlyNextRun($jobData);

            case 'cron':
                if (!empty($jobData['cron_expression'])) {
                    $cron = new \Cron\CronExpression($jobData['cron_expression']);
                    return $cron->getNextRunDate()->getTimestamp();
                }
                return $now + 3600; // Default to 1 hour

            default:
                return $now + 3600;
        }
    }

    private function calculateDailyNextRun(array $job): int
    {
        $timezone = $job['timezone'] ?? 'UTC';
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);

        $timeParts = explode(':', $job['time_of_day'] ?? '00:00:00');
        $hour = (int)$timeParts[0];
        $minute = (int)($timeParts[1] ?? 0);
        $second = (int)($timeParts[2] ?? 0);

        $nextRun = clone $now;
        $nextRun->setTime($hour, $minute, $second);

        if ($nextRun <= $now) {
            $nextRun->modify('+1 day');
        }

        return $nextRun->getTimestamp();
    }

    private function calculateWeeklyNextRun(array $job): int
    {
        $timezone = $job['timezone'] ?? 'UTC';
        $targetDayOfWeek = $job['day_of_week'] ?? 0;
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);

        $timeParts = explode(':', $job['time_of_day'] ?? '00:00:00');
        $hour = (int)$timeParts[0];
        $minute = (int)($timeParts[1] ?? 0);
        $second = (int)($timeParts[2] ?? 0);

        $nextRun = clone $now;
        $nextRun->setTime($hour, $minute, $second);

        $currentDayOfWeek = (int)$nextRun->format('w');

        if ($currentDayOfWeek == $targetDayOfWeek && $nextRun > $now) {
            return $nextRun->getTimestamp();
        }

        $daysUntilTarget = ($targetDayOfWeek - $currentDayOfWeek + 7) % 7;
        if ($daysUntilTarget == 0) {
            $daysUntilTarget = 7;
        }

        $nextRun->modify("+{$daysUntilTarget} days");

        return $nextRun->getTimestamp();
    }

    private function calculateMonthlyNextRun(array $job): int
    {
        $timezone = $job['timezone'] ?? 'UTC';
        $targetDayOfMonth = $job['day_of_month'] ?? 1;
        $tz = new \DateTimeZone($timezone);
        $now = new \DateTime('now', $tz);

        $timeParts = explode(':', $job['time_of_day'] ?? '00:00:00');
        $hour = (int)$timeParts[0];
        $minute = (int)($timeParts[1] ?? 0);
        $second = (int)($timeParts[2] ?? 0);

        $nextRun = clone $now;
        $daysInMonth = (int)$nextRun->format('t');
        $actualDay = min($targetDayOfMonth, $daysInMonth);

        $nextRun->setDate(
            (int)$nextRun->format('Y'),
            (int)$nextRun->format('m'),
            $actualDay
        );
        $nextRun->setTime($hour, $minute, $second);

        if ($nextRun <= $now) {
            $nextRun->modify('+1 month');
            $daysInMonth = (int)$nextRun->format('t');
            $actualDay = min($targetDayOfMonth, $daysInMonth);
            $nextRun->setDate(
                (int)$nextRun->format('Y'),
                (int)$nextRun->format('m'),
                $actualDay
            );
        }

        return $nextRun->getTimestamp();
    }

    /**
     * Decompress code for API response
     */
    private function decompressCode(array &$r): void
    {
        if (isset($r['code']) && !empty($r['code'])) {
            $decompressed = @bzdecompress($r['code']);
            if ($decompressed !== false) {
                $r['code'] = $decompressed;
            }
        }
    }

    /**
     * Get version information for a job
     * DEPRECATED: Version info now tracked per-function in CronJobFunctionVersion
     * Access via CronJobFunctionVersionController API
     */
    // private function getVersionInfo(int $jobId): ?array
    // {
    //     // No longer used - see CronJobFunctionVersion for per-function versioning
    //     return null;
    // }

    /**
     * Get execution summary statistics
     */
    private function getExecutionSummary(int $jobId): array
    {
        $sql = "
            SELECT
                COUNT(*) as total_executions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END) as timeouts,
                AVG(CASE WHEN status = 'completed' THEN duration_ms ELSE NULL END) as avg_duration_ms,
                MAX(completed_at) as last_execution
            FROM CronJobExecution
            WHERE cron_job = ?
        ";

        $stats = DBI::prepared_query($sql, 'i', [$jobId]);

        if (!empty($stats)) {
            $total = (int)$stats[0]['total_executions'];
            $successful = (int)$stats[0]['successful'];

            $stats[0]['success_rate'] = $total > 0 ? round(($successful / $total) * 100, 2) : 0;

            return $stats[0];
        }

        return [
            'total_executions' => 0,
            'successful' => 0,
            'failed' => 0,
            'timeouts' => 0,
            'success_rate' => 0,
            'avg_duration_ms' => null,
            'last_execution' => null
        ];
    }

    /**
     * Get parent job information
     */
    private function getParentJobInfo(int $parentJobId): ?array
    {
        $sql = "SELECT id, name, enabled, in_dead_letter_queue FROM CronJob WHERE id = ?";
        $parent = DBI::prepared_query($sql, 'i', [$parentJobId]);

        return !empty($parent) ? $parent[0] : null;
    }

    /**
     * Get next run time from pending execution
     */
    private function getNextRunTime(int $jobId): ?int
    {
        $sql = "
            SELECT next_run_time
            FROM CronJobExecution
            WHERE cron_job = ? AND status = 'pending'
            ORDER BY next_run_time ASC
            LIMIT 1
        ";

        $result = DBI::prepared_query($sql, 'i', [$jobId]);

        return !empty($result) ? (int)$result[0]['next_run_time'] : null;
    }

    // ========================================================================
    // CUSTOM ACTIONS (called via URL routing)
    // ========================================================================

    /**
     * Handle manual job trigger
     * URL: POST /CronJob/trigger/5
     */
    private function handleTrigger(int $jobId): void
    {
        $job = new ModelObject(CronJob);
        if (!$job->retrieve('id', $jobId) || !isset($job->id)) {
            $this->respond(['error' => 'Job not found'], 404);
            return;
        }

        if (!$job->enabled) {
            $this->respond(['error' => 'Job is disabled'], 400);
            return;
        }

        if ($job->in_dead_letter_queue) {
            $this->respond(['error' => 'Job is in dead letter queue. Recover it first.'], 400);
            return;
        }

        // Create immediate execution
        $now = time();
        $sql = "
            INSERT INTO CronJobExecution (
                cron_job, scheduled_time, next_run_time, status,
                application, kyte_account, created_by, date_created
            ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)
        ";

        DBI::prepared_query($sql, 'iiiiiii', [
            $job->id,
            $now,
            $now, // Run immediately
            $job->application,
            $job->kyte_account,
            $this->api->user ? $this->api->user->id : null,
            $now
        ]);

        $executionId = DBI::insert_id();

        $this->respond([
            'success' => true,
            'message' => 'Job execution queued',
            'execution_id' => $executionId,
            'job_id' => $job->id,
            'job_name' => $job->name
        ]);
    }

    /**
     * Handle DLQ recovery
     * URL: POST /CronJob/recover/5
     */
    private function handleRecover(int $jobId): void
    {
        $job = new ModelObject(CronJob);
        if (!$job->retrieve('id', $jobId) || !isset($job->id)) {
            $this->respond(['error' => 'Job not found'], 404);
            return;
        }

        if (!$job->in_dead_letter_queue) {
            $this->respond(['error' => 'Job is not in dead letter queue'], 400);
            return;
        }

        // Recover from DLQ
        $job->in_dead_letter_queue = 0;
        $job->dead_letter_reason = null;
        $job->dead_letter_since = null;
        $job->consecutive_failures = 0;
        $job->enabled = 1;
        $job->modified_by = $this->api->user ? $this->api->user->id : null;
        $job->date_modified = time();
        $job->save([
            'in_dead_letter_queue' => 0,
            'dead_letter_reason' => null,
            'dead_letter_since' => null,
            'consecutive_failures' => 0,
            'enabled' => 1,
            'modified_by' => $this->api->user ? $this->api->user->id : null,
            'date_modified' => time()
        ], $this->api->user ? $this->api->user->id : null);

        $this->respond([
            'success' => true,
            'message' => 'Job recovered from dead letter queue',
            'job_id' => $job->id,
            'job_name' => $job->name
        ]);
    }

    /**
     * Handle version rollback
     * URL: POST /CronJob/rollback/5?version=3
     */
    private function handleRollback(int $jobId, array $data): void
    {
        $job = new ModelObject(CronJob);
        if (!$job->retrieve('id', $jobId) || !isset($job->id)) {
            $this->respond(['error' => 'Job not found'], 404);
            return;
        }

        // Get version number from query param or data
        $versionNumber = $_GET['version'] ?? $data['version'] ?? null;

        if (!$versionNumber) {
            $this->respond(['error' => 'Version number is required'], 400);
            return;
        }

        // TODO: Implement rollback for function-based versioning
        // Rollback now works at per-function level via CronJobFunctionVersionController
        $this->respond([
            'error' => 'Rollback not yet implemented for function-based versioning. Use CronJobFunctionVersion API to rollback individual functions.'
        ], 501); // 501 Not Implemented
    }

    /**
     * Handle statistics request
     * URL: GET /CronJob/stats/5
     */
    private function handleStats(int $jobId): void
    {
        $job = new ModelObject(CronJob);
        if (!$job->retrieve('id', $jobId) || !isset($job->id)) {
            $this->respond(['error' => 'Job not found'], 404);
            return;
        }

        // Get execution stats by time period
        $sql = "
            SELECT
                DATE(FROM_UNIXTIME(completed_at)) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END) as timeouts,
                AVG(CASE WHEN status = 'completed' THEN duration_ms ELSE NULL END) as avg_duration
            FROM CronJobExecution
            WHERE cron_job = ?
                AND completed_at IS NOT NULL
                AND completed_at >= UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
            GROUP BY DATE(FROM_UNIXTIME(completed_at))
            ORDER BY date DESC
        ";

        $dailyStats = DBI::prepared_query($sql, 'i', [$jobId]);

        // Get overall stats
        $overallSummary = $this->getExecutionSummary($jobId);

        $this->respond([
            'job_id' => $job->id,
            'job_name' => $job->name,
            'overall_summary' => $overallSummary,
            'daily_stats' => $dailyStats ?: [],
        ]);
    }

    /**
     * Create default functions for a new cron job
     *
     * Creates execute, setUp, and tearDown functions with default bodies.
     */
    private function createDefaultFunctions(int $jobId, ?int $userId): void
    {
        $job = new ModelObject(CronJob);
        if (!$job->retrieve('id', $jobId) || !isset($job->id)) {
            return;
        }

        $defaultFunctions = [
            'execute' => '$this->log("Job started");

// Add your job logic here
// Examples:
// - Database cleanup
// - Send scheduled emails
// - Generate reports
// - Process queued items

$this->log("Job completed");
return "Success";',

            'setUp' => '// Initialize resources here (optional)',

            'tearDown' => '// Cleanup resources here (optional)'
        ];

        foreach ($defaultFunctions as $functionName => $functionBody) {
            // Calculate content hash
            $contentHash = hash('sha256', $functionBody);

            // Check if content exists (unlikely for defaults, but check anyway)
            $existingContent = new ModelObject(CronJobFunctionContent);
            $contentFound = $existingContent->retrieve('content_hash', $contentHash);

            if (!$contentFound || !isset($existingContent->id)) {
                // Create content record
                $compressed = bzcompress($functionBody, 9);

                $contentData = [
                    'content_hash' => $contentHash,
                    'content' => $compressed,
                    'reference_count' => 1,
                    'created_by' => $userId,
                    'date_created' => time()
                ];

                $contentObj = new ModelObject(CronJobFunctionContent);
                $contentObj->create($contentData, $userId);
            } else {
                // Increment reference count
                $existingContent->reference_count++;
                $existingContent->save([
                    'reference_count' => $existingContent->reference_count
                ], $userId);
            }

            // Create function record
            $functionData = [
                'cron_job' => $jobId,
                'name' => $functionName,
                'content_hash' => $contentHash,
                'application' => $job->application,
                'kyte_account' => $job->kyte_account,
                'created_by' => $userId,
                'date_created' => time()
            ];

            $functionObj = new ModelObject(CronJobFunction);
            $functionObj->create($functionData, $userId);

            // Create initial version (version 1)
            $versionSql = "
                INSERT INTO CronJobFunctionVersion (
                    cron_job_function, version_number, content_hash, is_current,
                    change_description, created_by, date_created, deleted
                ) VALUES (?, 1, ?, 1, 'Initial version', ?, ?, 0)
            ";

            DBI::prepared_query($versionSql, 'isii', [
                $functionObj->id,
                $contentHash,
                $userId,
                time()
            ]);

            // Note: Default function created successfully
        }

        // Generate complete class code
        \Kyte\Cron\CronJobCodeGenerator::regenerateJobCode($jobId);

        // Note: Complete class generated successfully
    }

    /**
     * Send JSON response and exit
     */
    private function respond(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        $this->response = array_merge($this->response, $data);
    }
}
