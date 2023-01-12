<?php

$SubjectAlternativeName = [
	'name' => 'SubjectAlternativeName',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'date'		=> false,
			'size'	=> 512,
		],
		
		'domain'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Domain',
				'field'	=> 'id',
			],
		],
	],
];

?>