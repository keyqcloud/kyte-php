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
