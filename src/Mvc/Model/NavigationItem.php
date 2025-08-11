<?php

$NavigationItem = [
	'name' => 'NavigationItem',
	'struct' => [
		'title'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'element_id'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'element_class'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'isLogout' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned' 	=> true,
			'date'		=> false,
			'default'	=> 0,
		],

		'center' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned' 	=> true,
			'date'		=> false,
			'default'	=> 1,
		],

		'faicon'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'link'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'page'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'KytePage',
				'field'	=> 'id',
			],
		],

		'parentItem'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'NavigationItem',
				'field'	=> 'id',
			],
		],

		'navigation'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Navigation',
				'field'	=> 'id',
			],
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

		'itemOrder' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned' 	=> true,
			'date'		=> false,
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
