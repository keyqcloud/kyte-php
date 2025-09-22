<?php

$KyteScriptVersionContent = [
    'name' => 'KyteScriptVersionContent',
    'struct' => [
        'content_hash' => [
            'type' => 's',
            'required' => true,
            'size' => 64,
            'date' => false,
        ],

        'content' => [
            'type' => 'lb',
            'required' => false,
            'date' => false,
        ],

        'content_js_obfuscated' => [
            'type' => 'lb',
            'required' => false,
            'date' => false,
        ],

        'reference_count' => [
            'type' => 'i',
            'required' => true,
            'size' => 11,
            'unsigned' => true,
            'default' => 1,
            'date' => false,
        ],

        'last_referenced' => [
            'type' => 'i',
            'required' => true,
            'date' => true,
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