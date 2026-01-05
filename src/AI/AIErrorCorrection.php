<?php

namespace Kyte\AI;

use Kyte\Core\ModelObject;
use Kyte\Core\Model;

/**
 * AI Error Correction Orchestrator
 *
 * Main entry point for AI-powered error correction system.
 * Called by ErrorHandler to queue errors for async AI analysis.
 *
 * @package Kyte\AI
 */
class AIErrorCorrection
{
    /**
     * Queue error for async AI analysis (non-blocking)
     *
     * Called by ErrorHandler after logging error to database.
     * Performs quick checks and queues error for analysis by cron job.
     *
     * @param ModelObject $error The KyteError object
     * @param object $apiContext The API context
     * @return void
     */
    public static function queueForAnalysis($error, $apiContext) {
        try {
            // Quick checks (should we analyze?)
            if (!self::shouldAnalyze($error, $apiContext)) {
                return;
            }

            // Generate error signature
            $signature = self::generateErrorSignature($error);

            // Extract controller and function names
            $controllerInfo = self::extractControllerInfo($error);

            // Check deduplication/cooldown
            if (self::isInCooldown($signature, $apiContext->appId, $apiContext->account->id)) {
                // Update occurrence count but don't create new analysis
                self::updateDeduplicationStats($signature, $apiContext->appId, $apiContext->account->id);
                return;
            }

            // Load configuration
            $config = self::getConfig($apiContext->appId, $apiContext->account->id);
            if (!$config) {
                error_log("AI Error Correction: No config found for application {$apiContext->appId}");
                return;
            }

            // Check rate limits
            if (!self::checkRateLimits($config, $apiContext->account->id)) {
                error_log("AI Error Correction: Rate limit exceeded for application {$apiContext->appId}");
                return;
            }

            // Get attempt number for this error signature
            $attemptNumber = self::getAttemptNumber($signature, $apiContext->appId, $apiContext->account->id);

            // Check if we've exceeded max attempts (loop detection prevention)
            if ($attemptNumber > $config->max_fix_attempts) {
                error_log("AI Error Correction: Max attempts ({$config->max_fix_attempts}) exceeded for signature {$signature}");
                return;
            }

            // Create analysis record in 'queued' status
            $analysis = new ModelObject(AIErrorAnalysis);
            $created = $analysis->create([
                'error_id' => $error->id,
                'error_signature' => $signature,
                'analysis_status' => 'queued',
                'queued_at' => time(),
                'controller_name' => $controllerInfo['controller'],
                'function_name' => $controllerInfo['function'],
                'controller_id' => $controllerInfo['controller_id'],
                'function_id' => $controllerInfo['function_id'],
                'attempt_number' => $attemptNumber,
                'application' => $apiContext->appId,
                'kyte_account' => $apiContext->account->id,
                'created_by' => null, // System-generated
                'date_created' => time(),
            ]);

            if (!$created) {
                error_log("AI Error Correction: Failed to create analysis record for error {$error->id}");
                return;
            }

            // Update deduplication tracking
            self::updateDeduplication($signature, $error, $controllerInfo, $apiContext->appId, $apiContext->account->id);

            error_log("AI Error Correction: Queued error {$error->id} for analysis (signature: {$signature})");

        } catch (\Exception $e) {
            // Don't throw - we don't want to break error handling
            error_log("AI Error Correction: Failed to queue analysis: " . $e->getMessage());
        }
    }

