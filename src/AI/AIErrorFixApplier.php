<?php

namespace Kyte\AI;

use Kyte\Core\ModelObject;

/**
 * AI Error Fix Applier
 *
 * Applies AI-suggested fixes to controller functions.
 * Creates new function versions for rollback capability.
 * Monitors outcome to detect if fix caused new errors.
 *
 * @package Kyte\AI
 */
class AIErrorFixApplier
{
    private $api;

    public function __construct($apiContext) {
        $this->api = $apiContext;
    }

    /**
     * Apply suggested fix
     *
     * @param ModelObject $analysis AIErrorAnalysis object
     * @param int|null $userId User ID (null if auto-applied)
     * @return bool Success
     */
    public function apply($analysis, $userId = null) {
        try {
            // Validate analysis
            if ($analysis->fix_status !== 'suggested' || !$analysis->syntax_valid) {
                throw new \Exception("Fix is not in applicable state");
            }

            if (empty($analysis->ai_suggested_fix)) {
                throw new \Exception("No fix code available");
            }

            // Get the function to update
            if (!$analysis->function_id) {
                throw new \Exception("No function ID associated with analysis");
            }

            $function = new ModelObject(constant("Function"));
            if (!$function->retrieve('id', $analysis->function_id)) {
                throw new \Exception("Function not found: {$analysis->function_id}");
            }

            // Create new function version with AI fix
            $versionId = $this->createFunctionVersion($function, $analysis, $userId);

            // Update function with new code
            $function->save([
                'code' => bzcompress($analysis->ai_suggested_fix, 9),
                'modified_by' => $userId ?? 0,
                'date_modified' => time(),
            ]);

            // Mark analysis as applied
            $analysis->save([
                'fix_status' => $userId ? 'applied_manual' : 'applied_auto',
                'applied_at' => time(),
                'applied_by' => $userId,
                'applied_function_version' => $versionId,
            ]);

            // Update config statistics
            $config = AIErrorCorrection::getConfig($analysis->application, $analysis->kyte_account);
            if ($config) {
                $config->save([
                    'total_fixes_applied' => $config->total_fixes_applied + 1,
                ]);
            }

            // Regenerate controller code
            $this->regenerateControllerCode($function->controller);

            error_log("AI Fix Applied: Analysis {$analysis->id}, Function {$function->id}, Version {$versionId}");

            return true;

        } catch (\Exception $e) {
            error_log("AI Fix Application Failed for analysis {$analysis->id}: " . $e->getMessage());

            // Mark as failed
            $analysis->save([
                'fix_status' => 'failed_validation',
                'last_error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Create function version with AI fix
     *
     * @param ModelObject $function Function object
     * @param ModelObject $analysis Analysis object
     * @param int|null $userId User ID
     * @return int Version ID
     */
    private function createFunctionVersion($function, $analysis, $userId) {
        // Get next version number
        $versionModel = new \Kyte\Core\Model(KyteFunctionVersion);
        $versionModel->retrieve('function', $function->id, false, null, false,
            [['field' => 'version_number', 'direction' => 'DESC']], 1);

        $nextVersion = 1;
        if ($versionModel->count() > 0) {
            $nextVersion = $versionModel->objects[0]->version_number + 1;
        }

        // Generate content hash
        $contentHash = hash('sha256', $analysis->ai_suggested_fix);

        // Mark previous version as not current
        $prevVersion = new ModelObject(KyteFunctionVersion);
        if ($prevVersion->retrieve('function', $function->id, [
            ['field' => 'is_current', 'value' => 1]
        ])) {
            $prevVersion->save([
                'is_current' => 0,
                'modified_by' => $userId ?? 0,
                'date_modified' => time(),
            ]);
        }

        // Create new version
        $version = new ModelObject(KyteFunctionVersion);
        $created = $version->create([
            'function' => $function->id,
            'version_number' => $nextVersion,
            'version_type' => $userId ? 'ai_fix_manual' : 'ai_fix_auto',
            'change_summary' => "AI-generated fix (Analysis #{$analysis->id})",
            'changes_detected' => json_encode([
                'ai_fix' => true,
                'analysis_id' => $analysis->id,
                'confidence' => $analysis->fix_confidence,
                'diagnosis' => $analysis->ai_diagnosis,
            ]),
            'content_hash' => $contentHash,
            'is_current' => 1,
            'kyte_account' => $function->kyte_account,
            'created_by' => $userId ?? 0,
            'date_created' => time(),
        ]);

        if (!$created) {
            throw new \Exception("Failed to create function version");
        }

        // Store or reference content
        $this->storeFunctionVersionContent($contentHash, $analysis->ai_suggested_fix, $function->kyte_account, $userId);

        return $version->id;
    }

    /**
     * Store function version content (with deduplication)
     *
     * @param string $contentHash SHA256 hash
     * @param string $code Function code
     * @param int $accountId Account ID
     * @param int|null $userId User ID
     * @return void
     */
    private function storeFunctionVersionContent($contentHash, $code, $accountId, $userId) {
        $content = new ModelObject(KyteFunctionVersionContent);

        if ($content->retrieve('content_hash', $contentHash, [
            ['field' => 'kyte_account', 'value' => $accountId]
        ])) {
            // Content already exists - increment reference count
            $content->save([
                'reference_count' => $content->reference_count + 1,
                'last_referenced' => time(),
                'modified_by' => $userId ?? 0,
                'date_modified' => time(),
            ]);
        } else {
            // Create new content record
            $content->create([
                'content_hash' => $contentHash,
                'code' => bzcompress($code, 9),
                'reference_count' => 1,
                'kyte_account' => $accountId,
                'created_by' => $userId ?? 0,
                'date_created' => time(),
                'last_referenced' => time(),
            ]);
        }
    }

    /**
     * Regenerate controller code
     *
     * @param int $controllerId Controller ID
     * @return void
     */
    private function regenerateControllerCode($controllerId) {
        $controller = new ModelObject(Controller);
        if ($controller->retrieve('id', $controllerId)) {
            // Call ControllerController's generateCodeBase method
            \Kyte\Mvc\Controller\ControllerController::generateCodeBase($controller);
        }
    }

    /**
     * Rollback an applied fix
     *
     * @param ModelObject $analysis AIErrorAnalysis object
     * @return bool Success
     */
    public function rollback($analysis) {
        try {
            if (!in_array($analysis->fix_status, ['applied_manual', 'applied_auto'])) {
                throw new \Exception("Fix is not in applied state");
            }

            if (!$analysis->applied_function_version) {
                throw new \Exception("No version to rollback from");
            }

            // Get the function
            $function = new ModelObject(constant("Function"));
            if (!$function->retrieve('id', $analysis->function_id)) {
                throw new \Exception("Function not found");
            }

            // Get the version before the AI fix
            $versionModel = new \Kyte\Core\Model(KyteFunctionVersion);
            $versionModel->retrieve('function', $function->id, false, [
                ['field' => 'version_number', 'value' => 'version_number < (SELECT version_number FROM KyteFunctionVersion WHERE id = ' . $analysis->applied_function_version . ')', 'operator' => 'raw']
            ], false, [['field' => 'version_number', 'direction' => 'DESC']], 1);

            if ($versionModel->count() === 0) {
                throw new \Exception("No previous version found to rollback to");
            }

            $previousVersion = $versionModel->objects[0];

            // Get previous version content
            $content = new ModelObject(KyteFunctionVersionContent);
            if (!$content->retrieve('content_hash', $previousVersion->content_hash)) {
                throw new \Exception("Previous version content not found");
            }

            $previousCode = bzdecompress($content->code);

            // Restore previous code
            $function->save([
                'code' => bzcompress($previousCode, 9),
                'modified_by' => $this->api->user->id ?? 0,
                'date_modified' => time(),
            ]);

            // Mark previous version as current
            $previousVersion->save([
                'is_current' => 1,
                'modified_by' => $this->api->user->id ?? 0,
                'date_modified' => time(),
            ]);

            // Mark AI fix version as not current
            $aiFixVersion = new ModelObject(KyteFunctionVersion);
            if ($aiFixVersion->retrieve('id', $analysis->applied_function_version)) {
                $aiFixVersion->save([
                    'is_current' => 0,
                    'modified_by' => $this->api->user->id ?? 0,
                    'date_modified' => time(),
                ]);
            }

            // Mark analysis as caused_error or rejected
            $analysis->save([
                'fix_status' => 'rejected',
                'caused_new_error' => 1,
            ]);

            // Update config statistics
            $config = AIErrorCorrection::getConfig($analysis->application, $analysis->kyte_account);
            if ($config) {
                $config->save([
                    'total_failed_fixes' => $config->total_failed_fixes + 1,
                ]);
            }

            // Regenerate controller code
            $this->regenerateControllerCode($function->controller);

            error_log("AI Fix Rolled Back: Analysis {$analysis->id}, Function {$function->id}");

            return true;

        } catch (\Exception $e) {
            error_log("AI Fix Rollback Failed for analysis {$analysis->id}: " . $e->getMessage());
            return false;
        }
    }
}
