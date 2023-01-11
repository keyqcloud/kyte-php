<?php

$Media = [
	'name' => 'Media',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
			'size'	=> 512,
		],
		's3key'		=> [
			'type'		=> 's',
			'required'	=> false,
			'date'		=> false,
			'size'	=> 512,
		],
		'thumbnail'		=> [
			'type'		=> 's',
			'required'	=> false,
			'date'		=> false,
			'size'	=> 512,
		],
		
		'site'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Site',
				'field'	=> 'id',
			],
		],
	],
];

?>