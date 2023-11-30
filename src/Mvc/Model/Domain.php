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

		'assigned' => [
			'type'		=> 's',
			'required'	=> false,
			'date'		=> false,
			'size'	=> 255,
		],
		
		'site'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'KyteSite',
				'field'	=> 'id',
			],
		],

		'kyte_account'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// audit attributes

		'created_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_created'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'modified_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_modified'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'deleted_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
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
	'externalTable' => [
		[
			'model' => 'SubjectAlternativeName',
			'field' => 'domain',
		],
	],
];

?>