-- =========================================================================
-- Kyte v4.13.0 - site-provisioning worker columns (KYTE-#201 #3)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds two columns to KyteSite for the SiteProvisioningWorker cron job (which
-- replaces kyte-lambda-site-management + kyte-lambda-database-transaction). The
-- create/delete sub-state itself is inferred from the existing s3*/cf* columns;
-- these are for surfacing failures to the dashboard and bounding retries:
--   provisioning_message   - last error/status detail
--   provisioning_attempts  - failed-tick counter; worker flips status='failed'
--                            after a cap instead of looping forever.
--
-- ADDITIVE / migration-first / inert on older code. PascalCase table.
--
-- See src/Cron/SiteProvisioningWorker.php, src/Mvc/Model/KyteSite.php,
-- docs/design/site-provisioning-cron-worker.md.
-- =========================================================================

ALTER TABLE `KyteSite`
    ADD COLUMN `provisioning_message`  TEXT             DEFAULT NULL,
    ADD COLUMN `provisioning_attempts` INT UNSIGNED NOT NULL DEFAULT 0;
