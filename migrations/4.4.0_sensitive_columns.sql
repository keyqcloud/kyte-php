-- =========================================================================
-- Kyte v4.4.0 - Sensitive-data flag columns
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds a per-row `sensitive` boolean (0/1, default 0) to three framework
-- tables. When set, the value gates whether activity-log and error-log
-- writers store the request body / response payload, and whether MCP
-- read tools expose source / definition for that controller, model, or
-- field. Default 0 means existing installs are no-op until a flag is set.
--
-- See SensitivityPolicy::class for the runtime evaluation order:
--   1. Controller.sensitive   blanket drop (handles no-model controllers)
--   2. DataModel.sensitive    blanket drop when the model is the target
--   3. ModelAttribute.sensitive per-field redaction
--
-- ModelAttribute.sensitive is distinct from the existing
-- ModelAttribute.protected column (which only blanks values in GET
-- responses). Set both if both behaviors are desired.
-- =========================================================================

ALTER TABLE `Controller`
    ADD COLUMN `sensitive` TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER `application`;

ALTER TABLE `DataModel`
    ADD COLUMN `sensitive` TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER `application`;

ALTER TABLE `ModelAttribute`
    ADD COLUMN `sensitive` TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER `password`;
