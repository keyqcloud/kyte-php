<?php

class MailController extends ModelController
{
    /* override constructor and authenticate as we don't need any of the initialization defined in the parent class for Mail */
    public function __construct($model, $dateformat, $token) {}
    private function authenticate() {}

    // new - send new email
    public function new($data)
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