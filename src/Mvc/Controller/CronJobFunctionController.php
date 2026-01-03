<?php

namespace Kyte\Mvc\Controller;

use Kyte\Cron\CronJobCodeGenerator;
use Kyte\Core\DBI;
use Kyte\Core\Model;
use Kyte\Core\ModelObject;

/**
 * Controller for CronJobFunction model
 *
 * Handles CRUD operations for individual cron job functions (execute, setUp, tearDown).
 * Automatically handles:
 * - Content validation
 * - Content deduplication via SHA256 hashing
 * - Compression with bzip2
 * - Version control for each function
 * - Class code regeneration after changes
 */
class CronJobFunctionController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }

    /**
     * Override new() to handle function creation with version control
     */
    public function new($data)
    {
        // Validate function name
        if (empty($data['name']) || !in_array($data['name'], ['execute', 'setUp', 'tearDown'])) {
            $this->respondError('Function name must be execute, setUp, or tearDown', 400);
            return;
        }

        // Validate cron_job exists
        if (empty($data['cron_job'])) {
            $this->respondError('cron_job ID is required', 400);
            return;
        }

        $cronJob = new ModelObject(CronJob);
        if (!$cronJob->retrieve('id', $data['cron_job']) || !isset($cronJob->id)) {
            $this->respondError('Cron job not found', 404);
            return;
        }

        // Get function body from request (temporary field, not stored in CronJobFunction)
        $functionBody = $data['function_body'] ?? '';
        unset($data['function_body']); // Don't store this field

        // Validate function body
        $validation = CronJobCodeGenerator::validateFunctionBody($functionBody, $data['name']);
        if (!$validation['valid']) {
            $this->respondError($validation['error'], 400);
            return;
        }

        // Calculate content hash
        $contentHash = hash('sha256', $functionBody);

        // Check if content already exists
        $existingContent = new ModelObject(CronJobFunctionContent);
        $contentFound = $existingContent->retrieve('content_hash', $contentHash);

        if ($contentFound && isset($existingContent->id)) {
            // Increment reference count
            $existingContent->reference_count++;
            $existingContent->save([
                'reference_count' => $existingContent->reference_count
            ], $this->api->user ? $this->api->user->id : null);
        } else {
            // Create new content record
            $compressed = bzcompress($functionBody, 9);

            $contentData = [
                'content_hash' => $contentHash,
                'content' => $compressed,
                'reference_count' => 1,
                'created_by' => $this->api->user ? $this->api->user->id : null,
                'date_created' => time()
            ];

            $contentObj = new ModelObject(CronJobFunctionContent);
            $contentObj->create($contentData, $this->api->user ? $this->api->user->id : null);
        }

        // Set content hash in function data
        $data['content_hash'] = $contentHash;

        // Set account/application from parent cron job
        $data['kyte_account'] = $cronJob->kyte_account;
        $data['application'] = $cronJob->application;

        // Create the function record
        parent::new($data);

        if (!empty($this->response['data'][0])) {
            $functionId = $this->response['data'][0]['id'];

            // Create initial version (version 1)
            $this->createVersion($functionId, $contentHash, 1, 'Initial version', $this->api->user ? $this->api->user->id : null);

            // Regenerate complete job class
            CronJobCodeGenerator::regenerateJobCode($data['cron_job']);

            // Note: Version created successfully (logged to response)
        }
    }

    /**
     * Override update() to handle function updates with version control
     */
    public function update($field, $value, $data)
    {
        $functionId = $value;

        $function = new ModelObject(CronJobFunction);
        if (!$function->retrieve('id', $functionId) || !isset($function->id)) {
            $this->respondError('Function not found', 404);
            return;
        }

        // Get function body from request
        $functionBody = $data['function_body'] ?? null;
        unset($data['function_body']);

        if ($functionBody !== null) {
            // Validate function body
            $validation = CronJobCodeGenerator::validateFunctionBody($functionBody, $function->name);
            if (!$validation['valid']) {
                $this->respondError($validation['error'], 400);
                return;
            }

            // Calculate new content hash
            $newContentHash = hash('sha256', $functionBody);

            // Check if content changed
            if ($newContentHash !== $function->content_hash) {
                // Get current version number
                $currentVersionSql = "
                    SELECT MAX(version_number) as max_version
                    FROM CronJobFunctionVersion
                    WHERE cron_job_function = ? AND deleted = 0
                ";
                $versionResult = DBI::prepared_query($currentVersionSql, 'i', [$functionId]);
                $nextVersion = ($versionResult[0]['max_version'] ?? 0) + 1;

                // Check if new content already exists
                $existingContent = new ModelObject(CronJobFunctionContent);
                $newContentFound = $existingContent->retrieve('content_hash', $newContentHash);

                if (!$newContentFound || !isset($existingContent->id)) {
                    // Create new content record
                    $compressed = bzcompress($functionBody, 9);

                    $contentData = [
                        'content_hash' => $newContentHash,
                        'content' => $compressed,
                        'reference_count' => 1,
                        'created_by' => $this->api->user ? $this->api->user->id : null,
                        'date_created' => time()
                    ];

                    $contentObj = new ModelObject(CronJobFunctionContent);
                    $contentObj->create($contentData, $this->api->user ? $this->api->user->id : null);
                } else {
                    // Increment reference count
                    $existingContent->reference_count++;
                    $existingContent->save([
                        'reference_count' => $existingContent->reference_count
                    ], $this->api->user ? $this->api->user->id : null);
                }

                // Decrement old content reference count
                if ($function->content_hash) {
                    $oldContent = new ModelObject(CronJobFunctionContent);
                    if ($oldContent->retrieve('content_hash', $function->content_hash) && isset($oldContent->id) && $oldContent->reference_count > 0) {
                        $oldContent->reference_count--;
                        $oldContent->save([
                            'reference_count' => $oldContent->reference_count
                        ], $this->api->user ? $this->api->user->id : null);
                    }
                }

                // Update function with new content hash
                $function->content_hash = $newContentHash;
                $function->modified_by = $this->api->user ? $this->api->user->id : null;
                $function->date_modified = time();

                // Pass the fields that changed to save()
                $function->save([
                    'content_hash' => $newContentHash,
                    'modified_by' => $this->api->user ? $this->api->user->id : null,
                    'date_modified' => time()
                ], $this->api->user ? $this->api->user->id : null);

                // Create new version
                $this->createVersion($functionId, $newContentHash, $nextVersion, 'Code updated', $this->api->user ? $this->api->user->id : null);

                // Regenerate complete job class
                CronJobCodeGenerator::regenerateJobCode($function->cron_job);

                // Note: Version updated successfully (version info will be in response)
            }
        }

        // Build response with updated function data
        $responseData = $function->getAllParams($this->dateformat);

        // Call hook_response_data to add decompressed function body and version info
        $this->hook_response_data('update', $function, $responseData);

        // Set response
        $this->response['data'] = [$responseData];

        // Don't echo here - let the parent controller handle the response
        // The Api class will automatically json_encode and output $this->response
    }

    /**
     * Override get() to include decompressed function body
     */
    public function hook_response_data($method, $o, &$r = null, &$d = null)
    {
        if (($method === 'get' || $method === 'new' || $method === 'update') && isset($r['content_hash'])) {
            // Load and decompress function body
            $contentSql = "SELECT content FROM CronJobFunctionContent WHERE content_hash = ?";
            $contentResult = DBI::prepared_query($contentSql, 's', [$r['content_hash']]);

            if (!empty($contentResult)) {
                $compressed = $contentResult[0]['content'];

                // Check if it's valid bzip2 data (same as FunctionController)
                if (strlen($compressed) >= 2 && substr($compressed, 0, 2) === 'BZ') {
                    $decompressed = bzdecompress($compressed);

                    if ($decompressed === false) {
                        $r['function_body'] = '';
                        $r['decompression_error'] = true;
                    } else {
                        // Clean UTF-8 for JSON compatibility (handle bad data from frontend saves)
                        $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $decompressed);
                        $r['function_body'] = ($cleaned !== false) ? $cleaned : $decompressed;
                    }
                } else {
                    // Not bzip2 data, assume already decompressed
                    $r['function_body'] = $compressed;
                }
            } else {
                // Content not found
                $r['function_body'] = '';
                $r['content_missing'] = true;
            }

            // Add version info (check if $o has id property)
            if (isset($o->id)) {
                $versionSql = "
                    SELECT version_number, is_current, date_created
                    FROM CronJobFunctionVersion
                    WHERE cron_job_function = ? AND is_current = 1 AND deleted = 0
                    LIMIT 1
                ";
                $versionResult = DBI::prepared_query($versionSql, 'i', [$o->id]);

                if (!empty($versionResult)) {
                    $r['current_version'] = $versionResult[0];
                }
            }
        }

        // Remove compressed code field from FK object (contains binary data that breaks JSON encoding)
        if (isset($r['cron_job']) && is_array($r['cron_job']) && isset($r['cron_job']['code'])) {
            unset($r['cron_job']['code']);
        }
    }

    /**
     * Create a new version record
     */
    private function createVersion(int $functionId, string $contentHash, int $versionNumber, string $description, ?int $userId): void
    {
        // Get kyte_account from the function
        $function = new ModelObject(CronJobFunction);
        if (!$function->retrieve('id', $functionId)) {
            return;
        }

        // Mark all previous versions as not current
        $updateSql = "
            UPDATE CronJobFunctionVersion
            SET is_current = 0
            WHERE cron_job_function = ? AND deleted = 0
        ";
        DBI::prepared_query($updateSql, 'i', [$functionId]);

        // Create new version
        $versionData = [
            'cron_job_function' => $functionId,
            'version_number' => $versionNumber,
            'content_hash' => $contentHash,
            'is_current' => 1,
            'change_description' => $description,
            'kyte_account' => $function->kyte_account,
            'created_by' => $userId,
            'date_created' => time(),
            'deleted' => 0
        ];

        $insertSql = "
            INSERT INTO CronJobFunctionVersion (
                cron_job_function, version_number, content_hash, is_current,
                change_description, kyte_account, created_by, date_created, deleted
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        DBI::prepared_query($insertSql, 'iisisiiii', [
            $versionData['cron_job_function'],     // i - INT
            $versionData['version_number'],        // i - INT
            $versionData['content_hash'],          // s - VARCHAR
            $versionData['is_current'],            // i - TINYINT
            $versionData['change_description'],    // s - TEXT
            $versionData['kyte_account'],          // i - INT
            $versionData['created_by'],            // i - INT
            $versionData['date_created'],          // i - INT
            $versionData['deleted']                // i - TINYINT
        ]);
    }

    /**
     * Override delete() to handle reference counting
     */
    public function delete($field, $value)
    {
        // Load the function to get its content_hash and cron_job before deletion
        $function = new ModelObject(CronJobFunction);

        $conditions = null;
        if ($this->model !== null) {
            if (!isset($this->model['appId']) && $this->requireAccount) {
                $conditions = [['field' => 'kyte_account', 'value' => $this->api->account->id]];
            }
        }

        if ($function->retrieve($field, $value, $conditions)) {
            $cronJobId = $function->cron_job;

            // Decrement reference count in content table
            if ($function->content_hash) {
                $content = new ModelObject(CronJobFunctionContent);
                if ($content->retrieve('content_hash', $function->content_hash)) {
                    if ($content->reference_count > 0) {
                        $content->reference_count--;
                        $content->save([
                            'reference_count' => $content->reference_count
                        ], $this->api->user ? $this->api->user->id : null);
                    }
                }
            }

            // Call parent delete
            parent::delete($field, $value);

            // Regenerate parent job code after function deletion
            if ($cronJobId) {
                CronJobCodeGenerator::regenerateJobCode($cronJobId);
            }
        } else {
            // Function not found or no permission
            parent::delete($field, $value);
        }
    }

    /**
     * Send error response
     */
    private function respondError(string $message, int $statusCode = 400): void
    {
        http_response_code($statusCode);
        $this->response['error'] = $message;
    }
}
