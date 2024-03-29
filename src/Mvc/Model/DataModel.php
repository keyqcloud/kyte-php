<?php

$DataModel = [
	'name' => 'DataModel',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'model_definition'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// 'get_request'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		// 'get_request_require_auth'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		// 'post_request'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		// 'post_request_require_auth'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		// 'put_request'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		// 'put_request_require_auth'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		// 'delete_request'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		// 'delete_request_require_auth'	=> [
		// 	'type'		=> 'i',
		// 	'required'	=> false,
		// 	'size'		=> 11,
		// 	'unsigned'	=> true,
		// 	'date'		=> false,
		// 	'default'	=> 1,
		// ],

		'application'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Application',
				'field'	=> 'id',
			],
		],

		// framework attributes

		'kyte_locked'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,		// 0 - not a kyte critical system...can be edited, 1 - cannot be deleted or edited
			'date'		=> false,
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
];
