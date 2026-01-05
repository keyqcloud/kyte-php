<?php

namespace Kyte\AI;

use Kyte\Core\ModelObject;
use Kyte\Core\Model;

/**
 * AI Error Context Builder
 *
 * Gathers context for AI error analysis:
 * - All functions for the affected controller
 * - Related model definitions
 * - Request data that triggered the error
 * - Framework documentation (optional)
 *
 * @package Kyte\AI
 */
class AIErrorContextBuilder
{
    private $api;
    private $maxContextSize = 100000; // Max tokens (~100K)

    public function __construct($apiContext) {
        $this->api = $apiContext;
    }

    /**
     * Build context for AI analysis
     *
     * @param ModelObject $analysis AIErrorAnalysis object
     * @param ModelObject $error KyteError object
     * @return array Context data
     */
    public function build($analysis, $error) {
        $context = [];

        // Load configuration
        $config = AIErrorCorrection::getConfig($analysis->application, $analysis->kyte_account);

        // 1. Get controller functions (highest priority)
        if ($analysis->controller_id) {
            $context['controller_functions'] = $this->getControllerFunctions($analysis->controller_id);
        }

        // 2. Get model definitions (if enabled)
        if ($config && $config->include_model_definitions && $analysis->controller_id) {
            $context['models'] = $this->getModelDefinitions($analysis->controller_id);
        }

        // 3. Get request data (if enabled)
        if ($config && $config->include_request_data) {
            $context['request_data'] = $this->getRequestContext($error);
        }

        // 4. Get framework docs (if enabled) - not implemented in v1.0
        // This would include ModelController docs, but it's a large amount of text
        // For now, we include basic info in the AI prompts themselves

        return $context;
    }

    /**
     * Get all functions for a controller
     *
     * @param int $controllerId Controller ID
     * @return array Array of function data
     */
    private function getControllerFunctions($controllerId) {
        $functions = [];

        $functionModel = new Model(constant("Function"));
        $functionModel->retrieve('controller', $controllerId);

        foreach ($functionModel->objects as $func) {
            // Decompress code
            $code = $func->code;
            if ($this->isValidBzip2Data($code)) {
                $code = bzdecompress($code);
            }

            $functions[] = [
                'id' => $func->id,
                'name' => $func->name,
                'type' => $func->type,
                'code' => $code,
            ];
        }

        return $functions;
    }

    /**
     * Get model definitions related to controller
     *
     * @param int $controllerId Controller ID
     * @return array Array of model definitions
     */
    private function getModelDefinitions($controllerId) {
        $models = [];

        // Get controller's dataModel
        $controller = new ModelObject(Controller);
        if (!$controller->retrieve('id', $controllerId)) {
            return $models;
        }

        if ($controller->dataModel) {
            // Load the data model
            $dataModel = new ModelObject(DataModel);
            if ($dataModel->retrieve('id', $controller->dataModel)) {
                $modelDef = json_decode($dataModel->model_definition, true);
                if ($modelDef) {
                    $models[$modelDef['name']] = $modelDef;

                    // Get related models (via foreign keys)
                    if (isset($modelDef['struct'])) {
                        foreach ($modelDef['struct'] as $field => $attrs) {
                            if (isset($attrs['fk']['model'])) {
                                $relatedModelName = $attrs['fk']['model'];
                                $relatedModel = new ModelObject(DataModel);

                                if ($relatedModel->retrieve('name', $relatedModelName)) {
                                    $relatedDef = json_decode($relatedModel->model_definition, true);
                                    if ($relatedDef) {
                                        $models[$relatedModelName] = $relatedDef;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Get request context from error
     *
     * @param ModelObject $error KyteError object
     * @return array Request data (sanitized)
     */
    private function getRequestContext($error) {
        $context = [];

        // Include basic request info
        if ($error->request) {
            $context['request_method'] = $error->request;
        }

        if ($error->model) {
            $context['model'] = $error->model;
        }

        if ($error->field) {
            $context['field'] = $error->field;
        }

        if ($error->value) {
            $context['value'] = $error->value;
        }

        // Include data if available (sanitize sensitive fields)
        if ($error->data) {
            $data = $this->sanitizeData($error->data);
            $context['data'] = $data;
        }

        return $context;
    }

    /**
     * Sanitize data (remove passwords, keys, tokens, etc.)
     *
     * @param string $dataStr Data string
     * @return string Sanitized data
     */
    private function sanitizeData($dataStr) {
        // Convert to array if possible
        $data = @json_decode($dataStr, true);
        if (!$data && is_string($dataStr)) {
            // Try to parse as print_r output
            return $this->sanitizeString($dataStr);
        }

        if (is_array($data)) {
            // Recursively sanitize
            $sanitized = $this->sanitizeArray($data);
            return json_encode($sanitized, JSON_PRETTY_PRINT);
        }

        return $this->sanitizeString($dataStr);
    }

    /**
     * Sanitize array recursively
     *
     * @param array $data Data array
     * @return array Sanitized array
     */
    private function sanitizeArray($data) {
        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'api_key', 'private_key', 'access_key'];

        foreach ($data as $key => &$value) {
            $lowerKey = strtolower($key);

            // Check if key is sensitive
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($lowerKey, $sensitiveKey) !== false) {
                    $value = '[REDACTED]';
                    continue 2;
                }
            }

            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $value = $this->sanitizeArray($value);
            }
        }

        return $data;
    }

    /**
     * Sanitize string (redact sensitive patterns)
     *
     * @param string $str String to sanitize
     * @return string Sanitized string
     */
    private function sanitizeString($str) {
        // Redact common sensitive patterns
        $patterns = [
            '/password[\'"\s]*[:=][\'"\s]*[^\s\'"]+/i' => 'password=[REDACTED]',
            '/secret[\'"\s]*[:=][\'"\s]*[^\s\'"]+/i' => 'secret=[REDACTED]',
            '/token[\'"\s]*[:=][\'"\s]*[^\s\'"]+/i' => 'token=[REDACTED]',
            '/api_key[\'"\s]*[:=][\'"\s]*[^\s\'"]+/i' => 'api_key=[REDACTED]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $str = preg_replace($pattern, $replacement, $str);
        }

        // Truncate if too long
        if (strlen($str) > 5000) {
            $str = substr($str, 0, 5000) . "\n... [truncated]";
        }

        return $str;
    }

    /**
     * Check if data is valid bzip2
     *
     * @param mixed $data Data to check
     * @return bool
     */
    private function isValidBzip2Data($data) {
        return is_string($data) && strlen($data) >= 2 && substr($data, 0, 2) === 'BZ';
    }
}
