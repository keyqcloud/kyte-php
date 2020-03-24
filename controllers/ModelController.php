<?php

class ModelController
{
    // new  :   {model}, {data}
    public static function new($model, $data, $dateformat)
    {
        $response = [];
        try {
            $obj = new \Kyte\ModelObject($$model);
            if ($obj->create($data)) {
                $response = $obj->getAllParams($dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update   :   {model}, {field}, {value}, {data}
    public static function update($model, $field, $value, $data, $dateformat)
    {
        $response = [];

        try {
            $obj = new \Kyte\ModelObject($$model);
            $obj->retrieve($field, $value);
            if ($obj) {
                $obj->save($data);
                $response = $obj->getAllParams($dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // get  :   {model}, {field}, {value}
    public static function get($model, $field, $value, $dateformat)
    {
        $response = [];

        try {
            $objs = new \Kyte\Model($$model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                // return list of data
                $response[] = $obj->getAllParams($dateformat);
            }
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // delete   :   {model}, {field}, {value}
    public static function delete($model, $field, $value, $dateformat)
    {
        $response = [];

        try {
            $objs = new \Kyte\Model($$model);
            $objs->retrieve($field, $value);
            foreach ($objs->objects as $obj) {
                $obj->delete();
            }

            $response = static::get($model, $field, $value, $dateformat);

        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }
}

?>
