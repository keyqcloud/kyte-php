# Cron System Testing Guide

This guide walks you through testing the Kyte cron system to verify the implementation is working correctly.

## Prerequisites

1. **Database Migration**: Run the migration to create cron tables
   ```bash
   mysql -u [user] -p [database] < migrations/cron-system.sql
   ```

2. **Install Dependencies**: Update composer to install cron-expression library
   ```bash
   composer update
   ```

3. **Configuration**: Ensure your `config/config.php` has database credentials defined

## Testing Workflow

### Step 1: Create a Test Job

Run the test script to create a simple test job:

```bash
php bin/test-cron.php create
```

This will:
- Create a job named "Test Job" in the database
- Schedule it to run every 60 seconds
- Store the compressed test job code
- Enable the job for execution

Expected output:
```
✓ Test job created with ID: 1

Job Details:
  Name: Test Job
  Schedule: Every 60 seconds (interval)
  Timeout: 300 seconds
  Max Retries: 3
  Status: Enabled
```

### Step 2: Start the Cron Worker

In a separate terminal window, start the worker daemon:

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
PHP Version: 7.4.x
Memory Limit: 128M
============================================

[2025-12-29 10:30:00] Kyte Cron Worker started
Server ID: hostname:12345
Lease Duration: 300s
Poll Interval: 10s
---
```

### Step 3: Wait for Execution

The worker polls every 10 seconds. Within 10-20 seconds, you should see:

```
[2025-12-29 10:30:10] Executing job #1 (Test Job) - execution #1
[2025-12-29 10:30:12] Job #1 completed in 2150ms
```

### Step 4: Check Job Status

In another terminal, check the execution results:

```bash
php bin/test-cron.php status
```

Expected output:
```
Job #1: Test Job
  Status: Enabled
  Created: 2025-12-29 10:30:00

  Recent Executions:
    Execution #1:
      Status: completed
      Scheduled: 2025-12-29 10:30:00
      Started: 2025-12-29 10:30:10
      Completed: 2025-12-29 10:30:12
      Duration: 2150ms
      Exit Code: 0
      Output:
        [2025-12-29 10:30:10] Setting up resources...
        [2025-12-29 10:30:10] TestCronJob started
        [2025-12-29 10:30:10] Processing data...
        [2025-12-29 10:30:12] Account ID: 1
        [2025-12-29 10:30:12] Execution ID: 1
        [2025-12-29 10:30:12] Scheduled time: 2025-12-29 10:30:00
        [2025-12-29 10:30:12] TestCronJob completed successfully
        [2025-12-29 10:30:12] Cleaning up resources...
        Job executed successfully at 2025-12-29 10:30:12
```

### Step 5: Verify Next Execution is Scheduled

Check status again after a minute to see the second execution:

```bash
php bin/test-cron.php status
```

You should see a second execution record scheduled 60 seconds after the first.

### Step 6: Test Worker Graceful Shutdown

Press `Ctrl+C` in the worker terminal. Expected output:

```
^C
[2025-12-29 10:31:00] Received shutdown signal (2)
[2025-12-29 10:31:00] Worker stopped
```

### Step 7: Clean Up Test Jobs

When done testing, remove the test jobs:

```bash
php bin/test-cron.php clean
```

## What to Verify

### ✅ Database Records

Check that tables are populated correctly:

```sql
-- Job record
SELECT * FROM CronJob WHERE name = 'Test Job';

-- Execution records
SELECT * FROM CronJobExecution WHERE cron_job = 1 ORDER BY scheduled_time;

-- Verify next execution is scheduled
SELECT * FROM CronJobExecution WHERE status = 'pending';
```

### ✅ Lease-Based Locking

Verify the locking mechanism:

```sql
-- While job is running, check lock fields
SELECT id, status, locked_by, locked_at, locked_until
FROM CronJobExecution
WHERE status = 'running';
```

Expected:
- `locked_by` = "hostname:pid"
- `locked_at` = current timestamp
- `locked_until` = locked_at + 300 (5-minute lease)

### ✅ Execution Metrics

Verify metrics are captured:

```sql
SELECT id, duration_ms, memory_peak_mb, exit_code
FROM CronJobExecution
WHERE status = 'completed';
```

Expected:
- `duration_ms` ≈ 2000 (2 seconds of sleep + overhead)
- `memory_peak_mb` > 0
- `exit_code` = 0

### ✅ Job Output Captured

Verify output is stored:

```sql
SELECT output FROM CronJobExecution WHERE id = 1;
```

Should contain all log messages from the test job.

## Advanced Testing

### Test Multiple Workers (Multi-Server Simulation)

Start two worker processes simultaneously:

```bash
# Terminal 1
php bin/cron-worker.php

