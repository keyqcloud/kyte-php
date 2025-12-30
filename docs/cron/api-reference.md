# Backend Controllers

Comprehensive REST API controllers for managing cron jobs, viewing execution history, accessing version control, and monitoring deduplication statistics.

## Controllers Overview

### 1. CronJobController - Main CRUD Operations

Complete REST API for cron job management with automatic version control integration.

**Standard Actions:**
- `GET /CronJob/id/5` - Get job details with version info
- `POST /CronJob` - Create new job (creates version 1)
- `PUT /CronJob/id/5` - Update job (auto-creates version)
- `DELETE /CronJob/id/5` - Soft delete job

**Custom Actions:**
- `POST /CronJob/trigger/5` - Manually trigger job execution
- `POST /CronJob/recover/5` - Recover job from DLQ
- `POST /CronJob/rollback/5?version=3` - Rollback to version 3
- `GET /CronJob/stats/5` - Get execution statistics

### 2. CronJobExecutionController - Execution History

Read-only API for viewing and analyzing job execution history.

**Standard Actions:**
- `GET /CronJobExecution/id/123` - Get execution details

**Custom Actions:**
- `GET /CronJobExecution/recent/5?limit=20` - Recent executions
- `GET /CronJobExecution/failed/5?days=7` - Failed executions (job_id optional)
- `GET /CronJobExecution/running` - Currently running jobs
- `GET /CronJobExecution/pending?limit=50` - Pending executions
- `GET /CronJobExecution/statistics/5?days=30` - Execution stats (job_id optional)

### 3. KyteCronJobVersionController - Version History

Read-only API for accessing job version history with diffs and comparisons.

**Standard Actions:**
- `GET /KyteCronJobVersion/id/10` - Get version details

**Custom Actions:**
- `GET /KyteCronJobVersion/history/5?limit=50` - Version history
- `GET /KyteCronJobVersion/compare/5?version1=2&version2=4` - Compare versions
- `GET /KyteCronJobVersion/code/5?version=3` - Get version code

### 4. KyteCronJobVersionContentController - Content Deduplication

Read-only API for viewing deduplicated content storage and statistics.

**Standard Actions:**
- `GET /KyteCronJobVersionContent/content_hash/abc123...` - Get content

**Custom Actions:**
- `GET /KyteCronJobVersionContent/stats` - Deduplication statistics
- `GET /KyteCronJobVersionContent/find?hash=abc` - Find by hash
- `GET /KyteCronJobVersionContent/orphaned` - Orphaned content

---

## API Reference

### CronJobController

#### Create New Job

**Request:**
```http
POST /api/CronJob
Content-Type: application/json

{
  "name": "Daily Report Generator",
  "description": "Generates daily sales reports",
  "code": "class DailyReport extends \\Kyte\\Core\\CronJobBase {\n  public function execute() {\n    $this->log('Generating report...');\n  }\n}",
  "schedule_type": "daily",
  "time_of_day": "02:00:00",
  "timezone": "America/New_York",
  "enabled": 1,
  "timeout_seconds": 600,
  "max_retries": 3,
  "retry_strategy": "exponential",
  "notify_on_failure": 1,
  "slack_webhook": "https://hooks.slack.com/..."
}
```

**Response:**
```json
{
  "id": 5,
  "name": "Daily Report Generator",
  "description": "Generates daily sales reports",
  "code": "class DailyReport extends \\Kyte\\Core\\CronJobBase...",
  "schedule_type": "daily",
  "time_of_day": "02:00:00",
  "timezone": "America/New_York",
  "enabled": 1,
  "version_info": {
    "current_version": 1,
    "total_versions": 1,
    "last_updated": 1735516800,
    "content_hash": "a3f5d8b9c..."
  },
  "execution_summary": {
    "total_executions": 0,
    "successful": 0,
    "failed": 0,
    "timeouts": 0,
    "avg_duration_ms": null,
    "last_execution": null,
    "success_rate": 0
  },
  "date_created": 1735516800
}
```

#### Update Job (with Version Control)

**Request:**
```http
PUT /CronJob/id/5
Content-Type: application/json

{
  "code": "class DailyReport extends \\Kyte\\Core\\CronJobBase {\n  public function execute() {\n    $this->log('Generating improved report...');\n    // New logic here\n  }\n}",
  "timeout_seconds": 900
}
```

