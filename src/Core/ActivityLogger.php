<?php

namespace Kyte\Core;

class ActivityLogger
{
    private static $instance = null;
    private $isLogging = false;

    // Context properties
    private $userId = null;
    private $userEmail = null;
    private $userName = null;
    private $accountId = null;
    private $accountName = null;
    private $applicationId = null;
    private $applicationName = null;
    private $ipAddress = null;
    private $userAgent = null;
    private $sessionToken = null;
    private $requestUri = null;
    private $requestMethod = null;
    private $requestStartTime = null;

    // Pre-update state for change tracking
    private $preUpdateState = null;

    // Sensitive field names to redact
    private const SENSITIVE_FIELDS = [
        'password', 'secret_key', 'access_key', 'token', 'secret',
        'access_key_id', 'secret_access_key', 'kms_keyid',
        'db_password', 'api_key', 'private_key', 'credential',
        'txToken', 'sessionToken', 'session_token'
    ];

    private function __construct() {
        $this->requestStartTime = microtime(true);
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set context from Api object after authentication
     */
    public function setContext($api) {
        $this->userId = isset($api->user->id) ? $api->user->id : null;
        $this->userEmail = isset($api->user->email) ? $api->user->email : null;
        $this->userName = isset($api->user->name) ? $api->user->name : null;
        $this->accountId = isset($api->account->id) ? $api->account->id : null;
        $this->accountName = isset($api->account->name) ? $api->account->name : null;
        $this->applicationId = isset($api->app->id) ? $api->app->id : null;
        $this->applicationName = isset($api->app->name) ? $api->app->name : null;
        $this->ipAddress = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $this->userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null;
        $this->requestUri = isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 2048) : null;
        $this->requestMethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;

        // Mask session token - show only last 8 chars
        $sessionToken = isset($api->response['session']) ? $api->response['session'] : null;
        if ($sessionToken && strlen($sessionToken) > 8) {
            $this->sessionToken = '***' . substr($sessionToken, -8);
        } else {
            $this->sessionToken = $sessionToken;
        }
    }

    /**
     * Capture the current state of a record before an update
     */
    public function capturePreUpdateState($model, $field, $value) {
        if (!self::isEnabled()) return;

        try {
            $obj = new \Kyte\Core\ModelObject(constant($model));
            if ($obj->retrieve($field, $value)) {
                $this->preUpdateState = $obj->getAllParams();
            }
        } catch (\Exception $e) {
            // Silently fail - don't break updates if pre-capture fails
            $this->preUpdateState = null;
        }
    }

    /**
     * Log an API action
     */
    public function log($action, $modelName, $field, $value, $requestData, $responseCode, $responseStatus, $errorMessage = null, $recordId = null) {
        if (!self::isEnabled()) return;
        if ($this->isLogging) return; // Prevent recursion

        // Skip logging for KyteActivityLog model
        if ($modelName === 'KyteActivityLog') return;

        // Skip GET requests unless explicitly enabled
        if ($action === 'GET' && !self::isGetLoggingEnabled()) return;

        // Check excluded models
        if (self::isModelExcluded($modelName)) return;

        $this->isLogging = true;

        try {
            $log = new \Kyte\Core\ModelObject(KyteActivityLog);

            $duration = round((microtime(true) - $this->requestStartTime) * 1000);

            $logData = [
                // WHO
                'user_id' => $this->userId,
                'user_email' => $this->userEmail,
                'user_name' => $this->userName,
                'account_id' => $this->accountId,
                'account_name' => $this->accountName,
                'application_id' => $this->applicationId,
                'application_name' => $this->applicationName,
                // WHAT
                'action' => $action,
                'model_name' => $modelName,
                'record_id' => $recordId,
                'field' => $field,
                'value' => $value ? substr((string)$value, 0, 255) : null,
                'request_data' => $requestData ? json_encode($this->redactSensitive($requestData)) : null,
                'changes' => null,
                // RESULT
                'response_code' => $responseCode,
                'response_status' => $responseStatus,
                'error_message' => $errorMessage,
                // WHERE
                'ip_address' => $this->ipAddress,
                'user_agent' => $this->userAgent,
                'session_token' => $this->sessionToken,
                'request_uri' => $this->requestUri,
                'request_method' => $this->requestMethod,
                // META
                'severity' => $this->determineSeverity($action, $responseCode, $errorMessage),
                'event_category' => $this->determineCategory($action, $modelName),
                'duration_ms' => $duration,
                'kyte_account' => $this->accountId,
            ];

            // For PUT actions, compute changes diff
            if ($action === 'PUT' && $this->preUpdateState !== null && is_array($requestData)) {
                $changes = $this->computeChanges($this->preUpdateState, $requestData);
                if (!empty($changes)) {
                    $logData['changes'] = json_encode($changes);
                }
            }

            $log->create($logData);
        } catch (\Exception $e) {
            error_log("ActivityLogger: Failed to log activity - " . $e->getMessage());
        }

        $this->isLogging = false;
        $this->preUpdateState = null;
    }

