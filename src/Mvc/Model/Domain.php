<?php

$Domain = [
	'name' => 'Domain',
	'struct' => [
		'domainName'		=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
			'size'	=> 512,
		],
		'certificateArn'		=> [
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