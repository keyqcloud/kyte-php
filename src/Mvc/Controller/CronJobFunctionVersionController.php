<?php

namespace Kyte\Mvc\Controller;

use Kyte\Core\DBI;

/**
 * Controller for CronJobFunctionVersion model
 *
 * Read-only controller for viewing version history of cron job functions.
 * Provides:
 * - List all versions for a function
 * - Get specific version code
 * - Compare two versions
 */
class CronJobFunctionVersionController extends ModelController
{
    protected $allowableActions = ['get']; // Read-only

    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
    }

    /**
     * Override get() to add decompressed content
     */
    public function hook_response_data($method, $o, &$r = null, &$d = null)
    {
        if ($method === 'get' && isset($r['content_hash'])) {
            // Load and decompress content
            $contentSql = "SELECT content FROM CronJobFunctionContent WHERE content_hash = ?";
            $contentResult = DBI::prepared_query($contentSql, 's', [$r['content_hash']]);

            if (!empty($contentResult)) {
                $compressed = $contentResult[0]['content'];
                $decompressed = bzdecompress($compressed);

                if ($decompressed !== false) {
                    $r['function_body'] = $decompressed;
                }
            }
        }

        // Remove compressed code field from nested FK objects (contains binary data that breaks JSON encoding)
        // CronJobFunctionVersion -> CronJobFunction -> CronJob (has compressed code)
        if (isset($r['cron_job_function']) && is_array($r['cron_job_function'])) {
            if (isset($r['cron_job_function']['cron_job']) && is_array($r['cron_job_function']['cron_job'])) {
                unset($r['cron_job_function']['cron_job']['code']);
            }
        }
    }
}
