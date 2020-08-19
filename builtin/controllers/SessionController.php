<?php

class SessionController extends ModelController
{
    protected $session;

    protected function authenticate()
    {
        // default user identifiers as email and password - override with custom abstract controller
        $this->session = new \Kyte\SessionManager(Session, Account, USERNAME_FIELD, PASSWORD_FIELD, ALLOW_MULTILOGON, SESSION_TIMEOUT);
    }
    
    // new - creates new session
    public function new($data)
    {
        $response = [];

        try {
            foreach (['email', 'password'] as $param) {
				if (!isset($data[$param]))
					throw new Exception("Incomplete data passed");
			}
            $response = $this->session->create($data['email'], $data['password']);
            $obj = new \Kyte\ModelObject(Account);
            if ($obj->retrieve('id', $response['uid'])) {
                $response['Account'] = $this->getObject($obj);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update
    public function update($field, $value, $data)
    {
        throw new \Exception("Undefined request method");
    }

    // get - validate session
    public function get($field, $value)
    {
        try {
            $response = $this->session->validate($this->txToken, $this->sessionToken, ALLOW_SAME_TXTOKEN);
            $obj = new \Kyte\ModelObject(Account);
            if ($obj->retrieve('id', $response['uid'])) {
                $response['Account'] = $this->getObject($obj);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete - destroy session
    public function delete($field, $value)
    {
        try {
            $this->session->destroy();
        } catch (Exception $e) {
            throw $e;
        }

        return [ 'txToken' => 0, 'sessionToken' => 0 ];
    }
}

?>
