# Locking & Idempotency

Robust locking mechanisms, lock contention tracking, and tools for managing distributed workers in production.

## Locking Features

### 1. Heartbeat Mechanism

Long-running jobs can now extend their lease while executing to prevent premature timeouts.

**Usage in your job:**

```php
class LongRunningJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        for ($i = 0; $i < 1000; $i++) {
            // Process batch
            processItem($i);

            // Extend lease every 100 iterations
            if ($i % 100 == 0) {
                $this->heartbeat(); // Adds 5 minutes to lease
            }
        }
    }
}
```

**Benefits:**
- Prevents jobs from being marked as timed out while still running
- Allows jobs to run longer than the default 5-minute lease
- Automatic logging of heartbeat activity

### 2. Lock Contention Metrics

The worker now tracks and reports detailed locking statistics:

**Metrics Tracked:**
- Locks acquired - How many jobs this worker successfully started
- Locks missed - How many jobs another worker got first
- Contention rate - Percentage of lock attempts that failed
- Success rate - Percentage of jobs that completed successfully
- Leases expired - How many jobs timed out

**Example output on worker shutdown:**

```
============================================
Worker Statistics
============================================
Runtime:
  Cycles:             120

Job Execution:
  Jobs Executed:      15
  Jobs Completed:     14
  Jobs Failed:        1
  Jobs Skipped:       2
  Success Rate:       93.3%

Locking:
  Locks Acquired:     15
  Locks Missed:       8
  Leases Expired:     0
  Contention Rate:    34.8%
============================================
```

**Use Cases:**
- Identify if you have too many workers (high contention)
- Detect worker crashes (leases expired)
- Monitor job success rates
- Capacity planning

### 3. Graceful Worker Shutdown

Workers now wait for active jobs to complete before shutting down.

**How it works:**
1. Worker receives shutdown signal (SIGTERM or SIGINT/Ctrl+C)
2. Worker stops accepting new jobs
3. Worker waits up to 30 seconds for current job to finish
4. If job completes, worker exits cleanly
5. If job doesn't complete, worker warns but still exits

**Example:**

```
^C
[2025-12-29 14:30:00] Received shutdown signal (2)
[2025-12-29 14:30:00] Waiting for active job to complete (max 30s)...
  Job: #5 (Daily Cleanup)
  Execution: #123
[2025-12-29 14:30:05] Active job completed successfully

[Worker statistics printed here]

[2025-12-29 14:30:05] Worker stopped
```

**Configuration:**

The grace period is configurable in `CronWorker.php`:

```php
private $shutdownGracePeriod = 30; // Seconds to wait
```

### 4. Enhanced Stale Lock Detection

The worker now provides detailed information about expired leases:

**Enhancements:**
- Lists each expired lease with job details
- Shows how long the job was locked
- Warns if lease expired long ago (possible worker crash)
- Shows which worker had the lock
- Automatically schedules next execution after cleanup

**Example output:**

```
[2025-12-29 14:35:00] Found 2 expired lease(s):
  - Job #3 (Email Sender)
    Execution: #145
    Locked by: server1.example.com:12345
    Locked for: 00:08:15
    Expired 125s ago
    ⚠ WARNING: Lease expired long ago - possible worker crash

  - Job #7 (Report Generator)
    Execution: #146
    Locked by: server2.example.com:54321
    Locked for: 00:06:30
    Expired 45s ago
  ✓ Scheduled next execution for job #3
  ✓ Scheduled next execution for job #7
```

### 5. Lock Management Utility

New command-line tool for managing and debugging locks.

**Installation:**

```bash
chmod +x bin/cron-locks.php
```

**Commands:**

#### List All Locks

```bash
php bin/cron-locks.php list
```

Shows all currently held locks with expiration times.

#### Show Stale Locks

```bash
php bin/cron-locks.php stale --verbose
```

Identifies locks that have expired and checks if worker process is still running.

#### Clear Specific Lock

```bash
php bin/cron-locks.php clear 123
```

