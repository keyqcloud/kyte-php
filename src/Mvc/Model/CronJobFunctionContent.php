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
	],
];
