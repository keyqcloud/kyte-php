<?php

$CronJobFunctionVersion = [
	'name' => 'CronJobFunctionVersion',
	'struct' => [
		'cron_job_function' => [
			'type'		=> 'i',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'CronJobFunction',
				'field'	=> 'id'
			]
		],

		'version_number' => [
			'type'		=> 'i',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'content_hash' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		'is_current' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'change_description' => [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'diff_json' => [
			'type'		=> 'lt',
			'required'	=> false,
			'date'		=> false,
		],
	],
];
