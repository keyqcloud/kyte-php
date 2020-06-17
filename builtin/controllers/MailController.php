<?php

class MailController extends ModelController
{
    // new - send new email
    public function new($data)
    {
        $response = [];

        try {
            $type = isset($data['type']) ? $data['type'] : 'text/plain';
            // [ {to_email} => {to_name} ], [ {from_email} => {from_name} ], {subject}, {body}
            \Kyte\Mail::email(
                [ 'address' => $data['email'], 'name' => $data['name'] ],
                [ 'address' => APP_EMAIL, 'name' => APP_NAME ],
				$data['subject'],
                $data['body'],
                $type
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
