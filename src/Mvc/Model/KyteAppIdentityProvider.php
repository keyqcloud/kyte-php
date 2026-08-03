<?php

/**
 * KyteAppIdentityProvider — per-application OIDC identity-provider config for
 * app-level SSO (KYTE-#560). One row per provider per app. Microsoft/Entra
 * first; the shape is generic OIDC so Google/Okta follow with config only.
 *
 * Kyte is the OIDC relying party: /sso/authorize + /sso/callback use this config
 * to run the auth-code+PKCE flow, validate the id_token, and issue a Kyte JWT
 * session for the app's user_model.
 *
 * Written ONLY via the Shipyard SSO config screen — it carries a client secret,
 * so it is never exposed through MCP. `client_secret` is KMS-encrypted at rest
 * (Aws\Kms) and `protected` so it never rides a GET/list response.
 *
 * Index note: (application, provider) lookup — index in the migration.
 */

$KyteAppIdentityProvider = [
	'name' => 'KyteAppIdentityProvider',
	'struct' => [
		'application'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> ['model' => 'Application', 'field' => 'id'],
		],

		// 'microsoft' (first) | future 'google' | 'okta' | 'oidc'
		'provider'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 32,
			'date'		=> false,
		],

		'enabled'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],

		// OIDC issuer, e.g. https://login.microsoftonline.com/<tenant>/v2.0
		'issuer'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
		],

		// Optional explicit discovery URL; otherwise issuer + /.well-known/openid-configuration.
		'discovery_url'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
		],

		// Azure tenant id — used to build the issuer and to reject id_tokens
		// whose `tid` claim doesn't match (tenant scoping).
		'tenant'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 128,
			'date'		=> false,
		],

		'client_id'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		// KMS-encrypted (base64 of the ciphertext blob). protected + never
		// returned to a client; decrypted only server-side at token exchange.
		'client_secret'	=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
			'protected'	=> true,
		],

		'scopes'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'default'	=> 'openid profile email',
			'date'		=> false,
		],

		// Kyte's own /sso/callback URL (validated / exact-matched).
		'redirect_uri'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 1024,
			'date'		=> false,
		],

		// Which id_token claim maps to the app user's username column.
		'user_email_claim'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 64,
			'default'	=> 'email',
			'date'		=> false,
		],

		// JIT: auto-create the app user on first successful SSO (default on).
		'jit_enabled'	=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 1,
			'date'		=> false,
		],

		// If set, only pre-existing app users may sign in (no JIT create).
		'restrict_to_existing'	=> [
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

		'created_by'	=> ['type' => 'i', 'required' => false, 'date' => false],
		'date_created'	=> ['type' => 'i', 'required' => false, 'date' => true],
		'modified_by'	=> ['type' => 'i', 'required' => false, 'date' => false],
		'date_modified'	=> ['type' => 'i', 'required' => false, 'date' => true],
		'deleted_by'	=> ['type' => 'i', 'required' => false, 'date' => false],
		'date_deleted'	=> ['type' => 'i', 'required' => false, 'date' => true],
		'deleted'		=> ['type' => 'i', 'required' => false, 'size' => 1, 'default' => 0, 'date' => false],
	],
];
