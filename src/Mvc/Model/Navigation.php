<?php

$Navigation = [
	'name' => 'Navigation',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'logo'		=> [
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

		'page'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			// 'fk'		=> [
			// 	'model'	=> 'KytePage',
			// 	'field'	=> 'id',
			// ],
		],

		'description'		=> [
			'type'		=> 't',
			'required'	=> false,
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

		// custom styles
		'bgColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#212529',
			'date'		=> false,
		],
		'fgColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#f8f9fa',
			'date'		=> false,
		],
		'bgDropdownColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#ffffff',
			'date'		=> false,
		],
		'fgDropdownColor'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 7,
			'default'	=> '#212529',
			'date'		=> false,
		],
		'isStickyTop'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
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
