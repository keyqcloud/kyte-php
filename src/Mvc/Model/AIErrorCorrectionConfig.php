<?php

$AIErrorCorrectionConfig = [
	'name' => 'AIErrorCorrectionConfig',
	'struct' => [
		// Application linkage
		'application'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Application',
				'field'	=> 'id',
			],
		],

		// Feature flags
		'enabled'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'auto_fix_enabled'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'auto_fix_min_confidence'		=> [
			'type'		=> 'd',
			'required'	=> false,
			'precision'	=> 5,
			'scale'		=> 2,
			'default'	=> '90.00',
			'date'		=> false,
		],

		// Rate limiting & cost control
		'max_analyses_per_hour'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 10,
			'date'		=> false,
		],

		'max_analyses_per_day'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 50,
			'date'		=> false,
		],

		'max_monthly_cost_usd'		=> [
			'type'		=> 'd',
			'required'	=> false,
			'precision'	=> 10,
			'scale'		=> 2,
			'default'	=> '100.00',
			'date'		=> false,
		],

		'cooldown_minutes'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 30,
			'date'		=> false,
		],

		// Loop detection thresholds
		'max_fix_attempts'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 5,
			'date'		=> false,
		],

		'loop_detection_window_minutes'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 60,
			'date'		=> false,
		],

		'auto_disable_on_loop'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		// Cron scheduling preferences
		'analysis_frequency_minutes'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 5,
			'date'		=> false,
		],

		'batch_size'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 10,
			'date'		=> false,
		],

		'max_concurrent_bedrock_calls'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 3,
			'date'		=> false,
		],

		// Analysis preferences
		'include_warnings'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'include_model_definitions'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'include_request_data'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'include_framework_docs'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		// Notification preferences (PLACEHOLDER for future)
		'notify_on_suggestion'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'notify_on_auto_fix'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'notify_on_loop_detection'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'notification_email'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'notification_slack_webhook'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
		],

		// Statistics
		'total_analyses'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'total_fixes_applied'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'total_successful_fixes'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'total_failed_fixes'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'total_cost_usd'		=> [
			'type'		=> 'd',
			'required'	=> false,
			'precision'	=> 10,
			'scale'		=> 2,
			'default'	=> '0.00',
			'date'		=> false,
		],

		'last_analysis_date'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// Framework attributes
		'kyte_account'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'date'		=> false,
		],

		// Audit attributes
		'created_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_created'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> true,
		],

		'modified_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_modified'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> true,
		],

		'deleted_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
		],

		'date_deleted'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> true,
		],

		'deleted'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],
	],
];
