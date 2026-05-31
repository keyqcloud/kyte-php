-- =========================================================================
-- Kyte v4.8.0 - drop JS obfuscation columns
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Phase 2 of the JS-obfuscation removal (KYTE-#191). Phase 1 (v4.7.0) already
-- removed obfuscation from the publish path and change-detection, leaving the
-- obfuscated columns inert: nothing reads or writes them anymore. This
-- migration drops those now-dead columns, reclaiming the stored-duplicate
-- storage bloat (every page/script/section stored a second bzcompressed copy
-- of its JS that was never served).
--
-- Columns dropped:
--   javascript_obfuscated      (KytePageData, KytePageVersionContent,
--                               KyteSectionTemplate)
--   content_js_obfuscated      (KyteScript, KyteScriptVersionContent)
--   kyte_connect_obfuscated    (Application)
--   obfuscate_js               (KyteSectionTemplate, KyteScript, KytePage,
--                               KytePageVersion, KyteScriptVersion)
--   obfuscate_kyte_connect     (Application)
--
-- ROLLOUT ORDER (expand-contract / code-first, drop-last): the v4.8.0 CODE
-- must be deployed to ALL instances BEFORE running this migration. The v4.8.0
-- code no longer references these columns, so dropping them is safe once it is
-- live. Running this migration against v4.7.0-or-earlier code will break
-- content save/publish/decompress.
--
-- Table names are PascalCase (matches the framework's model class names).
--
-- ALGORITHM=INSTANT: MariaDB 10.5+ supports instant DROP COLUMN, which avoids
-- a full table rebuild. If a given table/engine cannot perform the drop
-- instantly, MariaDB automatically falls back to a copy/inplace algorithm —
-- the statement still succeeds. Operators running this against large tables
-- (notably KytePageVersionContent) should expect I/O during any such fallback
-- and schedule accordingly.
--
-- See src/Mvc/Model/*.php (field definitions removed in v4.8.0).
-- =========================================================================

ALTER TABLE `KytePageData`
    DROP COLUMN `javascript_obfuscated`,
    ALGORITHM=INSTANT;

ALTER TABLE `KytePageVersionContent`
    DROP COLUMN `javascript_obfuscated`,
    ALGORITHM=INSTANT;

ALTER TABLE `KyteSectionTemplate`
    DROP COLUMN `javascript_obfuscated`,
    DROP COLUMN `obfuscate_js`,
    ALGORITHM=INSTANT;

ALTER TABLE `KyteScript`
    DROP COLUMN `content_js_obfuscated`,
    DROP COLUMN `obfuscate_js`,
    ALGORITHM=INSTANT;

ALTER TABLE `KyteScriptVersionContent`
    DROP COLUMN `content_js_obfuscated`,
    ALGORITHM=INSTANT;

ALTER TABLE `KytePage`
    DROP COLUMN `obfuscate_js`,
    ALGORITHM=INSTANT;

ALTER TABLE `KytePageVersion`
    DROP COLUMN `obfuscate_js`,
    ALGORITHM=INSTANT;

ALTER TABLE `KyteScriptVersion`
    DROP COLUMN `obfuscate_js`,
    ALGORITHM=INSTANT;

ALTER TABLE `Application`
    DROP COLUMN `kyte_connect_obfuscated`,
    DROP COLUMN `obfuscate_kyte_connect`,
    ALGORITHM=INSTANT;
