<?php

namespace Kyte\Mvc\Controller;

use Kyte\Core\Model;
use Kyte\Core\DBI;

/**
 * Controller for CronJobExecution model
 *
 * Provides read-only access to job execution history with filtering,
 * searching, and statistics.
 */
class CronJobExecutionController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
        // Executions should not be directly created/updated/deleted by users
        $this->allowableActions = ['get'];
    }

    /**
     * Override get() to handle custom actions via URL path routing
     *
     * Routes:
     * - GET /CronJobExecution/recent/{job_id}?limit=20
     * - GET /CronJobExecution/failed/{job_id}?days=7 (job_id optional)
     * - GET /CronJobExecution/running
     * - GET /CronJobExecution/pending?limit=50
     * - GET /CronJobExecution/statistics/{job_id}?days=30 (job_id optional)
     */
    public function get($field, $value)
    {
        // Check if this is a custom action
        $action = $field;
        $actionId = $value; // May be null for some actions

        if ($action) {
            switch ($action) {
                case 'recent':
                    return $this->handleRecent($actionId);

                case 'failed':
                    return $this->handleFailed($actionId);

                case 'running':
                    return $this->handleRunning();

                case 'pending':
                    return $this->handlePending();

                case 'statistics':
                    return $this->handleStatistics($actionId);

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

                // Add parent execution info if retry
                if (!empty($r['parent_execution'])) {
                    $r['parent_execution_info'] = $this->getExecutionInfo($r['parent_execution']);
                }

                // Add dependency execution info
                if (!empty($r['dependency_execution'])) {
                    $r['dependency_execution_info'] = $this->getExecutionInfo($r['dependency_execution']);
                }

                // Calculate duration if not set (for running jobs)
                if ($r['status'] === 'running' && !empty($r['started_at'])) {
                    $r['elapsed_ms'] = (time() - $r['started_at']) * 1000;
                }

                // Format output/error for readability
                $this->formatOutputFields($r);
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
        $sql = "SELECT id, name, schedule_type, enabled, in_dead_letter_queue FROM CronJob WHERE id = ?";
        $result = DBI::prepared_query($sql, 'i', [$jobId]);

        if (empty($result)) {
            return ['id' => $jobId, 'name' => 'Unknown'];
        }

        return $result[0];
    }

    /**
     * Get basic execution information
     */
    private function getExecutionInfo(int $executionId): array
    {
        $sql = "SELECT id, status, completed_at, duration_ms FROM CronJobExecution WHERE id = ?";
        $result = DBI::prepared_query($sql, 'i', [$executionId]);

        if (empty($result)) {
            return ['id' => $executionId, 'status' => 'unknown'];
        }

        return $result[0];
    }

    /**
     * Format output and error fields for better readability
     */
    private function formatOutputFields(array &$r): void
    {
        // Truncate very long output/error in list views
        // Full content is available when getting single execution
        if (isset($r['output']) && strlen($r['output']) > 10000) {
            $r['output_truncated'] = true;
            $r['output_length'] = strlen($r['output']);
        }

        if (isset($r['error']) && strlen($r['error']) > 5000) {
            $r['error_truncated'] = true;
            $r['error_length'] = strlen($r['error']);
        }
    }

    /**
     * Handle: Get recent executions for a job
     *
     * GET /CronJobExecution/recent/{job_id}?limit=20
     */
    private function handleRecent($jobId): void
    {
        if (!$jobId) {
            $this->respond(['error' => 'job_id is required in URL path'], 400);
            return;
        }

        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $limit = min($limit, 100); // Max 100

        $sql = "
            SELECT *
            FROM CronJobExecution
            WHERE cron_job = ?
            ORDER BY date_created DESC
            LIMIT ?
        ";

        $executions = DBI::prepared_query($sql, 'ii', [(int)$jobId, $limit]);

        // Add job info to each execution
        foreach ($executions as &$execution) {
            $execution['job'] = $this->getJobInfo($execution['cron_job']);

            // Truncate large fields
            if (isset($execution['output']) && strlen($execution['output']) > 1000) {
                $execution['output'] = substr($execution['output'], 0, 1000) . '... (truncated)';
                $execution['output_truncated'] = true;
            }

            if (isset($execution['error']) && strlen($execution['error']) > 1000) {
                $execution['error'] = substr($execution['error'], 0, 1000) . '... (truncated)';
                $execution['error_truncated'] = true;
            }
        }

        $this->respond([
            'job_id' => (int)$jobId,
            'count' => count($executions),
            'limit' => $limit,
            'executions' => $executions
        ]);
    }

    /**
     * Handle: Get failed executions
     *
     * GET /CronJobExecution/failed/{job_id}?days=7
     * GET /CronJobExecution/failed?days=7 (all jobs)
     */
    private function handleFailed($jobId): void
    {
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
        $days = min($days, 30); // Max 30 days

        $cutoffTime = time() - ($days * 24 * 60 * 60);

        if ($jobId) {
            $sql = "
                SELECT *
                FROM CronJobExecution
                WHERE cron_job = ? AND status IN ('failed', 'timeout')
                    AND date_created > ?
                ORDER BY date_created DESC
            ";
            $executions = DBI::prepared_query($sql, 'ii', [(int)$jobId, $cutoffTime]);
        } else {
            // All jobs
            $sql = "
                SELECT *
                FROM CronJobExecution
                WHERE status IN ('failed', 'timeout')
                    AND date_created > ?
                ORDER BY date_created DESC
                LIMIT 100
            ";
            $executions = DBI::prepared_query($sql, 'i', [$cutoffTime]);
        }

        // Add job info and truncate output
        foreach ($executions as &$execution) {
            $execution['job'] = $this->getJobInfo($execution['cron_job']);

            if (isset($execution['error']) && strlen($execution['error']) > 500) {
                $execution['error'] = substr($execution['error'], 0, 500) . '... (truncated)';
                $execution['error_truncated'] = true;
            }
        }

        $this->respond([
            'job_id' => $jobId ? (int)$jobId : null,
            'days' => $days,
            'count' => count($executions),
            'executions' => $executions
        ]);
    }

    /**
     * Handle: Get running executions
     *
     * GET /CronJobExecution/running
     */
    private function handleRunning(): void
    {
        $sql = "
            SELECT *
            FROM CronJobExecution
            WHERE status = 'running'
            ORDER BY started_at DESC
        ";

        $executions = DBI::query($sql);

        // Add job info and calculate elapsed time
        $now = time();
        foreach ($executions as &$execution) {
            $execution['job'] = $this->getJobInfo($execution['cron_job']);

            if (!empty($execution['started_at'])) {
                $execution['elapsed_seconds'] = $now - $execution['started_at'];
                $execution['elapsed_ms'] = $execution['elapsed_seconds'] * 1000;
            }

            // Check if lease expired (stale lock)
            if (!empty($execution['locked_until']) && $execution['locked_until'] < $now) {
                $execution['lease_expired'] = true;
                $execution['lease_expired_seconds_ago'] = $now - $execution['locked_until'];
            }
        }

        $this->respond([
            'count' => count($executions),
            'executions' => $executions
        ]);
    }

    /**
     * Handle: Get pending executions
     *
     * GET /CronJobExecution/pending?limit=50
     */
    private function handlePending(): void
    {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $limit = min($limit, 200); // Max 200

        $sql = "
            SELECT *
            FROM CronJobExecution
            WHERE status = 'pending'
            ORDER BY next_run_time ASC
            LIMIT ?
        ";

        $executions = DBI::prepared_query($sql, 'i', [$limit]);

        // Add job info
        foreach ($executions as &$execution) {
            $execution['job'] = $this->getJobInfo($execution['cron_job']);

            // Calculate time until execution
            if (!empty($execution['next_run_time'])) {
                $execution['seconds_until_run'] = max(0, $execution['next_run_time'] - time());
            }
        }

        $this->respond([
            'count' => count($executions),
            'limit' => $limit,
            'executions' => $executions
        ]);
    }

    /**
     * Handle: Get execution statistics
     *
     * GET /CronJobExecution/statistics/{job_id}?days=30
     * GET /CronJobExecution/statistics?days=30 (system-wide)
     */
    private function handleStatistics($jobId): void
    {
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
        $days = min($days, 365); // Max 1 year

        $cutoffTime = time() - ($days * 24 * 60 * 60);

        if ($jobId) {
            $sql = "
                SELECT
                    COUNT(*) as total_executions,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END) as timeouts,
                    SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
                    AVG(CASE WHEN status = 'completed' THEN duration_ms ELSE NULL END) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms,
                    MIN(CASE WHEN status = 'completed' AND duration_ms > 0 THEN duration_ms ELSE NULL END) as min_duration_ms,
                    AVG(CASE WHEN status = 'completed' THEN memory_peak_mb ELSE NULL END) as avg_memory_mb,
                    MAX(memory_peak_mb) as max_memory_mb
                FROM CronJobExecution
                WHERE cron_job = ? AND date_created > ?
            ";
            $stats = DBI::prepared_query($sql, 'ii', [(int)$jobId, $cutoffTime]);
        } else {
            // System-wide stats
            $sql = "
                SELECT
                    COUNT(*) as total_executions,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'timeout' THEN 1 ELSE 0 END) as timeouts,
                    SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped,
                    AVG(CASE WHEN status = 'completed' THEN duration_ms ELSE NULL END) as avg_duration_ms,
                    MAX(duration_ms) as max_duration_ms,
                    AVG(CASE WHEN status = 'completed' THEN memory_peak_mb ELSE NULL END) as avg_memory_mb,
                    MAX(memory_peak_mb) as max_memory_mb
                FROM CronJobExecution
                WHERE date_created > ?
            ";
            $stats = DBI::prepared_query($sql, 'i', [$cutoffTime]);
        }

        $stats = $stats[0];

        // Calculate success rate
        $total = (int)$stats['total_executions'];
        $successful = (int)$stats['successful'];
        $stats['success_rate'] = $total > 0 ? round(($successful / $total) * 100, 2) : 0;

        $this->respond([
            'job_id' => $jobId ? (int)$jobId : null,
            'period_days' => $days,
            'statistics' => $stats
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
