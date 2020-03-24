<?php

class SessionController extends ModelController
{
    private $session;

    public function __construct($token) {
        try {
            $this->session = new \Kyte\SessionManager(Session);
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    // new session  :   {model}, {data}
    public function new($model, $data, $dateformat)
    {
        $response = [];

        try {
            foreach (['email', 'password'] as $param) {
				if (!isset($data[$param]))
					throw new Exception("Incomplete data passed");
			}
			$response = [ 'token' => $this->session->create(Account, $data['email'], $data['password']) ];
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update   :   {model}, {field}, {value}, {data}
    public function update($model, $field, $value, $data, $dateformat)
    {
        throw new \Exception("Undefined request method");
    }

    // get  :   {model}, {field}, {value}
    public function get($model, $field, $value, $dateformat)
    {
        try {
            $response = [ 'token' => $this->session->validate(base64_decode($value)) ];
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete   :   {model}, {field}, {value}
    public function delete($model, $field, $value, $dateformat)
    {
        try {
            $this->session->validate(base64_decode($value));
            $this->session->destroy();
        } catch (Exception $e) {
            throw $e;
        }

        return [ 'token' => '' ];
    }
}

?>
