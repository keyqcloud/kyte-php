-- =========================================================================
-- Kyte v4.19.0 - App-level SSO (OIDC) tables (KYTE-#560)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- App-level Microsoft/OIDC SSO: an app's end users sign in with their identity
-- provider; Kyte (the relying party) validates the id_token and issues a Kyte
-- JWT session for the app's user_model.
--
--   KyteAppIdentityProvider - per-app OIDC config (Shipyard-managed; carries a
--                             KMS-encrypted client_secret; never via MCP).
--   KyteSsoState            - short-lived in-flight state/nonce/PKCE per login.
--   KyteSsoCode             - single-use hand-off code delivering the session to
--                             the app front-end (no tokens at rest).
--
-- Idempotent (CREATE TABLE IF NOT EXISTS). Conventions mirror
-- migrations/4.6.0_mcp_session_store.sql. Portable MySQL 8.x / MariaDB 10.5+.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `KyteAppIdentityProvider` (
    `id`                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `application`          BIGINT UNSIGNED NOT NULL,
    `provider`             VARCHAR(32)  NOT NULL,
    `enabled`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `issuer`               VARCHAR(512) DEFAULT NULL,
    `discovery_url`        VARCHAR(512) DEFAULT NULL,
    `tenant`               VARCHAR(128) DEFAULT NULL,
    `client_id`            VARCHAR(255) DEFAULT NULL,
    `client_secret`        TEXT         DEFAULT NULL COMMENT 'KMS-encrypted (base64 ciphertext)',
    `scopes`               VARCHAR(512) DEFAULT 'openid profile email',
    `redirect_uri`         VARCHAR(1024) DEFAULT NULL,
    `user_email_claim`     VARCHAR(64)  DEFAULT 'email',
    `jit_enabled`          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `restrict_to_existing` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `kyte_account`         BIGINT UNSIGNED NOT NULL,
    `created_by`           BIGINT UNSIGNED DEFAULT NULL,
    `date_created`         BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`          BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`        BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`           BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`         BIGINT UNSIGNED DEFAULT NULL,
    `deleted`              TINYINT UNSIGNED NOT NULL DEFAULT 0,
    KEY `idx_app_provider` (`application`, `provider`),
    KEY `idx_account`      (`kyte_account`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `KyteSsoState` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `state`          VARCHAR(64)  NOT NULL,
    `nonce`          VARCHAR(64)  NOT NULL,
    `code_verifier`  VARCHAR(128) NOT NULL,
    `application`    BIGINT UNSIGNED NOT NULL,
    `provider`       VARCHAR(32)  DEFAULT NULL,
    `return_url`     VARCHAR(1024) DEFAULT NULL,
    `redirect_uri`   VARCHAR(1024) DEFAULT NULL,
    `expires_at`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `consumed_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `kyte_account`   BIGINT UNSIGNED DEFAULT 0,
    `created_by`     BIGINT UNSIGNED DEFAULT NULL,
    `date_created`   BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`    BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`  BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`     BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`   BIGINT UNSIGNED DEFAULT NULL,
    `deleted`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY `idx_state` (`state`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `KyteSsoCode` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code_hash`     VARCHAR(64) NOT NULL,
    `application`   BIGINT UNSIGNED NOT NULL,
    `sso_user_id`   BIGINT UNSIGNED NOT NULL,
    `expires_at`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `consumed_at`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `kyte_account`  BIGINT UNSIGNED DEFAULT 0,
    `created_by`    BIGINT UNSIGNED DEFAULT NULL,
    `date_created`  BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`   BIGINT UNSIGNED DEFAULT NULL,
    `date_modified` BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`    BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`  BIGINT UNSIGNED DEFAULT NULL,
    `deleted`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY `idx_code_hash` (`code_hash`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