**Response:**
```json
{
  "id": 5,
  "name": "Daily Report Generator",
  "code": "class DailyReport extends \\Kyte\\Core\\CronJobBase...",
  "timeout_seconds": 900,
  "version_info": {
    "current_version": 2,
    "total_versions": 2,
    "last_updated": 1735603200,
    "content_hash": "b7e2a9f4d..."
  },
  "execution_summary": {
    "total_executions": 10,
    "successful": 9,
    "failed": 1,
    "success_rate": 90
  }
}
```

#### Get Job Details

**Request:**
```http
GET /CronJob/id/5
```

**Response:**
```json
{
  "id": 5,
  "name": "Daily Report Generator",
  "code": "class DailyReport extends \\Kyte\\Core\\CronJobBase...",
  "schedule_type": "daily",
  "time_of_day": "02:00:00",
  "timezone": "America/New_York",
  "enabled": 1,
  "timeout_seconds": 900,
  "max_retries": 3,
  "consecutive_failures": 0,
  "in_dead_letter_queue": 0,
  "version_info": {
    "current_version": 2,
    "total_versions": 2
  },
  "execution_summary": {
    "total_executions": 10,
    "successful": 9,
    "failed": 1,
    "success_rate": 90.0
  },
  "next_run": 1735689600
}
```

#### Manually Trigger Job

**Request:**
```http
POST /CronJob/trigger/5
```

**Response:**
```json
{
  "success": true,
  "message": "Job execution queued",
  "execution_id": 123,
  "job_id": 5,
  "job_name": "Daily Report Generator"
}
```

#### Recover from Dead Letter Queue

**Request:**
```http
POST /CronJob/recover/5
```

**Response:**
```json
{
  "success": true,
  "message": "Job recovered from dead letter queue",
  "job_id": 5,
  "job_name": "Daily Report Generator"
}
```

#### Rollback to Version

**Request:**
```http
POST /CronJob/rollback/5?version=3
```

**Response:**
```json
{
  "success": true,
  "message": "Job rolled back successfully",
  "from_version": 5,
  "to_version": 3,
  "job_id": 5,
  "job_name": "Daily Report Generator"
}
```

#### Get Job Statistics

**Request:**
```http
GET /CronJob/stats/5
```

**Response:**
```json
{
  "job_id": 5,
  "job_name": "Daily Report Generator",
  "daily_stats": [
    {
      "date": "2025-12-29",
      "total": 1,
      "successful": 1,
      "failed": 0,
      "avg_duration": 1234.56
    },
    {
      "date": "2025-12-28",
      "total": 1,
      "successful": 0,
      "failed": 1,
      "avg_duration": null
    }
  ],
  "summary": {
    "total_executions": 30,
    "successful": 27,
    "failed": 3,
    "success_rate": 90.0
  }
}
```

---

### CronJobExecutionController

#### Get Recent Executions

**Request:**
```http
GET /CronJobExecution/recent/5?limit=10
```

**Response:**
```json
{
  "job_id": 5,
  "count": 10,
  "limit": 10,
  "executions": [
    {
      "id": 123,
      "cron_job": 5,
      "status": "completed",
      "scheduled_time": 1735516800,
      "started_at": 1735516802,
      "completed_at": 1735516805,
      "duration_ms": 3245,
      "memory_peak_mb": 12.5,
      "output": "Report generated successfully",
      "exit_code": 0,
      "retry_count": 0,
      "is_retry": 0,
      "job": {
        "id": 5,
        "name": "Daily Report Generator",
        "enabled": 1
      }
    }
  ]
}
```

#### Get Failed Executions

**Request:**
```http
GET /CronJobExecution/failed/5?days=7
```

**Response:**
```json
{
  "job_id": 5,
  "days": 7,
  "count": 2,
  "executions": [
    {
      "id": 120,
      "status": "failed",
      "error": "Database connection timeout",
      "stack_trace": "...",
      "retry_count": 3,
      "job": {
        "id": 5,
        "name": "Daily Report Generator"
      }
    }
  ]
}
```

#### Get Running Executions

**Request:**
```http
GET /api/CronJobExecution/running
```

