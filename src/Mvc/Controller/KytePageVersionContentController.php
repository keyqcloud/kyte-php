<?php

namespace Kyte\Mvc\Controller;

/**
 * Controller for KytePageVersion model
 */
class KytePageVersionContentController extends ModelController
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

                // Get user info
                if (!empty($r['created_by'])) {
                    $user = new \Kyte\Core\ModelObject(KyteUser);
                    if ($user->retrieve('id', $r['created_by'])) {
                        $r['created_by'] = [
                            'id' => $user->id,
                            'name' => $user->name,
                            'username' => $user->username,
                            'email' => $user->email,
                        ];
                    }
                }
                break;

            default:
                break;
        }
    }
}
