-- =========================================================================
-- Kyte v4.5.3 - MCP token storage (Phase 2 backfill)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Creates the KyteMCPToken table consumed by the MCP auth strategy
-- (McpTokenStrategy) and issued via the Shipyard Tokens page. Opaque
-- bearer tokens (prefix `kmcp_live_...`) authenticate Claude Code /
-- Claude.ai to the per-tenant /mcp endpoint. Scope-gated (read/draft/
-- commit), revokable, IP-restrictable, TTL'd. Only the sha256 hash is
-- stored; the raw token is shown once at creation.
--
-- Backfill note: the KyteMCPToken model + /mcp server + McpTokenStrategy
-- shipped in the Phase 2 source merge, but the table-creation migration
-- was never committed (the model file notes "index creation happens in
-- the Phase 2 migration"). This file closes that gap. Existing installs
-- that already ran an equivalent CREATE are unaffected (IF NOT EXISTS).
--
-- See src/Mvc/Model/KyteMCPToken.php for the model spec and
-- docs/design/kyte-mcp-and-auth-migration.md section 5.4.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `KyteMCPToken` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    `token_hash`      VARCHAR(64)  NOT NULL COMMENT 'sha256 hex of the raw MCP token',
    `token_prefix`    VARCHAR(16)  NOT NULL COMMENT 'First chars of the raw token (kmcp_live_...) for UI identification',
    `name`            VARCHAR(255) NOT NULL COMMENT 'Human-facing label',

    `application`     BIGINT UNSIGNED DEFAULT NULL COMMENT 'App scope; NULL = account-wide (reserved)',
    `scopes`          VARCHAR(255) NOT NULL COMMENT 'CSV of read|draft|commit',

    `expires_at`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Unix epoch; 0 = never (discouraged)',
    `last_used_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_used_ip`    VARCHAR(45)  DEFAULT NULL,
    `ip_allowlist`    TEXT         DEFAULT NULL COMMENT 'Optional CSV CIDR allowlist; empty = any',

    `revoked_at`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = active, nonzero = revoked',

    `kyte_account`    BIGINT UNSIGNED NOT NULL,

    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `date_created`    BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`     BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`   BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`      BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`    BIGINT UNSIGNED DEFAULT NULL,
    `deleted`         TINYINT UNSIGNED NOT NULL DEFAULT 0,

    UNIQUE KEY `idx_token_hash`    (`token_hash`),
    KEY        `idx_application`   (`application`),
    KEY        `idx_account`       (`kyte_account`),
    KEY        `idx_account_revoked` (`kyte_account`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
