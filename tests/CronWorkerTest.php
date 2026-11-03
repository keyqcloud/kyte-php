<?php
namespace Kyte\Test;

use Kyte\Core\Api;
use Kyte\Core\DBI;
use Kyte\Cron\CronWorker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CronWorker scheduling + job-isolation coverage.
 *
 * Drives the worker's private cycle steps (scheduleDueJobs,
 * findPendingExecutions, createExecution, ...) directly via reflection against
 * the real test database, so the SQL itself is exercised. The CronJob /
 * CronJobExecution tables are recreated for every test.
 */
class CronWorkerTest extends TestCase
{
    private CronWorker $worker;

    /**
     * Production schema for the two tables (from
     * migrations/3.x-era_to_4.8.x_consolidated_upgrade.sql, FK constraints
     * dropped so the test needs no Application table). The builtin model
     * definitions can't be fed to DBI::createTable (no 'date' attrs).
     */
    private const DDL_CRONJOB = <<<'SQL'
CREATE TABLE `CronJob` (
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
  KEY `idx_app_account` (`application`,`kyte_account`)
) ENGINE=InnoDB
SQL;

    private const DDL_CRONJOBEXECUTION = <<<'SQL'
CREATE TABLE `CronJobExecution` (
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
  KEY `idx_deleted` (`deleted`)
) ENGINE=InnoDB
SQL;

