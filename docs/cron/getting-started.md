# Getting Started with Kyte Cron

This guide will help you set up and run your first cron job with Kyte.

## Prerequisites

- Kyte PHP framework installed
- MySQL/MariaDB database
- PHP 7.4 or higher
- Composer

## Installation

### Step 1: Run Database Migration

Create the required cron tables:

```bash
mysql -u [user] -p [database] < migrations/cron-system.sql
```

This creates four tables:
- `CronJob` - Job definitions and configuration
- `CronJobExecution` - Execution history and tracking
- `KyteCronJobVersion` - Version control metadata
- `KyteCronJobVersionContent` - Deduplicated code storage

### Step 2: Install Dependencies

Update Composer to install the cron expression library:

```bash
composer update
```

This installs `dragonmantank/cron-expression` for parsing cron schedules.

### Step 3: Verify Configuration

Ensure your `config/config.php` has database credentials:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database');
define('DB_USER', 'your_user');
define('DB_PASSWORD', 'your_password');
```

## Creating Your First Cron Job

### Write the Job Class

Create a file for your job class:

```php
<?php
class DailyReportJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        $this->log("Starting daily report generation...");

        // Your job logic here
        $reports = $this->generateReports();

        $this->log("Generated " . count($reports) . " reports");

        return "Daily report completed successfully";
    }

    private function generateReports() {
        // Example: Query data and process
        $data = []; // Fetch your data
        return $data;
    }
}
```

### Insert the Job into Database

Using the CronJobManager (recommended):

```php
use Kyte\Cron\CronJobManager;

$manager = new CronJobManager();

$job = $manager->createJob([
    'name' => 'Daily Report Generator',
    'description' => 'Generates daily sales reports',
    'code' => file_get_contents('path/to/DailyReportJob.php'),
    'schedule_type' => 'daily',
    'time_of_day' => '02:00:00',
    'timezone' => 'America/New_York',
    'timeout_seconds' => 600,
    'max_retries' => 3,
    'retry_strategy' => 'exponential',
    'enabled' => 1,
    'kyte_account' => 1,
    'application' => 'your-app-id'
]);

echo "Job created with ID: {$job->id}\n";
```

Or directly via SQL:

```sql
INSERT INTO CronJob (
    name, description, code,
    schedule_type, time_of_day, timezone,
    timeout_seconds, max_retries, retry_strategy,
    enabled, kyte_account, application,
    date_created
) VALUES (
    'Daily Report Generator',
    'Generates daily sales reports',
    -- Code is bzip2 compressed
    COMPRESS('<?php class DailyReportJob extends \\Kyte\\Core\\CronJobBase { ... }'),
    'daily',
    '02:00:00',
    'America/New_York',
    600,  -- 10 minutes
    3,    -- Max retries
    'exponential',
    1,    -- Enabled
    1,    -- Account ID
    'your-app-id',
    UNIX_TIMESTAMP()
);
```

## Running the Cron Worker

### Start the Worker Daemon

Open a terminal and start the worker:

```bash
php bin/cron-worker.php
```

Expected output:

```
============================================
Kyte Cron Worker Daemon
============================================
Base Path:  /path/to/kyte-php
Config:     /path/to/config/config.php
Database:   localhost/your_database
PHP Version: 8.1.0
Memory Limit: 128M
============================================

[2025-12-29 10:30:00] Kyte Cron Worker started
Server ID: server1.example.com:12345
Lease Duration: 300s
Poll Interval: 10s
---
```

### Worker Behavior

The worker will:
1. Poll for pending jobs every 10 seconds
2. Acquire lease-based locks on jobs
3. Execute job code in isolated context
4. Capture output, metrics, and errors
5. Schedule next execution automatically
6. Release locks when complete

### Stop the Worker

Press `Ctrl+C` for graceful shutdown:

```
^C
[2025-12-29 10:35:00] Received shutdown signal (2)
[2025-12-29 10:35:00] Waiting for active job to complete (max 30s)...
[2025-12-29 10:35:05] Active job completed successfully

[Worker statistics printed]

[2025-12-29 10:35:05] Worker stopped
```

## Monitoring Job Execution

### Check Execution Status

View recent executions:

```sql
SELECT
    e.id,
    e.status,
    e.scheduled_time,
    e.started_at,
    e.completed_at,
    e.duration_ms,
    e.exit_code
