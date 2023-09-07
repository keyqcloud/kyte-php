<?php

$Application = [
	'name' => 'Application',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'identifier'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		// database field

		'db_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		'db_host'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		'db_username'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		'db_password'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		// options for custom user table

		'user_model'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'username_colname'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'password_colname'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		// organization based user scoping

		'org_model'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'userorg_colname'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'obfuscate_kyte_connect' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'kyte_connect'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'kyte_connect_obfuscated'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// optional customer AWS credentials

		'aws_key'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'date'		=> false,
			'unsigned'	=> true,
			'fk' => [
				'model'	=> 'KyteAWSKey',
				'field'	=> 'id',
			]
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
];