    protected function setUp(): void
    {
        DBI::dbInit(KYTE_DB_USERNAME, KYTE_DB_PASSWORD, KYTE_DB_HOST, KYTE_DB_DATABASE, KYTE_DB_CHARSET, 'InnoDB');
        new Api(); // defines the framework constants (VERBOSE_LOG, ...)

        DBI::query("DROP TABLE IF EXISTS `CronJobExecution`");
        DBI::query("DROP TABLE IF EXISTS `CronJob`");
        DBI::query(self::DDL_CRONJOB);
        DBI::query(self::DDL_CRONJOBEXECUTION);

        $this->worker = new CronWorker();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** Invoke a private CronWorker method, swallowing its console output. */
    private function call(string $method, ...$args)
    {
        $m = new \ReflectionMethod(CronWorker::class, $method);
        $m->setAccessible(true);
        ob_start();
        try {
            return $m->invoke($this->worker, ...$args);
        } finally {
            ob_end_clean();
        }
    }

    private function insertJob(array $fields): int
    {
        $fields += [
            'name'                 => 'test-job',
            'enabled'              => 1,
            'deleted'              => 0,
            'in_dead_letter_queue' => 0,
            'timezone'             => 'UTC',
            'timeout_seconds'      => 60,
            'max_retries'          => 0,
            'allow_concurrent'     => 0,
            'notify_on_failure'    => 0,
            'notify_on_dead_letter' => 0,
            'consecutive_failures' => 0,
            'kyte_account'         => 1,
            'date_created'         => time(),
        ];
        $cols = array_keys($fields);
        $sql = 'INSERT INTO CronJob (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        DBI::prepared_query($sql, str_repeat('s', count($cols)), array_values($fields));
        return (int)DBI::insert_id();
    }

    private function getJob(int $id): array
    {
        return DBI::prepared_query("SELECT * FROM CronJob WHERE id = ?", 'i', [$id])[0];
    }

    private function executions(int $jobId, ?string $status = null): array
    {
        if ($status === null) {
            return DBI::prepared_query("SELECT * FROM CronJobExecution WHERE cron_job = ? ORDER BY id", 'i', [$jobId]);
        }
        return DBI::prepared_query(
            "SELECT * FROM CronJobExecution WHERE cron_job = ? AND status = ? ORDER BY id",
            'is',
            [$jobId, $status]
        );
    }

    // ------------------------------------------------------------------
    // #895 — non-interval jobs must get seeded
    // ------------------------------------------------------------------

    public static function scheduleTypes(): array
    {
        return [
            'cron'     => [['schedule_type' => 'cron', 'cron_expression' => '0 3 * * *'], true],
            'daily'    => [['schedule_type' => 'daily', 'time_of_day' => '03:00:00'], true],
            'weekly'   => [['schedule_type' => 'weekly', 'time_of_day' => '03:00:00', 'day_of_week' => 1], true],
            'monthly'  => [['schedule_type' => 'monthly', 'time_of_day' => '03:00:00', 'day_of_month' => 15], true],
            'interval' => [['schedule_type' => 'interval', 'interval_seconds' => 60], false],
        ];
    }

    #[DataProvider('scheduleTypes')]
    public function testEachScheduleTypeIsSeededExactlyOnce(array $schedule, bool $expectFuture): void
    {
        $jobId = $this->insertJob($schedule);
        $before = time();

        $this->call('scheduleDueJobs');

        $pending = $this->executions($jobId, 'pending');
        $this->assertCount(1, $pending, 'job should be seeded with exactly one pending execution');

        $next = (int)$pending[0]['next_run_time'];
        $this->assertSame($next, (int)$pending[0]['scheduled_time']);
        if ($expectFuture) {
            $this->assertGreaterThan($before, $next, 'non-interval jobs are seeded at their next (future) run time');
        } else {
            $this->assertLessThanOrEqual(time(), $next, 'first interval run is due immediately (unchanged)');
        }

        // Repeated worker loops must not add more rows.
        $this->call('scheduleDueJobs');
        $this->call('scheduleDueJobs');
        $this->assertCount(1, $this->executions($jobId), 'repeated loops must not duplicate the seeded execution');
    }

    public function testFutureExecutionIsOnlyPickedUpOnceDue(): void
    {
        $jobId = $this->insertJob(['schedule_type' => 'daily', 'time_of_day' => '03:00:00']);
        $this->call('scheduleDueJobs');

        $this->assertSame([], $this->call('findPendingExecutions'), 'future execution must not be picked up early');

        DBI::prepared_query("UPDATE CronJobExecution SET next_run_time = ? WHERE cron_job = ?", 'ii', [time() - 1, $jobId]);

        $due = $this->call('findPendingExecutions');
        $this->assertCount(1, $due);
        $this->assertSame($jobId, (int)$due[0]['cron_job']);
    }

    public function testCreateExecutionIsNoOpWhenJobAlreadyHasPendingExecution(): void
    {
        $jobId = $this->insertJob(['schedule_type' => 'daily', 'time_of_day' => '03:00:00']);
        $job = $this->getJob($jobId);

        $first = time() + 100;
        $this->assertTrue($this->call('createExecution', $job, $first));
        // e.g. completeExecution / cleanupExpiredLeases re-seeding while a pending row exists
        $this->assertFalse($this->call('createExecution', $job, $first + 100));

        $rows = $this->executions($jobId);
        $this->assertCount(1, $rows);
        $this->assertSame($first, (int)$rows[0]['next_run_time']);
    }

    public function testCreateExecutionHandlesNullAndSetApplication(): void
    {
        $a = $this->getJob($this->insertJob(['schedule_type' => 'interval', 'interval_seconds' => 60]));
        $b = $this->getJob($this->insertJob(['schedule_type' => 'interval', 'interval_seconds' => 60, 'application' => 7]));

        $this->assertTrue($this->call('createExecution', $a, time()));
        $this->assertTrue($this->call('createExecution', $b, time()));

        $this->assertNull($this->executions((int)$a['id'])[0]['application']);
        $this->assertSame(7, (int)$this->executions((int)$b['id'])[0]['application']);
    }

    public function testJobWithRunningExecutionIsNotSeeded(): void
    {
        $jobId = $this->insertJob(['schedule_type' => 'cron', 'cron_expression' => '*/5 * * * *']);
        DBI::prepared_query(
            "INSERT INTO CronJobExecution (cron_job, scheduled_time, next_run_time, status, kyte_account, date_created)
             VALUES (?, ?, ?, 'running', 1, ?)",
            'iiii',
            [$jobId, time(), time(), time()]
        );

        $this->call('scheduleDueJobs');

        $this->assertCount(0, $this->executions($jobId, 'pending'));
    }

    public function testDisabledDeletedAndDeadLetteredJobsAreNotSeeded(): void
    {
        $ids = [
            $this->insertJob(['schedule_type' => 'daily', 'time_of_day' => '03:00:00', 'enabled' => 0]),
            $this->insertJob(['schedule_type' => 'daily', 'time_of_day' => '03:00:00', 'deleted' => 1]),
            $this->insertJob(['schedule_type' => 'daily', 'time_of_day' => '03:00:00', 'in_dead_letter_queue' => 1]),
        ];

        $this->call('scheduleDueJobs');

        foreach ($ids as $id) {
            $this->assertCount(0, $this->executions($id), "job #{$id} must not be seeded");
        }
    }

    public function testCompletedJobIsReseededForItsNextRun(): void
    {
        // A finished (completed/failed/skipped/timeout) execution must not block
        // seeding the next one.
        $jobId = $this->insertJob(['schedule_type' => 'weekly', 'time_of_day' => '03:00:00', 'day_of_week' => 3]);
        DBI::prepared_query(
            "INSERT INTO CronJobExecution (cron_job, scheduled_time, next_run_time, status, kyte_account, date_created, completed_at)
             VALUES (?, ?, ?, 'completed', 1, ?, ?)",
            'iiiii',
            [$jobId, time() - 600, time() - 600, time() - 600, time() - 590]
        );

        $this->call('scheduleDueJobs');

        $pending = $this->executions($jobId, 'pending');
        $this->assertCount(1, $pending);
        $this->assertGreaterThan(time(), (int)$pending[0]['next_run_time']);
    }
}
