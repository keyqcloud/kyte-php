<?php

namespace Kyte\Mvc\Controller;

/**
 * Controller for KytePageVersion model
 */
class KytePageVersionController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
        // Version records should not be directly created/updated/deleted by users
        $this->allowableActions = ['get'];
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                // Decompress content fields for display
                $contentFields = ['html', 'stylesheet', 'javascript', 'javascript_obfuscated', 'block_layout'];
                foreach ($contentFields as $field) {
                    if (!empty($r[$field])) {
                        $r[$field] = bzdecompress($r[$field]);
                    }
                }

                // Add change summary parsing
                if (!empty($r['changes_detected'])) {
                    $r['changes_detected'] = json_decode($r['changes_detected'], true);
                    $r['change_count'] = count($r['changes_detected']);
                }

                // Add version metadata
                $r['can_revert'] = !$r['is_current']; // Can't revert to current version
                break;

            default:
                break;
        }
    }
}
