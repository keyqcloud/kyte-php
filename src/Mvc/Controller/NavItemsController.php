<?php

namespace Kyte\Mvc\Controller;

class NavItemsController extends ModelController
{
    public function hook_init() {
        $this->allowableActions = ['update'];
    }

    public function update($field, $value, $data)
    {
        $response = [];
        
        try {

            // check if field and value are set.
            // field contains the nav item model name (either NavigationItem or SideNavItem)
            // value contains the total count in the array that is supplied in the data
            if ($field === null || $value === null) throw new \Exception("Field and Values params not set");
            // determine model
            if ($field == 'NavigationItem' || $field == "SideNavItem") {
                $this->model(constant($field));
            } else {
                throw new \Exception("Unknown navigation item model type provided.");
            }
            // check if data is set
            if ($data == null || !isset($data['navitems'])) {
                throw new \Exception("Nav items must be supplied and cannot be null (param: navitems).");
            }
            if (!is_array($data['navitems'])) {
                throw new \Exception("Nav items must be supplied as an array.");
            }
            if (count($data['navitems']) != $value) {
                throw new \Exception("Supplied navitem count and array length do not match.");
            }

            if (!in_array('update', $this->allowableActions)) {
                return;
            }
    
            if (!$this->checkPermissions('update')) {
                throw new \Exception('Permission Denied');
            }

            $conditions = null;
            if ($this->model !== null) {
                if (!isset($this->model['appId']) && $this->requireAccount) {
                    $conditions = [['field' => 'kyte_account', 'value' => $this->api->account->id]];
                } elseif ($this->api->app->org_model !== null && $this->api->app->userorg_colname !== null && isset($this->model['struct'][$this->api->app->userorg_colname])) {
                    $conditions = [['field' => $this->api->app->userorg_colname, 'value' => $this->api->user->{$this->api->app->userorg_colname}]];
                }
            }
            
            // init object
            $obj = new \Kyte\Core\ModelObject($this->model);
            // iterate through each nav item and update
            foreach($data['navitems'] as $navitem) {
                if ($obj->retrieve('id', $navitem['id'], $conditions)) {

                    // add user info
                    if (isset($this->api->user->id)) {
                        $data['modified_by'] = $this->api->user->id;
                    }
    
                    $params = [];
                    $params['title'] = $navitem['title'];
                    $params['faicon'] = $navitem['faicon'];
                    $params['link'] = $navitem['link'];
                    $params['page'] = $navitem['page'];
                    $params['itemOrder'] = $navitem['itemOrder'];

                    if ($field == 'NavigationItem') {
                        // unique to NavigationItem
                        $params['isLogout'] = $navitem['isLogout'];
                        $params['center'] = $navitem['center'];
                        $params['parentItem'] = $navitem['parentItem'];
                    }

                    $obj->save($params);
    
                    $response[] = $navitem;
                } else {
                    if ($this->failOnNull) {
                        throw new \Exception($this->exceptionMessages['update']['failOnNull']);
                    }
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $this->response['data'] = $response;
    }

    // public function hook_auth() {}

    // public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {}

    // public function hook_preprocess($method, &$r, &$o = null) {}

    // public function hook_response_data($method, $o, &$r = null, &$d = null) {}

    // public function hook_process_get_response(&$r) {}
}
