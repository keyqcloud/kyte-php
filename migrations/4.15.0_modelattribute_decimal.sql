-- =========================================================================
-- Kyte v4.15.0 - ModelAttribute decimal precision/scale (KYTE-#325 PR A)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds `precision` and `scale` to ModelAttribute so the decimal (`d`) column
-- type works end-to-end. DBI::buildFieldDefinition emits `decimal(p,s)` only
-- when both are present; previously these were never stored or passed through,
-- so a `d` attribute produced an invalid column definition. Both are nullable
-- and only consumed for type='d', so this is a no-op for every existing row.
--
-- ADDITIVE / migration-first / inert on older code. PascalCase table.
--
-- See src/Mvc/Model/ModelAttribute.php,
-- src/Mvc/Controller/DataModelController.php::prepareModelDef,
-- src/Core/DBI.php::buildFieldDefinition.
-- =========================================================================

ALTER TABLE `ModelAttribute`
    ADD COLUMN `precision` INT UNSIGNED DEFAULT NULL AFTER `size`,
    ADD COLUMN `scale`     INT UNSIGNED DEFAULT NULL AFTER `precision`;
