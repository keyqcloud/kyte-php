<?php

namespace Kyte\Mvc\Controller;

class FunctionController extends ModelController
{
    // Configuration for function types and their templates
    private const FUNCTION_TYPES = [
        'hook_init' => [
            'template' => "public function hook_init() {\r\n\t\r\n}\r\n",
            'is_hook' => true
        ],
        'hook_auth' => [
            'template' => "public function hook_auth() {\r\n\t\r\n}\r\n",
            'is_hook' => true
        ],
        'hook_prequery' => [
            'template' => "public function hook_prequery(\$method, &\$field, &\$value, &\$conditions, &\$all, &\$order) {\r\n{switch_statement}}\r\n",
            'is_hook' => true
        ],
        'hook_preprocess' => [
            'template' => "public function hook_preprocess(\$method, &\$r, &\$o = null) {\r\n{switch_statement}}\r\n",
            'is_hook' => true
        ],
        'hook_response_data' => [
            'template' => "public function hook_response_data(\$method, \$o, &\$r = null, &\$d = null) {\r\n{switch_statement}}\r\n",
            'is_hook' => true
        ],
        'hook_process_get_response' => [
            'template' => "public function hook_process_get_response(&\$r) {\r\n\r\n}\r\n",
            'is_hook' => true
        ],
        'new' => [
            'template' => "public function new(\$data) {\r\n\r\n}\r\n",
            'is_hook' => false
        ],
        'update' => [
            'template' => "public function update(\$field, \$value, \$data) {\r\n\r\n}\r\n",
            'is_hook' => false
        ],
        'get' => [
            'template' => "public function get(\$field, \$value) {\r\n\r\n}\r\n",
            'is_hook' => false
        ],
        'delete' => [
            'template' => "public function delete(\$field, \$value) {\r\n\r\n}\r\n",
            'is_hook' => false
        ],
        'custom' => [
            'template' => "",
            'is_hook' => false
        ]
    ];

    private const SWITCH_STATEMENT = "\tswitch (\$method) {\r\n\t\tcase 'new':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'update':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'get':\r\n\t\t\tbreak;\r\n\r\n\t\tcase 'delete':\r\n\t\t\tbreak;\r\n\r\n\t\tdefault:\r\n\t\t\tbreak;\r\t}\r\n";

    // Methods that require controller code base regeneration
    private const REGENERATION_METHODS = ['update', 'delete'];

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $this->processNewFunction($r);
                break;
            case 'update':
                $this->processUpdateFunction($r, $o);
                break;
            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                // Create initial version after function creation
                $this->createFunctionVersion($o, $r, 'initial', 'Initial version');
                $this->decompressCode($r);
                
                // Add version information to response
                $r['version_info'] = $this->getFunctionVersionInfo($o->id);
                break;
                
            case 'get':
                $this->decompressCode($r);
                
                // Add version information to response
                $r['version_info'] = $this->getFunctionVersionInfo($o->id);
                break;
                
            case 'update':
                $this->handleCodeRegeneration($o, $method);
                $this->decompressCode($r);
                
                // Add updated version information to response
                $r['version_info'] = $this->getFunctionVersionInfo($o->id);
                break;
                
            case 'delete':
                // Clean up versions when function is deleted
                $this->cleanupFunctionVersions($o->id);
                $this->handleCodeRegeneration($o, $method);
                break;
                