Manually marks execution #123 as timed out and releases the lock.

#### Clear All Locks

```bash
php bin/cron-locks.php clear-all --force
```

Clears all locks (use with caution - will affect running jobs).

#### Lock Statistics

```bash
php bin/cron-locks.php stats
```

Shows:
- Overall execution statistics (last 24 hours)
- Current stale lock count
- Jobs with frequent timeouts (last 7 days)
- Timeout rates

**Example output:**

```
Lock Statistics
===============

Last 24 Hours:
  Total Executions: 1,247
  Completed:        1,198
  Timeouts:         15
  Currently Locked: 3
  Timeout Rate:     1.20%

✓ No stale locks

Jobs with Frequent Timeouts (Last 7 Days):
  - Job #5 (Large Report): 12 timeouts
    Last timeout: 2025-12-29 10:15:30
  - Job #8 (Data Sync): 8 timeouts
    Last timeout: 2025-12-29 12:45:00

⚠ These jobs may need timeout adjustments or code optimization
```

### 6. Multi-Worker Testing Tool

New script for testing lock contention with multiple workers.

**Usage:**

```bash
php bin/test-multi-worker.php --workers=5 --duration=120 --cleanup
```

**Options:**
- `--workers=N` - Number of worker processes to spawn (default: 3)
- `--duration=N` - Test duration in seconds (default: 60)
- `--cleanup` - Remove test jobs after completion

**What it tests:**
1. Creates a test job that runs every 5 seconds
2. Spawns N worker processes
3. Monitors for specified duration
4. Analyzes results:
   - Verifies no duplicate executions (idempotency)
   - Calculates lock contention rate
   - Shows worker participation distribution
   - Reports any issues

**Example output:**

```
Multi-Worker Lock Contention Test
============================================
Workers:  5
Duration: 120s
============================================

Creating test job...
  Job ID: 42

Spawning 5 worker processes...
  Worker 0: PID 12345
  Worker 1: PID 12346
  Worker 2: PID 12347
  Worker 3: PID 12348
  Worker 4: PID 12349

Workers are running...
[14:30:10] Running... (110s remaining)
[14:30:20] Running... (100s remaining)
...

Test Results
============================================

Execution Analysis:
-------------------
  Total Executions:   24
  Completed:          24
  Failed:             0
  Skipped:            0
  Still Running:      0
  Avg Duration:       1,850ms

  ✓ No duplicate executions detected (idempotency verified)

  Workers Participated: 5
    - server1:12345
    - server1:12346
    - server1:12347
    - server1:12348
    - server1:12349

Lock Contention Analysis:
-------------------------
  Worker 0: 6 locks acquired
  Worker 1: 5 locks acquired
  Worker 2: 4 locks acquired
  Worker 3: 5 locks acquired
  Worker 4: 4 locks acquired

  Total Locks Acquired: 24
  Total Locks Missed:   96
  Contention Rate:      80.0%

  ⚠ High contention detected - locks are highly contested

Test complete!
```

## Common Scenarios

### Scenario 1: Worker Crash Recovery

**Problem:** A worker crashes while executing a job.

**What happens:**
1. Job's lease eventually expires (5 minutes by default)
2. Next worker cycle detects expired lease
3. Execution is marked as "timeout"
4. Detailed error message is stored
5. Next execution is automatically scheduled

**Manual recovery:**

```bash
# Check for stale locks
php bin/cron-locks.php stale

# Clear them if needed
php bin/cron-locks.php clear-all
```

### Scenario 2: Job Takes Too Long

**Problem:** Job consistently times out because it takes longer than 5 minutes.

**Solution 1 - Increase timeout:**

Update job definition:

```sql
UPDATE CronJob
SET timeout_seconds = 1800  -- 30 minutes
WHERE id = 5;
```

**Solution 2 - Add heartbeats:**

```php
class SlowJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        for ($batch = 0; $batch < 10; $batch++) {
            processBatch($batch);

            // Extend lease after each batch
            $this->heartbeat();
        }
    }
}
```

