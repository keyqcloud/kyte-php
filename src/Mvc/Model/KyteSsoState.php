<?php

/**
 * KyteSsoState — short-lived, single-use in-flight state for an app-SSO login
 * (KYTE-#560). Created at /sso/authorize, consumed at /sso/callback. Binds the
 * OAuth `state` (CSRF) to the `nonce` (id_token replay defense) and the PKCE
 * `code_verifier`, plus the app + where to send the user afterward.
 *
 * Index: `state` (unique) — in the migration.
 */

$KyteSsoState = [
	'name' => 'KyteSsoState',
	'struct' => [
		// Opaque CSRF state echoed by the provider; unique + looked up at callback.
		'state'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		// id_token nonce (verified against the returned id_token). protected.
		'nonce'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
			'protected'	=> true,
		],

		// PKCE code_verifier presented at the token endpoint. protected.
		'code_verifier'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 128,
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

		'provider'	=> ['type' => 's', 'required' => false, 'size' => 32, 'date' => false],

		// The app URL to return the user to after login completes.
		'return_url'	=> ['type' => 's', 'required' => false, 'size' => 1024, 'date' => false],

		// The redirect_uri sent to the provider (must match at token exchange).
		'redirect_uri'	=> ['type' => 's', 'required' => false, 'size' => 1024, 'date' => false],

		// sha256 of a browser-bound correlator set as an HttpOnly cookie at
		// /authorize and required to match at /callback — ties the callback to
		// the user agent that started the flow (login-CSRF / session-fixation
		// defense; the OAuth `state` alone does not bind the browser).
		'browser_hash'	=> ['type' => 's', 'required' => false, 'size' => 64, 'date' => false],

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