**Response:**
```json
{
  "count": 2,
  "executions": [
    {
      "id": 125,
      "cron_job": 5,
      "status": "running",
      "started_at": 1735516800,
      "elapsed_seconds": 45,
      "elapsed_ms": 45000,
      "locked_by": "server1:12345",
      "locked_until": 1735517100,
      "lease_expired": false,
      "job": {
        "id": 5,
        "name": "Daily Report Generator"
      }
    }
  ]
}
```

#### Get Pending Executions

**Request:**
```http
GET /api/CronJobExecution/pending?limit=20
```

**Response:**
```json
{
  "count": 20,
  "limit": 20,
  "executions": [
    {
      "id": 126,
      "cron_job": 5,
      "status": "pending",
      "next_run_time": 1735603200,
      "seconds_until_run": 3600,
      "job": {
        "id": 5,
        "name": "Daily Report Generator"
      }
    }
  ]
}
```

#### Get Execution Statistics

**Request:**
```http
GET /CronJobExecution/statistics/5?days=30
```

**Response:**
```json
{
  "job_id": 5,
  "period_days": 30,
  "statistics": {
    "total_executions": 30,
    "successful": 27,
    "failed": 2,
    "timeouts": 1,
    "skipped": 0,
    "avg_duration_ms": 3250.5,
    "max_duration_ms": 8500,
    "min_duration_ms": 1200,
    "avg_memory_mb": 12.3,
    "max_memory_mb": 25.6,
    "success_rate": 90.0
  }
}
```

---

### KyteCronJobVersionController

#### Get Version History

**Request:**
```http
GET /KyteCronJobVersion/history/5?limit=10
```

**Response:**
```json
{
  "job_id": 5,
  "count": 10,
  "versions": [
    {
      "id": 25,
      "version_number": 5,
      "is_current": 1,
      "content_hash": "a3f5d8b9c4e2...",
      "date_created": 1735603200,
      "changes_from_previous": {
        "lines_added": 5,
        "lines_removed": 2,
        "lines_changed": 3,
        "total_lines_before": 42,
        "total_lines_after": 48
      },
      "change_summary": "+5 lines, -2 lines, ~3 lines (42 → 48 total)",
      "content_info": {
        "hash": "a3f5d8b9c...",
        "references": 1
      },
      "created_by_user": {
        "id": 10,
        "name": "John Doe",
        "email": "john@example.com"
      }
    }
  ]
}
```

#### Compare Two Versions

**Request:**
```http
GET /KyteCronJobVersion/compare/5?version1=2&version2=4
```

**Response:**
```json
{
  "version_1": 2,
  "version_2": 4,
  "diff": {
    "lines_added": 15,
    "lines_removed": 7,
    "lines_changed": 5,
    "total_lines_before": 35,
    "total_lines_after": 48,
    "changes": [
      {
        "type": "added",
        "line": 12,
        "content": "$this->log('Processing...');"
      },
      {
        "type": "changed",
        "line": 25,
        "old": "$data = $this->fetchData();",
        "new": "$data = $this->fetchDataWithCache();"
      }
    ]
  },
  "summary": "+15 lines, -7 lines, ~5 lines (35 → 48 total)",
  "code_1": "class DailyReport extends...",
  "code_2": "class DailyReport extends..."
}
```

#### Get Version Code

**Request:**
```http
GET /KyteCronJobVersion/code/5?version=3
```

**Response:**
```json
{
  "job_id": 5,
  "version": 3,
  "code": "class DailyReport extends \\Kyte\\Core\\CronJobBase {\n  public function execute() {\n    // Version 3 code\n  }\n}"
}
```

---

### KyteCronJobVersionContentController

#### Get Deduplication Statistics

**Request:**
```http
GET /api/KyteCronJobVersionContent/stats
```

**Response:**
```json
{
  "total_content_records": 45,
  "total_references": 128,
  "avg_references": 2.84,
  "max_references": 15,
  "total_storage_bytes": 2411520,
  "total_storage_mb": 2.3,
  "deduplication": {
    "saved_records": 83,
    "deduplication_rate": 64.84,
    "efficiency_description": "Good - Moderate deduplication providing noticeable savings"
  },
  "top_deduplicated": [
    {
      "content_hash": "a3f5d8b9c4e2...",
      "hash_short": "a3f5d8b9c...",
      "reference_count": 15,
      "size_bytes": 5120,
      "date_created": 1735516800,
      "versions": [
        {
          "id": 10,
          "cron_job": 5,
          "version_number": 2,
          "job_name": "Daily Report Generator"
        }
      ]
    }
  ]
}
```

