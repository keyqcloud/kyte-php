<?php

class AccountController extends ModelController
{
    // new - create new account
    public function new($data)
    {
        $response = [];
        
        try {
            $obj = new \Kyte\ModelObject($this->model);
            if ($obj->retrieve('email', $data['email'])) {
                throw new \Exception("Account already exists");
            }
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            if ($obj->create($data)) {
                $response = $obj->getAllParams($this->dateformat);
                $response['password'] = '';     // better leave password hash empty - no need for front-end to get it
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update - update account
    public function update($field, $value, $data)
    {
        if (!$field || !$value) throw new \Exception("Field and Value params not set");

        $response = [];

        try {
            $obj = new \Kyte\ModelObject($this->model);
            $obj->retrieve($field, $value);
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

    // get - get list of accounts
    public function get($field, $value)
    {
        if (!$field || !$value) throw new \Exception("Field and Value params not set");

        $response = [];

        try {
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                // return list of data
                $item = $obj->getAllParams($this->dateformat);
                $item['password'] = '';     // better leave password hash empty - no need for front-end to get it
                $response[] = $item;
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete - delete account
    public function delete($field, $value)
    {
        if (!$field || !$value) throw new \Exception("Field and Value params not set");

        $response = [];

        try {
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                $obj->delete();
            }

            $response = $this->get($field, $value);

        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }
}

?>
