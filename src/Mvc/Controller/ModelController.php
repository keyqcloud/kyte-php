<?php

namespace Kyte\Mvc\Controller;

class ModelController
{
    protected $user;
    protected $account;
    protected $session;
    protected $response;
    public $dateformat;
    public $model;

    // page controls
    protected $page_size;
    protected $page_total;
    protected $page_num;
    protected $total_count;
    protected $total_filtered;

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
    protected $existingThrowException;

    // array with error messages
    protected $exceptionMessages;

    public function __construct($model, $dateformat, &$account, &$session, &$user, &$response, &$page_size, &$page_total, &$page_num, &$total_count, &$total_filtered)
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
            $this->getExternalTables = false;
            $this->requireAuth = true;
            $this->requireRoles = true;
            $this->requireAccount = true;
            $this->checkExisting = null;
            $this->existingThrowException = true;
            $this->failOnNull = false;

            // page controls
            $this->page_size = &$page_size;
            $this->page_total = &$page_total;
            $this->page_num = &$page_num;
            $this->total_count = &$total_count;
            $this->total_filtered = &$total_filtered;

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

        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected function init()
    {
        if (isset($_SERVER['HTTP_X_KYTE_GET_EXTERNALTABLES'])) {
            $this->getExternalTables = strtolower($_SERVER['HTTP_X_KYTE_GET_EXTERNALTABLES']) == "true" ? true : false;
        }
        
        $this->shipyard_init();

        $this->hook_init();
        
        if ($this->requireAuth) {
            $this->authenticate();
        }
    }

    protected function authenticate()
    {
        if (!isset($this->user->id) || !$this->session->hasSession) {
            throw new \Kyte\Exception\SessionException("Unauthorized API request.");
        }
        $this->hook_auth();
    }

    protected function checkPermissions($requestType, $modelName = null) {
        if (isset($this->user->id, $this->account->id) && $this->requireRoles) {
            // if model name is set then use it, otherwise use clas model
            $modelName = $modelName ? $modelName : $this->model['name'];

            // check if user assigned role exists
            $role = new \Kyte\Core\ModelObject(Role);
            $cond = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->id]] : null;
            if (!$role->retrieve('id', $this->user->role, $cond)) {
                error_log('['.$this->model['name'].'] => ['.$requestType.'] unable to find role for '.$this->user->role.' and '.$this->account->id);
                return false;
            }

            // add model to condition
            $cond[] = ['field' => 'model', 'value' => $modelName];
            // add request type to condition
            $cond[] = ['field' => 'action', 'value' => $requestType];

