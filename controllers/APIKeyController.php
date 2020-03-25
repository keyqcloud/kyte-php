<?php

class APIKeyController extends ModelController
{
    private function authenticate() {}
    
    // new - creates new signature for api
    public function new($data)
    {
        $response = [];

        try {
            $obj = new \Kyte\ModelObject($this->model);
            if ($obj->retrieve('public_key', $this->publickey)) {
            } else throw new Exception("Invalid API access key");

            $date = new DateTime($data['kyte-time'], new DateTimeZone('UTC'));

            $hash1 = hash_hmac('SHA256', $date->format('U'), $obj->getParam('secret_key'), true);
            $hash2 = hash_hmac('SHA256', $data['kyte-identifier'], $hash1, true);
            $response['signature'] = hash_hmac('SHA256', $this->publickey, $hash2);
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update
    public function update($field, $value, $data)
    {
        throw new \Exception("Undefined request method");
    }

    // get
    public function get($field, $value)
    {
        throw new \Exception("Undefined request method");
    }

    // delete
    public function delete($field, $value)
    {
        throw new \Exception("Undefined request method");
    }
}

?>
