<?php

$KyteActivityLog = [
	'name' => 'KyteActivityLog',
	'struct' => [
		// WHO
		'user_id'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'user_email'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'user_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'account_id'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'account_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'application_id'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'application_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		// WHAT
		'action'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'date'		=> false,
		],

		'model_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'record_id'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'field'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'value'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'request_data'		=> [
			'type'		=> 'lt',
			'required'	=> false,
			'date'		=> false,
		],

		'changes'		=> [
			'type'		=> 'lt',
			'required'	=> false,
			'date'		=> false,
		],

		// RESULT
		'response_code'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'response_status'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'date'		=> false,
		],

		'error_message'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// WHERE
		'ip_address'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 45,
			'date'		=> false,
		],

		'user_agent'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
		],

		'session_token'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'request_uri'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 2048,
			'date'		=> false,
		],

		'request_method'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 10,
			'date'		=> false,
		],

		// META
		'severity'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'info',
			'date'		=> false,
		],

		'event_category'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 50,
			'date'		=> false,
		],

		'duration_ms'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'kyte_account'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// audit attributes

		'created_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_created'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'modified_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_modified'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'deleted_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_deleted'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'deleted'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],
	],
];