    /**
     * Check if error should be analyzed
     *
     * @param ModelObject $error The error object
     * @param object $apiContext The API context
     * @return bool
     */
    private static function shouldAnalyze($error, $apiContext) {
        // Only analyze application-level errors
        if ($error->log_type !== 'application') {
            return false;
        }

        // Check if application context exists
        if (!isset($apiContext->appId) || empty($apiContext->appId)) {
            return false;
        }

        // Load configuration
        $config = self::getConfig($apiContext->appId, $apiContext->account->id);
        if (!$config || !$config->enabled) {
            return false;
        }

        // Check log level
        if ($config->include_warnings) {
            // Analyze warnings, errors, and critical
            if (!in_array($error->log_level, ['warning', 'error', 'critical'])) {
                return false;
            }
        } else {
            // Only analyze error and critical levels
            if (!in_array($error->log_level, ['error', 'critical'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate error signature (SHA256 hash)
     *
     * Signature format: controller_name + function_name + error_message + file + line
     *
     * @param ModelObject $error The error object
     * @return string SHA256 hash
     */
    private static function generateErrorSignature($error) {
        $components = [
            $error->model ?? '',
            $error->message ?? '',
            $error->file ?? '',
            $error->line ?? '',
        ];

        $signatureString = implode('|', $components);
        return hash('sha256', $signatureString);
    }

    /**
     * Extract controller and function information from error
     *
     * @param ModelObject $error The error object
     * @return array ['controller' => string, 'function' => string|null, 'controller_id' => int|null, 'function_id' => int|null]
     */
    private static function extractControllerInfo($error) {
        $result = [
            'controller' => null,
            'function' => null,
            'controller_id' => null,
            'function_id' => null,
        ];

        // Try to extract from model field (controller name)
        if (!empty($error->model)) {
            $result['controller'] = $error->model;
        }

        // Try to extract from file path
        if (!empty($error->file)) {
            // Pattern: /path/to/Controller.php or Function code
            if (preg_match('/([A-Za-z0-9_]+)Controller\.php/', $error->file, $matches)) {
                $result['controller'] = $matches[1] . 'Controller';
            }
        }

        // Try to extract from stack trace
        if (!empty($error->trace)) {
            $lines = explode("\n", $error->trace);
            foreach ($lines as $line) {
                // Pattern: ClassName->methodName()
                if (preg_match('/([A-Za-z0-9_]+Controller)->([A-Za-z0-9_]+)\(/', $line, $matches)) {
                    $result['controller'] = $matches[1];
                    $result['function'] = $matches[2];
                    break;
                }
            }
        }

        // Try to find controller ID from database
        if ($result['controller']) {
            $controller = new ModelObject(Controller);
            if ($controller->retrieve('name', $result['controller'])) {
                $result['controller_id'] = $controller->id;

                // Try to find function ID
                if ($result['function']) {
                    $function = new ModelObject(constant("Function"));
                    if ($function->retrieve('controller', $controller->id, [
                        ['field' => 'type', 'value' => $result['function']]
                    ])) {
                        $result['function_id'] = $function->id;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Check if error signature is in cooldown period
     *
     * @param string $signature Error signature
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @return bool
     */
    private static function isInCooldown($signature, $appId, $accountId) {
        $dedup = new ModelObject(AIErrorDeduplication);
        if ($dedup->retrieve('error_signature', $signature, [
            ['field' => 'application', 'value' => $appId],
            ['field' => 'kyte_account', 'value' => $accountId],
        ])) {
            // Check if last analysis was within cooldown period
            if ($dedup->last_analyzed) {
                $config = self::getConfig($appId, $accountId);
                if ($config) {
                    $cooldownSeconds = $config->cooldown_minutes * 60;
                    $timeSinceLastAnalysis = time() - $dedup->last_analyzed;

                    if ($timeSinceLastAnalysis < $cooldownSeconds) {
                        return true; // Still in cooldown
                    }
                }
            }
        }

        return false;
    }

    /**
     * Update deduplication tracking
     *
     * @param string $signature Error signature
     * @param ModelObject $error The error object
     * @param array $controllerInfo Controller information
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @return void
     */
    private static function updateDeduplication($signature, $error, $controllerInfo, $appId, $accountId) {
        $dedup = new ModelObject(AIErrorDeduplication);

        if ($dedup->retrieve('error_signature', $signature, [
            ['field' => 'application', 'value' => $appId],
            ['field' => 'kyte_account', 'value' => $accountId],
        ])) {
            // Update existing record
            $dedup->save([
                'last_seen' => time(),
                'occurrence_count' => $dedup->occurrence_count + 1,
            ]);
        } else {
            // Create new record
            $dedup->create([
                'error_signature' => $signature,
                'controller_name' => $controllerInfo['controller'] ?? 'Unknown',
                'function_name' => $controllerInfo['function'],
                'error_message' => $error->message ?? '',
                'error_file' => $error->file ?? '',
                'error_line' => $error->line ?? 0,
                'first_seen' => time(),
                'last_seen' => time(),
                'occurrence_count' => 1,
                'analysis_count' => 0,
                'application' => $appId,
                'kyte_account' => $accountId,
            ]);
        }
    }

    /**
     * Update deduplication stats without analyzing
     *
     * @param string $signature Error signature
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @return void
     */
    private static function updateDeduplicationStats($signature, $appId, $accountId) {
        $dedup = new ModelObject(AIErrorDeduplication);

        if ($dedup->retrieve('error_signature', $signature, [
            ['field' => 'application', 'value' => $appId],
            ['field' => 'kyte_account', 'value' => $accountId],
        ])) {
            $dedup->save([
                'last_seen' => time(),
                'occurrence_count' => $dedup->occurrence_count + 1,
            ]);
        }
    }

    /**
     * Get configuration for application
     *
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @return ModelObject|null Configuration object or null
     */
    public static function getConfig($appId, $accountId) {
        $config = new ModelObject(AIErrorCorrectionConfig);

        if ($config->retrieve('application', $appId, [
            ['field' => 'kyte_account', 'value' => $accountId],
        ])) {
            return $config;
        }

        return null;
    }

    /**
     * Check rate limits
     *
     * @param ModelObject $config Configuration object
     * @param int $accountId Account ID
     * @return bool True if within limits
     */
    private static function checkRateLimits($config, $accountId) {
        // Check hourly limit
        $hourAgo = time() - 3600;
        $hourlyCount = self::getAnalysisCount($config->application, $accountId, $hourAgo);

        if ($hourlyCount >= $config->max_analyses_per_hour) {
            return false;
        }

        // Check daily limit
        $dayAgo = time() - 86400;
        $dailyCount = self::getAnalysisCount($config->application, $accountId, $dayAgo);

        if ($dailyCount >= $config->max_analyses_per_day) {
            return false;
        }

        // Check monthly cost budget
        $monthAgo = time() - (30 * 86400);
        $monthlyCost = self::getTotalCost($config->application, $accountId, $monthAgo);

        if ($monthlyCost >= $config->max_monthly_cost_usd) {
            error_log("AI Error Correction: Monthly budget exceeded ({$monthlyCost} >= {$config->max_monthly_cost_usd})");
            return false;
        }

        return true;
    }

    /**
     * Get analysis count since timestamp
     *
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @param int $since Unix timestamp
     * @return int Count
     */
    private static function getAnalysisCount($appId, $accountId, $since) {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('application', $appId, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'date_created', 'value' => $since, 'operator' => '>='],
        ]);

        return $model->count();
    }

    /**
     * Get total cost since timestamp
     *
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @param int $since Unix timestamp
     * @return float Total cost in USD
     */
    private static function getTotalCost($appId, $accountId, $since) {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('application', $appId, false, [
            ['field' => 'kyte_account', 'value' => $accountId],
            ['field' => 'date_created', 'value' => $since, 'operator' => '>='],
        ]);

        $total = 0.0;
        foreach ($model->objects as $analysis) {
            if ($analysis->estimated_cost_usd) {
                $total += floatval($analysis->estimated_cost_usd);
            }
        }

        return $total;
    }

    /**
     * Get attempt number for error signature
     *
     * @param string $signature Error signature
     * @param int $appId Application ID
     * @param int $accountId Account ID
     * @return int Attempt number (1-based)
     */
    private static function getAttemptNumber($signature, $appId, $accountId) {
        $model = new Model(AIErrorAnalysis);
        $model->retrieve('error_signature', $signature, false, [
            ['field' => 'application', 'value' => $appId],
            ['field' => 'kyte_account', 'value' => $accountId],
        ], false, [['field' => 'attempt_number', 'direction' => 'DESC']], 1);

        if ($model->count() > 0) {
            return $model->objects[0]->attempt_number + 1;
        }

        return 1;
    }
}
