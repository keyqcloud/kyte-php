<?php

$KyteCronJobVersion = [
	'name' => 'KyteCronJobVersion',
	'struct' => [
		'cron_job' => [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'CronJob',
				'field'	=> 'id',
			],
		],

		'version_number' => [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'version_type' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 20,
			'date'		=> false,
			'default'	=> 'manual_save',
		],

		'change_summary' => [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'changes_detected' => [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'content_hash' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		// Metadata snapshot
		'name' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'description' => [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'schedule_type' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'date'		=> false,
		],

		'cron_expression' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 100,
			'date'		=> false,
		],

		'interval_seconds' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'timeout_seconds' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'max_retries' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'enabled' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// Version metadata
		'is_current' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'parent_version' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'KyteCronJobVersion',
				'field'	=> 'id',
			],
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
