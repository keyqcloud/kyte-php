# Kyte Cron System Documentation

Comprehensive distributed cron job system with version control, retry logic, and web-based management.

## Overview

The Kyte cron system provides enterprise-grade scheduled job execution with:

- **Multiple Schedule Types** - Cron expressions, intervals, daily, weekly, monthly
- **Distributed Execution** - Lease-based locking for multi-server environments
- **Automatic Retries** - Three retry strategies with dead letter queue
- **Version Control** - SHA256 deduplication with rollback capability
- **Web Interface** - Full-featured UI for job management
- **REST API** - Complete CRUD and monitoring endpoints
- **Real-time Monitoring** - Execution tracking and statistics

## Quick Start

```bash
# 1. Install
mysql -u user -p database < migrations/cron-system.sql
composer update

# 2. Create a job
php -r "
\$manager = new \Kyte\Cron\CronJobManager();
\$job = \$manager->createJob([
    'name' => 'My First Job',
    'code' => 'class MyJob extends \Kyte\Core\CronJobBase {
        public function execute() {
            \$this->log(\"Hello World\");
        }
    }',
    'schedule_type' => 'interval',
    'interval_seconds' => 60,
    'enabled' => 1
]);
"

# 3. Start worker
php bin/cron-worker.php
```

## Documentation Structure

### Getting Started

**[Getting Started Guide](getting-started.md)** - Installation, first job, basic configuration

Start here if you're new to the Kyte cron system.

### Core Concepts

**[Job Development Guide](job-development.md)** - Writing robust cron jobs

Learn how to write job classes, use available methods, handle errors, and follow best practices.

**[Scheduling Guide](scheduling.md)** - Schedule types, timezones, dependencies

Master cron expressions, intervals, daily/weekly/monthly schedules, timezone support, and job dependencies.

**[Execution Guide](execution.md)** - Locking, retries, failures, notifications

Understand distributed locking, heartbeats, retry strategies, dead letter queue, and Slack/email notifications.

**[Version Control Guide](version-control.md)** - Code versioning and rollback

Learn about automatic versioning, SHA256 deduplication, rollback procedures, and version comparison.

### Integration

**[API Reference](api-reference.md)** - REST API endpoints

Complete reference for CronJob, CronJobExecution, KyteCronJobVersion, and KyteCronJobVersionContent controllers.

**[Web Interface Guide](web-interface.md)** - Frontend UI usage

Guide to the Kyte Shipyard web interface for managing jobs, viewing execution history, and monitoring system health.

### Testing

**[Testing Guide](testing.md)** - Testing strategies and tools

Comprehensive testing workflows, multi-worker testing, and validation checklists.

## Architecture

### Database Schema

Four tables power the cron system:

1. **CronJob** - Job definitions and configuration
   - Schedule type and parameters
   - Retry and timeout settings
   - Notification configuration
   - Dead letter queue status

2. **CronJobExecution** - Execution history and tracking
   - Lock state and lease expiration
   - Execution metrics (duration, memory)
   - Output and error capture
   - Status tracking

3. **KyteCronJobVersion** - Version control metadata
   - Version numbers and timestamps
   - Content hash references
   - Change diffs
   - Creator tracking

4. **KyteCronJobVersionContent** - Deduplicated code storage
   - SHA256 content hashing
   - Bzip2 compression
   - Reference counting

### Components

**Worker Daemon** (`bin/cron-worker.php`)
- Polls for pending jobs every 10 seconds
- Acquires lease-based locks
- Executes jobs in isolated context
- Captures output and metrics
- Schedules next execution

**CronJobManager** (`src/Cron/CronJobManager.php`)
- High-level API for job management
- Automatic version control
- Code validation
- Rollback operations

**CronVersionControl** (`src/Cron/CronVersionControl.php`)
- SHA256 content deduplication
- Version creation and tracking
- Diff calculation
- Reference counting

**CronJobBase** (`src/Core/CronJobBase.php`)
- Abstract base class for all jobs
- Logging and heartbeat methods
- Setup and teardown hooks
- API context access

**Controllers** (`src/Mvc/Controller/`)
- CronJobController - Job CRUD and custom actions
- CronJobExecutionController - Execution history
- KyteCronJobVersionController - Version access
- KyteCronJobVersionContentController - Content stats

## Key Features

### Distributed Locking

Lease-based locking ensures only one worker executes each job instance across multiple servers:

```
Worker 1 attempts lock → Success → Executes job
Worker 2 attempts lock → Fails (already locked) → Skips
```

### Retry Strategies

Three strategies for handling failures:

1. **Exponential Backoff** - 1min, 2min, 4min, 8min, 16min
2. **Fixed Delay** - Consistent retry interval
3. **Immediate** - Retry without delay

### Dead Letter Queue

Jobs that exceed max retries are moved to DLQ:
- Job automatically disabled
- Reason captured for review
- One-click recovery via API or UI

### Version Control

Automatic versioning on every code change:
- SHA256 content hashing prevents duplicates
- Full version history with diffs
- One-command rollback
- Reference counting for storage efficiency

### Schedule Types

