# Dependencies & Scheduling

Comprehensive scheduling options with timezone support and job dependency chains, allowing complex workflows to be automated reliably.

## Scheduling Types

### 1. Daily Schedules

Run jobs at a specific time each day with timezone support.

**Configuration:**

```sql
UPDATE CronJob SET
    schedule_type = 'daily',
    time_of_day = '02:00:00',  -- 2:00 AM
    timezone = 'UTC'
WHERE id = 5;
```

**Examples:**

```sql
-- Daily at 2 AM UTC
schedule_type = 'daily'
time_of_day = '02:00:00'
timezone = 'UTC'

-- Daily at 9 AM Eastern Time
schedule_type = 'daily'
time_of_day = '09:00:00'
timezone = 'America/New_York'

-- Daily at midnight Pacific Time
schedule_type = 'daily'
time_of_day = '00:00:00'
timezone = 'America/Los_Angeles'
```

**How it works:**
1. Worker calculates next run in job's timezone
2. If time has passed today, schedules for tomorrow
3. Converts to UTC timestamp for storage
4. Respects DST changes automatically

### 2. Weekly Schedules

Run jobs on a specific day of the week at a specific time.

**Configuration:**

```sql
UPDATE CronJob SET
    schedule_type = 'weekly',
    day_of_week = 1,  -- Monday (0=Sunday, 6=Saturday)
    time_of_day = '08:00:00',
    timezone = 'UTC'
WHERE id = 5;
```

**Day of week values:**
- `0` = Sunday
- `1` = Monday
- `2` = Tuesday
- `3` = Wednesday
- `4` = Thursday
- `5` = Friday
- `6` = Saturday

**Examples:**

```sql
-- Every Monday at 8 AM
schedule_type = 'weekly'
day_of_week = 1
time_of_day = '08:00:00'
timezone = 'UTC'

-- Every Friday at 5 PM
schedule_type = 'weekly'
day_of_week = 5
time_of_day = '17:00:00'
timezone = 'UTC'

-- Every Sunday at midnight
schedule_type = 'weekly'
day_of_week = 0
time_of_day = '00:00:00'
timezone = 'UTC'
```

### 3. Monthly Schedules

Run jobs on a specific day of each month at a specific time.

**Configuration:**

```sql
UPDATE CronJob SET
    schedule_type = 'monthly',
    day_of_month = 1,  -- 1st day of month
    time_of_day = '03:00:00',
    timezone = 'UTC'
WHERE id = 5;
```

**Special handling for month-end:**
- Day 31 in February → Runs on Feb 28 (or 29 in leap years)
- Day 31 in April/June/Sept/Nov → Runs on day 30
- Automatically adjusts for month length

**Examples:**

```sql
-- 1st of every month at 3 AM
schedule_type = 'monthly'
day_of_month = 1
time_of_day = '03:00:00'
timezone = 'UTC'

-- 15th of every month at noon
schedule_type = 'monthly'
day_of_month = 15
time_of_day = '12:00:00'
timezone = 'UTC'

-- Last day of month (using 31)
schedule_type = 'monthly'
day_of_month = 31
time_of_day = '23:00:00'
timezone = 'UTC'
```

### 4. Timezone Support

All time-based schedules (daily, weekly, monthly) support any valid PHP timezone.

**Common timezones:**
- `UTC` - Coordinated Universal Time
- `America/New_York` - Eastern Time
- `America/Chicago` - Central Time
- `America/Denver` - Mountain Time
- `America/Los_Angeles` - Pacific Time
- `Europe/London` - British Time
- `Asia/Tokyo` - Japan Standard Time

**View all available timezones:**
```php
print_r(DateTimeZone::listIdentifiers());
```

**How timezone conversion works:**
1. Job defined: "Run at 9 AM America/New_York"
2. Worker calculates next 9 AM Eastern
3. Converts to UTC timestamp
4. Stores in `next_run_time` field
5. Worker executes when `time() >= next_run_time`

