<?php

class EnrollmentController extends ModelController
{
    /* override authenticate to make public controller */
    protected function authenticate() {}

    // new - create new user with sign up token
    public function new($data)
    {
        $response = [];

        try {
            $obj = new \Kyte\ModelObject(Account);
            $data['password'] = 't0k3n'.password_hash($data['password'], PASSWORD_DEFAULT);
            if ($obj->create($data)) {
                $response = $obj->getAllParams($this->dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update - finish user registration and udpate
    public function update($field, $value, $data)
    {
        if (!field || !$value) throw new \Exception("Field and Value params not set");

        $response = [];

        try {
            $obj = new \Kyte\ModelObject(Account);
            $obj->retrieve('password', $value);
            if ($obj) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
                $obj->save($data);
                $response = $obj->getAllParams($this->dateformat);
                $response['password'] = '';     // better leave password hash empty - no need for front-end to get it
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // get
    public function get($field, $value)
    {
        throw new \Exception("Undefined request method");
    }

    // delete
    public function delete($field, $value)
    {
        throw new \Exception("Undefined request method");
    }
}

?>
