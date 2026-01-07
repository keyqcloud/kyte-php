<?php

namespace Kyte\AI;

use Kyte\Core\ModelObject;
use Aws\BedrockRuntime\BedrockRuntimeClient;

/**
 * AI Error Analyzer
 *
 * Performs AI-powered analysis of errors using AWS Bedrock (Claude Sonnet 4.5).
 * Stages:
 * 1. Classify error (is it fixable?)
 * 2. Gather context (controller functions, models, docs)
 * 3. Generate fix using Bedrock Claude
 * 4. Validate fix (PHP syntax check)
 *
 * @package Kyte\AI
 */
class AIErrorAnalyzer
{
    private $api;
    private $bedrockClient;

    // Bedrock pricing (Claude Sonnet 4.5) - per 1K tokens
    const INPUT_COST_PER_1K = 0.003;
    const OUTPUT_COST_PER_1K = 0.015;

    public function __construct($apiContext) {
        $this->api = $apiContext;

        // Initialize Bedrock client
        try {
            $this->bedrockClient = new BedrockRuntimeClient([
                'region' => defined('AI_BEDROCK_REGION') ? AI_BEDROCK_REGION : 'us-east-1',
                'version' => 'latest',
                'credentials' => [
                    'key' => AWS_ACCESS_KEY_ID,
                    'secret' => AWS_SECRET_KEY,
                ]
            ]);
        } catch (\Exception $e) {
            throw new \Exception("Failed to initialize Bedrock client: " . $e->getMessage());
        }
    }

