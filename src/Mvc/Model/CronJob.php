<?php

$CronJob = [
	'name' => 'CronJob',
	'struct' => [
		'name' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'description' => [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'code' => [
			'type'		=> 'lb',
			'required'	=> false,
			'date'		=> false,
			'protected'	=> true,
		],

		// Schedule configuration
		'schedule_type' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 20,
			'default'	=> 'cron',
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

		'time_of_day' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 8,
			'date'		=> false,
		],

		'day_of_week' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'day_of_month' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 2,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'timezone' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 50,
			'default'	=> 'UTC',
			'date'		=> false,
		],

		// Execution settings
		'enabled' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'timeout_seconds' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 300,
			'date'		=> false,
		],

		'max_retries' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 3,
			'date'		=> false,
		],

		'retry_strategy' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'exponential',
			'date'		=> false,
		],

		'retry_delay_seconds' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 60,
			'date'		=> false,
		],

		'allow_concurrent' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		// Dependencies
		'depends_on_job' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'CronJob',
				'field'	=> 'id',
			],
		],

		// Notifications
		'notify_on_failure' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'notify_after_failures' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 3,
			'date'		=> false,
		],

		'notify_on_dead_letter' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'slack_webhook' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
			'protected'	=> true,
		],

		'notification_email' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		// Dead Letter Queue
		'in_dead_letter_queue' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'dead_letter_reason' => [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'dead_letter_since' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> true,
		],

		'consecutive_failures' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		// Context
		'application' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Application',
				'field'	=> 'id',
			],
		],

		// Framework attributes
		'kyte_locked' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

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

		'deleted_by' => [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_deleted' => [
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