### Scenario 3: High Lock Contention

**Problem:** Lock contention rate is above 50%, indicating too many workers competing.

**Diagnosis:**

```bash
php bin/test-multi-worker.php --workers=5 --duration=60
```

**Solutions:**
1. **Reduce number of workers** - If contention is high but all jobs complete on time
2. **Increase job frequency** - If you have capacity, schedule jobs more frequently
3. **Split jobs** - Break large jobs into smaller, more frequent jobs

### Scenario 4: Debugging Stuck Job

**Problem:** A job appears to be running forever.

**Investigation:**

```bash
# List all current locks
php bin/cron-locks.php list --verbose

# Check if worker process is still alive (shown in verbose mode)
```

**Example output:**

```
Execution #156: Job #8 (Data Sync)
  Locked by:     server1:12345
  Locked at:     2025-12-29 14:00:00
  Locked for:    15m 30s
  Expires in:    -600s
  Status:        ⚠ STALE (expired 600s ago)
  ⚠ Worker process (PID 12345) is not running
```

**Fix:**

```bash
php bin/cron-locks.php clear 156
```

## Best Practices

### 1. Use Heartbeats Strategically

**Good:**
```php
// Extend lease at logical checkpoints
foreach ($batches as $i => $batch) {
    processBatch($batch);
    if ($i % 10 == 0) {
        $this->heartbeat();
    }
}
```

**Avoid:**
```php
// Don't heartbeat too frequently (overhead)
while ($data = fetchNext()) {
    process($data);
    $this->heartbeat(); // Called hundreds of times
}
```

### 2. Monitor Lock Statistics

Set up monitoring to alert when:
- Timeout rate exceeds 5%
- Contention rate exceeds 70%
- Stale locks remain for > 10 minutes

### 3. Graceful Shutdown in Production

Use proper signals when stopping workers:

```bash
# Good - graceful shutdown
sudo systemctl stop kyte-cron-worker

# Also good - manual graceful
kill -TERM <pid>

# Avoid - immediate kill
kill -9 <pid>  # Can leave stale locks
```

### 4. Regular Lock Audits

Run daily or weekly:

```bash
# Check for stale locks
php bin/cron-locks.php stale

# Review timeout statistics
php bin/cron-locks.php stats
```

### 5. Test Before Production

Always run multi-worker tests:

```bash
# Test with your production worker count
php bin/test-multi-worker.php --workers=10 --duration=300 --cleanup
```

Verify:
- ✓ No duplicate executions
- ✓ Reasonable contention rate (< 50%)
- ✓ Even distribution across workers

## Configuration Options

### Worker Configuration

In `CronWorker.php`:

```php
private $leaseDuration = 300;      // 5 minutes (increase for slow jobs)
private $pollInterval = 10;         // Check every 10 seconds
private $maxJobsPerCycle = 10;      // Max jobs to process per cycle
private $shutdownGracePeriod = 30;  // Wait time for active jobs
```

### Job Configuration

In database:

```sql
-- Per-job timeout
timeout_seconds INT DEFAULT 300

-- Allow multiple instances to run simultaneously
allow_concurrent TINYINT DEFAULT 0
```

## Troubleshooting

### Issue: High contention but low execution count

**Cause:** Too many workers for the job frequency

**Fix:** Reduce worker count or increase job frequency

### Issue: Frequent timeouts on specific jobs

**Cause:** Job takes longer than timeout allows

**Fix:** Increase `timeout_seconds` or optimize job code

### Issue: Stale locks accumulating

**Cause:** Workers crashing without cleanup

**Fix:**
1. Investigate why workers are crashing (check logs)
2. Set up monitoring/alerts for stale locks
3. Consider lower lease duration for faster recovery

### Issue: Jobs skipped due to concurrent execution

**Cause:** `allow_concurrent = 0` and previous execution still running

