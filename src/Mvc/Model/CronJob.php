<?php

$CronJob = [
	'name' => 'CronJob',
	'struct' => [
		'name' => [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
		],

		'description' => [
			'type'		=> 't',
			'required'	=> false,
		],

		'code' => [
			'type'		=> 'lb',
			'required'	=> false,
		],

		// Schedule configuration
		'schedule_type' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'cron',
		],

		'cron_expression' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 100,
		],

		'interval_seconds' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
		],

		'time_of_day' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
		],

		'day_of_week' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
		],

		'day_of_month' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 2,
			'unsigned'	=> true,
		],

		'timezone' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 50,
			'default'	=> 'UTC',
		],

		// Execution settings
		'enabled' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
		],

		'timeout_seconds' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 300,
		],

		'max_retries' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 3,
		],

		'retry_strategy' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'exponential',
		],

		'retry_delay_seconds' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 60,
		],

		'allow_concurrent' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
		],

		// Dependencies
		'depends_on_job' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
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
		],

		'notify_after_failures' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 3,
		],

		'notify_on_dead_letter' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
		],

		'slack_webhook' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
		],

		'notification_email' => [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
		],

		// Dead Letter Queue
		'in_dead_letter_queue' => [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
		],

		'dead_letter_reason' => [
			'type'		=> 't',
			'required'	=> false,
		],

		'dead_letter_since' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
		],

		'consecutive_failures' => [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
		],

		// Context
		'application' => [
			'type'		=> 'i',
			'required'	=> false,
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
		],

		'kyte_account' => [
			'type'		=> 'i',
			'required'	=> true,
			'unsigned'	=> true,
			'fk'		=> [
				'model'	=> 'KyteAccount',
				'field'	=> 'id',
			],
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
