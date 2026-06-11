-- =========================================================================
-- Kyte v4.11.0 - add Application.allow_public (JWT-mode anonymous access)
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Adds the per-app opt-in flag for anonymous/public API access via
-- AppContextStrategy (JWT-mode public storefronts). Tri-state:
--   0 = anonymous appid-only requests rejected (default);
--   1 = read-only (GET) anonymous access to requireAuth=false controllers;
--   2 = controller-governed — requireAuth=false + allowableActions apply,
--       including writes (password reset / signup-style public flows).
--
-- ADDITIVE / migration-first / inert on older code (older code ignores the
-- column; new code defaults it to 0). Table name is PascalCase. ALGORITHM not
-- pinned (engine auto-selects INSTANT for ADD COLUMN where available).
--
-- See src/Mvc/Model/Application.php + src/Core/Auth/AppContextStrategy.php.
-- =========================================================================

ALTER TABLE `Application`
    ADD COLUMN `allow_public` INT UNSIGNED NOT NULL DEFAULT 0;
