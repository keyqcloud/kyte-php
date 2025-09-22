<?php

namespace Kyte\Mvc\Controller;

/**
 * Controller for KyteScriptVersionController model
 */
class KyteScriptVersionController extends ModelController
{
    public function hook_init() {
        $this->dateformat = 'm/d/Y H:i:s';
        // Version records should not be directly created/updated/deleted by users
        $this->allowableActions = ['get'];
    }

    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        switch ($method) {
            case 'get':
                // Add change summary parsing
                if (!empty($r['changes_detected'])) {
                    $decoded = json_decode($r['changes_detected'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $r['changes_detected'] = $decoded;
                        $r['change_count'] = count($r['changes_detected']);
                    } else {
                        // Log the error and provide fallback
                        error_log("JSON decode failed for changes_detected: " . json_last_error_msg());
                        $r['changes_detected'] = [];
                        $r['change_count'] = 0;
                    }
                }

                // Get user info
                if (!empty($r['created_by'])) {
                    try {
                        $user = new \Kyte\Core\ModelObject(KyteUser);
                        if ($user->retrieve('id', $r['created_by'])) {
                            $r['created_by'] = [
                                'id' => $user->id,
                                'name' => $user->name ?? '',
                                'username' => $user->username ?? '',
                                'email' => $user->email ?? '',
                            ];
                        } else {
                            // User not found - provide fallback
                            $r['created_by'] = [
                                'id' => $r['created_by'],
                                'name' => 'Unknown User',
                                'username' => '',
                                'email' => '',
                            ];
                        }
                    } catch (Exception $e) {
                        error_log("User retrieval failed: " . $e->getMessage());
                        $r['created_by'] = [
                            'id' => $r['created_by'],
                            'name' => 'Error Loading User',
                            'username' => '',
                            'email' => '',
                        ];
                    }
                }

                // Add version metadata
                // Can't revert to current version
                $r['can_revert'] = isset($r['is_current']) ? !$r['is_current'] : true;

                if (isset($r['script'], $r['script']['content'])) {
                    $r['script']['content'] = '';
                    $r['script']['content_js_obfuscated'] = '';
                }
                break;

            default:
                break;
        }
    }
}