FROM CronJobExecution e
JOIN CronJob j ON e.cron_job = j.id
WHERE j.name = 'Daily Report Generator'
ORDER BY e.date_created DESC
LIMIT 10;
```

### View Job Output

```sql
SELECT output, error
FROM CronJobExecution
WHERE id = [execution_id];
```

### Check Next Run Time

```sql
SELECT name, next_run_time, FROM_UNIXTIME(next_run_time) as next_run_formatted
FROM CronJob
WHERE name = 'Daily Report Generator';
```

## Schedule Types

Kyte supports five schedule types:

### 1. Cron Expressions

Standard cron syntax for complex patterns:

```sql
schedule_type = 'cron'
cron_expression = '0 */6 * * *'  -- Every 6 hours
```

### 2. Interval

Run at fixed intervals:

```sql
schedule_type = 'interval'
interval_seconds = 300  -- Every 5 minutes
```

### 3. Daily

Run at specific time each day:

```sql
schedule_type = 'daily'
time_of_day = '02:00:00'
timezone = 'America/New_York'
```

### 4. Weekly

Run on specific day of week:

```sql
schedule_type = 'weekly'
day_of_week = 1  -- Monday
time_of_day = '08:00:00'
timezone = 'UTC'
```

### 5. Monthly

Run on specific day of month:

```sql
schedule_type = 'monthly'
day_of_month = 1  -- 1st of month
time_of_day = '03:00:00'
timezone = 'UTC'
```

## Common Job Patterns

### Simple Data Sync

```php
class DataSyncJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        $api = new ExternalAPI();
        $data = $api->fetchLatestData();

        foreach ($data as $record) {
            $this->saveRecord($record);
        }

        return "Synced " . count($data) . " records";
    }
}
```

### Report Generation with Email

```php
class WeeklyReportJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        $report = $this->generateWeeklyReport();
        $this->emailReport($report);

        return "Report sent to " . count($this->recipients) . " recipients";
    }
}
```

### Database Cleanup

```php
class CleanupJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        $cutoffDate = time() - (90 * 24 * 60 * 60); // 90 days ago

        $deleted = DBI::prepared_query(
            "DELETE FROM logs WHERE date_created < ?",
            'i',
            [$cutoffDate]
        );

        return "Deleted " . DBI::affected_rows() . " old records";
    }
}
```

## Next Steps

- **[Job Development Guide](job-development.md)** - Learn advanced job patterns
- **[Scheduling Guide](scheduling.md)** - Master schedule types and dependencies
- **[Execution Guide](execution.md)** - Understand locking, retries, and notifications
- **[Version Control](version-control.md)** - Manage job versions and rollbacks
- **[API Reference](api-reference.md)** - Use REST API endpoints
- **[Web Interface](web-interface.md)** - Use the frontend UI
- **[Testing Guide](testing.md)** - Test your cron jobs

## Troubleshooting

### Worker Not Starting

**Issue**: Worker exits immediately

**Check**:
- Database connection configured correctly
- Tables exist (run migration)
- PHP version >= 7.4

### Jobs Not Executing

**Issue**: Worker runs but jobs never execute

**Check**:
```sql
SELECT enabled, next_run_time, FROM_UNIXTIME(next_run_time)
FROM CronJob
WHERE name = 'Your Job';
```

Ensure:
- `enabled = 1`
- `next_run_time` is in the past

### Class Not Found Errors

**Issue**: "Class not found" when job executes

**Solution**:
- Run `composer dump-autoload`
- Verify job code includes full class definition
- Check namespace declarations

## Production Deployment

### Run Worker as Service

Create a systemd service file:

```ini
[Unit]
Description=Kyte Cron Worker
After=mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/kyte-php
ExecStart=/usr/bin/php /var/www/kyte-php/bin/cron-worker.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl enable kyte-cron-worker
sudo systemctl start kyte-cron-worker
```

### Multiple Workers

For high availability, run multiple worker processes across different servers. The lease-based locking ensures only one worker executes each job instance.

### Monitoring

Monitor worker health:
- Check process is running
- Watch for repeated errors in logs
- Monitor execution success rates
- Alert on jobs in dead letter queue

## Support

For detailed information on specific features, see the related documentation pages linked above.
