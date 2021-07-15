<?php

$Account = [
	'name' => 'Account',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'number'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 32,
			'date'		=> false,
		],

		// audit attributes

		'created_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'date_created'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'modified_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'deleted_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'created_by'		=> [
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
