<?php

$SecurityTransactionHistory = [
	'name' => 'SecurityTransactionHistory',
	'struct' => [
		'txToken'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'url_path'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 1024,
			'date'		=> false,
		],

		'origin'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'ip_address'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'user_agent'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'method'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'data'	=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
			'protected'	=> true,
		],

		'return'	=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
			'protected'	=> true,
		],

		// audit attributes

		'date_created'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
			'dateformat'	=> 'Y/m/d H:i:s',
		],

		'date_modified'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
			'dateformat'	=> 'Y/m/d H:i:s',
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

?>
