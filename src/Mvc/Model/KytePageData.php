<?php

$KytePageData = [
	'name' => 'KytePageData',
	'struct' => [
		// json definition of page layout defined by wizard
		// 'layout'	=> [
		// 	'type'		=> 't',
		// 	'required'	=> false,
		// 	'date'		=> false,
		// ],

		// json definition of page layout defined by block editor
		'block_layout'	=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'html'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'javascript'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'javascript_obfuscated'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'stylesheet'	=> [
			'type'		=> 't',
			'required'	=> false,
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
