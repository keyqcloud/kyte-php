-- =========================================================================
-- Kyte v4.17.0 - OAuth 2.1 Authorization Server for the hosted MCP connector
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- KYTE-#551 (hosted MCP: remote OAuth connector for Claude.ai / ChatGPT).
-- Creates the two tables backing this install's OAuth authorization server:
--
--   KyteOAuthClient  - RFC 7591 dynamically-registered OAuth clients
--                      (Claude / ChatGPT register as public PKCE clients
--                      before the authorization-code flow).
--   KyteOAuthCode    - short-lived, single-use authorization codes issued at
--                      /oauth/authorize and redeemed once at /oauth/token for
--                      a freshly-minted scoped KyteMCPToken (the access token).
--
-- The OAuth layer is a front door that mints existing `kmcp_live_` tokens;
-- all downstream MCP enforcement (McpTokenStrategy, scopes, sessions, audit)
-- is unchanged. See docs/design/hosted-mcp-oauth.md and the model specs in
-- src/Mvc/Model/KyteOAuthClient.php + KyteOAuthCode.php.
--
-- Idempotent (CREATE TABLE IF NOT EXISTS) — safe to re-run; existing installs
-- that already created equivalent tables are unaffected. Conventions mirror
-- migrations/4.6.0_mcp_session_store.sql (BIGINT UNSIGNED ids, InnoDB,
-- utf8mb4). Portable to MySQL 8.x + MariaDB 10.5+.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `KyteOAuthClient` (
    `id`                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    `client_id`                   VARCHAR(64)  NOT NULL COMMENT 'Public client identifier (opaque random) issued at registration',
    `client_secret`               VARCHAR(64)  DEFAULT NULL COMMENT 'sha256 of confidential-client secret; NULL for public/PKCE clients',
    `client_name`                 VARCHAR(255) DEFAULT NULL,
    `redirect_uris`               TEXT         NOT NULL COMMENT 'JSON array of allowed redirect URIs (exact-matched at authorize/token)',
    `grant_types`                 VARCHAR(255) DEFAULT NULL COMMENT 'CSV, default authorization_code',
    `response_types`              VARCHAR(255) DEFAULT NULL COMMENT 'CSV, default code',
    `token_endpoint_auth_method`  VARCHAR(64)  DEFAULT NULL COMMENT 'none for public PKCE clients',
    `scope`                       VARCHAR(512) DEFAULT NULL COMMENT 'Space-separated OAuth scopes requested at registration',

    `kyte_account`                BIGINT UNSIGNED DEFAULT 0 COMMENT 'Nullable/0 — clients register before any user authenticates',

    `created_by`                  BIGINT UNSIGNED DEFAULT NULL,
    `date_created`                BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`                 BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`               BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`                  BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`                BIGINT UNSIGNED DEFAULT NULL,
    `deleted`                     TINYINT UNSIGNED NOT NULL DEFAULT 0,

    UNIQUE KEY `idx_client_id` (`client_id`),
    KEY        `idx_account`   (`kyte_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `KyteOAuthCode` (
    `id`                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    `code_hash`              VARCHAR(64)   NOT NULL COMMENT 'sha256 of the raw authorization code; raw code never stored',
    `client_id`              VARCHAR(64)   NOT NULL COMMENT 'KyteOAuthClient.client_id this code was issued to',
    `redirect_uri`           VARCHAR(1024) NOT NULL COMMENT 'Must match exactly at /oauth/token',
    `code_challenge`         VARCHAR(255)  NOT NULL COMMENT 'PKCE code_challenge (base64url sha256(verifier))',
    `code_challenge_method`  VARCHAR(16)   NOT NULL COMMENT 'S256 only',
    `scope`                  VARCHAR(512)  DEFAULT NULL COMMENT 'Space-separated OAuth scopes granted at consent',
    `kyte_scopes`            VARCHAR(255)  NOT NULL COMMENT 'CSV of kmcp scopes to mint (read/draft/commit/provision/schema)',
    `application`            BIGINT UNSIGNED DEFAULT NULL COMMENT 'Optional app scope for the minted token',
    `expires_at`             BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unix epoch; codes are short-lived',
    `consumed_at`            BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unix epoch of redemption; 0 = unused (single-use guard)',

    `kyte_account`           BIGINT UNSIGNED NOT NULL COMMENT 'Account that consented; the minted token acts on this tenant',

    `created_by`             BIGINT UNSIGNED DEFAULT NULL COMMENT 'KyteUser who approved consent',
    `date_created`           BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`            BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`          BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`             BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`           BIGINT UNSIGNED DEFAULT NULL,
    `deleted`                TINYINT UNSIGNED NOT NULL DEFAULT 0,

    UNIQUE KEY `idx_code_hash` (`code_hash`),
    KEY        `idx_client`    (`client_id`),
    KEY        `idx_expires`   (`expires_at`),
    KEY        `idx_account`   (`kyte_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
