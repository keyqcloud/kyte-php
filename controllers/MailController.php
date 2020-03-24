<?php

class MailController extends ModelController
{
    // new  :   {model}, {data}
    public static function new($model, $data, $dateformat)
    {
        $response = [];
        try {
            // [ {to_email} => {to_name} ], [ {from_email} => {from_name} ], {subject}, {body}
            \Kyte\Mail::email(
				[ APP_EMAIL => APP_NAME ],
				$data['subject'],
				$data['body']
			);
        } catch (Exception $e) {
            throw $e;
        }

        return $response;
    }

    // update   :   {model}, {field}, {value}, {data}
    public static function update($model, $field, $value, $data, $dateformat)
    {
        throw "Undefined request method";
    }

    // get  :   {model}, {field}, {value}
    public static function get($model, $field, $value, $dateformat)
    {
        throw "Undefined request method";
    }

    // delete   :   {model}, {field}, {value}
    public static function delete($model, $field, $value, $dateformat)
    {
        throw "Undefined request method";
    }
}

?>