    /**
     * Log authentication events
     */
    public function logAuth($action, $email, $success, $errorMessage = null) {
        if (!self::isEnabled()) return;
        if ($this->isLogging) return;

        $this->isLogging = true;

        try {
            $log = new \Kyte\Core\ModelObject(KyteActivityLog);

            $severity = 'info';
            if ($action === 'LOGIN_FAIL') {
                $severity = 'warning';
            }

            $logData = [
                'user_id' => $this->userId,
                'user_email' => $email,
                'user_name' => $this->userName,
                'account_id' => $this->accountId,
                'account_name' => $this->accountName,
                'application_id' => $this->applicationId,
                'application_name' => $this->applicationName,
                'action' => $action,
                'model_name' => 'Session',
                'response_code' => $success ? 200 : 403,
                'response_status' => $success ? 'success' : 'error',
                'error_message' => $errorMessage,
                'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512) : null,
                'request_uri' => isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 2048) : null,
                'request_method' => 'POST',
                'severity' => $severity,
                'event_category' => 'auth',
                'kyte_account' => $this->accountId,
            ];

            $log->create($logData);
        } catch (\Exception $e) {
            error_log("ActivityLogger: Failed to log auth event - " . $e->getMessage());
        }

        $this->isLogging = false;
    }

    /**
     * Redact sensitive fields from data
     */
    public function redactSensitive($data) {
        if (!is_array($data)) return $data;

        $redacted = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower($key);
            $isSensitive = false;

            foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
                if (strpos($keyLower, strtolower($sensitiveField)) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redactSensitive($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }

    /**
     * Compute changes between old state and new data
     */
    private function computeChanges($oldState, $newData) {
        $changes = [];

        foreach ($newData as $field => $newValue) {
            // Skip internal/audit fields
            if (in_array($field, ['id', 'created_by', 'date_created', 'modified_by', 'date_modified', 'deleted', 'deleted_by', 'date_deleted'])) {
                continue;
            }

            $keyLower = strtolower($field);
            $isSensitive = false;
            foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
                if (strpos($keyLower, strtolower($sensitiveField)) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $changes[$field] = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];
                continue;
            }

            $oldValue = isset($oldState[$field]) ? $oldState[$field] : null;

            if ($oldValue != $newValue) {
                $changes[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Determine severity level based on action and response
     */
    private function determineSeverity($action, $responseCode, $errorMessage) {
        if ($errorMessage || ($responseCode && $responseCode >= 400)) {
            return 'critical';
        }

        switch ($action) {
            case 'DELETE':
                return 'warning';
            case 'POST':
            case 'PUT':
                return 'info';
            case 'LOGIN_FAIL':
                return 'warning';
            default:
                return 'info';
        }
    }

    /**
     * Determine event category based on action and model
     */
    private function determineCategory($action, $modelName) {
        if (in_array($action, ['LOGIN', 'LOGOUT', 'LOGIN_FAIL'])) {
            return 'auth';
        }

        $configModels = ['KyteAccount', 'Application', 'KyteAPIKey', 'KyteAWSKey', 'KyteEnvironmentVariable', 'DataStore'];
        if (in_array($modelName, $configModels)) {
            return 'config';
        }

        $systemModels = ['Session', 'KyteUser', 'KyteRole'];
        if (in_array($modelName, $systemModels)) {
            return 'system';
        }

        return 'data';
    }

    /**
     * Check if activity logging is enabled
     */
    private static function isEnabled() {
        return !defined('KYTE_ACTIVITY_LOG_ENABLED') || KYTE_ACTIVITY_LOG_ENABLED;
    }

    /**
     * Check if GET request logging is enabled
     */
    private static function isGetLoggingEnabled() {
        return defined('KYTE_ACTIVITY_LOG_GET') && KYTE_ACTIVITY_LOG_GET;
    }

    /**
     * Check if a model is excluded from logging
     */
    private static function isModelExcluded($modelName) {
        if (!defined('KYTE_ACTIVITY_LOG_EXCLUDED_MODELS')) return false;
        return in_array($modelName, KYTE_ACTIVITY_LOG_EXCLUDED_MODELS);
    }
}
