<?php

$KyteFunctionVersion = [
    'name' => 'KyteFunctionVersion',
    'struct' => [
        'function' => [
            'type' => 'i',
            'required' => true,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'Function',
                'field' => 'id',
            ],
        ],

        'version_number' => [
            'type' => 'i',
            'required' => true,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
        ],

        'version_type' => [
            'type' => 's',
            'required' => true,
            'size' => 20,
            'date' => false,
            'default' => 'manual_save',
        ],

        'change_summary' => [
            'type' => 't',
            'required' => false,
            'date' => false,
        ],

        'changes_detected' => [
            'type' => 't',
            'required' => false,
            'date' => false,
        ],

        'content_hash' => [
            'type' => 's',
            'required' => true,
            'size' => 64,
            'date' => false,
        ],

        // Page metadata fields (nullable - only stored if changed)
        'name' => [
            'type' => 's',
            'required' => false,
            'size' => 255,
            'date' => false,
        ],

        'description' => [
            'type' => 't',
            'required' => false,
            'date' => false,
        ],

        'function_type' => [
            'type' => 's',
            'required' => false,
            'size' => 255,
            'date' => false,
        ],

        'kyte_locked' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        // Code is stored in KyteFunctionVersionContent table via content_hash
        // No content fields here to avoid duplication

        // Version metadata
        'is_current' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'default' => 0,
            'date' => false,
        ],

        'parent_version' => [
            'type' => 'i',
            'required' => false,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'KyteFunctionVersion',
                'field' => 'id',
            ],
        ],

        // framework attributes

		'kyte_account'	=> [
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
