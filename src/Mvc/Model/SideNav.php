<?php

$SideNav = [
	'name' => 'SideNav',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'description'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// custom styles
		'bgColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#124ef5',
			'date'		=> false,
		],
		'fgColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#dce4fd',
			'date'		=> false,
		],
		'bgActiveColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#2764f5',
			'date'		=> false,
		],
		'fgActiveColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#ffffff',
			'date'		=> false,
		],
		'columnStyle'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 2,
			'default'	=> 0,
			'date'		=> false,
		],
		'labelCenterBlock'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'default'	=> 0,
			'date'		=> false,
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

		// 0 - public, 1 - private
		'protected'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
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
