<?php

$KyteScriptVersion = [
    'name' => 'KyteScriptVersion',
    'struct' => [
        'script' => [
            'type' => 'i',
            'required' => true,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'KyteScript',
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

        's3key' => [
            'type' => 's',
            'required' => false,
            'size' => 255,
            'date' => false,
        ],

        'script_type' => [
            'type' => 's',
            'required' => false,
            'size' => 255,
            'date' => false,
        ],

        'obfuscate_js' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        'is_js_module' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        'include_all' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        'state' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        // Content is stored in KytePageVersionContent table via content_hash
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
                'model' => 'KyteScriptVersion',
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