    /**
     * Analyze error and generate fix
     *
     * @param ModelObject $analysis AIErrorAnalysis object
     * @return array ['success' => bool, 'should_auto_fix' => bool, 'error' => string|null]
     */
    public function analyze($analysis) {
        $startTime = microtime(true);

        try {
            // Load error
            $error = new ModelObject(KyteError);
            if (!$error->retrieve('id', $analysis->error_id)) {
                throw new \Exception("Error not found: {$analysis->error_id}");
            }

            // Stage 1: Classify error (is it fixable?)
            $this->updateStage($analysis, 'classifying');
            $classification = $this->classifyError($error);

            $analysis->save([
                'is_fixable' => $classification['fixable'] ? 1 : 0,
                'fixable_confidence' => $classification['confidence'],
            ]);

            if (!$classification['fixable']) {
                // Not fixable - mark as completed
                $analysis->save([
                    'analysis_stage' => 'completed',
                    'ai_diagnosis' => $classification['reason'],
                ]);

                $processingTime = (microtime(true) - $startTime) * 1000;
                $analysis->save(['processing_time_ms' => intval($processingTime)]);

                return ['success' => true, 'should_auto_fix' => false];
            }

            // Stage 2: Gather context
            $this->updateStage($analysis, 'analyzing');
            $contextBuilder = new AIErrorContextBuilder($this->api);
            $context = $contextBuilder->build($analysis, $error);

            // Save context snapshot
            $analysis->save([
                'context_snapshot' => json_encode($context),
            ]);

            // Stage 3: Generate fix
            $this->updateStage($analysis, 'generating_fix');
            $fix = $this->generateFix($error, $context);

            $analysis->save([
                'ai_diagnosis' => $fix['diagnosis'],
                'ai_suggested_fix' => $fix['fix'],
                'fix_confidence' => $fix['confidence'],
                'fix_rationale' => $fix['rationale'],
            ]);

            // Stage 4: Validate syntax
            $this->updateStage($analysis, 'validating');
            $validation = $this->validateSyntax($fix['fix']);

            $analysis->save([
                'syntax_valid' => $validation['valid'] ? 1 : 0,
                'syntax_error' => $validation['errors'],
            ]);

            if (!$validation['valid']) {
                error_log("AIErrorAnalyzer: Validation FAILED for analysis #{$analysis->id}");
                $analysis->save([
                    'analysis_stage' => 'completed',
                    'fix_status' => 'failed_validation',
                ]);

                $processingTime = (microtime(true) - $startTime) * 1000;
                $analysis->save(['processing_time_ms' => intval($processingTime)]);

                return ['success' => true, 'should_auto_fix' => false];
            }

            // Validation passed - update fix_status to 'suggested'
            error_log("AIErrorAnalyzer: Validation PASSED for analysis #{$analysis->id}, setting fix_status='suggested'");

            // Identify which Function record contains the code to fix
            // This handles: mismatched names, "helper functions" containers, action overrides
            if (!empty($analysis->controller_id)) {
                error_log("AIErrorAnalyzer: Attempting to identify Function record from suggested fix");
                $matchResult = \Kyte\AI\AIFunctionMatcher::findMatchingFunction($fix['fix'], $analysis->controller_id);

                if ($matchResult) {
                    error_log("AIErrorAnalyzer: Function identified - ID: {$matchResult['function_id']}, Name: {$matchResult['function_name']}, Type: {$matchResult['function_type']}");
                    $analysis->save([
                        'function_id' => $matchResult['function_id'],
                        'function_name' => $matchResult['function_name'],
                        'function_type' => $matchResult['function_type'],
                    ]);
                } else {
                    error_log("AIErrorAnalyzer: Could not identify Function record from suggested fix");
                }
            }

            $analysis->save([
                'fix_status' => 'suggested',
            ]);
            error_log("AIErrorAnalyzer: fix_status saved, current value in object: " . $analysis->fix_status);

            // Analysis complete
            $this->updateStage($analysis, 'completed');

            $processingTime = (microtime(true) - $startTime) * 1000;
            $analysis->save(['processing_time_ms' => intval($processingTime)]);

            // Update deduplication - mark as analyzed
            $dedup = new ModelObject(AIErrorDeduplication);
            if ($dedup->retrieve('error_signature', $analysis->error_signature, [
                ['field' => 'application', 'value' => $analysis->application],
            ])) {
                $dedup->save([
                    'last_analyzed' => time(),
                    'analysis_count' => $dedup->analysis_count + 1,
                ]);
            }

            // Check if should auto-fix
            $config = AIErrorCorrection::getConfig($analysis->application, $analysis->kyte_account);
            $shouldAutoFix = $config && $config->auto_fix_enabled &&
                           $fix['confidence'] >= $config->auto_fix_min_confidence;

            return [
                'success' => true,
                'should_auto_fix' => $shouldAutoFix,
            ];

        } catch (\Exception $e) {
            // Analysis failed
            $analysis->save([
                'analysis_stage' => 'failed',
                'last_error' => $e->getMessage(),
            ]);

            error_log("AI Error Analysis failed for {$analysis->id}: " . $e->getMessage());

            return [
                'success' => false,
                'should_auto_fix' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Classify error (is it fixable by code changes?)
     *
     * @param ModelObject $error KyteError object
     * @return array ['fixable' => bool, 'confidence' => float, 'reason' => string]
     */
    private function classifyError($error) {
        $systemPrompt = <<<SYS
You are an expert PHP error analyzer for the Kyte framework. Your task is to determine if an error can be fixed by modifying controller code.

Analyze the error and respond ONLY with a JSON object:
{
  "fixable": true|false,
  "confidence": 0-100,
  "reason": "brief explanation"
}

Consider NOT fixable if:
- Database connection errors
- Missing PHP extensions or dependencies
- Server configuration issues (memory, permissions, etc.)
- Framework core bugs
- Syntax errors in framework files (not user code)
- Third-party library errors (not user code)

Consider fixable if:
- Logic errors in controller functions
- Incorrect API usage in user code
- Missing validation in user code
- Type errors in user code
- Incorrect database queries in user code
- Missing error handling in user code
- Incorrect function signatures
- Undefined variables or properties
SYS;

        $userPrompt = <<<USER
Error: {$error->message}
File: {$error->file}
Line: {$error->line}
Log Level: {$error->log_level}
Stack Trace:
{$error->trace}
USER;

        $response = $this->callBedrock($systemPrompt, $userPrompt);

        // Parse JSON response - handle various formatting from Claude
        $response = trim($response);

        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } else {
            // No code block - try to find JSON object directly
            if (preg_match('/(\{.*\})/s', $response, $matches)) {
                $response = $matches[1];
            }
        }

        $response = trim($response);
        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("AIErrorAnalyzer: Failed to parse classification response: " . substr($response, 0, 500));
            // Default to fixable with low confidence
            return [
                'fixable' => true,
                'confidence' => 30.0,
                'reason' => 'Unable to parse AI classification'
            ];
        }

        return [
            'fixable' => $result['fixable'] ?? true,
            'confidence' => floatval($result['confidence'] ?? 50.0),
            'reason' => $result['reason'] ?? 'No reason provided'
        ];
    }

    /**
     * Generate fix for error
     *
     * @param ModelObject $error KyteError object
     * @param array $context Context data
     * @return array ['diagnosis' => string, 'fix' => string, 'confidence' => float, 'rationale' => string]
     */
    private function generateFix($error, $context) {
        $systemPrompt = <<<'SYS'
You are an expert PHP developer specializing in the Kyte framework. Fix the error in the controller function below.

**Kyte Framework Context:**
- Controllers extend ModelController
- Available in $this: $this->user, $this->api, $this->model, $this->response, $this->account
- Database access: new \Kyte\Core\ModelObject(ModelName) or new \Kyte\Core\Model(ModelName)
- Always use ModelObject/Model for queries (never raw SQL)
- Hooks available: hook_init, hook_auth, hook_prequery, hook_preprocess, hook_response_data
- Action overrides: new, update, get, delete
- Log errors/debug: error_log() function

**ModelController Base Class Overview:**
- $this->user - Current logged-in user object
- $this->api - API context (contains request info, app context, etc.)
- $this->model - Current model definition array
- $this->response - Response array (modify this to change API response)
- $this->account - Current account object
- Protected methods you can override:
  * hook_init() - Runs on controller initialization
  * hook_auth() - Custom authentication logic
  * hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) - Modify query before execution
  * hook_preprocess($method, &$data, &$object) - Transform data before save
  * hook_response_data($method, $object, &$result, &$data) - Transform response data

**Current Controller Code:**
SYS;

        // Add controller functions to system prompt
        if (isset($context['controller_functions']) && !empty($context['controller_functions'])) {
            $systemPrompt .= "\n```php\n";
            foreach ($context['controller_functions'] as $func) {
                $systemPrompt .= "// Function: {$func['name']} (Type: {$func['type']})\n";
                $systemPrompt .= $func['code'] . "\n\n";
            }
            $systemPrompt .= "```\n";
        }

        // Add model definitions
        if (isset($context['models']) && !empty($context['models'])) {
            $systemPrompt .= "\n**Related Models:**\n";
            foreach ($context['models'] as $modelName => $modelDef) {
                $systemPrompt .= "Model: {$modelName}\n";
                $systemPrompt .= "Fields: " . implode(', ', array_keys($modelDef['struct'] ?? [])) . "\n\n";
            }
        }

        $userPrompt = <<<USER
**Error Context:**
Error: {$error->message}
File: {$error->file}
Line: {$error->line}
Stack Trace:
{$error->trace}
USER;

        // Add request data if available
        if (isset($context['request_data'])) {
            $requestData = json_encode($context['request_data'], JSON_PRETTY_PRINT);
            $userPrompt .= "\n**Request Data that Triggered Error:**\n{$requestData}\n";
        }

        $userPrompt .= <<<'USER'

Return ONLY a JSON object:
{
  "diagnosis": "detailed explanation of the problem",
  "fix": "complete corrected function code",
  "confidence": 0-100,
  "rationale": "explanation of why this fix will work",
  "changes_summary": "bullet points of what changed"
}

Requirements:
- Return ONLY the complete function code that needs to be fixed, not the entire controller class
- Preserve function signature exactly
- Maintain coding style and formatting
- Add brief inline comments explaining the fix
- Do NOT introduce new dependencies
- Do NOT modify other functions
- Ensure the fix addresses the specific error
USER;

        $response = $this->callBedrock($systemPrompt, $userPrompt);

        // Parse JSON response - handle various formatting from Claude
        $response = trim($response);

        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $response = $matches[1];
        } else {
            // No code block - try to find JSON object directly
            if (preg_match('/(\{.*\})/s', $response, $matches)) {
                $response = $matches[1];
            }
        }

