<?php

$APIKey = [
	'name' => 'APIKey',
	'struct' => [
		'id'			=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'domain'		=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'public_key'	=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
		],

		'secret_key'	=> [
			'type'		=> 's',
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
