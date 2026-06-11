<?php

namespace Kyte\Mvc\Controller;

use Kyte\Core\Auth\PasswordResetFlow;

class KytePasswordResetController extends ModelController
{
    public function hook_init() {
        $this->model = KyteUser;
        $this->allowableActions = ['new', 'get', 'update'];
        $this->requireAuth = false;
        $this->requireAccount = false;
    }

    public function hook_preprocess($method, &$r, &$o = null) {
        switch ($method) {
            case 'update':
                // Check if token exists and is valid
                if (!isset($r['token']) || !PasswordResetFlow::isValidToken((string)$r['token'], $o->password ?? null)) {
                    throw new \Exception("Invalid or expired token. Please request a new password reset.");
                }
                break;

            default:
                break;
        }
    }

    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        switch ($method) {
            case 'get':
                $field = 'password';
                break;

            default:
                break;
        }
    }

    public function new($data) {
        $response = [];

        if (!isset($data['email'])) {
            throw new \Exception('Account email to be reset must be provided.');
        }

        $user = new \Kyte\Core\ModelObject($this->model);
        if ($user->retrieve('email', $data['email'])) {
            // Generate secure token with timestamp. Token/email mechanics are
            // shared with the JWT-mode /jwt/password-* endpoints (KYTE-#268)
            // — see PasswordResetFlow.
            $token = PasswordResetFlow::generateToken($user->email);

            $data['password'] = $token;
            $user->save($data);

            // Send email
            PasswordResetFlow::sendResetEmail($user, $token);

        } else {
            // no need to report if account doesn't exist as we don't need people probing - TODO: possibly setup alert to see how often this happens
            error_log('Attempted reset for non-existing account '.$data['email']);
        }

        $this->response['data'] = $response;
    }
}
