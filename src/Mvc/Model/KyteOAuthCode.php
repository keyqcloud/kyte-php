<?php

/**
 * KyteOAuthCode — short-lived OAuth 2.1 authorization codes (KYTE-#551,
 * hosted MCP connector).
 *
 * Issued at /oauth/authorize once a Shipyard-authenticated user approves the
 * consent screen; redeemed once at /oauth/token (PKCE verifier check) for a
 * freshly-minted scoped KyteMCPToken (the OAuth access token). Single-use and
 * short-TTL.
 *
 * Only the sha256 of the raw code is stored (`code_hash`); the raw code lives
 * only in the redirect back to the client. The row binds the code to the
 * consenting account/app, the PKCE challenge, the exact redirect_uri, and the
 * granted scopes — all re-verified at redemption.
 *
 * Design: docs/design/hosted-mcp-oauth.md §5-7. Blueprint: keyq-slipstream
 * oauth_codes (api/db/migrations/0021_oauth_as.sql).
 *
 * Index note: `code_hash` must be indexed (unique). Index creation happens in
 * migrations/4.17.0_oauth_as.sql, not here.
 */

$KyteOAuthCode = [
	'name' => 'KyteOAuthCode',
	'struct' => [
		// sha256 (hex, 64) of the raw authorization code. Only the hash is
		// stored; the raw code is returned once in the authorize redirect and
		// never recoverable. `protected` keeps it out of list/get responses.
		'code_hash'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
			'protected'	=> true,
		],

		// The client (KyteOAuthClient.client_id) this code was issued to.
		// Must match the client presenting it at /oauth/token.
		'client_id'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		// The redirect_uri used at /oauth/authorize. Must match exactly at
		// /oauth/token (OAuth 2.1 code-injection defense).
		'redirect_uri'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 1024,
			'date'		=> false,
		],

		// PKCE code_challenge (base64url of sha256(verifier)). Verified against
		// the client's code_verifier at redemption. `protected`.
		'code_challenge'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
			'protected'	=> true,
		],

		// PKCE method. Only "S256" is accepted (plain is rejected).
		'code_challenge_method'	=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 16,
			'date'		=> false,
		],

		// Space-separated OAuth scopes granted at consent.
		'scope'			=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
		],

		// CSV of the kmcp scopes (read/draft/commit/provision/schema) the minted
		// KyteMCPToken will carry — the OAuth→kmcp scope map resolved at consent.
		'kyte_scopes'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		// Optional app scope for the minted token (mirrors KyteMCPToken.application).
		'application'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
			'fk'		=> [
				'model'	=> 'Application',
				'field'	=> 'id',
			],
		],

		// Expiry (unix epoch). Short — codes live ~seconds/minutes.
		'expires_at'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// Single-use marker (unix epoch of redemption). 0 = unused; nonzero =
		// already redeemed → any further presentation is rejected.
		'consumed_at'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// framework attributes

		// The account that consented (bound at /oauth/authorize). REQUIRED —
		// the minted token acts on this tenant.
		'kyte_account'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// audit attributes — created_by = the KyteUser who approved consent.

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

		'deleted'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'default'	=> 0,
			'date'		=> false,
		],
	],
];
