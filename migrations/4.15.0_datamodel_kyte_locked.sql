-- =========================================================================
-- Kyte v4.15.0 - Ensure `kyte_locked` on DataModel + ModelAttribute (KYTE-#325)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- v4.15.0 enforces `kyte_locked` on the DDL path: DataModelController and
-- ModelAttributeController refuse update/delete of a locked model/attribute.
-- That guard reads `kyte_locked` off the row, so it is only meaningful when
-- the column physically exists. The original consolidated 3.x->4.8 upgrade
-- added it, but some installs applied it only partially (observed in the wild:
-- ModelAttribute had the column while DataModel did not), which left the new
-- model-level lock guard silently inert (read as NULL -> 0 -> "not locked").
--
-- This migration GUARANTEES the column exists on both framework tables so the
-- lock guard is reliable on every install. It is idempotent (adds the column
-- only when absent), nullable, default 0 -> a no-op for existing rows and for
-- installs that already have it.
--
-- PORTABILITY (v4.15.1 fix): earlier this used MariaDB-only
-- `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, which is a syntax error on MySQL
-- (5.7 / 8.0). It now guards with an information_schema check + a prepared
-- statement, which is idempotent on BOTH MySQL and MariaDB. Safe to (re-)run
-- on any install regardless of engine or prior state.
--
-- ADDITIVE / migration-first / inert on older code. PascalCase tables.
--
-- See src/Mvc/Controller/DataModelController.php and
-- src/Mvc/Controller/ModelAttributeController.php (kyte_locked guards),
-- src/Mvc/Model/DataModel.php / src/Mvc/Model/ModelAttribute.php (definitions).
-- =========================================================================

-- DataModel.kyte_locked
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'DataModel'
      AND COLUMN_NAME  = 'kyte_locked'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `DataModel` ADD COLUMN `kyte_locked` INT UNSIGNED NULL DEFAULT 0',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ModelAttribute.kyte_locked
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'ModelAttribute'
      AND COLUMN_NAME  = 'kyte_locked'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `ModelAttribute` ADD COLUMN `kyte_locked` INT UNSIGNED NULL DEFAULT 0',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
