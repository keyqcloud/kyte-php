<?php

class SessionController extends ModelController
{
    public function hook_init() {}
    {
        // make a public controller
        $this->requireAuth = false;
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
            $obj = new \Kyte\ModelObject(User);
            if ($obj->retrieve('id', $response['uid'])) {
                $response['User'] = $this->getObject($obj);
            }
        } catch (Exception $e) {
            throw $e;
        }

        $this->response['token'] = $response['txToken'];
        $this->response['data'] = $response;
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
        } catch (Exception $e) {
            throw $e;
        }

        $this->response['token'] = 0;
        $this->response['session'] = 0;
        $this->response['data'] = [ 'txToken' => 0, 'sessionToken' => 0 ];
    }
}

?>
