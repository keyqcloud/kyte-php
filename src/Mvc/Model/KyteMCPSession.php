<?php

/**
 * KyteMCPSession — shared storage for MCP (Model Context Protocol) protocol
 * sessions, the JSON-RPC `MCP-Session-Id` state negotiated at `initialize`
 * and correlated on every subsequent request.
 *
 * Why this exists (the multi-instance problem):
 *   The mcp/sdk ships a `FileSessionStore` that writes one file per session
 *   under `sys_get_temp_dir()` — local to a single PHP host. On a deployment
 *   behind a load balancer (e.g. ETOM's 2 instances), `initialize` lands on
 *   instance A and the follow-up request hits instance B, which has no such
 *   file → SDK returns `-32600 "Session not found or has expired"` → the MCP
 *   client falls back to OAuth discovery (which Kyte doesn't serve) and the
 *   connection fails. `Kyte\Mcp\Session\DbSessionStore` persists sessions in
 *   THIS table instead, so any instance sharing the database can resolve any
 *   session. See docs/design/kyte-mcp-and-auth-migration.md section 11 and
 *   Tempo KYTE-183.
 *
 * Distinct from KyteMCPToken: that table is the *auth* credential (already
 * DB-backed, already cross-instance). This table is the short-lived *protocol*
 * session — ephemeral negotiation state (initialized flag, client info /
 * capabilities, protocol version, log level), not a business record. Rows are
 * hard-deleted (purged) on destroy and on TTL-based garbage collection.
 *
 * Field conventions mirror KyteMCPToken:
 *   - `kyte_account` is the standard Kyte tenancy FK convention.
 *   - `session_id` carries the RFC4122 UUID and must be UNIQUE; the index is
 *     created in the Phase-2/4.6.0 migration, not here (the model framework
 *     doesn't declare indexes).
 *   - `payload` is the raw `json_encode` of the SDK session array. It is NOT
 *     small/bounded: the streamable-HTTP SDK persists its outgoing-message
 *     queue (`_mcp.outgoing_queue`) — i.e. full JSON-RPC tool *responses* —
 *     inside the session between request and delivery. A single large read
 *     (e.g. `read_page` on a 300KB+ page) puts a ~800KB response in here. So
 *     `payload` is LONGTEXT, not TEXT: a TEXT column (64KB) silently truncates
 *     the queued response → corrupt JSON → `json_decode` ctrl-char error on the
 *     next read. The FileSessionStore this replaced had no size cap, so
 *     LONGTEXT restores parity. See migrations/4.6.1_mcp_session_payload_longtext.sql.
 */

$KyteMCPSession = [
	'name' => 'KyteMCPSession',
	'struct' => [
		// RFC4122 UUID (e.g. "9f1c...-...") issued by the SDK SessionFactory.
		// UNIQUE — the lookup key for every read/write/destroy. 36 chars covers
		// the canonical hyphenated form.
		'session_id'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 36,
			'date'		=> false,
		],

		// Encoded session state: json_encode of the SDK session data array.
		// Includes the SDK's `_mcp.outgoing_queue` — full JSON-RPC tool
		// responses staged for delivery — so this can run to hundreds of KB
		// for a large read. LONGTEXT ('lt'), NOT TEXT: a 64KB TEXT column
		// truncates large queued responses and corrupts the session. Opaque
		// to Kyte — written and read verbatim by the store.
		'payload'		=> [
			'type'		=> 'lt',
			'required'	=> true,
			'date'		=> false,
		],

		// Unix epoch of the last write. The store's TTL is measured against
		// this (mirrors FileSessionStore's per-write mtime touch): a session
		// stays alive as long as a request touches it within KYTE_MCP_SESSION_TTL.
		// The SDK calls session->save() at the end of every handled request,
		// so this slides on activity and acts as an idle timeout.
		'last_activity'		=> [
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
