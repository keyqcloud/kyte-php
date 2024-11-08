<?php

$Permission = [
    'name' => 'Permission',
    'struct' => [
        'role' => [
            'type'      => 'i',      // integer type
            'required'  => true,
            'size'      => 11,
            'unsigned'  => true,
            'date'      => false,
        ],

        'model' => [
            'type'      => 's',      // string type (for the model name)
            'required'  => true,
            'size'      => 255,
            'date'      => false,
        ],

        'action' => [
            'type'      => 's',      // string type (for action like 'new', 'update', etc.)
            'required'  => true,
            'size'      => 255,
            'date'      => false,
        ],

        'kyte_account' => [
            'type'      => 'i',      // integer type (to link the permission to a kyte account)
            'required'  => true,
            'size'      => 11,
            'unsigned'  => true,
            'date'      => false,
        ],

        // Audit fields (standard for most tables)
        'created_by' => [
            'type'      => 'i',      // integer type
            'required'  => false,
            'date'      => false,
        ],

        'date_created' => [
            'type'      => 'i',      // integer type (for timestamp)
            'required'  => false,
            'date'      => true,     // date field
        ],

        'modified_by' => [
            'type'      => 'i',      // integer type
            'required'  => false,
            'date'      => false,
        ],

        'date_modified' => [
            'type'      => 'i',      // integer type (for timestamp)
            'required'  => false,
            'date'      => true,     // date field
        ],

        'deleted_by' => [
            'type'      => 'i',      // integer type
            'required'  => false,
            'date'      => false,
        ],

        'date_deleted' => [
            'type'      => 'i',      // integer type (for timestamp)
            'required'  => false,
            'date'      => true,     // date field
        ],

        'deleted' => [
            'type'      => 'i',      // integer type (0 or 1 for soft delete)
            'required'  => false,
            'size'      => 1,
            'unsigned'  => true,
            'default'   => 0,
            'date'      => false,
        ],
    ],
];
