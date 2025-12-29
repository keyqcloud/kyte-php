<?php

namespace Kyte\Exception;

class ErrorHandler
{
    private static $instance = null;
    private $apiContext;
    private $requestId;
    private $outputBufferActive = false;

    // Private constructor to prevent direct instantiation
    private function __construct($apiContext) {
        $this->apiContext = $apiContext;
        $this->requestId = $this->generateRequestId();
    }

    // Method to get the single instance of the class
    public static function getInstance($apiContext = null) {
        if (self::$instance === null) {
            if ($apiContext === null) {
                throw new \Exception("API context required for the first initialization of ErrorHandler.");
            }
            self::$instance = new self($apiContext);
        }
        return self::$instance;
    }

    /**
     * Generate unique request ID
     */
    private function generateRequestId() {
        return uniqid('req_', true) . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Get current request ID
     */
    public function getRequestId() {
        return $this->requestId;
    }

    /**
     * Handle exceptions
     */
    public function handleException($exception) {
        $error = new \Kyte\Core\ModelObject(KyteError);

        $log_detail = [
            'account_id' => isset($this->apiContext->account->id) ? $this->apiContext->account->id : null,
            'user_id' => isset($this->apiContext->user->id) ? $this->apiContext->user->id : null,
            'app_id' => isset($this->apiContext->appId) ? $this->apiContext->appId : null,
            'api_key' => isset($this->apiContext->key) ? $this->apiContext->key : null,
            // Additional details
            'signature' => isset($this->apiContext->signature) ? $this->apiContext->signature : null,
            'contentType' => isset($this->apiContext->contentType) ? $this->apiContext->contentType : null,
            'request' => isset($this->apiContext->request) ? $this->apiContext->request : null,
            'model' => isset($this->apiContext->model) ? $this->apiContext->model : null,
            'field' => isset($this->apiContext->field) ? $this->apiContext->field : null,
            'value' => isset($this->apiContext->value) ? $this->apiContext->value : null,
            'data' => isset($this->apiContext->data) ? print_r($this->apiContext->data, true) : null,
            'response' => isset($this->apiContext->response) ? print_r($this->apiContext->response, true) : null,
            // Exception details
            'message' => $exception->getMessage(),
            'line' => $exception->getLine(),
            'file' => $exception->getFile(),
            // Enhanced logging fields
            'log_level' => 'critical',
            'log_type' => (isset($this->apiContext->appId) && strlen($this->apiContext->appId) > 0) ? 'application' : 'system',
            'request_id' => $this->requestId,
            'trace' => $exception->getTraceAsString(),
            'source' => 'exception_handler',
            'context' => json_encode([
                'exception_class' => get_class($exception),
                'code' => $exception->getCode(),
            ]),
        ];

        if (!$error->create($log_detail)) {
            error_log("FATAL: Failed to create log entry for " . print_r($log_detail, true));
        }

        $slackMessage = "Exception encountered:\n" . print_r($log_detail, true);
        // Platform level notification
        if (defined('SLACK_ERROR_WEBHOOK')) {
            $this->sendSlackNotification(SLACK_ERROR_WEBHOOK, $slackMessage);
        }
        // Application level notification
        if ($this->apiContext->appId && isset($this->apiContext->app->slack_error_webhook)) {
            $this->sendSlackNotification($this->apiContext->app->slack_error_webhook, $slackMessage);
        }
    }

    /**
     * Handle PHP errors
     */
    public function handleError($errno, $errstr, $errfile, $errline) {
        // Check if this error level should be logged based on configuration
        if (!$this->shouldLogError($errno)) {
            return false; // Continue with default PHP error handler
        }

        // Map PHP error constants to log levels
        $log_level = $this->mapErrorToLogLevel($errno);

        $error = new \Kyte\Core\ModelObject(KyteError);

        $log_detail = [
            'account_id' => isset($this->apiContext->account->id) ? $this->apiContext->account->id : null,
            'user_id' => isset($this->apiContext->user->id) ? $this->apiContext->user->id : null,
            'app_id' => isset($this->apiContext->appId) ? $this->apiContext->appId : null,
            'api_key' => isset($this->apiContext->key) ? $this->apiContext->key : null,
            'request' => isset($this->apiContext->request) ? $this->apiContext->request : null,
            'model' => isset($this->apiContext->model) ? $this->apiContext->model : null,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            // Enhanced logging fields
            'log_level' => $log_level,
            'log_type' => (isset($this->apiContext->appId) && strlen($this->apiContext->appId) > 0) ? 'application' : 'system',
            'request_id' => $this->requestId,
            'trace' => $this->getStackTrace(),
            'source' => 'error_handler',
            'context' => json_encode([
                'error_type' => $this->getErrorTypeName($errno),
                'error_code' => $errno,
            ]),
        ];

        if (!$error->create($log_detail)) {
            error_log("FATAL: Failed to create log entry for " . print_r($log_detail, true));
        }

        // Send Slack notifications for errors and critical issues
        if (in_array($log_level, ['error', 'critical'])) {
            $slackMessage = "Error encountered:\n" . print_r($log_detail, true);
            if (defined('SLACK_ERROR_WEBHOOK')) {
                $this->sendSlackNotification(SLACK_ERROR_WEBHOOK, $slackMessage);
            }
            if ($this->apiContext->appId && isset($this->apiContext->app->slack_error_webhook)) {
                $this->sendSlackNotification($this->apiContext->app->slack_error_webhook, $slackMessage);
            }
        }

        return true; // Don't execute PHP internal error handler
    }

    /**
     * Check if error should be logged based on LOG_LEVEL configuration
     */
    private function shouldLogError($errno) {
        if (!defined('LOG_LEVEL')) {
            // Default: only log errors (backward compatible)
            return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]);
        }

