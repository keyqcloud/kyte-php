<?php

namespace Kyte\Mvc\Controller;

/**
 * Class ModelController
 *
 * Default controller class which handles all HTTP requests. Can be subclassed for customization as well as creating service controllers.
 * 
 * @package Kyte\Mvc\Controller
 */
class ModelController
{
    /**
     * @deprecated These member variables are maintained for backwards compatibility but will be deprecated in the near future.
     * 
     * User object of the current user who is logged in. Null if no session.
     *
     * @var KyteUser
     */
    protected $user;
    /**
     * @deprecated These member variables are maintained for backwards compatibility but will be deprecated in the near future.
     * 
     * Account object for the current user who is logged in. If no session, then account of API key holder.
     *
     * @var KyteAccount
     */
    protected $account;
    /**
     * @deprecated These member variables are maintained for backwards compatibility but will be deprecated in the near future.
     * 
     * Session object of current session
     *
     * @var Session
     */
    protected $session;
    
    /**
     * Reference to object to be returned by API.
     *
     * @var array<string,mixed>
     */
    protected $response;

    /**
     * Model specified by request. Null if service controller.
     *
     * @var array[
     *     name: string,
     *     struct: [
     *         <attribute_name>: [
     *             'type' => 's' | 'i' | 't',
     *             'required' => bool,
     *             'size' => int,
     *             'date' => bool,
     *             'unsigned' => bool,
     *             'protected'	=> false,
     *             'default' => string | int
     *             'fk'		=> [
     *                  'model'	=> string,
     *                  'field'	=> string,
     *              ],
     *         ],
     *     ],
     * ]
     */
    public $model;
    
    /**
     * Reference to instantiated Api
     *
     * @var Api
     */
    protected $api;

    /**
     * Date time format string, i.e. Y/m/d
     *
     * @var string
     */
    public $dateformat;

    /**
     * List of allowable actions.
     * Accepted values are 'new' for POST, 'update' for PUT, 'get' for GET, and 'delete' for DELETE.
     *
     * @var array<string>
     */
    protected $allowableActions;

    /**
     * Flag to determine if delete should cascade.
     * Defualt is true.
     *
     * @var bool
     */
    protected $cascadeDelete;
    protected $getFKTables;
    protected $getExternalTables;
    protected $requireAuth;
    protected $requireRoles;
    protected $requireAccount;
    protected $failOnNull;
    protected $checkExisting;
    protected $existingThrowException;

    // array with error messages
    protected $exceptionMessages;

