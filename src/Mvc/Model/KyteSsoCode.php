<?php

/**
 * KyteSsoCode — short-lived, single-use hand-off code that delivers a completed
 * SSO login's Kyte session to the app front-end WITHOUT putting tokens in the
 * URL/history (KYTE-#560, "one-time code" pattern). Created at /sso/callback
 * after a successful login; redeemed once at /sso/exchange, which mints and
 * returns the Kyte JWT session for the mapped app user.
 *
 * Stores only identifiers (which app user, which app) — NOT the tokens — so no
 * session material sits at rest. Index: `code_hash` (unique) — in the migration.
 */

$KyteSsoCode = [
	'name' => 'KyteSsoCode',
	'struct' => [
		// sha256 of the raw hand-off code; the raw code is only in the redirect.
		'code_hash'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
			'protected'	=> true,
		],

		'application'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> ['model' => 'Application', 'field' => 'id'],
		],

		// The mapped app user's id within the app's user_model.
		'sso_user_id'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		'expires_at'	=> [
			'type' => 'i', 'required' => true, 'size' => 11, 'unsigned' => true, 'default' => 0, 'date' => true,
		],
		'consumed_at'	=> [
			'type' => 'i', 'required' => false, 'size' => 11, 'unsigned' => true, 'default' => 0, 'date' => true,
		],

		// framework + audit
		'kyte_account'	=> ['type' => 'i', 'required' => false, 'size' => 11, 'unsigned' => true, 'default' => 0, 'date' => false],
		'created_by'	=> ['type' => 'i', 'required' => false, 'date' => false],
		'date_created'	=> ['type' => 'i', 'required' => false, 'date' => true],
		'modified_by'	=> ['type' => 'i', 'required' => false, 'date' => false],
		'date_modified'	=> ['type' => 'i', 'required' => false, 'date' => true],
		'deleted_by'	=> ['type' => 'i', 'required' => false, 'date' => false],
		'date_deleted'	=> ['type' => 'i', 'required' => false, 'date' => true],
		'deleted'		=> ['type' => 'i', 'required' => false, 'size' => 1, 'default' => 0, 'date' => false],
	],
];