**Fix:**
1. Either increase job timeout so it completes faster
2. Or set `allow_concurrent = 1` if safe to do so
3. Or adjust schedule to run less frequently

## Summary

The locking system provides production-ready features with:
- ✅ Heartbeat mechanism for long-running jobs
- ✅ Comprehensive lock contention metrics
- ✅ Graceful worker shutdown
- ✅ Enhanced stale lock detection
- ✅ Lock management utility
- ✅ Multi-worker testing tools

The system can now safely run in distributed environments with multiple workers competing for jobs while maintaining idempotency guarantees.
# Retry & Failure Handling

Robust retry logic, dead letter queue management, and notification systems to handle job failures gracefully in production environments.

## Retry Features

### 1. Retry Logic with Multiple Strategies

Jobs now automatically retry on failure based on configurable strategies.

**Three Retry Strategies:**

#### Exponential Backoff (Default)
Retry delays double each time: 1min → 2min → 4min → 8min → 16min

```sql
UPDATE CronJob SET
    max_retries = 3,
    retry_strategy = 'exponential'
WHERE id = 5;
```

**Best for:** Transient failures (network issues, API rate limits)

#### Fixed Delay
Retry at consistent intervals

```sql
UPDATE CronJob SET
    max_retries = 5,
    retry_strategy = 'fixed',
    retry_delay_seconds = 120  -- 2 minutes
WHERE id = 5;
```

**Best for:** Predictable recovery times

#### Immediate
Retry without delay

```sql
UPDATE CronJob SET
    max_retries = 2,
    retry_strategy = 'immediate'
WHERE id = 5;
```

**Best for:** Quick fixes (temporary lock contention)

### 2. Dead Letter Queue (DLQ)

When a job exceeds its maximum retries, it's automatically moved to the dead letter queue.

**What happens:**
1. Job is disabled (`enabled = 0`)
2. `in_dead_letter_queue` flag set to 1
3. `dead_letter_reason` stores the final error
4. `dead_letter_since` timestamp recorded
5. Notification sent (if configured)

**Recovering from DLQ:**

```bash
# View DLQ jobs
php bin/test-retry.php dlq

# Recover specific job
php bin/test-retry.php recover 123
```

Or via SQL:

```sql
UPDATE CronJob SET
    in_dead_letter_queue = 0,
    dead_letter_reason = NULL,
    dead_letter_since = NULL,
    consecutive_failures = 0,
    enabled = 1
WHERE id = 123;
```

### 3. Consecutive Failure Tracking

The system tracks how many times in a row a job has failed.

**Behavior:**
- Increments on each failure
- Resets to 0 on first success
- Used for notification thresholds
- Visible in DLQ reports

**Example:**
```
Job fails → consecutive_failures = 1
Job fails → consecutive_failures = 2
Job fails → consecutive_failures = 3 → Notification sent
Job succeeds → consecutive_failures = 0
```

### 4. Slack Notifications

Rich formatted alerts sent to Slack when jobs fail repeatedly.

**Configuration:**

```sql
UPDATE CronJob SET
    notify_on_failure = 1,
    notify_after_failures = 3,  -- Alert after 3 consecutive failures
    notify_on_dead_letter = 1,  -- Alert when moved to DLQ
    slack_webhook = 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL'
WHERE id = 5;
```

**Notification Example:**

```
🔴 Cron Job Failure Alert

Job: Daily Report Generator (#5)
Execution: #1234
Consecutive Failures: 3
Error: Database connection timeout
Time: 2025-12-29 14:30:00
```

**DLQ Notification Example:**

```
💀 Cron Job Moved to Dead Letter Queue

Job: Email Sender (#8)
Execution: #4567
Max Retries: 3
Final Error: SMTP server unavailable
Time: 2025-12-29 15:45:00

Action Required: Job has been disabled. Review and re-enable manually.
```

### 5. Email Notifications

Plain text alerts via PHP mail() function.

**Configuration:**

```sql
UPDATE CronJob SET
    notify_on_failure = 1,
    notification_email = 'admin@example.com'
WHERE id = 5;
```

