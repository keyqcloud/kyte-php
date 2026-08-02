-- =========================================================================
-- Kyte v4.18.0 - Application lifecycle status (async app teardown, KYTE-#559)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds Application.status ('active' | 'deleting' | 'deleted'). When an app is
-- deleted it is marked 'deleting' (not dropped synchronously); the
-- SiteProvisioningWorker tears down each site's AWS infra (S3 + CloudFront +
-- ACM) then finalizes the app — drops the tenant DB + its user and sets
-- deleted=1/status='deleted'. This stops app deletion from orphaning site
-- infrastructure.
--
-- Portable + idempotent: MySQL has no `ADD COLUMN IF NOT EXISTS`, so guard with
-- an information_schema check + a prepared statement (works on MySQL 8.x +
-- MariaDB 10.5+). Existing rows default to 'active'.
-- =========================================================================

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Application' AND COLUMN_NAME = 'status'
);
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `Application` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT ''active''',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