**DST handling:**
- PHP's DateTime automatically handles DST transitions
- Jobs continue running at same local time year-round
- No manual adjustment needed

### 5. Job Dependencies (Linear Chains)

Jobs can depend on other jobs, creating execution chains where child jobs only run after parent jobs complete successfully.

**Configuration:**

```sql
-- Job A (no dependency)
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Job A', 'interval', 60, NULL, ...);

-- Job B (depends on A)
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Job B', 'interval', 60, [Job A ID], ...);

-- Job C (depends on B)
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Job C', 'interval', 60, [Job B ID], ...);
```

**Execution flow:**
```
Job A executes → Completes successfully
  ↓
Job B waits for A → A completed → B executes → Completes
  ↓
Job C waits for B → B completed → C executes → Completes
  ↓
Cycle repeats based on schedule
```

**Dependency rules:**
1. Parent job must exist and be enabled
2. Parent job's most recent execution must have status = 'completed'
3. Child job will skip if parent failed, is running, or hasn't executed yet
4. Dependencies checked both at scheduling time and execution time

**Example chain:**

```sql
-- ETL Pipeline: Extract → Transform → Load

-- Step 1: Extract data from API
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job)
VALUES ('Extract Data', 'daily', '01:00:00', NULL, ...);

-- Step 2: Transform data (depends on Extract)
INSERT INTO CronJob (name, schedule_type, depends_on_job)
VALUES ('Transform Data', 'daily', '01:00:00', [Extract ID], ...);

-- Step 3: Load to warehouse (depends on Transform)
INSERT INTO CronJob (name, schedule_type, depends_on_job)
VALUES ('Load Data', 'daily', '01:00:00', [Transform ID], ...);
```

## Configuration Examples

### Daily Report Generation

```sql
INSERT INTO CronJob (
    name, description, code,
    schedule_type, time_of_day, timezone,
    timeout_seconds, max_retries,
    enabled, kyte_account
) VALUES (
    'Daily Sales Report',
    'Generate and email daily sales report',
    [COMPRESSED_CODE],
    'daily',
    '06:00:00',  -- 6 AM
    'America/New_York',  -- Eastern Time
    1800,  -- 30 minutes
    3,
    1,
    1
);
```

### Weekly Backup

```sql
INSERT INTO CronJob (
    name, description, code,
    schedule_type, day_of_week, time_of_day, timezone,
    timeout_seconds, max_retries,
    enabled, kyte_account
) VALUES (
    'Weekly Database Backup',
    'Full database backup every Sunday',
    [COMPRESSED_CODE],
    'weekly',
    0,  -- Sunday
    '02:00:00',  -- 2 AM
    'UTC',
    3600,  -- 1 hour
    2,
    1,
    1
);
```

### Monthly Invoice Processing

```sql
INSERT INTO CronJob (
    name, description, code,
    schedule_type, day_of_month, time_of_day, timezone,
    timeout_seconds, max_retries,
    enabled, kyte_account
) VALUES (
    'Monthly Invoice Run',
    'Process and send monthly invoices',
    [COMPRESSED_CODE],
    'monthly',
    1,  -- 1st of month
    '09:00:00',  -- 9 AM
    'America/Chicago',
    7200,  -- 2 hours
    5,
    1,
    1
);
```

### Data Pipeline with Dependencies

```sql
-- Step 1: Extract
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Extract', 'interval', 3600, NULL, ...);
-- Returns: ID 101

-- Step 2: Transform (depends on Extract)
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Transform', 'interval', 3600, 101, ...);
-- Returns: ID 102

-- Step 3: Load (depends on Transform)
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Load', 'interval', 3600, 102, ...);
-- Returns: ID 103

-- Step 4: Notify (depends on Load)
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Notify', 'interval', 3600, 103, ...);
```

## Testing Guide

### Test Schedule Types

```bash
# Create test jobs with different schedules
php bin/test-schedules.php create-schedules

# View next run times (validates calculation logic)
php bin/test-schedules.php next-runs

# Start worker
php bin/cron-worker.php

# Check execution history
php bin/test-schedules.php status

# Cleanup
php bin/test-schedules.php cleanup
```

