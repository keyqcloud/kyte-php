<?php

class AccountController extends ModelController
{
    // new  :   {model}, {data}
    public function new($model, $data, $dateformat)
    {
        $response = [];
        
        try {
            $obj = new \Kyte\ModelObject($$model);
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            if ($obj->create($data)) {
                $response = $obj->getAllParams($dateformat);
                $response['password'] = '';     // better leave password hash empty - no need for front-end to get it
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
            $obj = new \Kyte\ModelObject($$model);
            $obj->retrieve($field, $value);
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
        $response = [];

        try {
            $objs = new \Kyte\Model($$model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                // return list of data
                $response[] = $obj->getAllParams($dateformat);
                $response['password'] = '';     // better leave password hash empty - no need for front-end to get it
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete   :   {model}, {field}, {value}
    public function delete($model, $field, $value, $dateformat)
    {
        $response = [];

        try {
            $objs = new \Kyte\Model($$model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                $obj->delete();
            }

            $response = $this->get($model, $field, $value, $dateformat);

        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }
}

?>
