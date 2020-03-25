<?php

class EnrollmentController extends ModelController
{
    public function __construct($token) {}

    // new  :   {model}, {data}
    public function new($model, $data, $dateformat)
    {
        $response = [];

        try {
            $obj = new \Kyte\ModelObject(Account);
            $data['password'] = 't0k3n'.password_hash($data['password'], PASSWORD_DEFAULT);
            if ($obj->create($data)) {
                $response = $obj->getAllParams($dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update   :   {model}, {field}, {value}, {data}
    public function update($model, $field, $value, $data, $dateformat)
    {
        $response = [];

        try {
            $obj = new \Kyte\ModelObject(Account);
            $obj->retrieve('password', $value);
            if ($obj) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
                $obj->save($data);
                $response = $obj->getAllParams($dateformat);
                $response['password'] = '';     // better leave password hash empty - no need for front-end to get it
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // get  :   {model}, {field}, {value}
    public function get($model, $field, $value, $dateformat)
    {
        throw new \Exception("Undefined request method");
    }

    // delete   :   {model}, {field}, {value}
    public function delete($model, $field, $value, $dateformat)
    {
        throw new \Exception("Undefined request method");
    }
}

?>