**Production Note:** For production, consider using a proper email service (AWS SES, SendGrid, etc.) instead of PHP's mail() function.

### 6. Automatic Retry Scheduling

The worker automatically creates retry execution records with calculated delays.

**Example Flow:**

```
1. Job fails (retry_count = 0)
   → Schedule retry in 1 minute (retry_count = 1)

2. Retry #1 fails (retry_count = 1)
   → Schedule retry in 2 minutes (retry_count = 2)

3. Retry #2 fails (retry_count = 2)
   → Schedule retry in 4 minutes (retry_count = 3)

4. Retry #3 fails (retry_count = 3)
   → Max retries exceeded → Move to DLQ
```

## Configuration Guide

### Job-Level Configuration

```sql
CREATE TABLE CronJob (
    -- Retry configuration
    max_retries TINYINT DEFAULT 3,  -- 0-5 range
    retry_strategy ENUM('immediate', 'fixed', 'exponential') DEFAULT 'exponential',
    retry_delay_seconds INT DEFAULT 60,  -- For 'fixed' strategy

    -- Failure tracking
    consecutive_failures INT DEFAULT 0,

    -- Dead letter queue
    in_dead_letter_queue TINYINT DEFAULT 0,
    dead_letter_reason TEXT,
    dead_letter_since INT,

    -- Notifications
    notify_on_failure TINYINT DEFAULT 0,
    notify_after_failures INT DEFAULT 3,
    notify_on_dead_letter TINYINT DEFAULT 1,
    slack_webhook VARCHAR(512),
    notification_email VARCHAR(255)
);
```

### Execution-Level Tracking

```sql
CREATE TABLE CronJobExecution (
    is_retry TINYINT DEFAULT 0,
    retry_count INT DEFAULT 0,
    -- Other fields...
);
```

## Testing Guide

### Test Retry Logic

```bash
# Create test jobs with different retry strategies
php bin/test-retry.php create --webhook=https://hooks.slack.com/...

# Start worker (in separate terminal)
php bin/cron-worker.php

# Watch retry behavior
php bin/test-retry.php status

# Check dead letter queue after jobs fail
php bin/test-retry.php dlq

# Recover a job from DLQ
php bin/test-retry.php recover 123

# Cleanup test jobs
php bin/test-retry.php cleanup
```

### Expected Test Behavior

**Job 1 (Exponential):**
```
Initial execution → Fails
Retry #1 (1 min later) → Fails
Retry #2 (2 min later) → Fails
Retry #3 (4 min later) → Fails
→ Moves to DLQ
→ Slack notification sent
```

**Job 2 (Fixed 30s):**
```
Initial execution → Fails
Retry #1 (30s later) → Fails
Retry #2 (30s later) → Fails
Retry #3 (30s later) → Fails
→ Moves to DLQ
```

**Job 3 (Immediate):**
```
Initial execution → Fails
Retry #1 (immediately) → Fails
Retry #2 (immediately) → Fails
→ Moves to DLQ
```

**Job 4 (No Retries):**
```
Initial execution → Fails
→ Immediately moves to DLQ
```

## Common Scenarios

### Scenario 1: Transient Network Failure

**Problem:** Job fails due to temporary API unavailability.

**Configuration:**
```sql
UPDATE CronJob SET
    max_retries = 5,
    retry_strategy = 'exponential',
    notify_on_failure = 1,
    notify_after_failures = 5  -- Only alert if all retries fail
WHERE name = 'API Data Sync';
```

**Outcome:**
- Job retries with increasing delays
- If API recovers within 5 retries, job succeeds
- Only notified if all 5 retries fail

### Scenario 2: Database Deadlock

**Problem:** Job occasionally fails due to database lock contention.

**Configuration:**
```sql
UPDATE CronJob SET
    max_retries = 3,
    retry_strategy = 'immediate',
    notify_on_failure = 1,
    notify_after_failures = 3
WHERE name = 'Report Generator';
```

