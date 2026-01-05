<?php

$AIErrorAnalysis = [
	'name' => 'AIErrorAnalysis',
	'struct' => [
		// Error linkage
		'error_id'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'KyteError',
				'field'	=> 'id',
			],
		],

		'error_signature'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		// Classification
		'is_fixable'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'fixable_confidence'		=> [
			'type'		=> 'd',
			'required'	=> false,
			'precision'	=> 5,
			'scale'		=> 2,
			'date'		=> false,
		],

		// Affected code
		'controller_id'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Controller',
				'field'	=> 'id',
			],
		],

		'controller_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'function_id'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Function',
				'field'	=> 'id',
			],
		],

		'function_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'function_type'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 50,
			'date'		=> false,
		],

		// AI analysis results
		'analysis_stage'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'pending',
			'date'		=> false,
		],

		'ai_diagnosis'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		'ai_suggested_fix'		=> [
			'type'		=> 'lt',
			'required'	=> false,
			'date'		=> false,
		],

		'fix_confidence'		=> [
			'type'		=> 'd',
			'required'	=> false,
			'precision'	=> 5,
			'scale'		=> 2,
			'date'		=> false,
		],

		'fix_rationale'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// Context captured
		'context_snapshot'		=> [
			'type'		=> 'lt',
			'required'	=> false,
			'date'		=> false,
		],

		// Queue status tracking
		'analysis_status'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'queued',
			'date'		=> false,
		],

		'queued_at'		=> [
			'type'		=> 'bi',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'processing_started_at'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'processing_completed_at'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'retry_count'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'last_error'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// Fix application tracking
		'fix_status'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 20,
			'default'	=> 'suggested',
			'date'		=> false,
		],

		'applied_at'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'applied_by'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'applied_function_version'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'KyteFunctionVersion',
				'field'	=> 'id',
			],
		],

		// Validation results
		'syntax_valid'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'syntax_error'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// Loop detection
		'attempt_number'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'previous_analysis_id'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'AIErrorAnalysis',
				'field'	=> 'id',
			],
		],

		'caused_new_error'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'new_error_id'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'KyteError',
				'field'	=> 'id',
			],
		],

		// Cost tracking
		'bedrock_request_id'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'bedrock_input_tokens'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'bedrock_output_tokens'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'estimated_cost_usd'		=> [
			'type'		=> 'd',
			'required'	=> false,
			'precision'	=> 10,
			'scale'		=> 4,
			'date'		=> false,
		],

		'processing_time_ms'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// Application context
		'application'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Application',
				'field'	=> 'id',
			],
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