        $logLevel = LOG_LEVEL;

        switch ($logLevel) {
            case 'error':
                // Only critical errors
                return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]);

            case 'warning':
                // Errors + warnings
                return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR,
                                         E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING]);

            case 'notice':
                // Errors + warnings + notices
                return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR,
                                         E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING,
                                         E_NOTICE, E_USER_NOTICE]);

            case 'all':
                // Everything including deprecated
                return true;

            default:
                // Fallback to error only
                return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR]);
        }
    }

    /**
     * Map PHP error code to log level
     */
    private function mapErrorToLogLevel($errno) {
        switch ($errno) {
            case E_ERROR:
            case E_PARSE:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
                return 'critical';

            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return 'warning';

            case E_NOTICE:
            case E_USER_NOTICE:
                return 'info';

            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'warning';

            case E_STRICT:
                return 'info';

            default:
                return 'error';
        }
    }

    /**
     * Get human-readable error type name
     */
    private function getErrorTypeName($errno) {
        $errorTypes = [
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
        ];

        return isset($errorTypes[$errno]) ? $errorTypes[$errno] : 'UNKNOWN';
    }

    /**
     * Get stack trace as string
     */
    private function getStackTrace() {
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
     * Start output buffering to capture echo/print statements
     */
    public function startOutputBuffering() {
        if (!defined('LOG_OUTPUT_BUFFERING') || !LOG_OUTPUT_BUFFERING) {
            return false;
        }

        $this->outputBufferActive = true;
        ob_start([$this, 'outputBufferCallback']);
        return true;
    }

    /**
     * Output buffer callback - captures unexpected output
     */
    public function outputBufferCallback($buffer) {
        // Only log if buffer contains content and is not expected output
        if (!empty($buffer) && defined('LOG_OUTPUT_BUFFERING_THRESHOLD')) {
            $threshold = LOG_OUTPUT_BUFFERING_THRESHOLD;

            if (strlen($buffer) > $threshold) {
                $error = new \Kyte\Core\ModelObject(KyteError);

                $log_detail = [
                    'account_id' => isset($this->apiContext->account->id) ? $this->apiContext->account->id : null,
                    'user_id' => isset($this->apiContext->user->id) ? $this->apiContext->user->id : null,
                    'app_id' => isset($this->apiContext->appId) ? $this->apiContext->appId : null,
                    'request' => isset($this->apiContext->request) ? $this->apiContext->request : null,
                    'model' => isset($this->apiContext->model) ? $this->apiContext->model : null,
                    'message' => 'Unexpected output captured via output buffering',
                    'log_level' => 'warning',
                    'log_type' => (isset($this->apiContext->appId) && strlen($this->apiContext->appId) > 0) ? 'application' : 'system',
                    'request_id' => $this->requestId,
                    'source' => 'output_buffer',
                    'data' => substr($buffer, 0, 5000), // Truncate to prevent huge logs
                    'context' => json_encode([
                        'buffer_length' => strlen($buffer),
                        'truncated' => strlen($buffer) > 5000,
                    ]),
                ];

                $error->create($log_detail);
            }
        }

        return $buffer; // Return buffer for output
    }

    /**
     * Stop output buffering
     */
    public function stopOutputBuffering() {
        if ($this->outputBufferActive) {
            ob_end_flush();
            $this->outputBufferActive = false;
        }
    }

    /**
     * Send Slack notification
     */
    private function sendSlackNotification($webhookUrl, $message) {
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
            error_log('Slack notification error: ' . curl_error($ch));
        }
        curl_close($ch);
    }

    /**
     * Register error and exception handlers
     */
    public function register() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);

        // Start output buffering if enabled
        $this->startOutputBuffering();
    }

    /**
     * Unregister handlers
     */
    public function unregister() {
        restore_error_handler();
        restore_exception_handler();

        // Stop output buffering
        $this->stopOutputBuffering();
    }
}