        $response = trim($response);
        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Log the problematic response for debugging
            error_log("AIErrorAnalyzer: Failed to parse response: " . substr($response, 0, 500));
            throw new \Exception("Failed to parse fix generation response: " . json_last_error_msg());
        }

        return [
            'diagnosis' => $result['diagnosis'] ?? 'No diagnosis provided',
            'fix' => $result['fix'] ?? '',
            'confidence' => floatval($result['confidence'] ?? 50.0),
            'rationale' => $result['rationale'] ?? 'No rationale provided',
        ];
    }

    /**
     * Validate PHP syntax using php -l
     *
     * @param string $code PHP code to validate
     * @return array ['valid' => bool, 'errors' => string|null]
     */
    private function validateSyntax($code) {
        // Check if PHP CLI is available
        $phpBinary = PHP_BINARY;
        if (!$phpBinary || !is_executable($phpBinary)) {
            error_log("AI Error Analyzer: PHP CLI not available for syntax validation");
            return ['valid' => true, 'errors' => null]; // Skip validation
        }

        // Detect if code is a method (needs class wrapper) or standalone code
        $trimmedCode = trim($code);
        $isMethod = preg_match('/^\s*(public|protected|private|function)\s+/', $trimmedCode);

        // Wrap code appropriately
        if ($isMethod) {
            // Wrap method in a dummy class for validation
            $validationCode = '<?php' . PHP_EOL .
                'class DummyValidationClass {' . PHP_EOL .
                $code . PHP_EOL .
                '}';
        } else {
            // Standalone code - just prepend <?php
            $validationCode = '<?php' . PHP_EOL . $code;
        }

        // Create temporary file
        $tmpFile = tempnam(sys_get_temp_dir(), 'kyte_ai_fix_');
        file_put_contents($tmpFile, $validationCode);

        // Run php -l
        $output = [];
        $returnCode = 0;
        exec($phpBinary . " -l " . escapeshellarg($tmpFile) . " 2>&1", $output, $returnCode);

        // Log validation results for debugging
        $outputStr = implode("\n", $output);
        error_log("AIErrorAnalyzer: Syntax validation - Return code: $returnCode, Output: $outputStr");
        if ($returnCode !== 0 && empty($outputStr)) {
            error_log("AIErrorAnalyzer: WARNING - Validation failed but no error message captured");
            error_log("AIErrorAnalyzer: Validation code:\n" . $validationCode);
        }

        // Clean up
        unlink($tmpFile);

        return [
            'valid' => ($returnCode === 0),
            'errors' => $returnCode === 0 ? null : ($outputStr ?: 'Syntax validation failed (no error message captured)')
        ];
    }

    /**
     * Call AWS Bedrock API
     *
     * @param string $system System prompt
     * @param string $user User prompt
     * @return string AI response text
     */
    private function callBedrock($system, $user) {
        try {
            $requestBody = [
                "anthropic_version" => "bedrock-2023-05-31",
                "max_tokens" => 8192,
                "temperature" => 0.2,
                "system" => $system,
                "messages" => [
                    [
                        "role" => "user",
                        "content" => $user
                    ]
                ]
            ];

            $modelId = defined('AI_BEDROCK_MODEL') ? AI_BEDROCK_MODEL : 'anthropic.claude-sonnet-4-5-20250929-v1:0';

            $response = $this->bedrockClient->invokeModel([
                'modelId' => $modelId,
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($requestBody)
            ]);

            $responseBody = json_decode($response['body'], true);

            if (isset($responseBody['content'][0]['text'])) {
                // Track token usage and cost
                $inputTokens = $responseBody['usage']['input_tokens'] ?? 0;
                $outputTokens = $responseBody['usage']['output_tokens'] ?? 0;
                $cost = $this->estimateCost($inputTokens, $outputTokens);

                // Store for later
                $this->lastInputTokens = $inputTokens;
                $this->lastOutputTokens = $outputTokens;
                $this->lastCost = $cost;
                $this->lastRequestId = $responseBody['id'] ?? null;

                return trim($responseBody['content'][0]['text']);
            }

            throw new \Exception("Unexpected Bedrock response structure");

        } catch (\Aws\Exception\AwsException $e) {
            throw new \Exception("AWS Bedrock Error: " . $e->getAwsErrorMessage());
        } catch (\Exception $e) {
            throw new \Exception("Error calling Bedrock: " . $e->getMessage());
        }
    }

    /**
     * Estimate cost based on token usage
     *
     * @param int $inputTokens Input token count
     * @param int $outputTokens Output token count
     * @return float Cost in USD
     */
    private function estimateCost($inputTokens, $outputTokens) {
        $inputCost = ($inputTokens / 1000) * self::INPUT_COST_PER_1K;
        $outputCost = ($outputTokens / 1000) * self::OUTPUT_COST_PER_1K;
        return $inputCost + $outputCost;
    }

    /**
     * Update analysis stage
     *
     * @param ModelObject $analysis AIErrorAnalysis object
     * @param string $stage New stage
     * @return void
     */
    private function updateStage($analysis, $stage) {
        $analysis->save(['analysis_stage' => $stage]);

        if ($stage === 'classifying') {
            $analysis->save(['processing_started_at' => time()]);
        } elseif ($stage === 'completed' || $stage === 'failed') {
            // Update both stage and status when complete
            $analysis->save([
                'analysis_status' => 'completed',
                'processing_completed_at' => time()
            ]);

            // Save token usage and cost
            if (isset($this->lastInputTokens)) {
                $analysis->save([
                    'bedrock_input_tokens' => $this->lastInputTokens,
                    'bedrock_output_tokens' => $this->lastOutputTokens,
                    'estimated_cost_usd' => $this->lastCost,
                    'bedrock_request_id' => $this->lastRequestId,
                ]);

                // Update config statistics
                $config = AIErrorCorrection::getConfig($analysis->application, $analysis->kyte_account);
                if ($config) {
                    $config->save([
                        'total_analyses' => $config->total_analyses + 1,
                        'total_cost_usd' => $config->total_cost_usd + $this->lastCost,
                        'last_analysis_date' => time(),
                    ]);
                }
            }
        }
    }
}
