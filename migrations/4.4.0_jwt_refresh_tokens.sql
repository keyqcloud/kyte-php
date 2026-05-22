-- =========================================================================
-- Kyte v4.4.0 - JWT refresh token storage
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Creates the KyteRefreshToken table used by the JWT auth strategy. Refresh
-- tokens are opaque (NOT JWTs) and exchanged at /jwt/refresh for a fresh
-- (access_jwt, new_refresh_token) pair. Each rotation revokes the presented
-- token and issues a new one in the same `token_family` — presenting a
-- revoked token is treated as a leak signal and revokes the entire family.
--
-- See src/Mvc/Model/KyteRefreshToken.php for the model spec and reuse
-- detection rationale.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `KyteRefreshToken` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    `token_hash`      VARCHAR(64)  NOT NULL COMMENT 'sha256 hex of the raw refresh token',
    `token_prefix`    VARCHAR(32)  NOT NULL COMMENT 'First chars of the raw token (kref_v1_...) for identification',
    `token_family`    VARCHAR(64)  NOT NULL COMMENT 'Hex uuid shared by every token in a rotation chain',

    `user`            BIGINT UNSIGNED NOT NULL,
    `application`     BIGINT UNSIGNED DEFAULT NULL,

    `expires_at`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_used_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_used_ip`    VARCHAR(45)  DEFAULT NULL,

    `revoked_at`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `revoked_reason`  VARCHAR(64)  DEFAULT NULL COMMENT 'rotated | reuse_detected | logout | admin_revoke | expired',
    `rotated_to`      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Successor token id when rotated; 0 while active',

    `kyte_account`    BIGINT UNSIGNED NOT NULL,

    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `date_created`    BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`     BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`   BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`      BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`    BIGINT UNSIGNED DEFAULT NULL,
    `deleted`         TINYINT UNSIGNED NOT NULL DEFAULT 0,

    UNIQUE KEY `idx_token_hash` (`token_hash`),
    KEY `idx_token_family`      (`token_family`),
    KEY `idx_user`              (`user`),
    KEY `idx_account_expires`   (`kyte_account`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
