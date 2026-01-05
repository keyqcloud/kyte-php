<?php

namespace Kyte\AI;

use Kyte\Core\ModelObject;
use Kyte\Core\Model;

/**
 * AI Loop Detector
 *
 * Detects infinite fix loops using multiple strategies:
 * 1. Same error recurring after fix applied
 * 2. N consecutive fixes without resolution (threshold: 5)
 * 3. Error count increasing after fix
 *
 * Auto-disables auto-fix mode if loop detected.
 *
 * @package Kyte\AI
 */
class AILoopDetector
{
    /**
     * Detect if error is in a loop
     *
     * @param string $errorSignature Error signature hash
     * @param int $applicationId Application ID
     * @param int $accountId Account ID
     * @return array|null Loop info if detected, null otherwise
     */
    public static function detectLoop($errorSignature, $applicationId, $accountId) {
        $config = AIErrorCorrection::getConfig($applicationId, $accountId);
        if (!$config) {
            return null;
        }

        // Strategy 1: Check if same error recurring after recent fix
        $recentFix = self::getRecentFix($errorSignature, $applicationId, $accountId);
        if ($recentFix) {
            $windowStart = $recentFix['applied_at'];
            $windowEnd = $windowStart + ($config->loop_detection_window_minutes * 60);

            if (self::hasErrorRecurred($errorSignature, $applicationId, $accountId, $windowStart, $windowEnd)) {
                return [
                    'type' => 'recurring_error',
                    'message' => 'Same error recurring after fix was applied',
                    'last_fix_id' => $recentFix['id'],
                    'last_fix_applied_at' => $recentFix['applied_at'],
                ];
            }
        }

        // Strategy 2: Check consecutive fix attempts
        $consecutiveFixes = self::countConsecutiveFixes($errorSignature, $applicationId, $accountId);
        if ($consecutiveFixes >= $config->max_fix_attempts) {
            return [
                'type' => 'max_attempts_exceeded',
                'message' => "Reached max fix attempts ({$consecutiveFixes} >= {$config->max_fix_attempts})",
                'consecutive_fixes' => $consecutiveFixes,
            ];
        }

        // Strategy 3: Check if error count is increasing
        if ($recentFix && self::isErrorCountIncreasing($errorSignature, $applicationId, $accountId, $recentFix['applied_at'])) {
            return [
                'type' => 'increasing_errors',
                'message' => 'Error occurrence count increasing after fix',
                'last_fix_id' => $recentFix['id'],
            ];
        }

        return null; // No loop detected
    }

    /**
     * Get most recent fix for error signature
     *
     * @param string $errorSignature Error signature
     * @param int $applicationId Application ID
     * @param int $accountId Account ID
     * @return array|null Fix info or null
     */
    private static function getRecentFix($errorSignature, $applicationId, $accountId) {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('error_signature', $errorSignature, false, [
            ['field' => 'application', 'value' => $applicationId],
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'fix_status', 'value' => 'applied_auto'],
        ], false, [['field' => 'applied_at', 'direction' => 'DESC']], 1);

        if ($model->count() > 0) {
            $fix = $model->objects[0];
            return [
                'id' => $fix->id,
                'applied_at' => $fix->applied_at,
            ];
        }

        return null;
    }

    /**
     * Check if error has recurred after fix
     *
     * @param string $errorSignature Error signature
     * @param int $applicationId Application ID
     * @param int $accountId Account ID
     * @param int $windowStart Window start timestamp
     * @param int $windowEnd Window end timestamp
     * @return bool True if error recurred
     */
    private static function hasErrorRecurred($errorSignature, $applicationId, $accountId, $windowStart, $windowEnd) {
        // Count errors with same signature that occurred after fix was applied
        $errorModel = new Model(KyteError);

        // Get all analyses with this signature to get error IDs
        $analysisModel = new Model(AIErrorAnalysis);
        $analysisModel->retrieve('error_signature', $errorSignature, false, [
            ['field' => 'application', 'value' => $applicationId],
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'date_created', 'value' => $windowStart, 'operator' => '>='],
            ['field' => 'date_created', 'value' => $windowEnd, 'operator' => '<='],
        ]);

        return $analysisModel->count() > 0;
    }

    /**
     * Count consecutive fixes for error signature
     *
     * @param string $errorSignature Error signature
     * @param int $applicationId Application ID
     * @param int $accountId Account ID
     * @return int Count of consecutive fixes
     */
    private static function countConsecutiveFixes($errorSignature, $applicationId, $accountId) {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('error_signature', $errorSignature, false, [
            ['field' => 'application', 'value' => $applicationId],
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'fix_status', 'value' => 'applied_auto'],
        ], false, [['field' => 'applied_at', 'direction' => 'DESC']]);

        return $model->count();
    }

    /**
     * Check if error count is increasing after fix
     *
     * @param string $errorSignature Error signature
     * @param int $applicationId Application ID
     * @param int $accountId Account ID
     * @param int $fixAppliedAt Timestamp when fix was applied
     * @return bool True if error count increasing
     */
    private static function isErrorCountIncreasing($errorSignature, $applicationId, $accountId, $fixAppliedAt) {
        $dedup = new ModelObject(AIErrorDeduplication);

        if ($dedup->retrieve('error_signature', $errorSignature, [
            ['field' => 'application', 'value' => $applicationId],
            ['field' => 'kyte_account', 'value' => $accountId],
        ])) {
            // Get occurrence count before fix (approximation based on when it was first seen)
            $hourBeforeFix = $fixAppliedAt - 3600;
            $countBefore = $dedup->occurrence_count;

            // Get occurrences since fix applied
            $analysisModel = new Model(AIErrorAnalysis);
            $analysisModel->retrieve('error_signature', $errorSignature, false, [
                ['field' => 'application', 'value' => $applicationId],
                ['field' => 'kyte_account', 'value' => $accountId],
                ['field' => 'date_created', 'value' => $fixAppliedAt, 'operator' => '>='],
            ]);

            $countAfter = $analysisModel->count();

            // If more errors after fix than before, it's increasing
            // This is a simple heuristic - could be improved
            if ($countAfter > ($countBefore / 2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Disable auto-fix for application
     *
     * @param int $applicationId Application ID
     * @param int $accountId Account ID
     * @param array $loopInfo Loop detection info
     * @return void
     */
    public static function disableAutoFix($applicationId, $accountId, $loopInfo) {
        $config = AIErrorCorrection::getConfig($applicationId, $accountId);
        if (!$config) {
            return;
        }

        // Disable auto-fix if enabled
        if ($config->auto_fix_enabled) {
            $config->save([
                'auto_fix_enabled' => 0,
            ]);

            error_log("AI Loop Detected - Auto-fix disabled for application {$applicationId}: " . $loopInfo['message']);

            // Send notification (placeholder for future)
            self::notifyLoopDetected($applicationId, $accountId, $loopInfo);
        }
    }

    /**
     * Send notification about loop detection (PLACEHOLDER for future)
     *
     * @param int $applicationId Application ID
     * @param int $accountId Account ID
     * @param array $loopInfo Loop detection info
     * @return void
     */
    private static function notifyLoopDetected($applicationId, $accountId, $loopInfo) {
        // FUTURE: Send email/Slack notification
        // For now, just log it
        error_log("AI Loop Notification: App {$applicationId}, Type: {$loopInfo['type']}, Message: {$loopInfo['message']}");

        // Could send Slack webhook if configured
        $config = AIErrorCorrection::getConfig($applicationId, $accountId);
        if ($config && $config->notification_slack_webhook) {
            // TODO: Send Slack notification in future version
        }
    }
}
