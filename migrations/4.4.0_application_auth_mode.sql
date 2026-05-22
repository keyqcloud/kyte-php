-- =========================================================================
-- Kyte v4.4.0 - Application.auth_mode column
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds a per-Application `auth_mode` setting that Shipyard's page
-- generators consult when emitting the kyte-api-js constructor call.
--
--   'hmac' (default) → preserves v1.x behavior; pages use HMAC sign/rotate.
--   'jwt'            → pages use the v2 JWT bearer flow (kyte-api-js >= 2.0).
--
-- Default 'hmac' means existing apps are no-op until the operator
-- explicitly opts in via the Shipyard Application form.
--
-- See src/Mvc/Model/Application.php for the model spec.
-- =========================================================================

ALTER TABLE `Application`
    ADD COLUMN `auth_mode` VARCHAR(16) NOT NULL DEFAULT 'hmac';
