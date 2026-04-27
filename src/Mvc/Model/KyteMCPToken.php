<?php

/**
 * KyteMCPToken — opaque bearer tokens for MCP (Model Context Protocol) clients.
 *
 * Issued by Shipyard per app, consumed by Claude Code / Claude.ai via the
 * `Authorization: Bearer kmcp_live_...` header. Scope-gated (read/draft/commit),
 * revokable, IP-restrictable, TTL'd.
 *
 * Spec: docs/design/kyte-mcp-and-auth-migration.md section 5.4.
 *
 * Scaffolding note (Phase 2 commit 1, 2026-04-24):
 *   This file exists as dead code — no controller, no strategy, no Shipyard UI
 *   consumes it yet. Phase 2 proper adds McpTokenStrategy (validation path),
 *   the /mcp endpoint (tool surface), and the Shipyard Tokens page (issuance UI).
 *   Creating the model first so the schema is nailed down and reviewable
 *   before the consumers land.
 *
 * Field conventions:
 *   - `kyte_account` follows the standard Kyte FK convention (matches KyteAPIKey,
 *     KyteAccount, etc.) rather than the design doc's `account` shorthand.
 *   - `token_hash` must be indexed + unique. Index creation happens in the
 *     Phase 2 migration, not here — the model framework doesn't declare indexes.
 *   - `secret_key`-style protection isn't applied to `token_hash` because only
 *     the hash is stored (raw token is shown once at creation and never again).
 */

$KyteMCPToken = [
	'name' => 'KyteMCPToken',
	'struct' => [
		// The sha256 of the raw token (hex, 64 chars). Only the hash is stored;
		// the raw token is shown once at creation and never recoverable.
		// `protected` keeps the hash out of list/get responses — knowing the
		// hash doesn't grant access (it's a hash, not a key) but there's no
		// reason to surface it; UI shows the prefix instead.
		'token_hash'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
			'protected'	=> true,
		],

		// First ~12 chars of raw token (e.g. "kmcp_live_abcd"). Displayed in
		// Shipyard UI so users can identify tokens at a glance.
		'token_prefix'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 16,
			'date'		=> false,
		],

		// Human-facing label ("Claude Code - laptop", "CI runner", etc.)
		'name'			=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		// Scoping: which app this token can act on. Nullable for account-wide
		// tokens (not expected in Phase 2, reserved for future use).
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

		// Comma-separated scopes: any combination of "read", "draft", "commit".
		// Default on issuance is "read,draft". "commit" requires explicit opt-in.
		'scopes'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 255,
			'date'		=> false,
		],

		// Expiration (unix epoch). 0 means never — discouraged; UI should default to 30d.
		'expires_at'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// Last-observed use (unix epoch). Updated asynchronously per validated request.
		'last_used_at'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// Last-observed source IP. IPv4 or IPv6 text form.
		'last_used_ip'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 45,
			'date'		=> false,
		],

		// Optional CIDR allowlist (comma-separated). Empty = any source IP.
		'ip_allowlist'		=> [
			'type'		=> 't',
			'required'	=> false,
			'date'		=> false,
		],

		// Revocation timestamp (unix epoch). 0 = active, nonzero = revoked at that time.
		'revoked_at'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// framework attributes

		'kyte_account'		=> [
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

		'deleted'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 1,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
		],
	],
];
