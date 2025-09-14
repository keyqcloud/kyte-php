<?php

$KytePageVersion = [
    'name' => 'KytePageVersion',
    'struct' => [
        'page' => [
            'type' => 'i',
            'required' => true,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'KytePage',
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
        'title' => [
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

        'lang' => [
            'type' => 's',
            'required' => false,
            'size' => 255,
            'date' => false,
        ],

        'page_type' => [
            'type' => 's',
            'required' => false,
            'size' => 255,
            'date' => false,
        ],

        'state' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        'sitemap_include' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
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

        'use_container' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        'protected' => [
            'type' => 'i',
            'required' => false,
            'size' => 1,
            'unsigned' => true,
            'date' => false,
        ],

        'webcomponent_obj_name' => [
            'type' => 's',
            'required' => false,
            'size' => 255,
            'date' => false,
        ],

        // Content is stored in KytePageVersionContent table via content_hash
        // No content fields here to avoid duplication

        // Relationship fields
        'header' => [
            'type' => 'i',
            'required' => false,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'KyteSectionTemplate',
                'field' => 'id',
            ],
        ],

        'footer' => [
            'type' => 'i',
            'required' => false,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'KyteSectionTemplate',
                'field' => 'id',
            ],
        ],

        'main_navigation' => [
            'type' => 'i',
            'required' => false,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'Navigation',
                'field' => 'id',
            ],
        ],

        'side_navigation' => [
            'type' => 'i',
            'required' => false,
            'size' => 11,
            'unsigned' => true,
            'date' => false,
            'fk' => [
                'model' => 'SideNav',
                'field' => 'id',
            ],
        ],

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
                'model' => 'KytePageVersion',
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
