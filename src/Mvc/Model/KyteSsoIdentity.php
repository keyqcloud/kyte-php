<?php

/**
 * KyteSsoIdentity — the federated-identity link binding an external IdP subject
 * to a local app user (KYTE-#560). This is the AUTHORITATIVE join key for SSO
 * logins: the immutable, provider-issued `sub` (+ tenant), NOT the mutable /
 * unverified `email` or `preferred_username` claim.
 *
 * Created on a user's first successful SSO login (linking either a JIT-created
 * app user, or — only when the asserting tenant is authoritative, i.e. a pinned
 * single-tenant config — a pre-existing app user matched by verified email).
 * On every subsequent login the user is resolved by (application, provider,
 * subject), so a token bearing someone else's email can never take over an
 * existing account.
 *
 * Stores only identifiers (which app user, which subject) — no tokens. Unique
 * index: (application, provider, subject) — in the migration.
 */

$KyteSsoIdentity = [
	'name' => 'KyteSsoIdentity',
	'struct' => [
		'application'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> ['model' => 'Application', 'field' => 'id'],
		],

		// 'microsoft' | future 'google' | 'okta' | 'oidc'
		'provider'	=> ['type' => 's', 'required' => true, 'size' => 32, 'date' => false],

		// The immutable OIDC subject (the id_token `sub`, pairwise per client) —
		// the identity key. Unique per (application, provider) via the migration.
		'subject'	=> ['type' => 's', 'required' => true, 'size' => 255, 'date' => false],

		// The IdP tenant (Entra `tid`) the subject belongs to — audit / scoping.
		'tenant_id'	=> ['type' => 's', 'required' => false, 'size' => 128, 'date' => false],

		// The linked app user's id within the app's user_model.
		'sso_user_id'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// Last-seen email for the subject — display / support only, NOT a key.
		'email'		=> ['type' => 's', 'required' => false, 'size' => 320, 'date' => false],

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
