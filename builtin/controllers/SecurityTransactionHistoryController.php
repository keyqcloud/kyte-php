<?php

class SecurityTransactionHistoryController extends ModelController
{

    // * for subclasses that are public, override with empty function
    protected function authenticate()
    {
        $this->user = $this->session->validate($this->txToken, $this->sessionToken, false);
    }

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
    public function hook_response_data($method, $o, &$r) {
        $tx_session = new \Kyte\ObjectModel(Session);
        // retrieve all
        if (!$tx_session->retrieve('txToken', $o->getParam('txToken'), null, null, true)) {
            throw new \Exception("[ERROR] **PROBLEM WITH SECURITY & AUDIT FEATURE** Unable to find session info! This is a security related issue - please contact your administrator.");
        }
        $tx_user = new \Kyte\ObjectModel(Account);
        if (!$tx_user->retrieve('id', $tx_session->getParam('uid'), null, null, true)) {
            throw new \Exception("[ERROR] **PROBLEM WITH SECURITY & AUDIT FEATURE** Unable to find user info! This is a security related issue - please contact your administrator.");
        }
        $r['TX_SESSION'] = $ret = $this->getObject($tx_session);
        $r['TX_ACCOUNT'] = $ret = $this->getObject($tx_user);
    }
}

?>
