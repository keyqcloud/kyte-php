<?php

class SessionController extends ModelController
{
    protected $session;

    protected function authenticate()
    {
        $this->session = new \Kyte\SessionManager(Session, Account);
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
            $response = $this->session->validate($this->txToken, $this->sessionToken);
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete - destroy session
    public function delete($field, $value)
    {
        try {
            // generate a new token and invalidate it
            $response = $this->session->validate($this->txToken, $this->sessionToken, true);
            $this->session->destroy();
        } catch (Exception $e) {
            throw $e;
        }

        return [ 'txToken' => 0, 'sessionToken' => 0 ];
    }
}

?>