**Outcome:**
- Immediate retries give quick recovery
- Most deadlocks resolve on second try
- Notified only if problem persists

### Scenario 3: External Service Outage

**Problem:** Third-party service is down for maintenance.

**Configuration:**
```sql
UPDATE CronJob SET
    max_retries = 10,
    retry_strategy = 'fixed',
    retry_delay_seconds = 300,  -- 5 minutes
    notify_on_failure = 1,
    notify_after_failures = 3,
    slack_webhook = 'https://...'
WHERE name = 'Payment Processor Sync';
```

**Outcome:**
- Retries every 5 minutes for 50 minutes total
- Early notification after 3 failures
- If service recovers, job succeeds
- If not, moves to DLQ with alert

### Scenario 4: Job Code Bug

**Problem:** Job has a bug that causes consistent failures.

**Expected Flow:**
1. Job fails immediately
2. Retries fail (same error)
3. After max retries, moves to DLQ
4. Notification sent with error details
5. Developer fixes code
6. Recover job from DLQ
7. Job succeeds on next run

**Recovery:**
```bash
# View error details
php bin/test-retry.php dlq

# Fix the code (update job code)
# Then recover
php bin/test-retry.php recover 123
```

## Best Practices

### 1. Choose Appropriate Retry Strategy

**Exponential Backoff (Default):**
- External API calls
- Network operations
- Rate-limited services

**Fixed Delay:**
- Scheduled maintenance windows
- Batch processing
- Known recovery patterns

**Immediate:**
- Lock contention
- Quick transient issues
- Low-cost operations

### 2. Set Reasonable Max Retries

**0 Retries:** Critical jobs that must succeed first time
**1-2 Retries:** Quick recovery scenarios
**3 Retries (Default):** Most jobs
**5+ Retries:** Jobs with known long recovery times

### 3. Configure Notification Thresholds

**notify_after_failures = 1:** Immediate alerts for critical jobs
**notify_after_failures = 3 (Default):** Balanced for most jobs
**notify_after_failures = max_retries:** Only alert on DLQ

### 4. Monitor DLQ Regularly

Set up alerts or scheduled checks:

```bash
# Add to crontab for daily DLQ report
0 9 * * * php /path/to/bin/test-retry.php dlq | mail -s "DLQ Report" admin@example.com
```

### 5. Use Different Webhooks for Severity

```sql
-- Critical jobs - urgent channel
UPDATE CronJob SET
    slack_webhook = 'https://hooks.slack.com/services/URGENT'
WHERE name LIKE 'Critical%';

-- Regular jobs - monitoring channel
UPDATE CronJob SET
    slack_webhook = 'https://hooks.slack.com/services/MONITORING'
WHERE name LIKE 'Report%';
```

### 6. Test Failure Scenarios

Before deploying:

```bash
# Create test jobs that simulate failures
php bin/test-retry.php create --webhook=YOUR_WEBHOOK

# Verify:
# - Retries happen at correct intervals
# - Notifications sent at right threshold
# - DLQ moves work correctly
# - Recovery process works
```

## Troubleshooting

### Issue: Job Goes to DLQ Immediately

**Possible Causes:**
- `max_retries = 0`
- Job throwing fatal error instead of exception

**Fix:**
```sql
-- Check retry configuration
SELECT max_retries, retry_strategy FROM CronJob WHERE id = 123;

-- Increase retries
UPDATE CronJob SET max_retries = 3 WHERE id = 123;
```

### Issue: Not Receiving Slack Notifications

**Checks:**
1. Verify webhook URL is correct
2. Check `notify_on_failure = 1`
3. Verify consecutive failures >= `notify_after_failures`
4. Check worker logs for curl errors

**Debug:**
```sql
SELECT notify_on_failure, notify_after_failures,
       consecutive_failures, slack_webhook
FROM CronJob WHERE id = 123;
```

### Issue: Too Many Notification Alerts

