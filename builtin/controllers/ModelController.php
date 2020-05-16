<?php

class ModelController
{

    protected $txToken;
    protected $sessionToken;
    protected $getFKTable;
    public $dateformat;
    public $model;

    public function __construct($model, $dateformat, $txToken, $sessionToken)
    {
        try {
            $this->txToken = $txToken;
            $this->sessionToken = $sessionToken;
            $this->dateformat = $dateformat;
            $this->model = $model;
            $this->init();
        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function init() {
        $this->authenticate();
        $this->getFKTable = true;
    }

    // * for subclasses that are public, override with empty function
    protected function authenticate()
    {
        $session = new \Kyte\SessionManager(Session, Account);
        $session->validate($this->txToken, $this->sessionToken, false);
    }

    protected function getObject($obj) {
        $response = [];

        try {
            $response = $obj->getAllParams($this->dateformat);
            foreach($response as $key => $value) {
                if (isset($this->model['struct'][$key])) {
                    // if protected attribute then return empty string
                    if (isset($this->model['struct'][$key]['protected'])) {
                        if ($this->model['struct'][$key]['protected']) {
                            $response[$key] = '';
                        }
                    }
                    // if foreign key, retrieve data from fk table
                    if (isset($this->model['struct'][$key]['fk'])) {
                        if ($this->model['struct'][$key]['fk']) {
                            $fk = explode('_', $key);
                            error_log("FK Identified for $key; explode count ".count($fk));
                            if (count($fk) == 2) {
                                error_log("FK explode ".$fk[0].' '.$fk[1]);
                                $fk_objs = new \Kyte\Model(${$fk[0]});
                                $fk_objs->retrieve($fk[1], $response[$key]);
                                foreach ($fk_objs->objects as $fk_obj) {
                                    // return list of data
                                    $response[$fk[0]][] = $this->getObject($obj);
                                }
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // new - create new entry in db
    public function new($data)
    {
        $response = [];

        foreach($data as $key => $value) {
            if (isset($this->model['struct'][$key])) {
                if ($this->model['struct'][$key]['date']) {
                    $data[$key] = strtotime($value);
                }
            }
        }

        try {
            $obj = new \Kyte\ModelObject($this->model);
            if ($obj->create($data)) {
                $response = $this->getObject($obj);
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
                foreach($data as $key => $value) {
                    if (isset($this->model['struct'][$key])) {
                        if ($this->model['struct'][$key]['date']) {
                            $data[$key] = strtotime($value);
                        }
                    }
                }
                $obj->save($data);
                $response = $this->getObject($obj);
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
                $response[] = $this->getObject($obj);
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
