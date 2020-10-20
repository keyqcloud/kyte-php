<?php

$APIKey = [
	'name' => 'APIKey',
	'struct' => [
		'id'			=> [
			'type'		=> 'i',
			'required'	=> false,
			'pk'		=> true,
			'size'		=> 11,
			'date'		=> false,
		],

		'identifier'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'public_key'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'secret_key'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		'epoch'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> true,
		],

		// framework attributes

		'account'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// audit attributes

		'date_created'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'date_modified'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
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
