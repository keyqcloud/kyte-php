<?php

namespace Kyte\Core;

/**
 * Class Logger
 *
 * PSR-3 compatible structured logging for Kyte-PHP
 * Provides static methods for logging at different severity levels
 *
 * @package Kyte\Core
 */
class Logger
{
    const DEBUG = 'debug';
    const INFO = 'info';
    const WARNING = 'warning';
    const ERROR = 'error';
    const CRITICAL = 'critical';

    private static $apiContext = null;
    private static $requestId = null;
    private static $enabled = false;

    /**
     * Initialize the logger with API context
     *
     * @param Api $apiContext The API context instance
     */
    public static function init($apiContext)
    {
        self::$apiContext = $apiContext;
        self::$enabled = defined('KYTE_LOGGER_ENABLED') && KYTE_LOGGER_ENABLED;

        // Generate unique request ID if not already set
        if (self::$requestId === null) {
            self::$requestId = self::generateRequestId();
        }
    }

    /**
     * Generate a unique request ID
     *
     * @return string Unique request identifier
     */
    private static function generateRequestId()
    {
        return uniqid('req_', true) . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Get current request ID
     *
     * @return string|null
     */
    public static function getRequestId()
    {
        return self::$requestId;
    }

    /**
     * Debug level logging (detailed diagnostic information)
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public static function debug($message, array $context = [])
    {
        return self::log(self::DEBUG, $message, $context);
    }

    /**
     * Info level logging (general informational messages)
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public static function info($message, array $context = [])
    {
        return self::log(self::INFO, $message, $context);
    }

    /**
     * Warning level logging (non-critical issues)
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public static function warning($message, array $context = [])
    {
        return self::log(self::WARNING, $message, $context);
    }

    /**
     * Error level logging (runtime errors)
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public static function error($message, array $context = [])
    {
        return self::log(self::ERROR, $message, $context);
    }

    /**
     * Critical level logging (critical conditions)
     *
     * @param string $message Log message
     * @param array $context Additional context data
     * @return bool Success status
     */
    public static function critical($message, array $context = [])
    {
        return self::log(self::CRITICAL, $message, $context);
    }

    /**
     * Core logging method
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context data
     * @return bool Success status
     */
    private static function log($level, $message, array $context = [])
    {
        // Check if logger is enabled
        if (!self::$enabled) {
            return false;
        }

        // Check if this log level should be captured based on LOG_LEVEL constant
        if (!self::shouldLog($level)) {
            return false;
        }

        try {
            $error = new ModelObject('KyteError');

            // Get caller information for file/line
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = isset($backtrace[2]) ? $backtrace[2] : $backtrace[0];

            $log_detail = [
                'account_id' => isset(self::$apiContext->account->id) ? self::$apiContext->account->id : null,
                'user_id' => isset(self::$apiContext->user->id) ? self::$apiContext->user->id : null,
                'app_id' => isset(self::$apiContext->appId) ? self::$apiContext->appId : null,
                'api_key' => isset(self::$apiContext->key) ? self::$apiContext->key : null,
                'model' => isset(self::$apiContext->model) ? self::$apiContext->model : null,
                'request' => isset(self::$apiContext->request) ? self::$apiContext->request : null,
                'message' => $message,
                'file' => isset($caller['file']) ? $caller['file'] : null,
                'line' => isset($caller['line']) ? $caller['line'] : null,
                'log_level' => $level,
                'log_type' => (isset(self::$apiContext->appId) && strlen(self::$apiContext->appId) > 0) ? 'application' : 'system',
                'context' => count($context) > 0 ? json_encode($context) : null,
                'request_id' => self::$requestId,
                'trace' => self::getStackTrace(),
                'source' => 'logger',
                'data' => isset(self::$apiContext->data) ? print_r(self::$apiContext->data, true) : null,
            ];

            if (!$error->create($log_detail)) {
                error_log("LOGGER: Failed to create log entry for " . print_r($log_detail, true));
                return false;
            }

            // Send Slack notification for errors and critical logs
            if (in_array($level, [self::ERROR, self::CRITICAL])) {
                self::sendSlackNotification($log_detail);
            }

            return true;
        } catch (\Exception $e) {
            error_log("LOGGER: Exception during logging: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a log level should be captured based on configuration
     *
     * @param string $level Log level to check
     * @return bool Whether to log this level
     */
    private static function shouldLog($level)
    {
        if (!defined('LOG_LEVEL')) {
            return true; // Default: log everything
        }

        $configLevel = LOG_LEVEL;

        // Define level hierarchy
        $levels = [
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'error' => 3,
            'critical' => 4,
        ];

        $currentLevelValue = isset($levels[$level]) ? $levels[$level] : 0;
        $configLevelValue = isset($levels[$configLevel]) ? $levels[$configLevel] : 0;

        return $currentLevelValue >= $configLevelValue;
    }

    /**
     * Get formatted stack trace
     *
     * @return string Formatted stack trace
     */
    private static function getStackTrace()
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $formatted = [];

        foreach ($trace as $index => $frame) {
            $file = isset($frame['file']) ? $frame['file'] : 'unknown';
            $line = isset($frame['line']) ? $frame['line'] : 0;
            $function = isset($frame['function']) ? $frame['function'] : 'unknown';
            $class = isset($frame['class']) ? $frame['class'] : '';
            $type = isset($frame['type']) ? $frame['type'] : '';

            $formatted[] = "#{$index} {$file}({$line}): {$class}{$type}{$function}()";
        }

        return implode("\n", $formatted);
    }

    /**
     * Send Slack notification for error/critical logs
     *
     * @param array $log_detail Log details
     */
    private static function sendSlackNotification($log_detail)
    {
        $slackMessage = "Log [{$log_detail['log_level']}]:\n" . print_r($log_detail, true);

        // Platform level notification
        if (defined('SLACK_ERROR_WEBHOOK')) {
            self::sendSlackWebhook(SLACK_ERROR_WEBHOOK, $slackMessage);
        }

        // Application level notification
        if (self::$apiContext && self::$apiContext->appId && isset(self::$apiContext->app->slack_error_webhook)) {
            self::sendSlackWebhook(self::$apiContext->app->slack_error_webhook, $slackMessage);
        }
    }

    /**
     * Send webhook to Slack
     *
     * @param string $webhookUrl Slack webhook URL
     * @param string $message Message to send
     */
    private static function sendSlackWebhook($webhookUrl, $message)
    {
        $payload = json_encode(['text' => $message]);
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload)
        ]);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('Logger Slack notification error: ' . curl_error($ch));
        }
        curl_close($ch);
    }
}
