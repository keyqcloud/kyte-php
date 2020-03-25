<?php

class AccountController extends ModelController
{
    // new - create new account
    public function new($data)
    {
        $response = [];
        
        try {
            $obj = new \Kyte\ModelObject($this->model);
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
        $response = [];

        try {
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                // return list of data
                $response[] = $obj->getAllParams($this->dateformat);
                $response['password'] = '';     // better leave password hash empty - no need for front-end to get it
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete - delete account
    public function delete($field, $value)
    {
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
