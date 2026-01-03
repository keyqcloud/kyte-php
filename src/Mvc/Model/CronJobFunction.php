<?php

$CronJobFunction = [
	'name' => 'CronJobFunction',
	'struct' => [
		'cron_job' => [
			'type'		=> 'i',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'CronJob',
				'field'	=> 'id'
			]
		],

		'name' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 50,
			'date'		=> false,
		],

		'content_hash' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 64,
			'date'		=> false,
		],

		'application' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Application',
				'field'	=> 'id'
			]
		],

		'kyte_account' => [
			'type'		=> 'i',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'KyteAccount',
				'field'	=> 'id'
			]
		],
	],
];
