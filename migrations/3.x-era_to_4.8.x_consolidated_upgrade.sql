-- =========================================================================
-- Kyte CONSOLIDATED upgrade: 3.x-era schema  ->  v4.8.x
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- One-shot, idempotent path to bring a pre-4.1.0 ("3.x era") install up to the
-- v4.8.x framework schema. Pre-4.1.0 schema changes were documented in the
-- CHANGELOG (not as .sql files); this consolidates everything from ~3.8.0
-- through 4.8.0 into one file, built to the FINAL 4.8.x shape (e.g. version
-- tables are created already WITHOUT the obfuscation columns, rather than
-- create-then-drop). Generated from a live v4.8.1 install's SHOW CREATE TABLE.
--
-- Idempotent & safe to re-run. BUILT FOR MariaDB (10.x) — the column-level
-- `ADD COLUMN IF NOT EXISTS` / `DROP COLUMN IF EXISTS` are MariaDB extensions that
-- MySQL 8.0 does NOT support. `CREATE TABLE IF NOT EXISTS` is universal. On a
-- MySQL target those column clauses would need to be adjusted (plain ADD/DROP, or
-- an information_schema-guarded approach). Client B (the first consumer) is MariaDB.
--
-- ROLLOUT: deploy the v4.8.x CODE first, THEN run this. After it, verify the
-- app + run a page publish. config.php also needs the 4.x constants (JWT
-- secret + TTLs, AUTH strategy, etc.) — see the upgrade notes, NOT in this SQL.
--
-- DATA-migration caveat: if the install has EXISTING cron jobs from the old
-- (pre-4.0.0, full-class) format, those need a separate conversion to the
-- function-based format — NOT covered here. A freshly-created CronJob* set is
-- empty, so this is N/A for an install that never used cron.
--
-- CHARSET/COLLATION: explicit charset + collation were intentionally stripped so
-- new tables/columns inherit the TARGET database's defaults. This keeps the file
-- portable (the source 4.8.1 install was MySQL 8.0, whose `utf8mb4_0900_ai_ci`
-- collation MariaDB does NOT support) and consistent with the install's existing
-- tables (avoids "illegal mix of collations" at runtime). Confirm the DB default
-- charset is adequate (utf8mb4 recommended) before running.
--
-- NOT a drop-in for prod: rehearse on a CLONE of the target DB first, then apply
-- to the real install only after the cloned upgrade + app smoke test pass.
-- =========================================================================

-- ============ 1. CREATE missing tables (3.8.x -> 4.6.0 additions), final 4.8.x shape ============

