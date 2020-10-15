<?php

class ModelController
{

    protected $user;
    protected $account;
    protected $session;
    protected $response;
    protected $failOnNull;
    protected $exceptionMessages;
    protected $requireAuth;
    public $dateformat;
    public $model;

    public function __construct($model, $dateformat, &$account, &$session, &$user, &$response)
    {
        try {
            $this->model = $model;
            // session related variables
            $this->user = &$user;
            $this->account = &$account;
            $this->session = &$session;
            // response
            $this->response = &$response;
            // controller behaviour
            $this->dateformat = $dateformat;
            $this->getFKTable = true;
            $this->failOnNull = false;
            $this->requireAuth = true;
            $this->exceptionMessages = [
                'new' => [
                    'failOnNull' => 'Unable to create new object',
                ],
                'update' => [
                    'failOnNull' => 'Unable to update object(s)',
                ],
                'get' => [
                    'failOnNull' => 'Unable to get object(s)',
                ],
                'delete' => [
                    'failOnNull' => 'Unable to delete object(s)',
                ],
            ];
            $this->init();
        } catch (Exception $e) {
            throw $e;
        }
    }

    protected function init()
    {
        $this->hook_init();
        if ($this->requireAuth) {
            $this->authenticate();
        }
    }

    protected function authenticate()
    {
        if (!$this->user && !this->session) {
            throw new \Kyte\SessionException("Unauthorized API request.");
        }
        $this->hook_auth();
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
                        if (isset($obj->model['struct'][$key]['fk'])) {
                            if ($obj->model['struct'][$key]['fk'] && $response[$key]) {
                                $fk = explode('_', $key);
                                if (count($fk) == 2) {
                                    $fk_objs = new \Kyte\Model(constant($fk[0]));
                                    // retrieve deleted items as well
                                    // retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null)
                                    $fk_objs->retrieve($fk[1], $response[$key], false, null, true);
                                    foreach ($fk_objs->objects as $fk_obj) {
                                        // return list of data
                                        $response[$fk[0]][] = $this->getObject($fk_obj);
                                    }
                                }
                            }
                        }
                    }
                    // if ($this->getFKTable) {
                    //     // if foreign key, retrieve data from fk table
                    //     if (isset($obj->model['struct'][$key]['fk'], $obj->model['struct'][$key]['fkCol'])) {
                    //         if ($obj->model['struct'][$key]['fk'] && $obj->model['struct'][$key]['fkCol'] && $response[$key]) {
                    //             // get table name
                    //             $fk = $obj->model['struct'][$key]['fk'];
                    //             // get column name
                    //             $field = $obj->model['struct'][$key]['fkCol'];

                    //             error_log("FK Identified for $key for table $fk on field $field");
                            
                    //             $fk_objs = new \Kyte\Model(constant($fk));
                    //             // retrieve deleted items as well
                    //             // retrieve($field = null, $value = null, $isLike = false, $conditions = null, $all = false, $order = null)
                    //             $fk_objs->retrieve($field, $response[$key], false, null, true);
                    //             $response[$key] = [];
                    //             foreach ($fk_objs->objects as $fk_obj) {
                    //                 // return list of data
                    //                 $response[$key][] = $this->getObject($fk_obj);
                    //             }            
                    //         }
                    //     }
                    // }
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

        // convert all dates to unix time
        foreach($data as $key => $value) {
            if (isset($this->model['struct'][$key])) {
                if ($this->model['struct'][$key]['date']) {
                    $data[$key] = strtotime($value);
                }
            }
        }

        try {
            // init new object
            $obj = new \Kyte\ModelObject($this->model);
            // hook for any custom behaviours before creating object
            $this->hook_preprocess('new', $data);
            // add account information
            $data['account_id'] = $this->account->getParam('id');
            // create object & get return
            if ($obj->create($data)) {
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('new', $obj, $ret);
                $response = $ret;
            } else {
                if ($this->failOnNull) {
                    throw new \Exception($this->exceptionMessages['new']['failOnNull']);
                }
            }
        } catch (Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    // update - update entry in db
    public function update($field, $value, $data)
    {
        if ($field === null || $value === null) throw new \Exception("Field ($field) and Value ($value) params not set");

        $response = [];

        try {
            $conditions = [];
            $all = false;
            $this->hook_prequery('update', $field, $value, $conditions, $all, $order);
            // add account id to query
            $conditions[] = ['field' => 'account_id', 'value' => $this->account->getParam('id')];
            // init object
            $obj = new \Kyte\ModelObject($this->model);
            if ($obj->retrieve($field, $value, $conditions, null, $all)) {

                // convert all date time strings to unix time
                foreach($data as $key => $value) {
                    if (isset($this->model['struct'][$key])) {
                        if ($this->model['struct'][$key]['date']) {
                            $data[$key] = strtotime($value);
                        }
                    }
                }
                $this->hook_preprocess('update', $data, $obj);
                $obj->save($data);
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('update', $obj, $ret);
                $response = $ret;
            } else {
                if ($this->failOnNull) {
                    throw new \Exception($this->exceptionMessages['update']['failOnNull']);
                }
            }
        } catch (Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    // get - retrieve objects from db
    public function get($field, $value)
    {
        $response = [];

        try {
            $conditions = [];
            $all = false;
            $order = null;
            $this->hook_prequery('get', $field, $value, $conditions, $all, $order);
            // add account id to query
            $conditions[] = ['field' => 'account_id', 'value' => $this->account->getParam('id')];
            // init model
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value, false, $conditions, $all, $order);

            if ($this->failOnNull && count($objs->objects) < 1) {
                throw new \Exception($this->exceptionMessages['get']['failOnNull']);
            }

            foreach ($objs->objects as $obj) {
                // return list of data
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('get', $obj, $ret);
                $response[] = $ret;
            }
            $this->hook_process_get_response($response);
        } catch (Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    // delete - delete objects from db
    public function delete($field, $value)
    {
        if ($field === null || $value === null) throw new \Exception("Field ($field) and Value ($value) params not set");

        $response = [];

        try {
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value, false, [['field' => 'account_id', 'value' => $this->account->getParam('id')]]);
            
            if ($this->failOnNull && count($objs->objects) < 1) {
                throw new \Exception($this->exceptionMessages['delete']['failOnNull']);
            }

            foreach ($objs->objects as $obj) {
                $obj->delete();
            }

        } catch (Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    // hook function - user defined
    public function hook_init() {}
    public function hook_auth() {}
    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}
    public function hook_preprocess($method, &$r, &$o = null) {}
    public function hook_response_data($method, $o, &$r) {}
    public function hook_process_get_response(&$r) {}
}

?>