            default:
                break;
        }
    }

    private function isValidBzip2Data($data) {
        // bzip2 data always starts with 'BZ' magic bytes
        return strlen($data) >= 2 && substr($data, 0, 2) === 'BZ';
    }

    /**
     * Process new function creation
     */
    private function processNewFunction(array &$r): void
    {
        $type = $r['type'] ?? '';
        
        if (!isset(self::FUNCTION_TYPES[$type])) {
            throw new \InvalidArgumentException("Invalid function type: {$type}");
        }

        $this->validateFunctionUniqueness($r['controller'], $type);
        
        $config = self::FUNCTION_TYPES[$type];
        $code = $this->generateCodeFromTemplate($config['template']);
        
        if (!empty($code) && !$this->isValidBzip2Data($code)) {
            $r['code'] = bzcompress($code, 9);
        }
    }

    /**
     * Process function update with version control
     */
    private function processUpdateFunction(array &$r, $o = null): void
    {
        if ($o && isset($r['code'])) {
            $versionType = isset($r['version_type']) ? $r['version_type'] : 'manual_save';
            $changeSummary = isset($r['change_summary']) ? $r['change_summary'] : null;
            
            // Check if content actually changed and create version before updating
            $this->createFunctionVersion($o, $r, $versionType, $changeSummary);
        }
        
        // Only compress if not already compressed
        if (isset($r['code']) && is_string($r['code'])) {
            // Check if already compressed
            if (!empty($r['code']) && !$this->isValidBzip2Data($r['code'])) {
                $r['code'] = bzcompress($r['code'], 9);
            }
        }
    }

    /**
     * Validate that function doesn't already exist for the controller
     */
    private function validateFunctionUniqueness(int $controllerId, string $type): void
    {
        if ($type == 'custom') return;  // allow for multiple custom types

        $functionModel = new \Kyte\Core\Model(constant("Function"));
        $functionModel->retrieve('controller', $controllerId, false, [
            ['field' => 'type', 'value' => $type]
        ]);

        if ($functionModel->count() > 0) {
            $config = self::FUNCTION_TYPES[$type];
            $errorType = $config['is_hook'] ? 'Hook' : 'Override';
            throw new \Exception("{$errorType} of type {$type} already exists for this controller.");
        }
    }

    /**
     * Generate code from template
     */
    private function generateCodeFromTemplate(string $template): string
    {
        return str_replace('{switch_statement}', self::SWITCH_STATEMENT, $template);
    }

    /**
     * Decompress code data
     */
    private function decompressCode(array &$r): void
    {
        if (isset($r['code'])) {
            if ($this->isValidBzip2Data($r['code'])) {
                $decompressed = bzdecompress($r['code']);
                if ($decompressed === false) {
                    throw new \Exception("Failed to decompress code data - data may be corrupted");
                }
                $r['code'] = $decompressed;
            }
            // If not valid bzip2 data, assume it's already decompressed
        }
    }

    /**
     * Handle controller code base regeneration
     */
    private function handleCodeRegeneration($o, string $method): void
    {
        if (!in_array($method, self::REGENERATION_METHODS)) {
            return;
        }

        $ctrl = new \Kyte\Core\ModelObject(constant("Controller"));
        if (!$ctrl->retrieve("id", $o->controller)) {
            throw new \Exception("Unable to find specified controller.");
        }

        // Update code base and save to file
        ControllerController::generateCodeBase($ctrl);
    }

    /**
     * Create a new function version if content has changed
     */
    private function createFunctionVersion($functionObj, $data, $versionType = 'manual_save', $changeSummary = null) {
        // Get current function data for comparison
        $currentData = $this->getCurrentFunctionData($functionObj->id);
        
        // Detect changes
        $changes = $this->detectFunctionChanges($functionObj, $currentData, $data);
        
        if (empty($changes) && $versionType !== 'initial') {
            return null; // No changes detected, don't create version
        }

        // Get next version number
        $nextVersion = $this->getNextFunctionVersionNumber($functionObj->id);

        // Create content hash for deduplication
        $contentHash = $this->generateFunctionContentHash($data);

        // Check if this exact content already exists
        $existingContent = $this->findExistingFunctionContent($contentHash);
        
        $versionData = [
            'function' => $functionObj->id,
            'version_number' => $nextVersion,
            'version_type' => $versionType,
            'change_summary' => $changeSummary,
            'changes_detected' => json_encode($changes),
            'content_hash' => $contentHash,
            'is_current' => 1,
            'kyte_account' => $this->account->id,
            'created_by' => $this->user->id,
            'date_created' => time(),
        ];

        // Only store changed fields to save space
        $this->addChangedFieldsToFunctionVersion($versionData, $changes, $functionObj, $data);

        // Mark previous version as not current
        $this->markPreviousFunctionVersionsAsNotCurrent($functionObj->id);

        // Create the version record
        $version = new \Kyte\Core\ModelObject(KyteFunctionVersion);
        if (!$version->create($versionData)) {
            throw new \Exception("CRITICAL ERROR: Unable to create function version.");
        }

        // Store or reference content
        if (!$existingContent) {
            $this->storeFunctionVersionContent($contentHash, $data);
        } else {
            $this->incrementFunctionContentReference($contentHash);
        }

        return $version;
    }

    /**
     * Get current function data for comparison
     */
    private function getCurrentFunctionData($functionId) {
        $func = new \Kyte\Core\ModelObject(constant("Function"));
        if (!$func->retrieve('id', $functionId)) {
            return null;
        }

        return [
            'code' => $this->isValidBzip2Data($func->code) ? bzdecompress($func->code) : $func->code,
        ];
    }

    /**
     * Detect what fields have changed in the function
     */
    private function detectFunctionChanges($functionObj, $currentData, $newData) {
        $changes = [];

        // Check function metadata fields
        $metadataFields = ['name', 'description', 'type', 'kyte_locked'];

        foreach ($metadataFields as $field) {
            if (isset($newData[$field]) && $functionObj->$field != $newData[$field]) {
                $changes[$field] = [
                    'old' => $functionObj->$field,
                    'new' => $newData[$field]
                ];
            }
        }

        // Check code content
        $oldCode = isset($currentData['code']) ? $currentData['code'] : '';
        $newCode = isset($newData['code']) ? $newData['code'] : '';
        
        // For new data, code might be compressed, so decompress if needed
        if (is_string($newCode) && strlen($newCode) > 0) {
            if ($this->isValidBzip2Data($newCode)) {
                $decompressed = bzdecompress($newCode);
                if ($decompressed !== false) {
                    $newCode = $decompressed;
                } else {
                    // Valid bzip2 magic bytes but decompression failed - data is corrupted
                    throw new \Exception("Corrupted compressed code data detected");
                }
            }
            // If not valid bzip2 data, assume it's already uncompressed
        }
        
        if ($oldCode !== $newCode) {
            $changes['code'] = [
                'old_length' => strlen($oldCode),
                'new_length' => strlen($newCode),
                'changed' => true
            ];
        }

        return $changes;
    }

    /**
     * Get version information for a function
     */
    private function getFunctionVersionInfo($functionId) {
        $versions = new \Kyte\Core\Model(KyteFunctionVersion);
        $versions->retrieve('function', $functionId, false, null, false, [['field' => 'version_number', 'direction' => 'desc']], 10);
        
        $versionInfo = [
            'current_version' => 0,
            'total_versions' => 0,
            'recent_versions' => []
        ];

        foreach ($versions->objects as $version) {
            if ($version->is_current) {
                $versionInfo['current_version'] = $version->version_number;
            }
            
            $versionInfo['recent_versions'][] = [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'version_type' => $version->version_type,
                'change_summary' => $version->change_summary,
                'changes_detected' => json_decode($version->changes_detected, true),
                'date_created' => $version->date_created,
                'created_by' => $version->created_by,
                'is_current' => (bool)$version->is_current
            ];
        }

        // Get total count
        $totalCount = new \Kyte\Core\Model(KyteFunctionVersion);
        $totalCount->retrieve('function', $functionId);
        $versionInfo['total_versions'] = $totalCount->count();

        return $versionInfo;
    }

    /**
     * Get the next version number for a function
     */
    private function getNextFunctionVersionNumber($functionId) {
        $lastVersion = new \Kyte\Core\Model(KyteFunctionVersion);
        $lastVersion->retrieve('function', $functionId, false, null, false, [['field' => 'version_number', 'direction' => 'desc']], 1);
        
        if ($lastVersion->count() > 0) {
            return $lastVersion->objects[0]->version_number + 1;
        }
        
        return 1;
    }

    /**
     * Generate content hash for deduplication
     */
    private function generateFunctionContentHash($data) {
        $code = isset($data['code']) ? $data['code'] : '';

        error_log("CODE IS ****** ".$code);
        
        // If code is compressed, decompress it for consistent hashing
        if (is_string($code) && strlen($code) > 0) {
            if ($this->isValidBzip2Data($code)) {
                $decompressed = @bzdecompress($code);
                if ($decompressed !== false) {
                    $code = $decompressed;
                }
            }
        }
        return hash('sha256', $code);
    }

    /**
     * Find existing content with same hash
     */
    private function findExistingFunctionContent($contentHash) {
        $content = new \Kyte\Core\ModelObject(KyteFunctionVersionContent);
        return $content->retrieve('content_hash', $contentHash, [['field' => 'kyte_account', 'value' => $this->account->id]]) ? $content : null;
    }

    /**
     * Store new version content
     */
    private function storeFunctionVersionContent($contentHash, $data) {
        $code = isset($data['code']) ? $data['code'] : '';
        
        // If code is not compressed, compress it
        if (is_string($code) && strlen($code) > 0) {
            if (!empty($code) && !$this->isValidBzip2Data($code)) {
                $code = bzcompress($code, 9);
            }
        }

        $contentData = [
            'content_hash' => $contentHash,
            'code' => $code,
            'reference_count' => 1,
            'kyte_account' => $this->account->id,
            'created_by' => $this->user->id,
            'date_created' => time(),
            'last_referenced' => time(),
        ];

        $content = new \Kyte\Core\ModelObject(KyteFunctionVersionContent);
        if (!$content->create($contentData)) {
            throw new \Exception("CRITICAL ERROR: Unable to store function version content.");
        }
        
        return $content;
    }

    /**
     * Increment reference count for existing content
     */
    private function incrementFunctionContentReference($contentHash) {
        $content = new \Kyte\Core\ModelObject(KyteFunctionVersionContent);
        if ($content->retrieve('content_hash', $contentHash, [['field' => 'kyte_account', 'value' => $this->account->id]])) {
            $content->save([
                'reference_count' => $content->reference_count + 1,
                'last_referenced' => time(),
                'modified_by' => $this->user->id,
                'date_modified' => time(),
            ]);
        }
    }

    /**
     * Mark all previous versions as not current
     */
    private function markPreviousFunctionVersionsAsNotCurrent($functionId) {
        $lastVersion = new \Kyte\Core\ModelObject(KyteFunctionVersion);
        if ($lastVersion->retrieve('function', $functionId, [['field' => 'is_current', 'value' => 1]])) {
            $lastVersion->save([
                'is_current' => 0,
                'modified_by' => $this->user->id,
                'date_modified' => time(),
            ]);
        }
    }

    /**
     * Add only changed fields to version data to save space
     */
    private function addChangedFieldsToFunctionVersion(&$versionData, $changes, $functionObj, $newData) {
        // Add changed metadata fields
        $metadataFields = ['name', 'description', 'function_type', 'kyte_locked'];

        foreach ($metadataFields as $field) {
            if (isset($changes[$field])) {
                $dbField = $field === 'function_type' ? 'type' : $field; // Map function_type to type
                $versionData[$field] = isset($newData[$dbField]) ? $newData[$dbField] : $functionObj->$dbField;
            }
        }
    }

    /**
     * Decrement content reference count and clean up if needed
     */
    private function decrementFunctionContentReference($contentHash) {
        $content = new \Kyte\Core\ModelObject(KyteFunctionVersionContent);
        if ($content->retrieve('content_hash', $contentHash, [['field' => 'kyte_account', 'value' => $this->account->id]])) {
            if ($content->reference_count <= 1) {
                $content->delete();
            } else {
                $content->save([
                    'reference_count' => $content->reference_count - 1,
                    'modified_by' => $this->user->id,
                    'date_modified' => time(),
                ]);
            }
        }
    }

    /**
     * Clean up content records with zero references
     */
    private function cleanupUnreferencedFunctionContent() {
        $contents = new \Kyte\Core\Model(KyteFunctionVersionContent);
        $contents->retrieve('reference_count', 0);
        foreach ($contents->objects as $content) {
            $content->delete();
        }
    }

    /**
     * Clean up all versions when a function is deleted
     */
    private function cleanupFunctionVersions($functionId) {
        $versions = new \Kyte\Core\Model(KyteFunctionVersion);
        $versions->retrieve('function', $functionId);
        
        foreach ($versions->objects as $version) {
            $this->decrementFunctionContentReference($version->content_hash);
            $version->delete();
        }
        
        $this->cleanupUnreferencedFunctionContent();
    }
}