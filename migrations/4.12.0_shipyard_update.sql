-- =========================================================================
-- Kyte v4.12.0 - KyteShipyardUpdate (async Shipyard self-update) - KYTE-#201
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration.
--
-- Tracks Shipyard dashboard update requests + outcomes. The dashboard enqueues
-- a row (status='pending') via KyteShipyardUpdateController; the
-- ShipyardUpdateWorker cron job claims it (pending -> running) and performs the
-- download/extract/upload/CloudFront-invalidate out-of-band, so a Cloudflare
-- (~100s, non-enterprise) or ALB request timeout can't kill a long update.
-- Replaces the SNS -> kyte-lambda-update-shipyard path.
--
-- Idempotency: the controller refuses to enqueue a second row while one is
-- pending/running (request dedup); the cron worker runs with allow_concurrent=0
-- (lease lock) and claims rows with a guarded pending->running UPDATE
-- (execution dedup).
--
-- After this migration, register the worker:  php bin/register-shipyard-update-job.php
-- and set KYTE_SHIPYARD_S3 / KYTE_SHIPYARD_CF in config.php.
--
-- See src/Mvc/Model/KyteShipyardUpdate.php, src/Cron/ShipyardUpdateWorker.php.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `KyteShipyardUpdate` (
    `id`                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    `current_version`        VARCHAR(20)  DEFAULT NULL COMMENT 'Version the dashboard reported at request time',
    `requested_version`      VARCHAR(20)  DEFAULT NULL COMMENT 'Latest CDN version we intend to deploy',
    `deployed_version`       VARCHAR(20)  DEFAULT NULL COMMENT 'Version last successfully published',

    `status`                 VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending|running|complete|failed',
    `message`                TEXT         DEFAULT NULL COMMENT 'Result or error detail for the dashboard',

    `files_uploaded`         INT UNSIGNED NOT NULL DEFAULT 0,
    `files_failed`           INT UNSIGNED NOT NULL DEFAULT 0,
    `cloudfront_invalidated` TINYINT UNSIGNED NOT NULL DEFAULT 0,

    `started_at`             BIGINT UNSIGNED DEFAULT NULL,
    `finished_at`            BIGINT UNSIGNED DEFAULT NULL,

    `kyte_account`           BIGINT UNSIGNED NOT NULL,

    `created_by`             BIGINT UNSIGNED DEFAULT NULL,
    `date_created`           BIGINT UNSIGNED DEFAULT NULL,
    `modified_by`            BIGINT UNSIGNED DEFAULT NULL,
    `date_modified`          BIGINT UNSIGNED DEFAULT NULL,
    `deleted_by`             BIGINT UNSIGNED DEFAULT NULL,
    `date_deleted`           BIGINT UNSIGNED DEFAULT NULL,
    `deleted`                TINYINT UNSIGNED NOT NULL DEFAULT 0,

    KEY `idx_account`        (`kyte_account`),
    KEY `idx_status`         (`status`, `deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
