<?php

class ModelController
{

    protected $user;
    protected $account;
    protected $session;
    protected $response;
    public $dateformat;
    public $model;

    // controller behaviour flags
    protected $cascadeDelete;
    protected $getFKTables;
    protected $getExternalTables;
    protected $requireAuth;
    protected $requireRoles;
    protected $requireAccount;
    protected $failOnNull;
    protected $allowableActions;
    protected $checkExisting;

    // array with error messages
    protected $exceptionMessages;

    public function __construct($model, $dateformat, &$account, &$session, &$user, &$response)
    {
        try {
            // default to allow all actions
            $this->allowableActions = ['new', 'update', 'get', 'delete'];

            $this->model = $model;
            // session related variables
            $this->user = &$user;
            $this->account = &$account;
            $this->session = &$session;

            // response
            $this->response = &$response;

            // date time format
            $this->dateformat = $dateformat;
            
            // controller behaviour flags
            $this->cascadeDelete = true;
            $this->getFKTables = true;
            $this->getExternalTables = true;
            $this->requireAuth = true;
            $this->requireRoles = true;
            $this->requireAccount = true;
            $this->checkExisting = null;
            $this->failOnNull = false;

            // default error messages
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
        if (!$this->user && !$this->session) {
            throw new SessionException("Unauthorized API request.");
        }
        $this->hook_auth();
    }

    protected function checkPermissions($requestType, $modelName = null) {
        if ($this->requireAuth && $this->requireRoles) {
            // if model name is set then use it, otherwise use clas model
            $modelName = $modelName ? $modelName : $this->model['name'];

            // check if user assigned role exists
            $role = new \Kyte\ModelObject(Role);
            $cond = $this->requireAccount ? [ 'field' => 'kyte_account', 'value' => $this->account->getParam('id')] : null;
            if (!$role->retrieve('id', $this->user->getParam('role'), [$cond])) {
                error_log('unable to find role for '.$this->user->getParam('role').' and '.$this->account->getParam('id'));
                return false;
            }

            // check if assigned role has permission for request type
            $permission = new \Kyte\ModelObject(Permission);
            if (!$permission->retrieve('role', $role->getParam('id'), [ ['field' => 'model', 'value' => $modelName], ['field' => 'action', 'value' => $requestType], $cond ])) {
                error_log('unable to find permission');
                return false;
            }
        }

        return true;
    }

    protected function getObject($obj) {
        $response = [];

        try {
            $response = $obj->getAllParams();

            // iterate through each param and apply filter
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

                    // if get FK is set then check for FK
                    if ($this->getFKTables) {
                        if (isset($obj->model['struct'][$key]['fk']) && !empty($response[$key])) {

                            $fk = $obj->model['struct'][$key]['fk'];

                            if (isset($fk['model'], $fk['field'])) {
                                
                                // check if permissions allow for this behaviour
                                if ($this->checkPermissions('get', $fk['model'])) {

                                    $fk_obj = new \Kyte\ModelObject(constant($fk['model']));
                                    // check if account is required
                                    $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->getParam('id')]] : null;

                                    // retrieve deleted items as well
                                    if ($fk_obj->retrieve($fk['field'], $response[$key], $conditions, null, true)) {
                                        // return list of data
                                        $response[$key] = $this->getObject($fk_obj);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // next, get external tables that have fk to this
            if ($this->getExternalTables && isset($obj->model['externalTables'])) {
                // temporarily set FK table to false so we don't cause an endless loop
                $fkFlag = $this->getFKTables;
                $this->getFKTables = false;

                // define array
                $response['ExternalTables'] = [];

                foreach ($obj->model['externalTables'] as $et) {

                    if (isset($et['model'], $et['field'])) {

                        // check if permissions allow for this behaviour
                        if ($this->checkPermissions('get', $et['model'])) {

                            $et_objs = new \Kyte\Model(constant($et['model']));
                            // check if account is required
                            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->getParam('id')]] : null;

                            // retrieve deleted items as well
                            $et_objs->retrieve($et['field'], $response['id'], false, $conditions, true);
                            foreach ($et_objs->objects as $et_obj) {
                                // return list of data
                                $response['ExternalTables'][] = $this->getObject($et_obj);
                            }
                        }
                    }
                }

                // return FK table flag to original value
                $this->getFKTables = $fkFlag;
            }

        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // new - create new entry in db
    public function new($data)
    {
        if (!in_array('new', $this->allowableActions)) {
            return;
        }

        if (!$this->checkPermissions('new')) {
            throw new \Exception('Permission Denied');
        }

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

            // check existing and fail if present
            if ($this->checkExisting) {
                if ($obj->retrieve($this->checkExisting, $data[$this->checkExisting])) {
                    throw new \Exception($this->model['name'].' already exists');
                }
            }
            
            // hook for any custom behaviours before creating object
            $this->hook_preprocess('new', $data);
            // add account information
            $data['kyte_account'] = $this->account->getParam('id');
            // create object & get return
            if ($obj->create($data)) {
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('new', $obj, $ret, $data);
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
        if (!in_array('update', $this->allowableActions)) {
            return;
        }

        if (!$this->checkPermissions('update')) {
            throw new \Exception('Permission Denied');
        }

        if ($field === null || $value === null) throw new \Exception("Field ($field) and Value ($value) params not set");

        $response = [];

        try {
            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->getParam('id')]] : null;
            $all = false;
            $this->hook_prequery('update', $field, $value, $conditions, $all, $order);
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

                // check existing and fail if present
                if ($this->checkExisting) {
                    $existing = new \Kyte\ModelObject($this->model);
                    if ($existing->retrieve($this->checkExisting, $data[$this->checkExisting])) {
                        if ($existing->getParam('id') != $obj->getParam('id')) {
                            throw new \Exception('There is already a '.strtolower($this->model['name']).' with that '.$this->checkExisting.'.');
                        }
                    }
                }

                $this->hook_preprocess('update', $data, $obj);
                $obj->save($data);
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('update', $obj, $ret, $data);
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
        if (!in_array('get', $this->allowableActions)) {
            return;
        }

        if (!$this->checkPermissions('get')) {
            throw new \Exception('Permission Denied');
        }

        $response = [];

        try {
            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->getParam('id')]] : null;
            $all = false;
            $order = null;
            $this->hook_prequery('get', $field, $value, $conditions, $all, $order);
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

    protected function deleteCascade($obj) {
        if ($this->cascadeDelete) {
            // find external tables and delete associated entries
            foreach ($obj->model['externalTables'] as $extTbl) {
                $dep = new \Kyte\Model(constant($extTbl['model']));
                $dep->retrieve($extTbl['field'], $obj->getParam('id'), false, $conditions);

                // delete each associated entry in the table
                foreach ($dep->objects as $item) {
                    $this->deleteCascade($item);
                    $item->delete();
                }
            }
        }
    }

    // delete - delete objects from db
    public function delete($field, $value)
    {
        if (!in_array('delete', $this->allowableActions)) {
            return;
        }

        if (!$this->checkPermissions('delete')) {
            throw new \Exception('Permission Denied');
        }

        if ($field === null || $value === null) throw new \Exception("Field ($field) and Value ($value) params not set");

        $response = [];

        try {
            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->getParam('id')]] : null;
            $objs = new \Kyte\Model($this->model);
            $objs->retrieve($field, $value, false, $conditions);
            
            if ($this->failOnNull && count($objs->objects) < 1) {
                throw new \Exception($this->exceptionMessages['delete']['failOnNull']);
            }

            foreach ($objs->objects as $obj) {
                $this->hook_response_data('delete', $obj);

                // if cascade delete is set delete associated data
                if ($this->cascadeDelete) {
                    // find external tables and delete associated entries
                    foreach ($this->model['externalTables'] as $extTbl) {
                        $dep = new \Kyte\Model(constant($extTbl['model']));
                        $dep->retrieve($extTbl['field'], $obj->getParam('id'), false, $conditions);

                        // delete each associated entry in the table
                        foreach ($dep->objects as $item) {
                            $this->deleteCascade($item);
                            $item->delete();
                        }
                    }
                }

                // finally, delete object
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
    public function hook_response_data($method, $o, &$r = null, &$d = null) {}
    public function hook_process_get_response(&$r) {}
}

?>
