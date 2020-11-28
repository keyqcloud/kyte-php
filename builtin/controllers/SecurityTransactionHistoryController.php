<?php

class SecurityTransactionHistoryController extends ModelController
{

    // disallowed methods
    public function new($data) { throw new \Exception("Undefined request method"); }
    public function update($field, $value, $data) { throw new \Exception("Undefined request method"); }
    public function delete($field, $value) { throw new \Exception("Undefined request method"); }

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

    // hook function - user defined
    public function hook_response_data($method, $o, &$r = null) {
        $tx_session = new \Kyte\ModelObject(Session);
        $tx_user = new \Kyte\ModelObject(Account);
        // retrieve all
        if ($tx_session->retrieve('txToken', $o->getParam('txToken'), null, null, true)) {
            $r['TX_SESSION'] = $ret = $this->getObject($tx_session);
            if (!$tx_user->retrieve('id', $tx_session->getParam('uid'), null, null, true)) {
                throw new \Exception("[ERROR] **PROBLEM WITH SECURITY & AUDIT FEATURE** Unable to find user info! This is a security related issue - please contact your administrator.");
            }
            $r['TX_USER'] = $ret = $this->getObject($tx_user);
        } else {
            $r['TX_SESSION'] = 'Anonymous';
            $r['TX_USER']['name'] = 'Anonymous';
        }
        
    }
}

?>