Five flexible scheduling options:

```php
// Cron expression
'schedule_type' => 'cron', 'cron_expression' => '0 */6 * * *'

// Interval
'schedule_type' => 'interval', 'interval_seconds' => 300

// Daily with timezone
'schedule_type' => 'daily', 'time_of_day' => '02:00:00', 'timezone' => 'America/New_York'

// Weekly
'schedule_type' => 'weekly', 'day_of_week' => 1, 'time_of_day' => '08:00:00'

// Monthly
'schedule_type' => 'monthly', 'day_of_month' => 1, 'time_of_day' => '03:00:00'
```

### Notifications

Slack and email notifications for failures:

```sql
UPDATE CronJob SET
    notify_on_failure = 1,
    notify_after_failures = 3,  -- Alert after 3 consecutive failures
    notify_on_dead_letter = 1,  -- Alert when moved to DLQ
    slack_webhook = 'https://hooks.slack.com/...'
WHERE id = 5;
```

## Common Use Cases

### ETL Pipeline

```sql
-- Extract → Transform → Load chain
INSERT INTO CronJob (name, depends_on_job, ...) VALUES
    ('Extract Data', NULL, ...),        -- Runs first
    ('Transform Data', [Extract ID], ...),  -- Waits for Extract
    ('Load Data', [Transform ID], ...);     -- Waits for Transform
```

### Multi-Region Reports

```sql
-- Different timezones, same local time
INSERT INTO CronJob (name, time_of_day, timezone, ...) VALUES
    ('US Report', '09:00:00', 'America/New_York', ...),
    ('EU Report', '09:00:00', 'Europe/London', ...),
    ('Asia Report', '09:00:00', 'Asia/Tokyo', ...);
```

### Periodic Cleanup

```sql
INSERT INTO CronJob (name, schedule_type, interval_seconds, ...) VALUES
    ('Cleanup Old Logs', 'daily', '02:00:00', ...);
```

## Performance

### Storage Efficiency

- **Compression**: Bzip2 reduces code size by ~80%
- **Deduplication**: SHA256 hashing achieves 40-70% storage savings
- **Reference Counting**: Shared content stored once

Example:
```
100 jobs × 10 versions = 1000 versions
Average code: 50 KB uncompressed

Without optimization: 50 MB
With compression: 10 MB
With compression + dedup (60%): 4 MB
```

### Execution Performance

- **Polling**: 10-second interval
- **Lock Acquisition**: <5ms (database UPDATE)
- **Job Startup**: ~50-100ms (code decompression + class loading)
- **Lease Duration**: 5 minutes (configurable)

### Scalability

- **Workers**: Tested with 10+ concurrent workers
- **Jobs**: Supports thousands of jobs
- **Executions**: Millions of execution records
- **Contention**: <50% recommended for optimal performance

## Monitoring

### Health Checks

```bash
# Check running jobs
php bin/cron-locks.php list

# View stale locks
php bin/cron-locks.php stale

# Statistics
php bin/cron-locks.php stats
```

### Metrics to Track

- Total executions per hour
- Success/failure rates
- Average execution duration
- Jobs in dead letter queue
- Lock contention rate
- Lease expiration frequency

### Alerts

Set up monitoring for:
- Jobs stuck in DLQ
- High failure rates (>10%)
- Stale locks (>10 minutes)
- High contention (>70%)
- Slow execution times

## Production Deployment

### System Requirements

- PHP 7.4+ with bcmath, bz2 extensions
- MySQL 5.7+ or MariaDB 10.2+
- 512MB+ RAM per worker
- Composer for dependency management

### Deployment Steps

1. Run database migration
2. Update composer dependencies
3. Deploy worker as systemd service
4. Configure monitoring and alerts
5. Set up log rotation
6. Test with non-critical jobs first

### High Availability

- Run 2-3 worker processes per server
- Deploy across multiple servers
- Use load balancer for web interface
- Set up database replication
- Monitor worker health

### Maintenance

- Regular version pruning (keep 50+ versions)
- Log rotation (90+ day retention)
- Database optimization (weekly)
- Dead letter queue review (daily)
- Performance monitoring (continuous)

## Troubleshooting

### Common Issues

**Jobs not executing**
- Check job is enabled
- Verify next_run_time is in past
- Ensure worker is running

**High lock contention**
- Reduce number of workers
- Increase job frequency
- Review job schedules

**Memory issues**
- Process data in batches
- Use heartbeat for long jobs
- Increase PHP memory_limit

**Version storage growth**
- Prune old versions regularly
- Check deduplication rate
- Review version creation frequency

## Support and Resources

- **GitHub Issues**: Report bugs and request features
- **CHANGELOG.md**: Version history and breaking changes
- **Migration Guide**: Upgrade procedures

## Future Enhancements

See `docs/future/` for planned features:

- **WebSocket Integration** - Real-time execution monitoring
- **Job Templates** - Marketplace for pre-built jobs
- **Advanced Dependencies** - Complex DAG support
- **Priority Queues** - Job prioritization
- **Bulk Operations** - Manage multiple jobs at once