# Terminal 2
php bin/cron-worker.php
```

Create a job that runs every 10 seconds. Verify that:
- Only ONE worker executes each instance
- No duplicate executions occur
- Both workers show competing for locks but only one succeeds

### Test Lease Expiration Recovery

1. Create a test job with short timeout (e.g., 30 seconds)
2. Modify test job code to sleep longer than timeout
3. Start worker and observe:
   - Job starts executing
   - Lease expires (5 minutes by default)
   - Worker marks it as "timeout" status
   - Next execution is scheduled

### Test Concurrent Execution Control

1. Create a job with `allow_concurrent = 0`
2. Make the job sleep for 90 seconds
3. Verify second execution is skipped with reason "Job already running"

## Troubleshooting

### Worker Not Picking Up Jobs

**Issue**: Worker starts but never executes jobs

**Check**:
```sql
-- Verify job is enabled
SELECT enabled FROM CronJob WHERE name = 'Test Job';

-- Check for pending executions
SELECT * FROM CronJobExecution WHERE status = 'pending';
```

**Solution**: Ensure `enabled = 1` and at least one execution record exists with status = 'pending'

### Jobs Not Executing at Expected Time

**Issue**: Jobs run but not at the scheduled interval

**Check**: Worker log output for scheduling messages

**Solution**: Verify the `calculateNextRun()` logic is working correctly

### Memory or Timeout Errors

**Issue**: Jobs fail with memory or timeout errors

**Check**:
```sql
SELECT error, stack_trace FROM CronJobExecution WHERE status = 'failed';
```

**Solution**:
- Increase `timeout_seconds` in job definition
- Increase PHP `memory_limit` in config
- Optimize job code to use less memory

### Composer Autoloader Errors

**Issue**: "Class not found" errors when worker runs

**Check**: Verify composer autoload paths in `bin/cron-worker.php`

**Solution**: Run `composer dump-autoload` to regenerate autoloader

## Validation Checklist

- [ ] Database tables created successfully
- [ ] Test job inserted into CronJob table
- [ ] Worker daemon starts without errors
- [ ] Worker picks up pending execution within 10-20 seconds
- [ ] Job code decompresses and executes correctly
- [ ] Output is captured and stored
- [ ] Metrics (duration, memory) are recorded
- [ ] Exit code is set correctly (0 for success)
- [ ] Next execution is automatically scheduled
- [ ] Worker responds to shutdown signal (Ctrl+C)
- [ ] Graceful shutdown works without orphaned processes
- [ ] Multiple executions occur at correct intervals
- [ ] Lease-based locking prevents duplicate execution
- [ ] Expired leases are cleaned up automatically

## Example Test Job Variations

### Job That Throws Exception

```php
class FailingTestJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        $this->log("About to fail...");
        throw new \Exception("Intentional test failure");
    }
}
```

Expected: Status = 'failed', error message stored, next execution scheduled

### Job With Custom Metadata

```php
class CustomMetadataJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        $metadata = $this->getJobMetadata();
        $this->log("Job name: " . $metadata['name']);
        $this->log("App ID: " . $metadata['appId']);
        return "Custom metadata accessed";
    }
}
```

### Job Using API Context

```php
class ApiContextJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        // Access models via API
        $model = new \Kyte\Core\Model(SomeModel);
        $records = $model->get();

        $this->log("Found " . count($records) . " records");
        return count($records);
    }
}
```

## Support

For issues or questions:
- Check CHANGELOG.md for known issues
- Review CronWorker error logs
- Examine CronJobExecution.error and stack_trace fields
- Enable verbose logging in worker if needed