    public function __construct($model, &$api, $dateformat, &$response)
    {
        try {
            
            $this->model = $model;
            $this->api = $api;
            $this->dateformat = $dateformat;
            $this->response = &$response;

            /**
             * @deprecated These member variables are maintained for backwards compatibility but will be deprecated in the near future.
             */
            $this->user = $this->api->user;
            /**
             * @deprecated These member variables are maintained for backwards compatibility but will be deprecated in the near future.
             */
            $this->account = $this->api->account;
            /**
             * @deprecated These member variables are maintained for backwards compatibility but will be deprecated in the near future.
             */
            $this->session = $this->api->session;

            // Set default actions allowed by controller.
            // Accepted values are 'new' for POST, 'update' for PUT, 'get' for GET, and 'delete' for DELETE.
            $this->allowableActions = ['new', 'update', 'get', 'delete'];

            // set default behaviours for controller
            $this->cascadeDelete = true;
            $this->getFKTables = true;
            $this->getExternalTables = false;
            $this->requireAuth = true;
            $this->requireRoles = true;
            $this->requireAccount = true;
            $this->checkExisting = null;
            $this->existingThrowException = true;
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
        if (!isset($this->api->user->id) || !$this->api->session->hasSession) {
            throw new \Kyte\Exception\SessionException("Unauthorized API request.");
        }
        $this->hook_auth();
    }

    protected function checkPermissions($requestType, $modelName = null) {
        // check if user id, account id is set, and whether the flag to check roles is also set to true
        // also check to make sure this isn't a service controller by checking the model
        if (!isset($this->user->id, $this->account->id) && $this->requireRoles && $this->model === null) {
            return true; // Skip permission check if conditions are not met
        }
    
        $modelName = $modelName ?? $this->model['name'];
    
        // check if this requested model is at the app level
        if (isset($this->model['appId'])) {
            return true; // Skip permission check if it's a app level model
        }
    
        $role = new \Kyte\Core\ModelObject(Role);

        $cond = $this->requireAccount ? [['field' => 'kyte_account', 'value' => $this->api->account->id]] : null;
    
        if (!$role->retrieve('id', $this->app->user->role, $cond)) {
            error_log('['.$this->model['name'].'] => ['.$requestType.'] unable to find role for '.$this->api->user->role.' and '.$this->api->account->id);
            return false;
        }
    
        $cond[] = ['field' => 'model', 'value' => $modelName];
        $cond[] = ['field' => 'action', 'value' => $requestType];
    
        $permission = new \Kyte\Core\ModelObject(Permission);
        if (!$permission->retrieve('role', $role->id, $cond)) {
            error_log('unable to find permission');
            return false;
        }
    
        return true;
    }

    protected function getObject($obj) {
        try {
            $response = $obj->getAllParams();
    
            foreach ($response as $key => &$value) {
                if (!isset($obj->kyte_model['struct'][$key])) {
                    continue; // Skip if key not found in struct
                }
    
                $struct = $obj->kyte_model['struct'][$key];
    
                if (STRICT_TYPING) {
                    $value = $struct['type'] === 'i' ? intval($value) : strval($value);
                }
    
                if (isset($struct['protected']) && $struct['protected']) {
                    $value = ''; // Set to empty string for protected attribute
                }
    
                if (isset($struct['date']) && $struct['date']) {
                    $dateFormat = isset($struct['dateformat']) ? $struct['dateformat'] : $this->dateformat;
                    $value = !empty($value) ? date($dateFormat, $value) : ''; // Format date value
                }
    
                if ($this->getFKTables && isset($struct['fk'], $value) && !empty($value)) {
                    $fk = $struct['fk'];
    
                    if (isset($fk['model'], $fk['field']) && $this->checkPermissions('get', $fk['model'])) {
                        $fk_model = constant($fk['model']);
                        $fk_obj = new \Kyte\Core\ModelObject($fk_model);

                        $conditions = null;
                        if (!isset($fk_model['appId']) && $this->requireAccount && $fk['model'] !== 'KyteAccount') {
                            $conditions = [['field' => 'kyte_account', 'value' => $this->api->account->id]];
                        } elseif ($this->api->app != null && $this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($fk_model['struct'][$this->api->app->userorg_colname])) {
                            $conditions = [['field' => $this->api->app->userorg_colname, 'value' => $this->api->user->{$this->api->app->userorg_colname}]];
                        }
    
                        if ($fk_obj->retrieve($fk['field'], $value, $conditions, null, true)) {
                            $value = $this->getObject($fk_obj); // Recursively get object for FK
                        }
                    }
                }
            }
    
            if ($this->getExternalTables && isset($obj->kyte_model['externalTables'])) {
                $fkFlag = $this->getFKTables;
                $this->getFKTables = false;
                $response['ExternalTables'] = [];
    
                foreach ($obj->kyte_model['externalTables'] as $et) {
                    if (isset($et['model'], $et['field']) && $this->checkPermissions('get', $et['model'])) {
                        $et_model = constant($et['model']);
                        $et_objs = new \Kyte\Core\Model($et_model);
                        
                        $conditions = null;
                        if (!isset($et_model['appId']) && $this->requireAccount && $et_model['name'] !== 'KyteAccount') {
                            $conditions = [['field' => 'kyte_account', 'value' => $this->api->account->id]];
                        } elseif ($this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($et_model['struct'][$this->api->app->userorg_colname])) {
                            $conditions = [['field' => $this->api->app->userorg_colname, 'value' => $this->api->user->{$this->api->app->userorg_colname}]];
                        }

                        $et_objs->retrieve($et['field'], $response['id'], false, $conditions);
    
                        foreach ($et_objs->objects as $et_obj) {
                            if (!isset($response['ExternalTables'][$et['model']])) {
                                $response['ExternalTables'][$et['model']] = [];
                            }
                            $response['ExternalTables'][$et['model']][] = $this->getObject($et_obj);
                        }
                    }
                }
    
                $this->getFKTables = $fkFlag;
            }
    
            return $response;
        } catch (\Exception $e) {
            throw $e;
        }
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
                        if ($this->model !== null) {
                            if ($this->model['appId'] === null) {
                                // add account information
                                $models[$this->model['struct'][$key]['fk']['model']]['kyte_account'] = $data['kyte_account'] ?? $this->api->account->id;
                            } elseif ($this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($models[$this->model['struct'][$key]['fk']['model']][$this->api->app->userorg_colname])) {
                                $models[$this->model['struct'][$key]['fk']['model']][$this->api->app->userorg_colname] = $data[$this->api->app->userorg_colname] ?? $this->api->user->{$this->api->app->userorg_colname};
                            }
                        }

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
    
            if ($this->model !== null) {
                if ($this->model['appId'] === null) {
                    // add account information
                    $data['kyte_account'] = $data['kyte_account'] ?? $this->api->account->id;
                } elseif ($this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($this->model['struct'][$this->api->app->userorg_colname])) {
                    $data[$this->api->app->userorg_colname] = $data[$this->api->app->userorg_colname] ?? $this->api->user->{$this->api->app->userorg_colname};
                }
            }

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
            if (isset($this->api->user->id)) {
                $data['created_by'] = $this->api->user->id;
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

            $conditions = null;
            if ($this->model !== null) {
                if ($this->model['appId'] === null && $this->requireAccount) {
                    $conditions = [['field' => 'kyte_account', 'value' => $this->api->account->id]];
                } elseif ($this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($this->model['struct'][$this->api->app->userorg_colname])) {
                    $conditions = [['field' => $this->api->app->userorg_colname, 'value' => $this->api->user->{$this->api->app->userorg_colname}]];
                }
            }
            

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
                if (isset($this->api->user->id)) {
                    $data['modified_by'] = $this->api->user->id;
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

            $conditions = null;
            if ($this->model !== null) {
                if ($this->model['appId'] === null && $this->requireAccount) {
                    $conditions = [['field' => 'kyte_account', 'value' => $this->api->account->id]];
                } elseif ($this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($this->model['struct'][$this->api->app->userorg_colname])) {
                    $conditions = [['field' => $this->api->app->userorg_colname, 'value' => $this->api->user->{$this->api->app->userorg_colname}]];
                }
            }
            
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
                    foreach ($supplied_conditions as $sc) {
                        $new_cond = [];
                        foreach(array_keys($sc) as $key) {
                            $new_cond[$key] = rtrim($sc[$key]);
                            error_log($key.' => '.$new_cond[$key]);
                        }
                        if ($conditions == null) {
                            $conditions = [];
                            $conditions[] = $new_cond;
                        } else {
                            $conditions[] = $new_cond;
                        }
                    }
                } else {
                    error_log("Supplied conditions were not an array. JSON may be corrupt. ".$decoded_string);
                }
            }

            $isLike = false;
            if (isset($_SERVER['HTTP_X_KYTE_QUERY_LIKE']) && $_SERVER['HTTP_X_KYTE_QUERY_LIKE'] == 'true') {
                $isLike = true;
            }

            $this->hook_prequery('get', $field, $value, $conditions, $all, $order);
            
            // search fields and values passed from DataTables
            $search_fields = isset($_SERVER['HTTP_X_KYTE_PAGE_SEARCH_FIELDS']) ? $_SERVER['HTTP_X_KYTE_PAGE_SEARCH_FIELDS'] : null;
            $search_values = isset($_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE']) ? urldecode(base64_decode($_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE'])) : null;

