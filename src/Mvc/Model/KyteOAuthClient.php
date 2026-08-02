<?php

/**
 * KyteOAuthClient — OAuth 2.1 clients registered against this install's
 * authorization server (KYTE-#551, hosted MCP connector).
 *
 * Populated by RFC 7591 dynamic client registration (POST /oauth/register):
 * Claude.ai / ChatGPT register themselves as PUBLIC clients (PKCE, no secret)
 * before running the authorization-code flow to connect to /mcp.
 *
 * NOT account-scoped: a client is the connecting app (Claude/ChatGPT), global
 * to the install. The account/tenant binding happens later, at consent time,
 * on the KyteOAuthCode (and ultimately the minted KyteMCPToken). `kyte_account`
 * is therefore nullable here (0 = unscoped), unlike KyteMCPToken.
 *
 * Design: docs/design/hosted-mcp-oauth.md §5-6. Blueprint: keyq-slipstream
 * oauth_clients (api/db/migrations/0021_oauth_as.sql).
 *
 * Index note: `client_id` must be UNIQUE + indexed. Index creation happens in
 * migrations/4.17.0_oauth_as.sql, not here — the model framework doesn't
 * declare indexes.
 */

$KyteOAuthClient = [
	'name' => 'KyteOAuthClient',
	'struct' => [
		// Public client identifier issued at registration (opaque random).
		// UNIQUE + indexed (see migration). Looked up at /oauth/authorize and
		// /oauth/token.
		'client_id'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		// Confidential-client secret (sha256 at rest). MCP connectors are
		// PUBLIC clients (token_endpoint_auth_method = "none") and have NO
		// secret; reserved for a future confidential-client path. `protected`
		// keeps it out of list/get responses.
		'client_secret'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 64,
			'date'		=> false,
			'protected'	=> true,
		],

		// Human-facing client name from the registration request
		// (e.g. "Claude", "ChatGPT").
		'client_name'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		// JSON array of allowed redirect URIs. Enforced (exact match) at
		// /oauth/authorize and /oauth/token. https-only (except http://localhost
		// / 127.0.0.1 for native/desktop loopback per the OAuth native-app BCP).
		'redirect_uris'		=> [
			'type'		=> 't',
			'required'	=> true,
			'date'		=> false,
		],

		// CSV of registered grant types. Default "authorization_code".
		'grant_types'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		// CSV of registered response types. Default "code".
		'response_types'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 255,
			'date'		=> false,
		],

		// Client authentication method at the token endpoint. "none" for the
		// public PKCE clients Claude/ChatGPT use.
		'token_endpoint_auth_method'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 64,
			'date'		=> false,
		],

		// Space-separated OAuth scopes the client requested at registration.
		'scope'			=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 512,
			'date'		=> false,
		],

		// framework attributes

		// Nullable (0) — clients register before any user authenticates, so a
		// client is not bound to one account.
		'kyte_account'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
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

		'deleted'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'default'	=> 0,
			'date'		=> false,
		],
	],
];
