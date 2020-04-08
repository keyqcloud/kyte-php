<?php

class ModelController
{

    protected $token;
    public $dateformat;
    public $model;

    public function __construct($model, $dateformat, $token)
    {
        try {
            $this->token = $token;
            $this->dateformat = $dateformat;
            $this->model = $model;
            $this->authenticate();
        } catch (Exception $e) {
            throw $e;
        }
    }

    // * for subclasses that are public, override with empty function
    protected function authenticate()
    {
        $session = new \Kyte\SessionManager(Session, Account);
        $session->validate($this->token);   
    }

    // new - create new entry in db
    public function new($data)
    {
        $response = [];

        try {
            $obj = new \Kyte\ModelObject($this->model);
            if ($obj->create($data)) {
                $response = $obj->getAllParams($this->dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update - update entry in db
    public function update($field, $value, $data)
    {
        if (!$field || !$value) throw new \Exception("Field and Value params not set");

        $response = [];

        try {
            $obj = new \Kyte\ModelObject($this->model);
            if ($obj->retrieve($field, $value)) {
                $obj->save($data);
                $response = $obj->getAllParams($this->dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // get - retrieve objects from db
    public function get($field, $value)
    {
        $response = [];

        try {
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                // return list of data
                $response[] = $obj->getAllParams($this->dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete - delete objects from db
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
