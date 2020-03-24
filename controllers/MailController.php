<?php

class MailController extends ModelController
{
    public function __construct($token) {}

    // new  :   {model}, {data}
    public function new($model, $data, $dateformat)
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
    public function update($model, $field, $value, $data, $dateformat)
    {
        throw new \Exception("Undefined request method");
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
