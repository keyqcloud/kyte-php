<?php

class ModelController
{

    protected $txToken;
    protected $sessionToken;
    protected $getFKTable;
    protected $session;
    protected $user;
    public $dateformat;
    public $model;

    public function __construct($model, $dateformat, $txToken, $sessionToken)
    {
        try {
            $this->txToken = $txToken;
            $this->sessionToken = $sessionToken;
            $this->dateformat = $dateformat;
            $this->model = $model;
            $this->session = new \Kyte\SessionManager(Session, Account);
            $this->getFKTable = true;
            $this->user = [];
            $this->init();
        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function init()
    {
        $this->authenticate();
    }

    // * for subclasses that are public, override with empty function
    protected function authenticate()
    {
        $this->user = $this->session->validate($this->txToken, $this->sessionToken, false);
    }

    protected function getObject($obj) {
        $response = [];

        try {
            $response = $obj->getAllParams();
            foreach($response as $key => $value) {
                if (isset($obj->model['struct'][$key])) {
                    // if protected attribute then return empty string
                    if (isset($obj->model['struct'][$key]['protected'])) {
                        if ($obj->model['struct'][$key]['protected']) {
                            $response[$key] = '';
                        }
                    }
                    // if date format is specified
                    if (isset($obj->model['struct'][$key]['date'])) {
                        if ($obj->model['struct'][$key]['date']) {
                            if (!empty($response[$key])) {
                                if (isset($obj->model['struct'][$key]['dateformat'])) {
                                    $response[$key] = date($obj->model['struct'][$key]['dateformat'], $response[$key]);
                                } else {
                                    $response[$key] = date($this->dateformat, $response[$key]);
                                }
                            } else {
                                $response[$key] = '';
                            }
                        }
                    }

                    if ($this->getFKTable) {
                        // if foreign key, retrieve data from fk table
                        if (isset($obj->model['struct'][$key]['fk'], $obj->model['struct'][$key]['fkCol'])) {
                            if ($obj->model['struct'][$key]['fk'] && $obj->model['struct'][$key]['fkCol'] && $response[$key]) {
                                // get table name
                                $fk = $obj->model['struct'][$key]['fk'];
                                // get column name
                                $field = $obj->model['struct'][$key]['fkCol'];

                                error_log("FK Identified for $key for table $fk on field $field");
                            
                                $fk_objs = new \Kyte\Model(constant($fk));
                                // retrieve deleted items as well
                                // retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null)
                                $fk_objs->retrieve($field, $response[$key], false, null, true);
                                $response[$key] = [];
                                foreach ($fk_objs->objects as $fk_obj) {
                                    // return list of data
                                    $response[$key][] = $this->getObject($fk_obj);
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
            $this->hook_preprocess('new', $data);
            if ($obj->create($data)) {
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('new', $obj, $ret);
                $response = $ret;
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update - update entry in db
    public function update($field, $value, $data)
    {
        if ($field === null || $value === null) throw new \Exception("Field ($field) and Value ($value) params not set");

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
                $this->hook_preprocess('update', $data);
                $obj->save($data);
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('update', $obj, $ret);
                $response = $ret;
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
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('get', $obj, $ret);
                $response[] = $ret;
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete - delete objects from db
    public function delete($field, $value)
    {
        if ($field === null || $value === null) throw new \Exception("Field ($field) and Value ($value) params not set");

        $response = [];

        try {
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                $obj->delete();
            }

        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // hook function - user defined
    public function hook_preprocess($method, &$r) {}
    public function hook_response_data($method, $o, &$r) {}
}

?>
