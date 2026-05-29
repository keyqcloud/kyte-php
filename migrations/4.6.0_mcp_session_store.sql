-- =========================================================================
-- Kyte v4.6.0 - MCP protocol session store (cross-instance / load-balanced)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Creates the KyteMCPSession table consumed by Kyte\Mcp\Session\DbSessionStore,
-- the default MCP protocol-session backend as of 4.6.0. It replaces the SDK's
-- per-host FileSessionStore so that deployments behind a load balancer (e.g.
-- multiple PHP instances sharing one database) can resolve the same MCP
-- `MCP-Session-Id` on any instance.
--
-- Background: the SDK FileSessionStore writes one file per session under
-- sys_get_temp_dir(), local to a single host. When `initialize` lands on
-- instance A and the follow-up request hits instance B, B has no such file and
-- the SDK returns -32600 "Session not found or has expired", collapsing the
-- MCP connection. This table makes session state shared. See Tempo KYTE-183
-- and docs/design/kyte-mcp-and-auth-migration.md section 11.
--
-- This is MCP *protocol* session state (the negotiated initialize/capabilities
-- handshake), NOT auth. MCP auth (KyteMCPToken) was already DB-backed and
-- cross-instance. Rows here are short-lived and hard-deleted on destroy / TTL
-- garbage collection.
--
-- Single-instance installs that prefer no extra table can stay on the file
-- store by setting `define('KYTE_MCP_SESSION_STORE', 'file');` in config; in
-- that case this table is simply unused. Idle TTL is KYTE_MCP_SESSION_TTL
-- (default 3600s).
--
-- See src/Mvc/Model/KyteMCPSession.php for the model spec.
-- Existing installs that already created an equivalent table are unaffected
-- (IF NOT EXISTS).
-- =========================================================================

CREATE TABLE IF NOT EXISTS `KyteMCPSession` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    `session_id`      VARCHAR(36)  NOT NULL COMMENT 'RFC4122 UUID issued by the MCP SDK session factory',
    `payload`         TEXT         NOT NULL COMMENT 'json_encode of the SDK session data array (opaque to Kyte)',
    `last_activity`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unix epoch of last write; TTL is measured against this (idle timeout)',

    `kyte_account`    BIGINT UNSIGNED NOT NULL,

    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `date_created`    BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`     BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`   BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`      BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`    BIGINT UNSIGNED DEFAULT NULL,
    `deleted`         TINYINT UNSIGNED NOT NULL DEFAULT 0,

    UNIQUE KEY `idx_session_id`         (`session_id`),
    KEY        `idx_account`            (`kyte_account`),
    KEY        `idx_account_session`    (`kyte_account`, `session_id`),
    KEY        `idx_last_activity`      (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