            if (DEBUG) {
                error_log("HTTP_X_KYTE_PAGE_SEARCH_VALUE: ".$_SERVER['HTTP_X_KYTE_PAGE_SEARCH_VALUE']."; Decoded: ".$search_values);
            }

            // init model
            $objs = new \Kyte\Core\Model($this->model, $this->api->page_size, $this->api->page_num, $search_fields, $search_values);
            $objs->retrieve($field, $value, $isLike, $conditions, $all, $order);

            // get total count
            $this->api->total_count = $objs->total;
            $this->api->total_filtered = $objs->total_filtered;
            $this->api->page_total = ceil($this->api->total_filtered / $this->api->page_size);

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
            $userId = isset($this->api->user->id) ? $this->api->user->id : null;
            
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
            $userId = isset($this->api->user->id) ? $this->api->user->id : null;

            $conditions = null;
            if ($this->model !== null) {
                if ($this->model['appId'] === null && $this->requireAccount) {
                    $conditions = [['field' => 'kyte_account', 'value' => $this->api->account->id]];
                } elseif ($this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($this->model['struct'][$this->api->app->userorg_colname])) {
                    $conditions = [['field' => $this->api->app->userorg_colname, 'value' => $this->api->user->{$this->api->app->userorg_colname}]];
                }
            }
            
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