#### Find Content by Hash

**Request:**
```http
GET /api/KyteCronJobVersionContent/find?hash=a3f5d8&include_code=1
```

**Response:**
```json
{
  "content_hash": "a3f5d8b9c4e2...",
  "hash_short": "a3f5d8b9c...",
  "reference_count": 5,
  "date_created": 1735516800,
  "code": "class DailyReport extends...",
  "code_decompressed": true,
  "versions": [
    {
      "id": 10,
      "cron_job": 5,
      "version_number": 2,
      "is_current": 0,
      "job_name": "Daily Report Generator"
    }
  ]
}
```

#### Get Orphaned Content

**Request:**
```http
GET /api/KyteCronJobVersionContent/orphaned
```

**Response:**
```json
{
  "count": 3,
  "total_size_bytes": 15360,
  "total_size_mb": 0.01,
  "orphaned": [
    {
      "content_hash": "b7e2a9f4d3c1...",
      "hash_short": "b7e2a9f4d...",
      "reference_count": 0,
      "date_created": 1735430400,
      "size_bytes": 5120
    }
  ],
  "note": "These records can be safely deleted to free up space"
}
```

---

## Common Usage Patterns

### Pattern 1: Creating and Monitoring a Job

```javascript
// 1. Create job
const createResponse = await fetch('/CronJob', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    name: 'My New Job',
    code: jobCode,
    schedule_type: 'daily',
    time_of_day: '02:00:00'
  })
});

const job = await createResponse.json();
console.log(`Job created with ID: ${job.id}, Version: ${job.version_info.current_version}`);

// 2. Manually trigger for testing
await fetch(`/CronJob/trigger/${job.id}`, { method: 'POST' });

// 3. Monitor recent executions
const executions = await fetch(`/CronJobExecution/recent/${job.id}?limit=5`);
const data = await executions.json();

data.executions.forEach(exec => {
  console.log(`${exec.status}: ${exec.duration_ms}ms`);
});
```

### Pattern 2: Updating Job Code with Version Tracking

```javascript
// 1. Get current job
const job = await fetch('/CronJob/id/5').then(r => r.json());
console.log(`Current version: ${job.version_info.current_version}`);

// 2. Update code
const updateResponse = await fetch('/CronJob/id/5', {
  method: 'PUT',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    code: newCode
  })
});

const updated = await updateResponse.json();
console.log(`New version: ${updated.version_info.current_version}`);

// 3. View version history
const history = await fetch(`/KyteCronJobVersion/history/5`).then(r => r.json());

history.versions.forEach(v => {
  console.log(`v${v.version_number}: ${v.change_summary}`);
});
```

### Pattern 3: Handling Failures and DLQ

```javascript
// 1. Check for failed executions
const failed = await fetch('/CronJobExecution/failed?days=7').then(r => r.json());

if (failed.count > 0) {
  console.log(`${failed.count} failed executions in last 7 days`);

  // 2. Check if any jobs in DLQ
  for (const exec of failed.executions) {
    const job = await fetch(`/CronJob/id/${exec.cron_job}`).then(r => r.json());

    if (job.in_dead_letter_queue) {
      console.log(`Job ${job.name} is in DLQ`);
      console.log(`Reason: ${job.dead_letter_reason}`);

      // 3. Review and recover if appropriate
      const recover = await fetch(`/CronJob/recover/${job.id}`, {
        method: 'POST'
      });

      console.log('Job recovered from DLQ');
    }
  }
}
```

### Pattern 4: Version Rollback Workflow

```javascript
// 1. Job is broken, need to rollback
const job = await fetch('/CronJob/id/5').then(r => r.json());
console.log(`Current version: ${job.version_info.current_version}`);

// 2. View version history to find stable version
const history = await fetch(`/KyteCronJobVersion/history/5`).then(r => r.json());

history.versions.forEach(v => {
  console.log(`v${v.version_number} (${v.change_summary})`);
});

// 3. Compare current with previous stable version
const comparison = await fetch(
  `/KyteCronJobVersion/compare/5?version1=3&version2=5`
).then(r => r.json());

console.log('Differences:', comparison.summary);

// 4. Rollback to stable version
const rollback = await fetch('/CronJob/rollback/5?version=3', {
  method: 'POST'
}).then(r => r.json());

console.log(rollback.message);
```