**Expected output from next-runs:**

```
Next Run Times (Schedule Calculation)
======================================

Job #5: Schedule Test - Daily
  Schedule Type: daily
  Next Run: 2025-12-30 02:00:00 (in 14h 23m 15s)
  Current time in UTC: 2025-12-29 11:36:45 UTC

Job #6: Schedule Test - Daily NY
  Schedule Type: daily
  Next Run: 2025-12-29 14:00:00 (in 2h 23m 15s)
  Current time in America/New_York: 2025-12-29 06:36:45 EST

Job #7: Schedule Test - Weekly
  Schedule Type: weekly
  Next Run: 2025-12-30 08:00:00 (in 20h 23m 15s)

Job #8: Schedule Test - Monthly
  Schedule Type: monthly
  Next Run: 2026-01-01 03:00:00 (in 2d 15h 23m 15s)
```

### Test Dependency Chains

```bash
# Create 3-job dependency chain
php bin/test-schedules.php create-chain

# Start worker (watch logs for dependency checking)
php bin/cron-worker.php

# Verify execution order
php bin/test-schedules.php status
```

**Expected worker output:**

```
[14:30:10] Executing job #101 (Dependency Test - Job A) - execution #1
[14:30:12] Job #101 completed in 2150ms

[14:30:20] Dependency met: Parent job #101 (Dependency Test - Job A) completed successfully
[14:30:20] Executing job #102 (Dependency Test - Job B) - execution #1
[14:30:22] Job #102 completed in 2100ms

[14:30:30] Dependency met: Parent job #102 (Dependency Test - Job B) completed successfully
[14:30:30] Executing job #103 (Dependency Test - Job C) - execution #1
[14:30:32] Job #103 completed in 2080ms
```

## Common Scenarios

### Scenario 1: Daily Job Across Timezones

**Problem:** Need reports at 9 AM local time in different regions.

**Solution:**

```sql
-- US East Coast report
INSERT INTO CronJob (name, schedule_type, time_of_day, timezone, ...)
VALUES ('East Coast Report', 'daily', '09:00:00', 'America/New_York', ...);

-- US West Coast report
INSERT INTO CronJob (name, schedule_type, time_of_day, timezone, ...)
VALUES ('West Coast Report', 'daily', '09:00:00', 'America/Los_Angeles', ...);

-- UK report
INSERT INTO CronJob (name, schedule_type, time_of_day, timezone, ...)
VALUES ('UK Report', 'daily', '09:00:00', 'Europe/London', ...);
```

Each runs at 9 AM in its respective timezone automatically.

### Scenario 2: Month-End Processing

**Problem:** Need to run on last day of every month.

**Solution:**

```sql
UPDATE CronJob SET
    schedule_type = 'monthly',
    day_of_month = 31,  -- Will adjust to actual last day
    time_of_day = '23:00:00',
    timezone = 'UTC'
WHERE id = 5;
```

Automatically runs on:
- Jan 31, Mar 31, May 31, etc. (31 days)
- Apr 30, Jun 30, Sep 30, Nov 30 (30 days)
- Feb 28 or Feb 29 (28/29 days)

### Scenario 3: Sequential Data Processing

**Problem:** ETL pipeline must run in strict order: Extract → Transform → Load.

**Solution:**

```sql
-- Create dependency chain
-- Extract (no dependency)
INSERT INTO CronJob (name, depends_on_job, ...) VALUES ('Extract', NULL, ...);
-- ID: 201

-- Transform (depends on Extract)
INSERT INTO CronJob (name, depends_on_job, ...) VALUES ('Transform', 201, ...);
-- ID: 202

-- Load (depends on Transform)
INSERT INTO CronJob (name, depends_on_job, ...) VALUES ('Load', 202, ...);
-- ID: 203
```

Execution guarantees:
- Transform never runs unless Extract completed
- Load never runs unless Transform completed
- If any step fails, chain stops

