-- =========================================================================
-- Kyte v4.10.0 - add draft flags to version tables (MCP draft/write)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds `draft` + `draft_source` to the three content-version tables that the
-- MCP draft/write feature operates on — pages, controller functions, and
-- standalone scripts. A draft is a pending version: draft=1 AND is_current=0,
-- leaving the live content and the current version untouched until the draft
-- is committed (which flips it to is_current=1, draft=0). Distinct from a
-- historical version (draft=0, is_current=0).
--
--   draft         INT          - 1 = a pending draft, 0 = normal version.
--   draft_source  VARCHAR(50)  - origin of a draft, e.g. 'mcp'; NULL otherwise.
--
-- ROLLOUT ORDER (expand / migration-first): ADDITIVE and safe to run BEFORE
-- the v4.10.0 code is deployed. Older code ignores the new columns (SELECT *
-- maps only defined fields; inserts omit them → default draft=0 / NULL = an
-- ordinary non-draft version). Run the migration first, then deploy v4.10.0.
--
-- Table names are PascalCase (framework model class names). ALGORITHM not
-- pinned (engine auto-selects INSTANT for ADD COLUMN where available).
--
-- See src/Mvc/Model/{KytePageVersion,KyteFunctionVersion,KyteScriptVersion}.php
-- (field definitions added in v4.10.0).
-- =========================================================================

ALTER TABLE `KytePageVersion`
    ADD COLUMN `draft` INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN `draft_source` VARCHAR(50) NULL;

ALTER TABLE `KyteFunctionVersion`
    ADD COLUMN `draft` INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN `draft_source` VARCHAR(50) NULL;

ALTER TABLE `KyteScriptVersion`
    ADD COLUMN `draft` INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN `draft_source` VARCHAR(50) NULL;
