<?php

namespace Kyte\Exception;

class ErrorHandler
{
    private static $instance = null;
    private $apiContext;

    // Private constructor to prevent direct instantiation
    private function __construct($apiContext) {
        $this->apiContext = $apiContext;
    }

    // Method to get the single instance of the class
    public static function getInstance($apiContext = null) {
        if (self::$instance === null) {
            if ($apiContext === null) {
                throw new Exception("API context required for the first initialization of ErrorHandler.");
            }
            self::$instance = new self($apiContext);
        }
        return self::$instance;
    }

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
        ];
    
        if (!$error->create($log_detail)) {
            error_log("FATAL: Failed to create log entry for " . print_r($log_detail, true));
        }
    
        $slackMessage = "Error encountered:\n" . print_r($log_detail, true);
        // Platform level notification
        if (defined('SLACK_ERROR_WEBHOOK')) {
            $this->sendSlackNotification(SLACK_ERROR_WEBHOOK, $slackMessage);
        }
        // Application level notification
        if ($this->apiContext->appId && $this->apiContext->app->slack_error_webhook) {
            $this->sendSlackNotification($this->apiContext->app->slack_error_webhook, $slackMessage);
        }
    }
    
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
    

    public function handleError($errno, $errstr, $errfile, $errline) {
        // skip warnings, notices, deprecations for now
        if ($errno == E_WARNING || $errno == E_NOTICE || $errno == E_USER_WARNING || $errno == E_USER_NOTICE || $errno == E_DEPRECATED || $errno == E_USER_DEPRECATED) {
            // Just return false to continue with the normal error handler for warnings and notices
            return false;
        }

        $exception = new \ErrorException($errstr, $errno, 0, $errfile, $errline);
        $this->handleException($exception);
    }

    public function register() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
    }

    public function unregister() {
        restore_error_handler();
        restore_exception_handler();
    }
}
