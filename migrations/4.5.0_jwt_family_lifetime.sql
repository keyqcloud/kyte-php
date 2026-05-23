-- =========================================================================
-- Kyte v4.5.0 - JWT refresh token family lifetime anchor
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds `family_started_at` to KyteRefreshToken. This column anchors the
-- absolute session-lifetime cap (KYTE_JWT_FAMILY_MAX_LIFETIME, default 12h)
-- to the original `/jwt/login` moment, independent of how active the user
-- is. Without it, sliding refresh-token rotation extends sessions
-- indefinitely (the 4.4.x behavior we're capping in 4.5.0).
--
-- Backward compatibility: existing rows backfill to 0. RefreshTokenStore::rotate()
-- treats family_started_at = 0 as "legacy, uncapped" on the first
-- post-upgrade rotation, and the successor token anchors family_started_at
-- to that moment. No mass logout at deploy time.
--
-- See src/Mvc/Model/KyteRefreshToken.php for the model spec and
-- src/Core/Auth/RefreshTokenStore.php for the cap enforcement logic.
-- =========================================================================

ALTER TABLE `KyteRefreshToken`
    ADD COLUMN `family_started_at` BIGINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Unix epoch when this token family was born at /jwt/login. Copied forward on rotation; anchors the absolute-cap clock.'
        AFTER `expires_at`;