### Pattern 5: Monitoring System Health

```javascript
// 1. Check running jobs
const running = await fetch('/CronJobExecution/running').then(r => r.json());

running.executions.forEach(exec => {
  console.log(`${exec.job.name}: ${exec.elapsed_seconds}s`);

  if (exec.lease_expired) {
    console.warn(`WARNING: Lease expired ${exec.lease_expired_seconds_ago}s ago - possible worker crash`);
  }
});

// 2. Check deduplication efficiency
const stats = await fetch('/KyteCronJobVersionContent/stats').then(r => r.json());

console.log(`Deduplication rate: ${stats.deduplication.deduplication_rate}%`);
console.log(`Storage saved: ${stats.deduplication.saved_records} records`);

// 3. Overall system statistics
const systemStats = await fetch('/CronJobExecution/statistics?days=30').then(r => r.json());

console.log(`Success rate: ${systemStats.statistics.success_rate}%`);
console.log(`Avg duration: ${systemStats.statistics.avg_duration_ms}ms`);
```

---

## Validation and Error Handling

### Job Creation Validation

**Invalid Schedule Type:**
```json
{
  "error": "Invalid schedule_type. Must be one of: cron, interval, daily, weekly, monthly"
}
```

**Missing Required Fields:**
```json
{
  "error": "time_of_day is required for daily schedule type"
}
```

**Invalid Code:**
```json
{
  "error": "Invalid job code: Code must contain a class that extends Kyte\\Core\\CronJobBase"
}
```

### Trigger Validation

**Job Disabled:**
```json
{
  "error": "Job is disabled"
}
```

**Job in DLQ:**
```json
{
  "error": "Job is in dead letter queue. Recover it first."
}
```

### Rollback Validation

**Version Not Found:**
```json
{
  "error": "Version 10 not found for job 5"
}
```

**Already at Version:**
```json
{
  "message": "Already at version 3"
}
```

---

## Security Considerations

### 1. Authentication and Authorization

All controller endpoints inherit authentication from the Kyte framework. Ensure users have appropriate permissions before allowing:
- Job creation/modification
- Manual triggering
- DLQ recovery
- Version rollback

### 2. Code Validation

The `CronJobController` validates code syntax before allowing updates:
- Checks for required class structure
- PHP syntax validation via `php -l`
- Prevents execution of invalid code

### 3. Rate Limiting

Consider implementing rate limiting for:
- Manual trigger endpoint (prevent job spam)
- Version rollback (prevent rapid rollbacks)
- Statistics endpoints (prevent resource exhaustion)

### 4. Input Sanitization

All parameters are sanitized and type-cast before database queries:
- Job IDs cast to integers
- Limits capped at maximum values
- Hash parameters validated for length/format

---

## Performance Optimization

### 1. Pagination

All list endpoints support pagination:
```http
GET /api/CronJobExecution/recent?job_id=5&limit=20&offset=0
```

### 2. Selective Field Loading

Version endpoints allow excluding large fields:
```http
GET /api/KyteCronJobVersion?id=10
# By default, excludes 'code' field

GET /api/KyteCronJobVersion?id=10&include_code=1
# Includes code field when explicitly requested
```

### 3. Caching

Consider caching for:
- Job statistics (refresh every 5 minutes)
- Deduplication stats (refresh every hour)
- Version history (refresh on updates)

### 4. Database Indexes

All controllers rely on these database indexes for optimal performance:
- `idx_cron_job` on executions
- `idx_status` on executions
- `idx_content_hash` on versions
- `idx_is_current` on versions

---

## Summary

The cron system provides production-ready REST API controllers with:
- ✅ Complete CRUD for cron jobs with automatic versioning
- ✅ Read-only access to execution history with filtering
- ✅ Version history access with diff viewing and comparison
- ✅ Content deduplication statistics and monitoring
- ✅ Custom actions (trigger, recover, rollback, stats)
- ✅ Comprehensive validation and error handling
- ✅ Security considerations built-in
- ✅ Performance optimizations (pagination, selective loading)
- ✅ Detailed API documentation with examples

The backend API is now complete and ready for frontend integration!