CREATE TABLE IF NOT EXISTS `KyteLibraryAssignment` (
  `library` int unsigned DEFAULT NULL,
  `global_scope` tinyint unsigned DEFAULT '0',
  `page` int unsigned DEFAULT NULL,
  `site` int unsigned DEFAULT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` int unsigned DEFAULT '0',
  `id` int NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KytePageVersion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `page` int unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `version_type` enum('auto_save','manual_save','publish') NOT NULL DEFAULT 'manual_save',
  `change_summary` text,
  `changes_detected` json DEFAULT NULL,
  `content_hash` varchar(64) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text,
  `lang` varchar(255) DEFAULT NULL,
  `page_type` varchar(255) DEFAULT NULL,
  `state` int unsigned DEFAULT NULL,
  `sitemap_include` int unsigned DEFAULT NULL,
  `is_js_module` int unsigned DEFAULT NULL,
  `use_container` int unsigned DEFAULT NULL,
  `protected` int unsigned DEFAULT NULL,
  `webcomponent_obj_name` varchar(255) DEFAULT NULL,
  `header` int unsigned DEFAULT NULL,
  `footer` int unsigned DEFAULT NULL,
  `main_navigation` int unsigned DEFAULT NULL,
  `side_navigation` int unsigned DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `parent_version` int unsigned DEFAULT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int unsigned DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KytePageVersionContent` (
  `id` int NOT NULL AUTO_INCREMENT,
  `content_hash` varchar(64) NOT NULL,
  `html` longblob,
  `stylesheet` longblob,
  `javascript` longblob,
  `block_layout` longblob,
  `reference_count` int unsigned NOT NULL DEFAULT '1',
  `last_referenced` bigint unsigned NOT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int unsigned DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_hash_per_account` (`content_hash`,`kyte_account`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteScriptVersion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `script` int unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `version_type` enum('auto_save','manual_save','publish') NOT NULL DEFAULT 'manual_save',
  `change_summary` varchar(500) DEFAULT NULL,
  `changes_detected` json DEFAULT NULL,
  `content_hash` varchar(64) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text,
  `s3key` varchar(255) DEFAULT NULL,
  `script_type` varchar(255) DEFAULT NULL,
  `is_js_module` int unsigned DEFAULT NULL,
  `include_all` int unsigned DEFAULT NULL,
  `state` int unsigned DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `parent_version` int unsigned DEFAULT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int NOT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int NOT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int NOT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteScriptVersionContent` (
  `id` int NOT NULL AUTO_INCREMENT,
  `content_hash` varchar(64) NOT NULL,
  `content` longblob,
  `reference_count` int unsigned NOT NULL DEFAULT '1',
  `last_referenced` bigint unsigned NOT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int NOT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int NOT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int NOT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_hash_per_account` (`content_hash`,`kyte_account`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteFunctionVersion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `function` int unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `version_type` enum('auto_save','manual_save','publish') NOT NULL DEFAULT 'manual_save',
  `change_summary` varchar(500) DEFAULT NULL,
  `changes_detected` json DEFAULT NULL,
  `content_hash` varchar(64) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `description` text,
  `function_type` varchar(255) DEFAULT NULL,
  `kyte_locked` int unsigned DEFAULT NULL,
  `is_current` tinyint(1) NOT NULL DEFAULT '0',
  `parent_version` int unsigned DEFAULT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int NOT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int NOT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int NOT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteFunctionVersionContent` (
  `id` int NOT NULL AUTO_INCREMENT,
  `content_hash` varchar(64) NOT NULL,
  `code` longblob,
  `reference_count` int unsigned NOT NULL DEFAULT '1',
  `last_referenced` bigint unsigned NOT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int NOT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int NOT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int NOT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_hash_per_account` (`content_hash`,`kyte_account`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `CronJob` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text,
  `code` longblob COMMENT 'bzip2 compressed PHP code',
  `schedule_type` varchar(20) DEFAULT 'cron' COMMENT 'Types: cron, interval, daily, weekly, monthly',
  `cron_expression` varchar(100) DEFAULT NULL COMMENT 'Standard 5-field cron: 0 2 * * * (2am daily)',
  `interval_seconds` int unsigned DEFAULT NULL COMMENT 'For interval type: 300 = every 5 minutes',
  `time_of_day` time DEFAULT NULL COMMENT 'For daily type: 02:00:00',
  `day_of_week` tinyint unsigned DEFAULT NULL COMMENT 'For weekly type: 0=Sunday, 6=Saturday',
  `day_of_month` tinyint unsigned DEFAULT NULL COMMENT 'For monthly type: 1-31',
  `timezone` varchar(50) DEFAULT 'UTC' COMMENT 'Job timezone',
  `enabled` tinyint unsigned DEFAULT '1',
  `timeout_seconds` int unsigned DEFAULT '300' COMMENT 'Default 5min, max 1800 (30min)',
  `max_retries` tinyint unsigned DEFAULT '3' COMMENT '0-5 range',
  `retry_strategy` varchar(20) DEFAULT 'exponential' COMMENT 'Types: immediate, fixed, exponential',
  `retry_delay_seconds` int unsigned DEFAULT '60' COMMENT 'For fixed strategy',
  `allow_concurrent` tinyint unsigned DEFAULT '0',
  `depends_on_job` int unsigned DEFAULT NULL COMMENT 'FK to parent CronJob',
  `notify_on_failure` tinyint unsigned DEFAULT '0',
  `notify_after_failures` int unsigned DEFAULT '3' COMMENT 'Alert after N consecutive failures',
  `notify_on_dead_letter` tinyint unsigned DEFAULT '1' COMMENT 'Alert when moved to DLQ',
  `slack_webhook` varchar(512) DEFAULT NULL COMMENT 'Optional per-job webhook (overrides app default)',
  `notification_email` varchar(255) DEFAULT NULL,
  `in_dead_letter_queue` tinyint unsigned DEFAULT '0',
  `dead_letter_reason` text,
  `dead_letter_since` int unsigned DEFAULT NULL,
  `consecutive_failures` int unsigned DEFAULT '0' COMMENT 'Track failure streak',
  `application` int DEFAULT NULL COMMENT 'FK to Application',
  `kyte_locked` tinyint unsigned DEFAULT '0',
  `kyte_account` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `date_created` int unsigned DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `date_modified` int unsigned DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` int unsigned DEFAULT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_application` (`application`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_depends_on` (`depends_on_job`),
  KEY `idx_dead_letter` (`in_dead_letter_queue`),
  KEY `idx_deleted` (`deleted`),
  KEY `idx_app_account` (`application`,`kyte_account`),
  CONSTRAINT `fk_cronjob_application` FOREIGN KEY (`application`) REFERENCES `Application` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cronjob_depends_on` FOREIGN KEY (`depends_on_job`) REFERENCES `CronJob` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `CronJobExecution` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cron_job` int unsigned NOT NULL,
  `scheduled_time` int unsigned NOT NULL COMMENT 'Unix timestamp when job was supposed to run',
  `next_run_time` int unsigned DEFAULT NULL COMMENT 'When this job should run next',
  `status` varchar(20) DEFAULT 'pending' COMMENT 'Types: pending, running, completed, failed, timeout, skipped',
  `locked_by` varchar(255) DEFAULT NULL COMMENT 'Server identifier: hostname:pid',
  `locked_at` int unsigned DEFAULT NULL COMMENT 'When lock was acquired',
  `locked_until` int unsigned DEFAULT NULL COMMENT 'Lease expiration timestamp',
  `started_at` int unsigned DEFAULT NULL,
  `completed_at` int unsigned DEFAULT NULL,
  `duration_ms` int unsigned DEFAULT NULL COMMENT 'Execution time in milliseconds',
  `exit_code` int DEFAULT NULL COMMENT '0 = success, non-zero = error',
  `output` mediumtext COMMENT 'stdout / success messages',
  `error` mediumtext COMMENT 'stderr / exception messages',
  `stack_trace` text COMMENT 'Full PHP stack trace on error',
  `memory_peak_mb` decimal(10,2) DEFAULT NULL COMMENT 'Peak memory usage',
  `retry_count` int unsigned DEFAULT '0',
  `is_retry` tinyint unsigned DEFAULT '0',
  `parent_execution` int unsigned DEFAULT NULL COMMENT 'FK to original execution if retry',
  `retry_scheduled_time` int unsigned DEFAULT NULL COMMENT 'When retry should happen',
  `skipped_reason` varchar(255) DEFAULT NULL COMMENT 'Reason if skipped',
  `dependency_execution` int unsigned DEFAULT NULL COMMENT 'FK to parent job execution checked',
  `application` int DEFAULT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL COMMENT 'NULL for automatic, set for manual triggers',
  `date_created` int unsigned DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `date_modified` int unsigned DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` int unsigned DEFAULT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_cron_job` (`cron_job`),
  KEY `idx_status` (`status`),
  KEY `idx_next_run` (`next_run_time`,`status`),
  KEY `idx_locked_until` (`locked_until`),
  KEY `idx_scheduled_time` (`scheduled_time`),
  KEY `idx_parent_execution` (`parent_execution`),
  KEY `idx_retry_scheduled` (`retry_scheduled_time`,`status`),
  KEY `idx_application` (`application`),
  KEY `fk_cronjobexecution_dependency` (`dependency_execution`),
  KEY `idx_deleted` (`deleted`),
  CONSTRAINT `fk_cronjobexecution_application` FOREIGN KEY (`application`) REFERENCES `Application` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cronjobexecution_cronjob` FOREIGN KEY (`cron_job`) REFERENCES `CronJob` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cronjobexecution_dependency` FOREIGN KEY (`dependency_execution`) REFERENCES `CronJobExecution` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cronjobexecution_parent` FOREIGN KEY (`parent_execution`) REFERENCES `CronJobExecution` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `CronJobFunction` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cron_job` int unsigned NOT NULL,
  `name` varchar(50) NOT NULL COMMENT 'execute, setUp, or tearDown',
  `content_hash` varchar(64) DEFAULT NULL COMMENT 'SHA256 hash of current content',
  `application` int unsigned DEFAULT NULL,
  `kyte_account` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `date_created` int unsigned NOT NULL,
  `modified_by` int DEFAULT NULL,
  `date_modified` int unsigned DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` int unsigned DEFAULT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_job_function` (`cron_job`,`name`,`deleted`),
  KEY `idx_cron_job` (`cron_job`),
  KEY `idx_name` (`name`),
  KEY `idx_content_hash` (`content_hash`),
  KEY `idx_application` (`application`),
  KEY `idx_account` (`kyte_account`),
  KEY `idx_deleted` (`deleted`),
  CONSTRAINT `CronJobFunction_ibfk_1` FOREIGN KEY (`cron_job`) REFERENCES `CronJob` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `CronJobFunctionContent` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `content_hash` varchar(64) NOT NULL COMMENT 'SHA256 hash',
  `content` longblob NOT NULL COMMENT 'Compressed function body (bzip2)',
  `reference_count` int unsigned DEFAULT '0' COMMENT 'Number of versions using this content',
  `created_by` bigint unsigned DEFAULT NULL,
  `date_created` bigint unsigned NOT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` int unsigned DEFAULT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `content_hash` (`content_hash`),
  KEY `idx_hash` (`content_hash`),
  KEY `idx_ref_count` (`reference_count`),
  KEY `idx_deleted` (`deleted`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `CronJobFunctionVersion` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cron_job_function` int unsigned NOT NULL,
  `version_number` int unsigned NOT NULL,
  `content_hash` varchar(64) NOT NULL COMMENT 'FK to CronJobFunctionContent',
  `is_current` tinyint(1) DEFAULT '0',
  `change_description` text COMMENT 'What changed in this version',
  `diff_json` longtext COMMENT 'JSON-encoded line-by-line diff from previous version',
  `kyte_account` int NOT NULL,
  `created_by` int DEFAULT NULL,
  `date_created` int unsigned NOT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` int unsigned DEFAULT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_function_version` (`cron_job_function`,`version_number`),
  KEY `idx_function` (`cron_job_function`),
  KEY `idx_version` (`version_number`),
  KEY `idx_content_hash` (`content_hash`),
  KEY `idx_current` (`is_current`),
  KEY `idx_deleted` (`deleted`),
  KEY `idx_account` (`kyte_account`),
  CONSTRAINT `CronJobFunctionVersion_ibfk_1` FOREIGN KEY (`cron_job_function`) REFERENCES `CronJobFunction` (`id`) ON DELETE CASCADE,
  CONSTRAINT `CronJobFunctionVersion_ibfk_2` FOREIGN KEY (`content_hash`) REFERENCES `CronJobFunctionContent` (`content_hash`) ON DELETE RESTRICT,
  CONSTRAINT `CronJobFunctionVersion_ibfk_3` FOREIGN KEY (`kyte_account`) REFERENCES `KyteAccount` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `AIErrorAnalysis` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `error_id` int NOT NULL COMMENT 'FK to KyteError.id',
  `error_signature` varchar(64) NOT NULL COMMENT 'SHA256 hash: controller+function+error_msg+file+line',
  `is_fixable` tinyint unsigned DEFAULT '0' COMMENT 'AI determined if fixable',
  `fixable_confidence` decimal(5,2) DEFAULT NULL COMMENT 'AI confidence 0.00-100.00',
  `controller_id` int DEFAULT NULL COMMENT 'FK to Controller.id',
  `controller_name` varchar(255) DEFAULT NULL,
  `function_id` int DEFAULT NULL COMMENT 'FK to Function.id',
  `function_name` varchar(255) DEFAULT NULL,
  `function_type` varchar(50) DEFAULT NULL COMMENT 'hook_init, hook_preprocess, etc.',
  `analysis_stage` enum('pending','classifying','analyzing','generating_fix','validating','completed','failed') DEFAULT 'pending',
  `ai_diagnosis` text COMMENT 'AI explanation of the problem',
  `ai_suggested_fix` longtext COMMENT 'AI proposed code fix',
  `fix_confidence` decimal(5,2) DEFAULT NULL COMMENT 'Fix confidence 0.00-100.00',
  `fix_rationale` text COMMENT 'AI explanation of the fix',
  `context_snapshot` longtext COMMENT 'JSON: all controller functions, models, request data',
  `analysis_status` enum('queued','processing','completed','failed') DEFAULT 'queued',
  `queued_at` bigint unsigned NOT NULL,
  `processing_started_at` bigint unsigned DEFAULT NULL,
  `processing_completed_at` bigint unsigned DEFAULT NULL,
  `retry_count` int unsigned DEFAULT '0',
  `last_error` text,
  `fix_status` enum('suggested','applied_manual','applied_auto','rejected','failed_validation','caused_error') DEFAULT 'suggested',
  `applied_at` bigint unsigned DEFAULT NULL COMMENT 'Unix timestamp when fix was applied',
  `applied_by` int unsigned DEFAULT NULL COMMENT 'User who applied (NULL if auto)',
  `applied_function_version` int DEFAULT NULL COMMENT 'FK to KyteFunctionVersion.id created',
  `syntax_valid` tinyint unsigned DEFAULT NULL COMMENT 'PHP syntax check result',
  `syntax_error` text COMMENT 'Syntax validation error if any',
  `attempt_number` int unsigned DEFAULT '1' COMMENT 'Retry attempt for this error signature',
  `previous_analysis_id` bigint unsigned DEFAULT NULL COMMENT 'FK to parent analysis if retry',
  `caused_new_error` tinyint unsigned DEFAULT '0' COMMENT 'Did this fix cause a new error?',
  `new_error_id` int DEFAULT NULL COMMENT 'FK to new KyteError if caused',
  `bedrock_request_id` varchar(255) DEFAULT NULL,
  `bedrock_input_tokens` int unsigned DEFAULT NULL,
  `bedrock_output_tokens` int unsigned DEFAULT NULL,
  `estimated_cost_usd` decimal(10,4) DEFAULT NULL,
  `processing_time_ms` int unsigned DEFAULT NULL COMMENT 'Total analysis time',
  `application` int DEFAULT NULL COMMENT 'FK to Application',
  `kyte_account` int NOT NULL,
  `created_by` int DEFAULT NULL,
  `date_created` bigint unsigned NOT NULL,
  `modified_by` int DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_error_analysis` (`error_id`,`deleted`),
  KEY `idx_error_id` (`error_id`),
  KEY `idx_error_signature` (`error_signature`),
  KEY `idx_controller_function` (`controller_id`,`function_id`),
  KEY `idx_fix_status` (`fix_status`),
  KEY `idx_attempt_number` (`attempt_number`),
  KEY `idx_analysis_stage` (`analysis_stage`),
  KEY `idx_analysis_status` (`analysis_status`,`queued_at`),
  KEY `idx_application` (`application`),
  KEY `idx_account` (`kyte_account`),
  KEY `idx_date_created` (`date_created`),
  KEY `idx_deleted` (`deleted`),
  KEY `function_id` (`function_id`),
  KEY `previous_analysis_id` (`previous_analysis_id`),
  KEY `new_error_id` (`new_error_id`),
  KEY `applied_function_version` (`applied_function_version`),
  CONSTRAINT `AIErrorAnalysis_ibfk_1` FOREIGN KEY (`error_id`) REFERENCES `KyteError` (`id`) ON DELETE CASCADE,
  CONSTRAINT `AIErrorAnalysis_ibfk_2` FOREIGN KEY (`controller_id`) REFERENCES `Controller` (`id`) ON DELETE SET NULL,
  CONSTRAINT `AIErrorAnalysis_ibfk_3` FOREIGN KEY (`function_id`) REFERENCES `Function` (`id`) ON DELETE SET NULL,
  CONSTRAINT `AIErrorAnalysis_ibfk_4` FOREIGN KEY (`previous_analysis_id`) REFERENCES `AIErrorAnalysis` (`id`) ON DELETE SET NULL,
  CONSTRAINT `AIErrorAnalysis_ibfk_5` FOREIGN KEY (`new_error_id`) REFERENCES `KyteError` (`id`) ON DELETE SET NULL,
  CONSTRAINT `AIErrorAnalysis_ibfk_6` FOREIGN KEY (`applied_function_version`) REFERENCES `KyteFunctionVersion` (`id`) ON DELETE SET NULL,
  CONSTRAINT `AIErrorAnalysis_ibfk_7` FOREIGN KEY (`application`) REFERENCES `Application` (`id`) ON DELETE CASCADE,
  CONSTRAINT `AIErrorAnalysis_ibfk_8` FOREIGN KEY (`kyte_account`) REFERENCES `KyteAccount` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `AIErrorCorrectionConfig` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `application` int NOT NULL COMMENT 'FK to Application',
  `enabled` tinyint unsigned DEFAULT '0' COMMENT 'Master enable/disable',
  `auto_fix_enabled` tinyint unsigned DEFAULT '0' COMMENT 'Auto-apply fixes without user approval',
  `auto_fix_min_confidence` decimal(5,2) DEFAULT '90.00' COMMENT 'Minimum confidence for auto-fix (0-100)',
  `max_analyses_per_hour` int unsigned DEFAULT '10' COMMENT 'Max analyses per hour',
  `max_analyses_per_day` int unsigned DEFAULT '50' COMMENT 'Max analyses per day',
  `max_monthly_cost_usd` decimal(10,2) DEFAULT '100.00' COMMENT 'Budget cap',
  `cooldown_minutes` int unsigned DEFAULT '30' COMMENT 'Minutes between analyses of same signature',
  `max_fix_attempts` int unsigned DEFAULT '5' COMMENT 'Max attempts before disabling',
  `loop_detection_window_minutes` int unsigned DEFAULT '60' COMMENT 'Time window for loop detection',
  `auto_disable_on_loop` tinyint unsigned DEFAULT '1' COMMENT 'Disable auto-fix if loop detected',
  `analysis_frequency_minutes` int unsigned DEFAULT '5' COMMENT 'How often cron runs (1-60)',
  `batch_size` int unsigned DEFAULT '10' COMMENT 'Max analyses per cron run',
  `max_concurrent_bedrock_calls` int unsigned DEFAULT '3' COMMENT 'Max parallel API calls',
  `include_warnings` tinyint unsigned DEFAULT '0' COMMENT 'Analyze warnings (not just errors/critical)',
  `include_model_definitions` tinyint unsigned DEFAULT '1' COMMENT 'Include model schemas in context',
  `include_request_data` tinyint unsigned DEFAULT '1' COMMENT 'Include request data in context',
  `include_framework_docs` tinyint unsigned DEFAULT '1' COMMENT 'Include ModelController docs',
  `notify_on_suggestion` tinyint unsigned DEFAULT '0' COMMENT 'FUTURE: Notify when AI suggests fix',
  `notify_on_auto_fix` tinyint unsigned DEFAULT '1' COMMENT 'FUTURE: Notify when auto-fix applied',
  `notify_on_loop_detection` tinyint unsigned DEFAULT '1' COMMENT 'FUTURE: Notify when loop detected',
  `notification_email` varchar(255) DEFAULT NULL COMMENT 'FUTURE: Email for notifications',
  `notification_slack_webhook` varchar(512) DEFAULT NULL COMMENT 'FUTURE: Slack webhook override',
  `total_analyses` int unsigned DEFAULT '0',
  `total_fixes_applied` int unsigned DEFAULT '0',
  `total_successful_fixes` int unsigned DEFAULT '0',
  `total_failed_fixes` int unsigned DEFAULT '0',
  `total_cost_usd` decimal(10,2) DEFAULT '0.00',
  `last_analysis_date` bigint unsigned DEFAULT NULL,
  `kyte_account` int NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `date_created` bigint unsigned NOT NULL,
  `modified_by` int unsigned DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int unsigned DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_app_config` (`application`,`deleted`),
  KEY `idx_application` (`application`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_account` (`kyte_account`),
  KEY `idx_deleted` (`deleted`),
  CONSTRAINT `AIErrorCorrectionConfig_ibfk_1` FOREIGN KEY (`application`) REFERENCES `Application` (`id`) ON DELETE CASCADE,
  CONSTRAINT `AIErrorCorrectionConfig_ibfk_2` FOREIGN KEY (`kyte_account`) REFERENCES `KyteAccount` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `AIErrorDeduplication` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `error_signature` varchar(64) NOT NULL COMMENT 'SHA256 hash',
  `controller_name` varchar(255) NOT NULL,
  `function_name` varchar(255) DEFAULT NULL,
  `error_message` text NOT NULL,
  `error_file` varchar(255) NOT NULL,
  `error_line` int unsigned NOT NULL,
  `first_seen` bigint unsigned NOT NULL,
  `last_seen` bigint unsigned NOT NULL,
  `last_analyzed` bigint unsigned DEFAULT NULL,
  `occurrence_count` int unsigned DEFAULT '1',
  `analysis_count` int unsigned DEFAULT '0',
  `is_resolved` tinyint unsigned DEFAULT '0',
  `resolved_at` bigint unsigned DEFAULT NULL,
  `resolved_by` int unsigned DEFAULT NULL,
  `application` int DEFAULT NULL,
  `kyte_account` int NOT NULL,
  `deleted` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_signature_app` (`error_signature`,`application`,`deleted`),
  KEY `idx_last_analyzed` (`last_analyzed`),
  KEY `idx_is_resolved` (`is_resolved`),
  KEY `idx_application` (`application`),
  KEY `idx_account` (`kyte_account`),
  KEY `idx_deleted` (`deleted`),
  CONSTRAINT `AIErrorDeduplication_ibfk_1` FOREIGN KEY (`application`) REFERENCES `Application` (`id`) ON DELETE CASCADE,
  CONSTRAINT `AIErrorDeduplication_ibfk_2` FOREIGN KEY (`kyte_account`) REFERENCES `KyteAccount` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteActivityLog` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `account_id` bigint unsigned DEFAULT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `application_id` bigint unsigned DEFAULT NULL,
  `application_name` varchar(255) DEFAULT NULL,
  `action` varchar(20) DEFAULT NULL COMMENT 'GET, POST, PUT, DELETE, LOGIN, LOGOUT, LOGIN_FAIL',
  `model_name` varchar(255) DEFAULT NULL,
  `record_id` bigint unsigned DEFAULT NULL,
  `field` varchar(255) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL,
  `request_data` longtext COMMENT 'JSON request payload (sensitive fields redacted)',
  `changes` longtext COMMENT 'JSON diff of old vs new values (PUT only)',
  `response_code` int DEFAULT NULL,
  `response_status` varchar(20) DEFAULT NULL,
  `error_message` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `session_token` varchar(255) DEFAULT NULL COMMENT 'Masked - shows only last 8 chars',
  `request_uri` varchar(2048) DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `severity` varchar(20) DEFAULT 'info' COMMENT 'info, warning, critical',
  `event_category` varchar(50) DEFAULT NULL COMMENT 'auth, data, config, system',
  `duration_ms` int DEFAULT NULL,
  `kyte_account` bigint unsigned DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `date_created` int DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `date_modified` int DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` int DEFAULT NULL,
  `deleted` int unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_account_date` (`kyte_account`,`date_created`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_model_action` (`model_name`,`action`),
  KEY `idx_application_id` (`application_id`),
  KEY `idx_severity` (`severity`),
  KEY `idx_event_category` (`event_category`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteRefreshToken` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` varchar(64) NOT NULL COMMENT 'sha256 hex of the raw refresh token',
  `token_prefix` varchar(32) NOT NULL COMMENT 'First chars of the raw token (kref_v1_...) for identification',
  `token_family` varchar(64) NOT NULL COMMENT 'Hex uuid shared by every token in a rotation chain',
  `user` bigint unsigned NOT NULL,
  `application` bigint unsigned DEFAULT NULL,
  `expires_at` bigint unsigned NOT NULL DEFAULT '0',
  `family_started_at` bigint unsigned NOT NULL DEFAULT '0' COMMENT 'Unix epoch when this token family was born at /jwt/login. Copied forward on rotation; anchors the absolute-cap clock.',
  `last_used_at` bigint unsigned NOT NULL DEFAULT '0',
  `last_used_ip` varchar(45) DEFAULT NULL,
  `revoked_at` bigint unsigned NOT NULL DEFAULT '0',
  `revoked_reason` varchar(64) DEFAULT NULL COMMENT 'rotated | reuse_detected | logout | admin_revoke | expired',
  `rotated_to` bigint unsigned NOT NULL DEFAULT '0' COMMENT 'Successor token id when rotated; 0 while active',
  `kyte_account` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` bigint unsigned DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_token_hash` (`token_hash`),
  KEY `idx_token_family` (`token_family`),
  KEY `idx_user` (`user`),
  KEY `idx_account_expires` (`kyte_account`,`expires_at`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteMCPToken` (
  `token_hash` varchar(64) NOT NULL,
  `token_prefix` varchar(16) NOT NULL,
  `name` varchar(255) NOT NULL,
  `application` int unsigned DEFAULT NULL,
  `scopes` varchar(255) NOT NULL,
  `expires_at` bigint unsigned NOT NULL DEFAULT '0',
  `last_used_at` bigint unsigned DEFAULT '0',
  `last_used_ip` varchar(45) DEFAULT NULL,
  `ip_allowlist` text,
  `revoked_at` bigint unsigned DEFAULT '0',
  `kyte_account` int unsigned NOT NULL,
  `created_by` int DEFAULT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` int DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` int DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` int unsigned DEFAULT '0',
  `id` int NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `KyteMCPSession` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(36) NOT NULL,
  `payload` longtext NOT NULL,
  `last_activity` bigint unsigned NOT NULL DEFAULT '0',
  `kyte_account` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `date_created` bigint unsigned DEFAULT NULL,
  `modified_by` bigint unsigned DEFAULT NULL,
  `date_modified` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `date_deleted` bigint unsigned DEFAULT NULL,
  `deleted` tinyint unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_session_id` (`session_id`),
  KEY `idx_account` (`kyte_account`),
  KEY `idx_account_session` (`kyte_account`,`session_id`),
  KEY `idx_last_activity` (`last_activity`)
) ENGINE=InnoDB;

-- ============ 2. ADD columns to pre-existing tables (defs from live 4.8.1) ============
ALTER TABLE `Application` ADD COLUMN IF NOT EXISTS `auth_mode` varchar(16) NOT NULL DEFAULT 'hmac';
ALTER TABLE `Application` ADD COLUMN IF NOT EXISTS `language` varchar(5) DEFAULT NULL COMMENT 'Application language preference: en, ja, es, ko (NULL = use account default)';
ALTER TABLE `Controller` ADD COLUMN IF NOT EXISTS `sensitive` tinyint unsigned NOT NULL DEFAULT '0';
ALTER TABLE `DataModel` ADD COLUMN IF NOT EXISTS `sensitive` tinyint unsigned NOT NULL DEFAULT '0';
ALTER TABLE `KytePage` ADD COLUMN IF NOT EXISTS `sensitive` tinyint unsigned NOT NULL DEFAULT '0';
ALTER TABLE `ModelAttribute` ADD COLUMN IF NOT EXISTS `sensitive` tinyint unsigned NOT NULL DEFAULT '0';
ALTER TABLE `KyteAccount` ADD COLUMN IF NOT EXISTS `default_language` varchar(5) DEFAULT 'en' COMMENT 'Account default language: en, ja, es, ko';
ALTER TABLE `KyteUser` ADD COLUMN IF NOT EXISTS `language` varchar(5) DEFAULT NULL COMMENT 'User language preference: en, ja, es, ko (NULL = auto-detect)';
ALTER TABLE `KyteScriptAssignment` ADD COLUMN IF NOT EXISTS `global_scope` tinyint unsigned DEFAULT '0';
ALTER TABLE `KyteError` ADD COLUMN IF NOT EXISTS `log_level` enum('debug','info','warning','error','critical') NOT NULL DEFAULT 'error';
ALTER TABLE `KyteError` ADD COLUMN IF NOT EXISTS `log_type` enum('system','application') NOT NULL DEFAULT 'system';
ALTER TABLE `KyteError` ADD COLUMN IF NOT EXISTS `context` mediumtext COMMENT 'JSON-encoded structured context data';
ALTER TABLE `KyteError` ADD COLUMN IF NOT EXISTS `request_id` varchar(64) DEFAULT NULL;
ALTER TABLE `KyteError` ADD COLUMN IF NOT EXISTS `trace` longtext;
ALTER TABLE `KyteError` ADD COLUMN IF NOT EXISTS `source` enum('error_handler','exception_handler','logger','output_buffer') NOT NULL DEFAULT 'error_handler';

-- ============ 3. DROP obfuscation columns (4.8.0) ============
ALTER TABLE `KytePageData` DROP COLUMN IF EXISTS `javascript_obfuscated`;
ALTER TABLE `KyteSectionTemplate` DROP COLUMN IF EXISTS `javascript_obfuscated`;
ALTER TABLE `KyteSectionTemplate` DROP COLUMN IF EXISTS `obfuscate_js`;
ALTER TABLE `KyteScript` DROP COLUMN IF EXISTS `content_js_obfuscated`;
ALTER TABLE `KyteScript` DROP COLUMN IF EXISTS `obfuscate_js`;
ALTER TABLE `KytePage` DROP COLUMN IF EXISTS `obfuscate_js`;
ALTER TABLE `Application` DROP COLUMN IF EXISTS `kyte_connect_obfuscated`;
ALTER TABLE `Application` DROP COLUMN IF EXISTS `obfuscate_kyte_connect`;
ALTER TABLE `KytePageVersionContent` DROP COLUMN IF EXISTS `javascript_obfuscated`;
ALTER TABLE `KyteScriptVersionContent` DROP COLUMN IF EXISTS `content_js_obfuscated`;
ALTER TABLE `KytePageVersion` DROP COLUMN IF EXISTS `obfuscate_js`;
ALTER TABLE `KyteScriptVersion` DROP COLUMN IF EXISTS `obfuscate_js`;
