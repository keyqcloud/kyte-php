<?php

$CronJobFunctionContent = [
	'name' => 'CronJobFunctionContent',
	'struct' => [
		'content_hash' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		'content' => [
			'type'		=> 'lb',
			'required'	=> true,
			'date'		=> false,
			'protected'	=> true,
		],

		'reference_count' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
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