### Scenario 4: Weekly Maintenance Window

**Problem:** Database maintenance every Sunday at 2 AM.

**Solution:**

```sql
UPDATE CronJob SET
    schedule_type = 'weekly',
    day_of_week = 0,  -- Sunday
    time_of_day = '02:00:00',
    timezone = 'UTC',
    timeout_seconds = 7200  -- 2 hours
WHERE id = 5;
```

### Scenario 5: Dependency with Different Schedules

**Problem:** Daily job depends on hourly job completing at least once.

**Solution:**

```sql
-- Hourly sync job
INSERT INTO CronJob (name, schedule_type, interval_seconds, depends_on_job, ...)
VALUES ('Hourly Sync', 'interval', 3600, NULL, ...);
-- ID: 301

-- Daily report (depends on sync)
INSERT INTO CronJob (name, schedule_type, time_of_day, depends_on_job, ...)
VALUES ('Daily Report', 'daily', '09:00:00', 301, ...);
```

Daily report runs at 9 AM only if hourly sync has completed at least once.

## Best Practices

### 1. Choose Appropriate Schedule Type

**Use cron expressions** for complex patterns:
```sql
schedule_type = 'cron'
cron_expression = '0 */6 * * *'  -- Every 6 hours
```

**Use daily** for simple daily schedules:
```sql
schedule_type = 'daily'
time_of_day = '02:00:00'
```

**Use interval** for frequency-based:
```sql
schedule_type = 'interval'
interval_seconds = 300  -- Every 5 minutes
```

### 2. Timezone Considerations

**Always specify timezone explicitly:**
```sql
timezone = 'America/New_York'  -- Good
timezone = NULL  -- Bad (defaults to UTC)
```

**Use IANA timezone names:**
```sql
timezone = 'America/New_York'  -- Good
timezone = 'EST'  -- Bad (doesn't handle DST)
```

**Test DST transitions:**
- Run tests around March (spring forward) and November (fall back)
- Verify jobs still run at expected local time

### 3. Dependency Chain Design

**Keep chains simple:**
```
✓ Good: A → B → C (3 jobs)
✗ Avoid: A → B → C → D → E → F → G (7+ jobs)
```

**Use for strict ordering:**
```
✓ Good: Extract → Transform → Load (must run in order)
✗ Bad: ReportA → ReportB (could run independently)
```

**Consider timeouts:**
```sql
-- If A takes 10 minutes, B might wait 15+ minutes to start
-- Plan total pipeline time accordingly
```

### 4. Handle Dependency Failures

**Monitor parent job health:**
```sql
-- If parent keeps failing, child never runs
-- Set up alerts for parent failures
```

**Provide fallbacks:**
```php
public function execute() {
    // Try to use parent's output
    // If not available, use cached data or skip gracefully
}
```

### 5. Test Schedule Calculations

Before deploying:

```bash
# Create test job
php bin/test-schedules.php create-schedules

# Verify next run time is correct
php bin/test-schedules.php next-runs

# Check multiple times to ensure recalculation works
# Wait 1 minute, run next-runs again
```

## Troubleshooting

### Issue: Daily Job Not Running at Expected Time

**Possible Causes:**
1. Wrong timezone specified
2. Time already passed today
3. Job disabled

**Debug:**

```sql
SELECT name, schedule_type, time_of_day, timezone, enabled
FROM CronJob WHERE id = 5;
```

```bash
php bin/test-schedules.php next-runs
```

**Fix:**

```sql
-- Correct timezone
UPDATE CronJob SET timezone = 'America/New_York' WHERE id = 5;
```

### Issue: Job Not Running Due to Dependency

**Symptoms:**
- Child job shows as "skipped"
- Skip reason: "Dependency not met"

**Debug:**

```sql
-- Check parent job status
SELECT id, name, enabled FROM CronJob WHERE id = [parent_id];

-- Check parent's recent execution
SELECT status FROM CronJobExecution
WHERE cron_job = [parent_id]
ORDER BY date_created DESC LIMIT 1;
```

