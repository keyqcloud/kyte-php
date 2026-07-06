-- =========================================================================
-- Kyte v4.16.0 - controller_config on DataModel + Controller (KYTE-#342)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds a JSON `controller_config` column to DataModel (the baseline the generic
-- controller path reads) and Controller (per-controller override), holding
-- DB-driven, Shipyard-togglable controller behaviour flags. First flag:
-- allow_projection (KYTE-#190 opt-in). See
-- docs/design/db-driven-controller-config.md.
--
-- Nullable TEXT, no default -> a no-op for every existing row (empty config =
-- global defaults). ADDITIVE / migration-first / inert on older code.
--
-- Portable idempotent add (MySQL 5.7/8 + MariaDB): MySQL has no
-- `ADD COLUMN IF NOT EXISTS`, so guard with an information_schema check +
-- prepared statement.
-- =========================================================================

-- DataModel.controller_config
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'DataModel'
      AND COLUMN_NAME  = 'controller_config'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `DataModel` ADD COLUMN `controller_config` TEXT NULL DEFAULT NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Controller.controller_config
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'Controller'
      AND COLUMN_NAME  = 'controller_config'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `Controller` ADD COLUMN `controller_config` TEXT NULL DEFAULT NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
