<?php

$Account = [
	'name' => 'Account',
	'struct' => [
		'id'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'email'		=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'password'	=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'role_id'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> false,
		],

		'deleted'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],
	],
];

?>
