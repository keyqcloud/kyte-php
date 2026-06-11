<?php

$Application = [
	'name' => 'Application',
	'struct' => [
		'name'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		'identifier'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'language'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 5,
			'date'		=> false,
			'default'	=> null,
		],

		// slack notifications
		'slack_error_webhook'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
			'protected'	=> true,
		],

		// log bucket name
		's3LogBucketName'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		's3LogBucketRegion'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		// database field

		'db_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		'db_host'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		'db_username'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		'db_password'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		// options for custom user table

		'user_model'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'username_colname'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'password_colname'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		// organization based user scoping

		'org_model'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'userorg_colname'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> false,
		],

		'kyte_connect'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// optional customer AWS credentials

		'aws_key'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'date'		=> false,
			'unsigned'	=> true,
			'fk' => [
				'model'	=> 'KyteAWSKey',
				'field'	=> 'id',
			]
		],

		'aws_public_key'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		'aws_private_key'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		// Auth mode for code that Shipyard generates for this app.
		// 'hmac' (default) → generated pages use the v1.x HMAC sign/rotate
		//                    flow; new Kyte(url, key, iden, num, app).
		// 'jwt'            → generated pages use the v2 JWT flow;
		//                    new Kyte(url, null, null, null, app,
		//                             { authMode: 'jwt' }).
		// Switching mid-flight is a deliberate migration step — both
		// strategies coexist on the server, but each generated page is
		// pinned to whichever mode was active at the time of build.
		'auth_mode'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 16,
			'date'		=> false,
			'default'	=> 'hmac',
		],

		// Per-app opt-in for anonymous/public API access (AppContextStrategy,
		// JWT-mode public access). Tri-state:
		//   0 = anonymous appid-only requests rejected (default);
		//   1 = requireAuth=false controllers reachable anonymously, READ-ONLY
		//       (GET only — public catalog/storefront browsing);
		//   2 = controller-governed — the controller's requireAuth=false +
		//       allowableActions declaration applies, including writes
		//       (password reset / signup-style public flows).
		// See src/Core/Auth/AppContextStrategy.php.
		'allow_public'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
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
