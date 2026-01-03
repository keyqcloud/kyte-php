<?php

$CronJobExecution = [
	'name' => 'CronJobExecution',
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

		'scheduled_time' => [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> true,
		],

		'next_run_time' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'status' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'pending',
			'date'		=> false,
		],

		'locked_by' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'locked_at' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'locked_until' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'started_at' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'completed_at' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'duration_ms' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'exit_code' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'date'		=> false,
		],

		'output' => [
			'type'		=> 'mt',
			'required'	=> false,
			'date'		=> false,
		],

		'error' => [
			'type'		=> 'mt',
			'required'	=> false,
			'date'		=> false,
		],

		'stack_trace' => [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'memory_peak_mb' => [
			'type'		=> 'd',
			'required'	=> false,
			'precision'	=> 10,
			'scale'		=> 2,
			'date'		=> false,
		],

		'retry_count' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'is_retry' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'parent_execution' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'CronJobExecution',
				'field'	=> 'id',
			],
		],

		'retry_scheduled_time' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'skipped_reason' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'dependency_execution' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'CronJobExecution',
				'field'	=> 'id',
			],
		],

		'application' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Application',
				'field'	=> 'id',
			],
		],

		'kyte_account' => [
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
