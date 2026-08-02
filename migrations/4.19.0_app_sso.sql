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
--                             libsodium-encrypted client_secret; never via MCP).
--   KyteSsoState            - short-lived in-flight state/nonce/PKCE per login,
--                             plus a browser-binding correlator (login-CSRF).
--   KyteSsoCode             - single-use hand-off code delivering the session to
--                             the app front-end (no tokens at rest).
--   KyteSsoIdentity         - subject->app-user link (the authoritative SSO join
--                             key; email is never the identity key).
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
    `client_secret`        TEXT         DEFAULT NULL COMMENT 'libsodium secretbox, base64(nonce+ciphertext)',
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
    `browser_hash`   VARCHAR(64)  DEFAULT NULL,
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

-- KyteSsoIdentity: the federated-identity link binding an external IdP subject
-- to a local app user. This is the AUTHORITATIVE join key for SSO logins (the
-- immutable `sub` + tenant), NOT the mutable/unverified email claim — closing
-- the account-takeover vector where a token bearing another user's email would
-- otherwise be mapped onto that user's account.
CREATE TABLE IF NOT EXISTS `KyteSsoIdentity` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `application`   BIGINT UNSIGNED NOT NULL,
    `provider`      VARCHAR(32)  NOT NULL,
    `subject`       VARCHAR(255) NOT NULL,
    `tenant_id`     VARCHAR(128) DEFAULT NULL,
    `sso_user_id`   BIGINT UNSIGNED NOT NULL,
    `email`         VARCHAR(320) DEFAULT NULL,
    `kyte_account`  BIGINT UNSIGNED DEFAULT 0,
    `created_by`    BIGINT UNSIGNED DEFAULT NULL,
    `date_created`  BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`   BIGINT UNSIGNED DEFAULT NULL,
    `date_modified` BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`    BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`  BIGINT UNSIGNED DEFAULT NULL,
    `deleted`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY `idx_app_provider_subject` (`application`, `provider`, `subject`),
    KEY `idx_sso_user` (`application`, `sso_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Idempotent ALTERs for installs that created the tables from an earlier cut
-- of this migration (before browser_hash / the identity table existed).
-- ---------------------------------------------------------------------------

-- KyteSsoState.browser_hash — login-CSRF browser-binding correlator.
SET @add_browser_hash := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE `KyteSsoState` ADD COLUMN `browser_hash` VARCHAR(64) DEFAULT NULL AFTER `redirect_uri`',
        'SELECT 1')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'KyteSsoState' AND COLUMN_NAME = 'browser_hash'
);
PREPARE stmt FROM @add_browser_hash; EXECUTE stmt; DEALLOCATE PREPARE stmt;