            // check if assigned role has permission for request type
            $permission = new \Kyte\Core\ModelObject(Permission);
            if (!$permission->retrieve('role', $role->id, $cond)) {
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
                if (isset($obj->kyte_model['struct'][$key])) {
                    // if protected attribute then return empty string
                    if (isset($obj->kyte_model['struct'][$key]['protected'])) {
                        if ($obj->kyte_model['struct'][$key]['protected']) {
                            $response[$key] = '';
                        }
                    }

                    // if date format is specified
                    if (isset($obj->kyte_model['struct'][$key]['date'])) {
                        if ($obj->kyte_model['struct'][$key]['date']) {
                            if (!empty($response[$key])) {
                                if (isset($obj->kyte_model['struct'][$key]['dateformat'])) {
                                    $response[$key] = date($obj->kyte_model['struct'][$key]['dateformat'], $response[$key]);
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
                        if (isset($obj->kyte_model['struct'][$key]['fk']) && !empty($response[$key])) {

                            $fk = $obj->kyte_model['struct'][$key]['fk'];

                            if (isset($fk['model'], $fk['field'])) {
                                
                                // check if permissions allow for this behaviour
                                if ($this->checkPermissions('get', $fk['model'])) {

                                    $fk_obj = new \Kyte\Core\ModelObject(constant($fk['model']));
                                    // check if account is required
                                    $conditions = ( $this->requireAccount && $fk['model'] != 'Account' ) ? [[ 'field' => 'kyte_account', 'value' => $this->account->id]] : null;

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
            if ($this->getExternalTables && isset($obj->kyte_model['externalTables'])) {
                // temporarily set FK table to false so we don't cause an endless loop
                $fkFlag = $this->getFKTables;
                $this->getFKTables = false;

                // define array
                $response['ExternalTables'] = [];

                foreach ($obj->kyte_model['externalTables'] as $et) {

                    if (isset($et['model'], $et['field'])) {

                        // check if permissions allow for this behaviour
                        if ($this->checkPermissions('get', $et['model'])) {

                            $et_objs = new \Kyte\Core\Model(constant($et['model']));
                            // check if account is required
                            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->id]] : null;

                            // retrieve deleted items as well
                            $et_objs->retrieve($et['field'], $response['id'], false, $conditions);
                            foreach ($et_objs->objects as $et_obj) {
                                if (!array_key_exists($et['model'], $response['ExternalTables'])) {
                                    $response['ExternalTables'][$et['model']] = [];
                                }
                                // return list of data
                                $response['ExternalTables'][$et['model']][] = $this->getObject($et_obj);
                            }
                        }
                    }
                }

                // return FK table flag to original value
                $this->getFKTables = $fkFlag;
            }

        } catch (\Exception $e) {
            throw $e;
        }

        return $response;
    }

    protected function createFkEntries($models, &$data) {

        if (count($models) == 0) return;

        foreach($this->model['struct'] as $key) {

            // check if FK attribute exists for property
            if (isset($this->model['struct'][$key]['fk'])) {

                // if fk model exists in our data set, create entry and update data
                if (isset($models[$this->model['struct'][$key]['fk']['model']])) {

                    $obj = new \Kyte\Core\ModelObject(constant($this->model['struct'][$key]['fk']['model']));

                    $fkExists = false;

                    // check if FK id is provided...in which case we need to update
                    if (isset($data[$this->model['struct'][$key]])) {
                        if ($obj->retrieve($this->model['struct'][$key]['fk']['field'], $data[$this->model['struct'][$key]])) {
                            $obj->save($models[$this->model['struct'][$key]['fk']['model']]);

                            $fkExists = true;
                        }

                    }

                    // else, create new entry
                    if (!$fkExists) {
                        // add account information
                        $models[$this->model['struct'][$key]['fk']['model']]['kyte_account'] = isset($data['kyte_account']) ? $data['kyte_account'] : $this->account->id;

                        // create object & get return
                        if ($obj->create($models[$this->model['struct'][$key]['fk']['model']])) {
                            if ($this->failOnNull) {
                                throw new \Exception("createFkEntries(): ".$this->exceptionMessages['new']['failOnNull']);
                            }
                        }

                        $data[$this->model['struct'][$key]] = $obj->{$this->model['struct'][$key]['fk']['field']};
                    }
                }
            }

        }

    }

    protected function createExternalEntries($models, $parentId) {
        if (count($models) == 0) return;

        if (isset($this->model['externalTables'])) {
            foreach($this->model['struct'] as $mod) {
                if (isset($models[$mod])) {
                    // find out which fk id corresponds to ext table model
                    $extTableModel = constant($mod);
                    foreach($extTableModel['struct'] as $key) {
                        if (isset($extTableModel['struct'][$key]['fk'])) {
                            if ($extTableModel['struct'][$key]['fk']['model'] == $mod) {
                                $models[$mod][$key] = $parentId;

                                // create external table
                                $obj = new \Kyte\Core\ModelObject(extTableModel);

                                if (!$obj->create($models[$mod])) {
                                    if ($this->failOnNull) {
                                        throw new \Exception("createExternalEntries(): ".$this->exceptionMessages['new']['failOnNull']);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    //
    //      1. covert times to unix time
    //      2. convert passwords to hashes
    //      3. check for foregin ands external table data
    protected function sift(&$data) {

        $linkedModels = [];

        foreach($data as $key => $value) {

            // first check existing model
            if (isset($this->model['struct'][$key])) {
                $requiresDateTimeFormat = isset($this->model['struct'][$key]['date']) ? $this->model['struct'][$key]['date'] : false;
                if ($requiresDateTimeFormat) {
                    // convert all dates to unix time
                    $data[$key] = strtotime($value);
                }
                $requiresPasswordHashing = isset($this->model['struct'][$key]['password']) ? $this->model['struct'][$key]['password'] : false;
                if ($requiresPasswordHashing) {
                    $data[$key] = password_hash($value, PASSWORD_DEFAULT);
                }
            } else {
                // see if data is in dot-notation i.e. <model>.<attribute>
                $p = explode('.', $key);
                // if count of 2, then dot-notation, process data
                if (count($p) == 2) {
                    $linkedModel = constant($p[0]);
                    if (isset($linkedModel['struct'][$p[1]])) {
                        if ($linkedModel['struct'][$p[1]]['date']) {
                            // convert all dates to unix time
                            $linkedModels[$p[0]] = [ $p[1] => strtotime($value) ];
                        } else {
                            // store data values
                            $linkedModels[$p[0]] = [ $p[1] => $value ];
                        }
                    }
                }
            }
        }

        return $linkedModels;
    }

    /*
     * NEW
     *  
     * new($data)
     * 
     * create new entry in db
     */
    public function new($data)
    {
        $response = [];

        try {
            if (!in_array('new', $this->allowableActions)) {
                return;
            }
    
            if (!$this->checkPermissions('new')) {
                throw new \Exception('Permission Denied');
            }
    
            // add account information
            $data['kyte_account'] = isset($data['kyte_account']) ? $data['kyte_account'] : $this->account->id;

            // hook for any custom behaviours before creating object
            $this->hook_preprocess('new', $data);
            
            // go through data parameters and...
            //      1. covert times to unix time
            //      2. check for foregin key table data
            //      3. check for external table data
            $linkedModels = $this->sift($data);

            // create any FK entries
            $this->createFkEntries($linkedModels, $data);

            // init new object
            $obj = new \Kyte\Core\ModelObject($this->model);

            // check existing and fail if present
            if ($this->checkExisting) {
                if ($obj->retrieve($this->checkExisting, $data[$this->checkExisting])) {
                    if ($this->existingThrowException) {
                        throw new \Exception($this->model['name'].' already exists');
                    } else {
                        return;
                    }
                }
            }

            // add user info
            if (isset($this->user->id)) {
                $data['created_by'] = $this->user->id;
            }
            
            // create object & get return
            if ($obj->create($data)) {
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('new', $obj, $ret, $data);
                $response[] = $ret;
            } else {
                if ($this->failOnNull) {
                    throw new \Exception($this->exceptionMessages['new']['failOnNull']);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    /*
     * UPDATE
     *  
     * update($field, $value, $data)
     * 
     * update entry in db
     */
    public function update($field, $value, $data)
    {
        $response = [];
        
        try {
            if (!in_array('update', $this->allowableActions)) {
                return;
            }
    
            if (!$this->checkPermissions('update')) {
                throw new \Exception('Permission Denied');
            }

            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->id]] : null;
            $all = false;
            $this->hook_prequery('update', $field, $value, $conditions, $all, $order);
            // init object
            $obj = new \Kyte\Core\ModelObject($this->model);

            if ($field === null || $value === null) throw new \Exception("Field and Values params not set");

            if ($obj->retrieve($field, $value, $conditions, null, $all)) {

                $this->hook_preprocess('update', $data, $obj);

                // go through data parameters and...
                //      1. covert times to unix time
                //      2. check for foregin key table data
                //      3. check for external table data
                $linkedModels = $this->sift($data);

                // create any FK entries
                $this->createFkEntries($linkedModels, $data);

                // check existing and fail if present
                if ($this->checkExisting && isset($data[$this->checkExisting])) {
                    $existing = new \Kyte\Core\ModelObject($this->model);
                    if ($existing->retrieve($this->checkExisting, $data[$this->checkExisting])) {
                        if ($existing->id != $obj->id) {
                            throw new \Exception('There is already a '.strtolower($this->model['name']).' with that '.$this->checkExisting.'.');
                        }
                    }
                }

                // add user info
                if (isset($this->user->id)) {
                    $data['modified_by'] = $this->user->id;
                }

                $obj->save($data);
                $ret = [];
                $ret = $this->getObject($obj);
                $this->hook_response_data('update', $obj, $ret, $data);
                $response[] = $ret;
            } else {
                if ($this->failOnNull) {
                    throw new \Exception($this->exceptionMessages['update']['failOnNull']);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    /*
     * GET
     *  
     * get($field, $value)
     * 
     * get entry from db
     */
    public function get($field, $value)
    {
        $response = [];

        try {
            if (!in_array('get', $this->allowableActions)) {
                return;
            }
    
            if (!$this->checkPermissions('get')) {
                throw new \Exception('Permission Denied');
            }

            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->id]] : null;
            $all = false;
            $order = null;

            // handle table order requests
            if (isset($_SERVER['HTTP_X_KYTE_PAGE_ORDER_COL'], $_SERVER['HTTP_X_KYTE_PAGE_ORDER_DIR'])) {
                $col = $_SERVER['HTTP_X_KYTE_PAGE_ORDER_COL'];
                $dir = $_SERVER['HTTP_X_KYTE_PAGE_ORDER_DIR'];
                if (!empty($col) && !empty($dir)) {
                    $order = [['field' => $col, 'direction' => $dir]];
                }
            }

            // check if conditions were passed through header
            if (isset($_SERVER['HTTP_X_KYTE_QUERY_CONDITIONS'])) {
                $decoded_string = $json_string = preg_replace('~^"?(.*?)"?$~', '$1', stripslashes(base64_decode($_SERVER['HTTP_X_KYTE_QUERY_CONDITIONS'])));
                $supplied_conditions = json_decode($decoded_string, true);
                if (is_array($supplied_conditions)) {
                    // $conditions[] = $supplied_conditions;
                    error_log(print_r($supplied_conditions));
                } else {
                    error_log("Supplied conditions were not an array. JSON may be corrupt. ".$decoded_string);
                }
            }

            $this->hook_prequery('get', $field, $value, $conditions, $all, $order);
            
            // search fields and values passed from DataTables
            $search_fields = isset($_SERVER['HTTP_X_KYTE_PAGE_SEARCH_FIELDS']) ? $_SERVER['HTTP_X_KYTE_PAGE_SEARCH_FIELDS'] : null;
            $search_values = isset($_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE']) ? urldecode(base64_decode($_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE'])) : null;

            if (DEBUG) {
                error_log("HTTP_X_KYTE_PAGE_SEARCH_VALUE: ".$_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE']."; Decoded: ".$search_values);
            }

            // init model
            $objs = new \Kyte\Core\Model($this->model, $this->page_size, $this->page_num, $search_fields, $search_values);
            $objs->retrieve($field, $value, false, $conditions, $all, $order);

            // get total count
            $this->total_count = $objs->total;
            $this->total_filtered = $objs->total_filtered;
            $this->page_total = ceil($this->total_filtered / $this->page_size);

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
        } catch (\Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    protected function deleteCascade($obj) {
        if ($this->cascadeDelete && isset($obj->kyte_model['externalTables'])) {
            // get uid if set
            $userId = isset($this->user->id) ? $this->user->id : null;
            
            // find external tables and delete associated entries
            foreach ($obj->kyte_model['externalTables'] as $extTbl) {
                $dep = new \Kyte\Core\Model(constant($extTbl['model']));
                $dep->retrieve($extTbl['field'], $obj->id, false, $conditions);

                // delete each associated entry in the table
                foreach ($dep->objects as $item) {
                    $this->deleteCascade($item);
                    $item->delete(null, null, $userId);
                }
            }
        }
    }

    /*
     * DELETE
     *  
     * delete($field, $value)
     * 
     * mark entry as deleted in db
     */
    public function delete($field, $value)
    {
        $response = [];

        try {
            if (!in_array('delete', $this->allowableActions)) {
                return;
            }
    
            if (!$this->checkPermissions('delete')) {
                throw new \Exception('Permission Denied');
            }
    
            if ($field === null || $value === null) throw new \Exception("Field ($field) and Value ($value) params not set");

            // get uid if set
            $userId = isset($this->user->id) ? $this->user->id : null;

            $conditions = $this->requireAccount ? [[ 'field' => 'kyte_account', 'value' => $this->account->id]] : null;
            $objs = new \Kyte\Core\Model($this->model);
            $objs->retrieve($field, $value, false, $conditions);
            
            if ($this->failOnNull && count($objs->objects) < 1) {
                throw new \Exception($this->exceptionMessages['delete']['failOnNull']);
            }

            foreach ($objs->objects as $obj) {
                $autodelete = true;
                $this->hook_response_data('delete', $obj, $autodelete);

                if ($autodelete) {
                    // if cascade delete is set delete associated data
                    if ($this->cascadeDelete && isset($this->model['externalTables'])) {
                        // find external tables and delete associated entries
                        foreach ($this->model['externalTables'] as $extTbl) {
                            $dep = new \Kyte\Core\Model(constant($extTbl['model']));
                            $dep->retrieve($extTbl['field'], $obj->id, false, $conditions);

                            // delete each associated entry in the table
                            foreach ($dep->objects as $item) {
                                $this->deleteCascade($item);
                                $item->delete(null, null, $userId);
                            }
                        }
                    }

                    // finally, delete object
                    $obj->delete(null, null, $userId);
                }
            }

        } catch (\Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    // kyte shipyard hooks
    public function shipyard_init() {}
    
    // hook function - user defined
    public function hook_init() {}
    public function hook_auth() {}
    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}
    public function hook_preprocess($method, &$r, &$o = null) {}
    public function hook_response_data($method, $o, &$r = null, &$d = null) {}
    public function hook_process_get_response(&$r) {}
}
