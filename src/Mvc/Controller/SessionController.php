<?php

namespace Kyte\Mvc\Controller;

class SessionController extends ModelController
{
    public function hook_init()
    {
        // make a public controller
        $this->requireAuth = false;
        $this->getFKTables = SESSION_RETURN_FK;
    }

    // new - creates new session
    public function new($data)
    {
        $response = [];
        $session = [];

        try {
            if ($this->api->appId != null && $this->api->app->user_model != null && $this->api->app->username_colname != null && $this->api->app->password_colname != null) {
                // check for required params
                foreach ([$this->api->app->username_colname, $this->api->app->password_colname] as $param) {
                    if (!isset($data[$param]))
                        throw new \Exception("Incomplete data passed");
                }

                // create session for user and obtain user information
                $session = $this->session->create($data[$this->api->app->username_colname], $data[$this->api->app->password_colname]);

                $user = new \Kyte\Core\ModelObject(constant($this->api->app->user_model));
                if (!$user->retrieve('id', $session['uid'])) {
                    throw new \Exception("Unable to find user information");    
                }

                $this->user = $user;

                if (USE_SESSION_MAP) {
                    $response = $this->getObject($user);
                } else {
                    $response[] = $this->getObject($user);
                }
            } else {
                // check for required params
                foreach ([USERNAME_FIELD, PASSWORD_FIELD] as $param) {
                    if (!isset($data[$param]))
                        throw new \Exception("Incomplete data passed");
                }

                // create session for user and obtain user information
                $session = $this->session->create($data[USERNAME_FIELD], $data[PASSWORD_FIELD]);

                $user = new \Kyte\Core\ModelObject(KyteUser);
                if (!$user->retrieve('id', $session['uid'])) {
                    throw new \Exception("Unable to find user information");    
                }
                // get user account
                $account = new \Kyte\Core\ModelObject(KyteAccount);
                if (!$account->retrieve('id', $user->kyte_account)) {
                    throw new \Exception("Unable to find account associated with user");
                }

                // set user and account in class
                $this->user = $user;
                $this->account = $account;

                if (USE_SESSION_MAP) {
                    $response = $this->getObject($user);
                } else {
                    $response[] = $this->getObject($user);
                }
            }

            // get api associated with account
            $account_api = new \Kyte\Core\ModelObject(KyteAPIKey);
            if (!$account_api->retrieve('kyte_account', $this->account->id)) {
                throw new \Exception("[ERROR] Unable to find API information for account");
            }

            // return account information in response - this is required for API handoff between master account and subaccounts
            $this->response['kyte_pub'] = $account_api->public_key;
            $this->response['kyte_num'] = $this->account->number;
            $this->response['kyte_iden'] = $account_api->identifier;
            // $this->response['role'] = $response[0]['role'];
            $this->response['token'] = $session['txToken'];
            $this->response['account_id'] = $this->account->id;
            $this->response['session'] = $session['sessionToken'];
            $this->response['data'] = $response;
        } catch (\Exception $e) {
            // $this->api->logger->security($e->getMessage());
            throw $e;
        }
    }

    // update
    public function update($field, $value, $data)
    {
        throw new \Exception("Undefined request method");
    }

    // get - validate session
    public function get($field, $value)
    {
        throw new \Exception("Undefined request method");
    }

    // delete - destroy session
    public function delete($field, $value)
    {
        try {
            $this->session->destroy();
        } catch (\Exception $e) {
            throw $e;
        }

        $this->response['token'] = 0;
        $this->response['session'] = 0;
        $this->response['data'] = [ 'txToken' => 0, 'sessionToken' => 0 ];
    }
}