**Common causes:**
1. Parent job failed
2. Parent job hasn't run yet
3. Parent job disabled
4. Parent job deleted

**Fix:**

```bash
# Check parent job status
php bin/test-schedules.php status

# If parent failed, check error logs
# Fix parent issue first
```

### Issue: Monthly Job Not Running on 31st

**Cause:** Month has fewer than 31 days

**Expected Behavior:** Job runs on last available day

**Verify:**

```bash
php bin/test-schedules.php next-runs
```

For February:
```
Next Run: 2025-02-28 03:00:00  -- Correct (Feb has 28 days)
```

### Issue: Timezone Not Respected

**Symptoms:**
- Job runs at wrong time
- Runs at UTC time instead of specified timezone

**Check:**

```sql
SELECT timezone FROM CronJob WHERE id = 5;
-- Should NOT be NULL or empty
```

**Fix:**

```sql
UPDATE CronJob SET timezone = 'America/New_York' WHERE id = 5;
```

### Issue: Dependency Chain Stuck

**Symptoms:**
- Child jobs never execute
- All showing "dependency not met"

**Debug:**

```sql
-- Check entire chain
SELECT id, name, depends_on_job, enabled
FROM CronJob
WHERE id IN (101, 102, 103);

-- Check recent executions
SELECT cron_job, status, completed_at
FROM CronJobExecution
WHERE cron_job IN (101, 102, 103)
ORDER BY date_created DESC;
```

**Common causes:**
1. Parent job in dead letter queue
2. Parent job disabled
3. Circular dependency (not possible in current implementation but worth checking)

**Fix:**

```bash
# Check DLQ
php bin/test-retry.php dlq

# Recover if needed
php bin/test-retry.php recover [parent_id]
```

## Advanced Usage

### Combining Schedules and Dependencies

```sql
-- Daily data import at 1 AM
INSERT INTO CronJob (name, schedule_type, time_of_day, depends_on_job, ...)
VALUES ('Import', 'daily', '01:00:00', NULL, ...);
-- ID: 401

-- Weekly aggregation on Mondays (depends on Sunday's import)
INSERT INTO CronJob (name, schedule_type, day_of_week, time_of_day, depends_on_job, ...)
VALUES ('Weekly Aggregate', 'weekly', 1, '02:00:00', 401, ...);
-- ID: 402

-- Monthly report on 1st (depends on month-end aggregation)
INSERT INTO CronJob (name, schedule_type, day_of_month, time_of_day, depends_on_job, ...)
VALUES ('Monthly Report', 'monthly', 1, '09:00:00', 402, ...);
```

### Multi-Region Coordination

```sql
-- US region processing at 2 AM local
INSERT INTO CronJob (name, schedule_type, time_of_day, timezone, ...)
VALUES ('US Processing', 'daily', '02:00:00', 'America/New_York', ...);
-- ID: 501

-- EU region processing at 2 AM local (6-8 hours before US)
INSERT INTO CronJob (name, schedule_type, time_of_day, timezone, ...)
VALUES ('EU Processing', 'daily', '02:00:00', 'Europe/London', ...);
-- ID: 502

-- Global rollup after both complete
INSERT INTO CronJob (name, schedule_type, time_of_day, depends_on_job, ...)
VALUES ('Global Rollup', 'daily', '12:00:00', 501, ...);
-- Note: Will wait for US processing (last to run)
```

## Summary

The scheduling system provides powerful orchestration with:
- ✅ Daily schedules with time-of-day
- ✅ Weekly schedules with day-of-week
- ✅ Monthly schedules with day-of-month
- ✅ Full timezone support with DST handling
- ✅ Month-end special handling (31st in short months)
- ✅ Linear job dependency chains (A→B→C)
- ✅ Automatic dependency validation
- ✅ Dependency-aware scheduling and execution
- ✅ Comprehensive testing tools

Jobs can now run on complex schedules in any timezone and coordinate with each other through dependencies, enabling sophisticated automated workflows.