**Solution:** Increase threshold
```sql
UPDATE CronJob SET
    notify_after_failures = 5  -- Was 3
WHERE id = 123;
```

### Issue: Job Stuck in DLQ

**Recovery Steps:**
1. Identify root cause from `dead_letter_reason`
2. Fix the underlying issue (code, config, external service)
3. Recover job from DLQ
4. Monitor next execution

```bash
php bin/test-retry.php recover 123
```

### Issue: Retries Happening Too Fast

**For Exponential:**
- Working as designed (1min, 2min, 4min...)
- If too fast, switch to fixed delay

**For Fixed:**
```sql
UPDATE CronJob SET
    retry_delay_seconds = 600  -- Increase to 10 minutes
WHERE id = 123;
```

### Issue: Email Notifications Not Sending

**Possible Causes:**
- PHP mail() not configured
- No SMTP server on host
- Email in spam folder

**Solutions:**
1. Configure PHP mail() properly
2. Use external email service (recommended for production)
3. Check spam folders and whitelist sender

## Production Recommendations

### 1. Use External Notification Services

Replace PHP mail() with proper service:

```php
// In CronWorker::sendEmailNotification()
// Use AWS SES, SendGrid, Mailgun, etc.
$ses = new \Aws\Ses\SesClient([...]);
$ses->sendEmail([...]);
```

### 2. Implement Notification Rate Limiting

Prevent alert fatigue:

```sql
-- Only alert once per hour for same job
ALTER TABLE CronJob ADD last_notification_sent INT;

-- Check in notification logic
IF (last_notification_sent < UNIX_TIMESTAMP() - 3600) THEN
    SEND_NOTIFICATION();
    UPDATE last_notification_sent;
END IF;
```

### 3. Set Up DLQ Monitoring Dashboard

Create dashboard showing:
- Jobs currently in DLQ
- DLQ entry rate (jobs/day)
- Most common failure reasons
- Average time to recovery

### 4. Automated DLQ Alerts

```bash
# Cron job to check DLQ and alert if non-empty
*/30 * * * * php /path/to/check-dlq.php
```

### 5. Retry Budget Tracking

Monitor retry costs:
- Total retries per day
- Average retries per job
- Retry success rate
- Time spent on retries

## Metrics to Track

**Job-Level:**
- Success rate
- Average retries before success
- Time to DLQ
- Recovery rate from DLQ

**System-Level:**
- Total jobs in DLQ
- Retry volume (retries/hour)
- Notification volume
- Most failed jobs

**Example Queries:**

```sql
-- Jobs with highest failure rates
SELECT name, consecutive_failures, in_dead_letter_queue
FROM CronJob
WHERE consecutive_failures > 5
ORDER BY consecutive_failures DESC;

-- Retry success rate
SELECT
    COUNT(DISTINCT cron_job) as jobs,
    SUM(CASE WHEN is_retry = 1 AND status = 'completed' THEN 1 ELSE 0 END) as retry_successes,
    SUM(CASE WHEN is_retry = 1 THEN 1 ELSE 0 END) as total_retries
FROM CronJobExecution
WHERE date_created > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY));

-- DLQ growth rate
SELECT
    DATE(FROM_UNIXTIME(dead_letter_since)) as date,
    COUNT(*) as jobs_added_to_dlq
FROM CronJob
WHERE in_dead_letter_queue = 1
GROUP BY date
ORDER BY date DESC;
```

## Summary

The retry and failure handling system makes the cron system resilient to failures with:
- ✅ 3 retry strategies (immediate, fixed, exponential)
- ✅ Dead letter queue for permanent failures
- ✅ Consecutive failure tracking
- ✅ Slack notifications with rich formatting
- ✅ Email notifications
- ✅ Configurable notification thresholds
- ✅ Automatic retry scheduling
- ✅ DLQ recovery utilities
- ✅ Comprehensive testing tools

Jobs now handle transient failures gracefully, alert appropriately, and provide clear paths for recovery when issues persist.
