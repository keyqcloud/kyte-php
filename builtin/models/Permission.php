<?php

$Permission = [
	'name' => 'Permission',
	'struct' => [
		'role'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'model'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'action'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		// framework attributes

		'kyte_account'	=> [
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
