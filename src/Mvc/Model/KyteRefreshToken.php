<?php

/**
 * KyteRefreshToken — opaque refresh-token storage for the JWT auth strategy.
 *
 * Refresh tokens are NOT JWTs. They're long-lived random opaque bearers
 * (prefix `kref_v1_...`) exchanged at /jwt/refresh for a freshly minted
 * (access_jwt, new_refresh_token) pair. The presented refresh token is
 * always revoked and replaced on every successful rotation (RFC 6819
 * single-use refresh tokens with reuse detection).
 *
 * Reuse detection via `token_family`:
 *   - Login issues refresh token A with a freshly generated family uuid.
 *   - /jwt/refresh presented A → revoke A, issue B with the SAME family,
 *     set A.rotated_to = B.id.
 *   - Presented B → revoke B, issue C, rotated_to = C.id, same family.
 *   - If a revoked token is presented (presented A after it was already
 *     rotated to B) → that's a leak. Revoke EVERY token in the family,
 *     forcing the legitimate client to re-login. revoked_reason logs the
 *     reuse event.
 *
 * Field conventions:
 *   - `token_hash` is sha256 of the raw token. Only the hash is stored.
 *   - `token_prefix` shows the first ~16 chars for UI / audit-log identification.
 *   - `token_family` is a 64-char random hex string (uuid-equivalent), shared
 *     by every token in the rotation chain.
 *   - `rotated_to` points to the successor token id once this token is rotated
 *     out. 0 while still active.
 *   - `revoked_at` / `revoked_reason` mark explicit revocation. Reuse-detected
 *     revocation sets `revoked_reason` to 'reuse_detected'.
 */

$KyteRefreshToken = [
	'name' => 'KyteRefreshToken',
	'struct' => [
		// sha256 of the raw refresh token. Only the hash is stored; the
		// raw token is returned once at issuance and never recoverable.
		'token_hash'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
			'protected'	=> true,
		],

		// First ~16 chars of the raw token (e.g. "kref_v1_abcdef"). Surfaced
		// in admin tooling and audit logs so a row is identifiable without
		// possession of the raw token.
		'token_prefix'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 32,
			'date'		=> false,
		],

		// 64-char hex identifying the rotation family. All tokens descended
		// from a single login share one family — reuse detection revokes the
		// entire family when a revoked token is presented again.
		'token_family'		=> [
			'type'		=> 's',
			'required'	=> true,
			'size'		=> 64,
			'date'		=> false,
		],

		// Owning user. FK to whichever user model the app is configured with;
		// not declared as a strict FK here because it can target KyteUser or
		// an app-specific user table. user_model on the application row
		// determines which.
		'user'			=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'date'		=> false,
		],

		// Optional application scope. Nullable for account-wide refresh tokens
		// (Shipyard-style admin sessions that aren't bound to a single app).
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

		// Expiration (unix epoch). 0 means never — strongly discouraged; UI
		// defaults to KYTE_JWT_REFRESH_TTL (4 hours).
		'expires_at'		=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// Family-wide absolute lifetime anchor (unix epoch). Set once when
		// the family is born at /jwt/login and copied forward unchanged on
		// every rotation in `RefreshTokenStore::issueInFamily()`. Used to
		// enforce KYTE_JWT_FAMILY_MAX_LIFETIME — the absolute cap on how
		// long a single login session can survive before forced re-auth,
		// independent of how active the user is. Without this, sliding
		// `expires_at` rotation allows indefinite sessions.
		'family_started_at'	=> [
			'type'		=> 'i',
			'required'	=> true,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// Last-observed use (unix epoch). Updated on every rotation attempt.
		'last_used_at'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// Last-observed source IP (IPv4 or IPv6 text). Captured at rotation
		// for forensic correlation.
		'last_used_ip'		=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 45,
			'date'		=> false,
		],

		// Revocation timestamp (unix epoch). 0 = active, nonzero = revoked.
		'revoked_at'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> true,
		],

		// Why this token was revoked. Common values: 'rotated' (normal
		// rotation), 'reuse_detected' (presented after revocation — leak
		// signal), 'logout', 'admin_revoke', 'expired'.
		'revoked_reason'	=> [
			'type'		=> 's',
			'required'	=> false,
			'size'		=> 64,
			'date'		=> false,
		],

		// Successor token id when this token has been rotated. 0 while
		// active. Provides an audit trail of the rotation chain.
		'rotated_to'		=> [
			'type'		=> 'i',
			'required'	=> false,
			'size'		=> 11,
			'unsigned'	=> true,
			'default'	=> 0,
			'date'		=> false,
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
