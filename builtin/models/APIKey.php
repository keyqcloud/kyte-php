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
			'size'		=> 11,
			'date'		=> false,
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
