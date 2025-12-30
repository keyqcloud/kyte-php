<?php

$KyteCronJobVersionContent = [
	'name' => 'KyteCronJobVersionContent',
	'struct' => [
		'content_hash' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		'code' => [
			'type'		=> 'lb',
			'required'	=> false,
			'date'		=> false,
		],

		'reference_count' => [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'last_referenced' => [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> true,
		],

		// Framework attributes
		'kyte_account' => [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// Audit attributes
		'created_by' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_created' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'modified_by' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_modified' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'deleted' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],
	],
];
