<?php

$AIErrorDeduplication = [
	'name' => 'AIErrorDeduplication',
	'struct' => [
		'error_signature'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		'controller_name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'function_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'error_message'		=> [
			'type'		=> 't',
			'required'	=> true,
			'date'		=> false,
		],

		'error_file'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'error_line'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// Tracking
		'first_seen'		=> [
			'type'		=> 'bi',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'last_seen'		=> [
			'type'		=> 'bi',
			'required'	=> true,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'last_analyzed'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'occurrence_count'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		'analysis_count'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		// Status
		'is_resolved'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		'resolved_at'		=> [
			'type'		=> 'bi',
			'required'	=> false,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'resolved_by'		=> [
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
