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
-- Columns dropped (12 columns across 9 tables):
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
-- ALGORITHM: intentionally NOT pinned. Modern engines (MySQL 8.0.29+,
-- MariaDB 10.5+ for the compatible cases) perform DROP COLUMN as an INSTANT
-- operation automatically, with no table rebuild. Older engines fall back to
-- INPLACE/COPY automatically. Do NOT add an explicit `ALGORITHM=INSTANT`: when
-- requested explicitly it ERRORS on an engine that can't honor it (it does not
-- silently fall back). Letting the engine choose is portable and still gets
-- INSTANT where available. On large tables (notably KytePageVersionContent),
-- if the engine has to fall back to a rebuild, expect I/O and schedule it.
--
-- See src/Mvc/Model/*.php (field definitions removed in v4.8.0).
-- =========================================================================

ALTER TABLE `KytePageData`
    DROP COLUMN `javascript_obfuscated`;

ALTER TABLE `KytePageVersionContent`
    DROP COLUMN `javascript_obfuscated`;

ALTER TABLE `KyteSectionTemplate`
    DROP COLUMN `javascript_obfuscated`,
    DROP COLUMN `obfuscate_js`;

ALTER TABLE `KyteScript`
    DROP COLUMN `content_js_obfuscated`,
    DROP COLUMN `obfuscate_js`;

ALTER TABLE `KyteScriptVersionContent`
    DROP COLUMN `content_js_obfuscated`;

ALTER TABLE `KytePage`
    DROP COLUMN `obfuscate_js`;

ALTER TABLE `KytePageVersion`
    DROP COLUMN `obfuscate_js`;

ALTER TABLE `KyteScriptVersion`
    DROP COLUMN `obfuscate_js`;

ALTER TABLE `Application`
    DROP COLUMN `kyte_connect_obfuscated`,
    DROP COLUMN `obfuscate_kyte_connect`;
