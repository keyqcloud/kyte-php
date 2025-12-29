<?php

namespace Kyte\Mvc\Controller;

class KyteScriptController extends ModelController
{
    /**
     * Store original include_all value to detect changes
     */
    private $originalIncludeAll = null;

    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }
    
    /**
     * Check if data has valid bzip2 magic bytes
     */
    private function isValidBzip2Data($data): bool {
        return is_string($data) && strlen($data) >= 2 && substr($data, 0, 2) === 'BZ';
    }

    /**
     * Safely compress code if not already compressed
     */
    private function safeCompressCode($code): string {
        if (empty($code) || $this->isValidBzip2Data($code)) {
            return $code; // Already compressed or empty
        }
        return bzcompress($code, 9);
    }

    /**
     * Safely decompress code if compressed
     */
    private function safeDecompressCode($code): string {
        if (!$this->isValidBzip2Data($code)) {
            return $code; // Not compressed
        }
        
        $decompressed = bzdecompress($code);
        if ($decompressed === false) {
            throw new \Exception("Failed to decompress code data - data may be corrupted");
        }
        
        return $decompressed;
    }
    
    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'new':
                $r['s3key'] = 'assets/'.$r['script_type'].'/'.strtolower(preg_replace('/[^A-Za-z0-9_.-\/]/', '-', $r['s3key']));
                break;
            case 'update':
                // Capture original include_all value BEFORE update to detect changes
                if ($o && isset($r['include_all'])) {
                    $this->originalIncludeAll = $o->include_all;
                }

                // Create version before updating if script exists
                if ($o && (isset($r['content']) || isset($r['content_js_obfuscated']))) {
                    $versionType = $r['version_type'] ?? 'manual_save';
                    $changeSummary = $r['change_summary'] ?? null;

                    // Check if content actually changed before creating version
                    if ($this->hasScriptChanged($o, $r)) {
                        $this->createScriptVersion($o, $r, $versionType, $changeSummary);
                    }
                }

                // Compress content for storage
                if (isset($r['content'])) {
                    $r['content'] = $this->safeCompressCode($r['content']);
                }
                if (isset($r['content_js_obfuscated'])) {
                    $r['content_js_obfuscated'] = $this->safeCompressCode($r['content_js_obfuscated']);
                }
                break;

            default:
                break;
        }
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'new':
                // Create initial version after script creation
                $this->createScriptVersion($o, $r, 'initial', 'Initial version');
                
                // Decompress content for response
                $this->decompressScriptContent($r);
                
                // Add version information to response
                $r['version_info'] = $this->getScriptVersionInfo($o->id);
                break;
                
            case 'get':
                $app = new \Kyte\Core\ModelObject(Application);
                if (!$app->retrieve('id', $r['site']['application']['id'])) {
                    throw new \Exception("CRITICAL ERROR: Unable to find application.");
                }
                $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
                $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);
                $r['download_link'] = $s3->getObject($o->s3key);
                
                // Decompress content
                $this->decompressScriptContent($r);
                
                // Add version information to response
                $r['version_info'] = $this->getScriptVersionInfo($o->id);
                break;
                
            case 'update':
                // Decompress content
                $this->decompressScriptContent($r);
                
                // Add updated version information to response
                $r['version_info'] = $this->getScriptVersionInfo($o->id);
                
                if ($o->state == 1 && !isset($d['state'])) {
                    $o->save(['state' => 2]);
                }
                if ((isset($d['state']) && $d['state'] == 1) || ($o->state == 1 && isset($d['include_all']) && $d['include_all'] == 1)) {
                    $this->handleScriptPublication($o, $r, $d);
                }
                break;

            case 'delete':
                // Clean up versions when script is deleted
                $this->cleanupScriptVersions($o->id);
                
                // Handle S3 cleanup and page regeneration
                if ($o->state > 0) {
                    $this->handleScriptDeletion($o);
                }
                break;
            
            default:
                break;
        }
    }

    /**
     * Decompress script content in response
     */
    private function decompressScriptContent(array &$r): void {
        if (isset($r['content'])) {
            $r['content'] = $this->safeDecompressCode($r['content']);
        }
        if (isset($r['content_js_obfuscated'])) {
            $r['content_js_obfuscated'] = $this->safeDecompressCode($r['content_js_obfuscated']);
        }
    }

    /**
     * Check if script content has actually changed
     */
    private function hasScriptChanged($scriptObj, $newData): bool {
        $currentData = $this->getCurrentScriptData($scriptObj->id);
        $changes = $this->detectScriptChanges($scriptObj, $currentData, $newData);
        return !empty($changes);
    }

    /**
     * Get current script data for comparison
     */
    private function getCurrentScriptData($scriptId): ?array {
        $script = new \Kyte\Core\ModelObject(constant("KyteScript"));
        if (!$script->retrieve('id', $scriptId)) {
            return null;
        }

        return [
            'content' => $this->safeDecompressCode($script->content ?? ''),
            'content_js_obfuscated' => $this->safeDecompressCode($script->content_js_obfuscated ?? ''),
        ];
    }

    /**
     * Detect what fields have changed in the script
     */
    private function detectScriptChanges($scriptObj, $currentData, $newData): array {
        $changes = [];

        // Check script metadata fields
        $metadataFields = ['name', 'description', 's3key', 'script_type', 'obfuscate_js', 'is_js_module', 'include_all', 'state'];

        foreach ($metadataFields as $field) {
            if (isset($newData[$field]) && $scriptObj->$field != $newData[$field]) {
                $changes[$field] = [
                    'old' => $scriptObj->$field,
                    'new' => $newData[$field]
                ];
            }
        }

        // Check content fields
        $contentFields = ['content', 'content_js_obfuscated'];
        
        foreach ($contentFields as $field) {
            $oldContent = $currentData[$field] ?? '';
            $newContent = $newData[$field] ?? '';
            
            // Ensure new content is decompressed for comparison
            $newContent = $this->safeDecompressCode($newContent);
            
            if ($oldContent !== $newContent) {
                $changes[$field] = [
                    'old_length' => strlen($oldContent),
                    'new_length' => strlen($newContent),
                    'changed' => true
                ];
            }
        }

        return $changes;
    }

    /**
     * Create a new script version if content has changed
     */
    private function createScriptVersion($scriptObj, $data, $versionType = 'manual_save', $changeSummary = null) {
        // Get current script data for comparison
        $currentData = $this->getCurrentScriptData($scriptObj->id);
        
        // Detect changes
        $changes = $this->detectScriptChanges($scriptObj, $currentData, $data);
        
        if (empty($changes) && $versionType !== 'initial') {
            return null; // No changes detected, don't create version
        }

        // Get next version number
        $nextVersion = $this->getNextScriptVersionNumber($scriptObj->id);

        // Create content hash for deduplication
        $contentHash = $this->generateScriptContentHash($data);

        // Check if this exact content already exists
        $existingContent = $this->findExistingScriptContent($contentHash);
        
        $versionData = [
            'script' => $scriptObj->id,
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
        $this->addChangedFieldsToScriptVersion($versionData, $changes, $scriptObj, $data);

        // Mark previous version as not current
        $this->markPreviousScriptVersionsAsNotCurrent($scriptObj->id);

        // Create the version record
        $version = new \Kyte\Core\ModelObject(KyteScriptVersion);
        if (!$version->create($versionData)) {
            throw new \Exception("CRITICAL ERROR: Unable to create script version.");
        }

        // Store or reference content
        if (!$existingContent) {
            $this->storeScriptVersionContent($contentHash, $data);
        } else {
            $this->incrementScriptContentReference($contentHash);
        }

        return $version;
    }

    /**
     * Generate content hash for deduplication (only from main content field)
     */
    private function generateScriptContentHash($data): string {
        $content = $data['content'] ?? '';
        
        // Ensure content is decompressed for consistent hashing
        $content = $this->safeDecompressCode($content);
        
        return hash('sha256', $content);
    }

    /**
     * Find existing content with same hash
     */
    private function findExistingScriptContent($contentHash): ?\Kyte\Core\ModelObject {
        $content = new \Kyte\Core\ModelObject(KyteScriptVersionContent);
        $conditions = [['field' => 'kyte_account', 'value' => $this->account->id]];
        
        return $content->retrieve('content_hash', $contentHash, $conditions) ? $content : null;
    }

    /**
     * Store new version content
     */
    private function storeScriptVersionContent($contentHash, $data): \Kyte\Core\ModelObject {
        $content = $data['content'] ?? '';
        $contentJsObfuscated = $data['content_js_obfuscated'] ?? '';
        
        // Ensure both are compressed for storage
        $content = $this->safeCompressCode($content);
        $contentJsObfuscated = $this->safeCompressCode($contentJsObfuscated);

        $contentData = [
            'content_hash' => $contentHash,
            'content' => $content,
            'content_js_obfuscated' => $contentJsObfuscated,
            'reference_count' => 1,
            'kyte_account' => $this->account->id,
            'created_by' => $this->user->id,
            'date_created' => time(),
            'last_referenced' => time(),
        ];

        $contentObj = new \Kyte\Core\ModelObject(KyteScriptVersionContent);
        if (!$contentObj->create($contentData)) {
            throw new \Exception("CRITICAL ERROR: Unable to store script version content.");
        }
        
        return $contentObj;
    }

    /**
     * Get the next version number for a script
     */
    private function getNextScriptVersionNumber($scriptId): int {
        $lastVersion = new \Kyte\Core\Model(KyteScriptVersion);
        $lastVersion->retrieve('script', $scriptId, false, null, false, [['field' => 'version_number', 'direction' => 'desc']], 1);
        
        if ($lastVersion->count() > 0) {
            return $lastVersion->objects[0]->version_number + 1;
        }
        
        return 1;
    }

    /**
     * Get version information for a script
     */
    private function getScriptVersionInfo($scriptId): array {
        $versions = new \Kyte\Core\Model(KyteScriptVersion);
        $versions->retrieve('script', $scriptId, false, null, false, [['field' => 'version_number', 'direction' => 'desc']], 10);
        
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
        $totalCount = new \Kyte\Core\Model(KyteScriptVersion);
        $totalCount->retrieve('script', $scriptId);
        $versionInfo['total_versions'] = $totalCount->count();

        return $versionInfo;
    }

    /**
     * Mark all previous versions as not current
     */
    private function markPreviousScriptVersionsAsNotCurrent($scriptId): void {
        $lastVersion = new \Kyte\Core\ModelObject(KyteScriptVersion);
        if ($lastVersion->retrieve('script', $scriptId, [['field' => 'is_current', 'value' => 1]])) {
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
    private function addChangedFieldsToScriptVersion(&$versionData, $changes, $scriptObj, $newData): void {
        // Add changed metadata fields
        $metadataFields = ['name', 'description', 's3key', 'script_type', 'obfuscate_js', 'is_js_module', 'include_all', 'state'];

        foreach ($metadataFields as $field) {
            if (isset($changes[$field])) {
                $versionData[$field] = $newData[$field] ?? $scriptObj->$field;
            }
        }
    }

    /**
     * Increment reference count for existing content
     */
    private function incrementScriptContentReference($contentHash): void {
        $content = new \Kyte\Core\ModelObject(KyteScriptVersionContent);
        $conditions = [['field' => 'kyte_account', 'value' => $this->account->id]];
        
        if ($content->retrieve('content_hash', $contentHash, $conditions)) {
            $content->save([
                'reference_count' => $content->reference_count + 1,
                'last_referenced' => time(),
                'modified_by' => $this->user->id,
                'date_modified' => time(),
            ]);
        }
    }

    /**
     * Decrement content reference count and clean up if needed
     */
    private function decrementScriptContentReference($contentHash): void {
        $content = new \Kyte\Core\ModelObject(KyteScriptVersionContent);
        $conditions = [['field' => 'kyte_account', 'value' => $this->account->id]];
        
        if ($content->retrieve('content_hash', $contentHash, $conditions)) {
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
     * Clean up all versions when a script is deleted
     */
    private function cleanupScriptVersions($scriptId): void {
        $versions = new \Kyte\Core\Model(KyteScriptVersion);
        $versions->retrieve('script', $scriptId);
        
        foreach ($versions->objects as $version) {
            $this->decrementScriptContentReference($version->content_hash);
            $version->delete();
        }
        
        $this->cleanupUnreferencedScriptContent();
    }

    /**
     * Clean up content records with zero references
     */
    private function cleanupUnreferencedScriptContent(): void {
        $contents = new \Kyte\Core\Model(KyteScriptVersionContent);
        $contents->retrieve('reference_count', 0);
        foreach ($contents->objects as $content) {
            $content->delete();
        }
    }

    /**
     * Handle script publication (extracted from original update case)
     */
    private function handleScriptPublication($o, $r, $d): void {
        $app = new \Kyte\Core\ModelObject(Application);
        if (!$app->retrieve('id', $r['site']['application']['id'])) {
            throw new \Exception("CRITICAL ERROR: Unable to find application.");
        }

        // publish file to s3
        $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
        $s3 = new \Kyte\Aws\S3($credential, $r['site']['s3BucketName']);

        // write script to file
        $content = ($o->script_type == 'css') ? $r['content'] : 
                ($o->obfuscate_js ? $r['content_js_obfuscated'] : $r['content']);

        $s3->write($o->s3key, $content);

        // Only delete assignments if include_all CHANGED from 1 to 0
        // This preserves manual page assignments when republishing without include_all enabled
        if (isset($d['include_all']) && $d['include_all'] == 0 && $this->originalIncludeAll == 1) {
            // User changed include_all from 1 to 0, remove all script assignments for this script
            $scriptAssignments = new \Kyte\Core\Model(KyteScriptAssignment);
            $scriptAssignments->retrieve('script', $o->id);

            foreach ($scriptAssignments->objects as $assignment) {
                $assignment->delete();
            }
        }

        $this->updatePagesForScript($o, $r, $d, $s3);
        $this->invalidateCloudFront($r);

        // Reset original value for next request
        $this->originalIncludeAll = null;
    }

    /**
     * Handle script deletion (extracted from original delete case)
     */
    private function handleScriptDeletion($o): void {
        $d = $this->getObject($o);
        $app = new \Kyte\Core\ModelObject(Application);
        if (!$app->retrieve('id', $d['site']['application']['id'])) {
            throw new \Exception("CRITICAL ERROR: Unable to find application.");
        }

        // delete file
        $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
        $s3 = new \Kyte\Aws\S3($credential, $d['site']['s3BucketName']);
        
        if (!empty($o->s3key)) {
            // delete s3 file
            $s3->unlink($o->s3key);

            // remove script assignments
            $assignments = new \Kyte\Core\Model(KyteScriptAssignment);
            $assignments->retrieve('script', $o->id);
            foreach ($assignments->objects as $assignment) {
                $assignment->delete();
            }

            $this->regeneratePagesAfterScriptDeletion($d, $s3);
            $this->invalidateCloudFrontForDeletion($d);
        }
    }

    /**
     * Update pages when script is published
     */
    private function updatePagesForScript($o, $r, $d, $s3): void {
        $pages = new \Kyte\Core\Model(KytePage);
        $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $r['site']['id']]]);

        // iterate through each page
        foreach($pages->objects as $page) {
            // if script was marked as include_all then update assignments for each page.
            if (($o->include_all == 1 && (!isset($d['include_all']) || $d['include_all'] === '' || $d['include_all'] == 1)) || (isset($d['include_all']) && $d['include_all'] == 1)) {
                // check if assignment already exists
                $scriptAssignment = new \Kyte\Core\ModelObject(KyteScriptAssignment);
                if (!$scriptAssignment->retrieve('script', $o->id, [['field' => 'page', 'value' => $page->id]])) {
                    // create new assignment
                    if (!$scriptAssignment->create([
                        'script'        => $o->id,
                        'global_scope'  => 1,
                        'page'          => $page->id,
                        'site'          => $page->site,
                        'kyte_account'  => $page->kyte_account,
                    ], $this->api->user->id)) {
                        throw new \Exception("Failed to assign script {$o->s3key} to page '{$page->title}'");
                    }
                }
            }

            $this->regeneratePageHtml($page, $s3);
        }
    }

    /**
     * Regenerate pages after script deletion
     */
    private function regeneratePagesAfterScriptDeletion($d, $s3): void {
        $pages = new \Kyte\Core\Model(KytePage);
        $pages->retrieve("state", 1, false, [['field' => 'site', 'value' => $d['site']['id']]]);

        // iterate through each page
        foreach($pages->objects as $page) {
            $this->regeneratePageHtml($page, $s3);
        }
    }

    /**
     * Regenerate HTML for a specific page
     */
    private function regeneratePageHtml($page, $s3): void {
        $params = $this->getObject($page);
        $pd = new \Kyte\Core\ModelObject(KytePageData);
        if (!$pd->retrieve('page', $page->id)) {
            throw new \Exception("CRITICAL ERROR: Unable to find page data.");
        }
        
        $params['html'] = bzdecompress($pd->html);
        $params['stylesheet'] = bzdecompress($pd->stylesheet);
        $params['javascript'] = bzdecompress($pd->javascript);
        $params['javascript_obfuscated'] = bzdecompress($pd->javascript_obfuscated);
        
        // footers and headers
        if ($params['footer'] && isset($params['footer']['html'], $params['footer']['stylesheet'], $params['footer']['javascript'], $params['footer']['javascript_obfuscated'], $params['footer']['block_layout'])) {
            $params['footer']['html'] = bzdecompress($params['footer']['html']);
            $params['footer']['stylesheet'] = bzdecompress($params['footer']['stylesheet']);
            $params['footer']['javascript'] = bzdecompress($params['footer']['javascript']);
            $params['footer']['javascript_obfuscated'] = bzdecompress($params['footer']['javascript_obfuscated']);
            $params['footer']['block_layout'] = bzdecompress($params['footer']['block_layout']);
        }
        if ($params['header'] && isset($params['header']['html'], $params['header']['stylesheet'], $params['header']['javascript'], $params['header']['javascript_obfuscated'], $params['header']['block_layout'])) {
            $params['header']['html'] = bzdecompress($params['header']['html']);
            $params['header']['stylesheet'] = bzdecompress($params['header']['stylesheet']);
            $params['header']['javascript'] = bzdecompress($params['header']['javascript']);
            $params['header']['javascript_obfuscated'] = bzdecompress($params['header']['javascript_obfuscated']);
            $params['header']['block_layout'] = bzdecompress($params['header']['block_layout']);
        }
        
        // compile html file
        $data = \Kyte\Mvc\Controller\KytePageController::createHtml($params);
        // write to file
        $s3->write($page->s3key, $data);
    }

    /**
     * Invalidate CloudFront for script updates
     */
    private function invalidateCloudFront($r): void {
        $invalidationPaths = ['/*'];
        if (KYTE_USE_SNS) {
            $credential = new \Kyte\Aws\Credentials(SNS_REGION);
            $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
            $sns->publish([
                'action' => 'cf_invalidate',
                'site_id' => $r['site']['id'],
                'cf_id' => $r['site']['cfDistributionId'],
                'cf_invalidation_paths' => $invalidationPaths,
                'caller_id' => time(),
            ]);
        } else {
            // invalidate CF
            $app = new \Kyte\Core\ModelObject(Application);
            $app->retrieve('id', $r['site']['application']['id']);
            $credential = new \Kyte\Aws\Credentials($r['site']['region'], $app->aws_public_key, $app->aws_private_key);
            $cf = new \Kyte\Aws\CloudFront($credential);
            $cf->createInvalidation($r['site']['cfDistributionId'], $invalidationPaths);
        }
    }

    /**
     * Invalidate CloudFront for script deletion
     */
    private function invalidateCloudFrontForDeletion($d): void {
        $invalidationPaths = ['/*'];
        if (KYTE_USE_SNS) {
            $credential = new \Kyte\Aws\Credentials(SNS_REGION);
            $sns = new \Kyte\Aws\Sns($credential, SNS_QUEUE_SITE_MANAGEMENT);
            $sns->publish([
                'action' => 'cf_invalidate',
                'site_id' => $d['site']['id'],
                'cf_id' => $d['site']['cfDistributionId'],
                'cf_invalidation_paths' => $invalidationPaths,
                'caller_id' => time(),
            ]);
        } else {
            // invalidate CF
            $app = new \Kyte\Core\ModelObject(Application);
            $app->retrieve('id', $d['site']['application']['id']);
            $credential = new \Kyte\Aws\Credentials($d['site']['region'], $app->aws_public_key, $app->aws_private_key);
            $cf = new \Kyte\Aws\CloudFront($credential);
            $cf->createInvalidation($d['site']['cfDistributionId'], $invalidationPaths);
        }
    }
}