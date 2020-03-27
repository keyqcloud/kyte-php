<?php

$Session = [
	'name' => 'Session',
	'struct' => [
		'id'			=> [
			'type'		=> 'i',
			'required'	=> false,
			'pk'		=> true,
			'size'		=> 11,
			'date'		=> false,
		],

		'uid'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'create_date'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> true,
		],

		'exp_date'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> true,
		],

		'token'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
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
