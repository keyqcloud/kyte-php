<?php

$KytePageVersionContent = [
    'name' => 'KytePageVersionContent',
    'struct' => [
        'content_hash' => [
            'type' => 's',
            'required' => true,
            'size' => 64,
            'date' => false,
        ],

        'html' => [
            'type' => 'lb',
            'required' => false,
            'date' => false,
        ],

        'stylesheet' => [
            'type' => 'lb',
            'required' => false,
            'date' => false,
        ],

        'javascript' => [
            'type' => 'lb',
            'required' => false,
            'date' => false,
        ],

        'javascript_obfuscated' => [
            'type' => 'lb',
            'required' => false,
            'date' => false,
        ],

        'block_layout' => [
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
    ],
];