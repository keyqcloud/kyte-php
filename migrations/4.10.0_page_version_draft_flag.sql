-- =========================================================================
-- Kyte v4.10.0 - add draft flag to KytePageVersion (MCP draft/write, Phase A)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds two columns to KytePageVersion in support of the MCP draft/write
-- feature (KYTE MCP initiative, Phase 2 write tools):
--
--   draft         INT  - 1 = a pending draft version (not live), 0 = normal.
--                        A draft has draft=1 AND is_current=0; the page's
--                        live (is_current=1) version is untouched until the
--                        draft is committed. Distinct from a historical
--                        version (draft=0, is_current=0).
--   draft_source  VARCHAR(50) - origin of a draft, e.g. 'mcp' for an
--                        AI-authored draft; NULL for ordinary versions.
--
-- ROLLOUT ORDER (expand / migration-first): this migration is ADDITIVE and
-- safe to run BEFORE the v4.10.0 code is deployed. Older code ignores the
-- new columns (SELECT * maps only defined fields; inserts omit them, so they
-- default to draft=0 / NULL = an ordinary non-draft version). Run the
-- migration first, then deploy the v4.10.0 code that reads/writes drafts.
--
-- Table name is PascalCase (matches the framework's model class name).
-- ALGORITHM intentionally not pinned (engine auto-selects INSTANT where
-- available for ADD COLUMN; falls back automatically otherwise).
--
-- See src/Mvc/Model/KytePageVersion.php (field definitions added in v4.10.0).
-- =========================================================================

ALTER TABLE `KytePageVersion`
    ADD COLUMN `draft` INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN `draft_source` VARCHAR(50) NULL;
