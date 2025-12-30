# Job Development Guide

Learn how to write robust, maintainable cron jobs for Kyte.

## Job Structure

Every cron job must extend `\Kyte\Core\CronJobBase` and implement the `execute()` method.

### Basic Job Template

```php
<?php
class YourJobName extends \Kyte\Core\CronJobBase
{
    /**
     * Main execution method - required
     * This runs when the job executes
     */
    public function execute() {
        $this->log("Job starting...");

        // Your job logic here

        return "Job completed successfully";
    }

    /**
     * Setup method - optional
     * Runs before execute()
     */
    public function setUp() {
        // Initialize resources
        // Open connections
        // Load configuration
    }

    /**
     * Teardown method - optional
     * Runs after execute(), even if execute() fails
     */
    public function tearDown() {
        // Clean up resources
        // Close connections
        // Free memory
    }
}
```

## Available Methods and Properties

### Logging

```php
$this->log($message);
```

Outputs timestamped messages that are captured in execution output:

```php
$this->log("Processing batch 1 of 10...");
$this->log("Found " . count($records) . " records");
```

### Heartbeat (Long-Running Jobs)

```php
$this->heartbeat($extendSeconds = 300);
```

Extends the lease to prevent timeout for long-running jobs:

```php
for ($i = 0; $i < 1000; $i++) {
    processItem($i);

    // Extend lease every 100 iterations (adds 5 minutes)
    if ($i % 100 == 0) {
        $this->heartbeat();
    }
}
```

### Accessing Job Metadata

```php
// Job configuration
$this->kyte_cron_job  // ModelObject with job details

// Current execution
$this->execution      // Array with execution details

// API context
$this->api            // Kyte API instance
```

### Using Database

```php
// Direct queries
$results = DBI::prepared_query(
    "SELECT * FROM users WHERE status = ?",
    's',
    ['active']
);

// Using models
$users = Model::all('User', ['status' => 'active']);
```

### Return Values

Return a string or array to be stored in execution output:

```php
return "Processed 150 records successfully";

// Or structured data
return [
    'records_processed' => 150,
    'errors' => 0,
    'duration' => '2.5s'
];
```

## Common Job Patterns

### Data Processing Job

```php
class DataProcessingJob extends \Kyte\Core\CronJobBase
{
    private $batchSize = 100;

    public function execute() {
        $offset = 0;
        $totalProcessed = 0;

        while (true) {
            // Fetch batch
            $records = $this->fetchBatch($offset, $this->batchSize);

            if (empty($records)) {
                break; // No more records
            }

            // Process batch
            foreach ($records as $record) {
                $this->processRecord($record);
                $totalProcessed++;
            }

            $this->log("Processed {$totalProcessed} records so far...");

            // Extend lease every 10 batches
            if ($totalProcessed % 1000 == 0) {
                $this->heartbeat();
            }

            $offset += $this->batchSize;
        }

        return "Total records processed: {$totalProcessed}";
    }

    private function fetchBatch($offset, $limit) {
        return DBI::prepared_query(
            "SELECT * FROM data_queue WHERE processed = 0 LIMIT ? OFFSET ?",
            'ii',
            [$limit, $offset]
        );
    }

    private function processRecord($record) {
        // Processing logic
    }
}
```

### API Integration Job

```php
class APISync Job extends \Kyte\Core\CronJobBase
{
    private $apiClient;

    public function setUp() {
        $this->apiClient = new ExternalAPI([
            'key' => getenv('API_KEY'),
            'timeout' => 30
        ]);
    }

    public function execute() {
        $this->log("Fetching data from API...");

        try {
            $data = $this->apiClient->fetchData([
                'since' => $this->getLastSyncTime()
            ]);

            $this->log("Received " . count($data) . " items");

            foreach ($data as $item) {
                $this->saveItem($item);
            }

            $this->updateLastSyncTime();

            return "Synced " . count($data) . " items successfully";

        } catch (APIException $e) {
            $this->log("API error: " . $e->getMessage());
            throw $e; // Will trigger retry logic
        }
    }

    public function tearDown() {
        if ($this->apiClient) {
            $this->apiClient->disconnect();
        }
    }

    private function getLastSyncTime() {
        // Fetch from config or database
    }

    private function saveItem($item) {
        // Save to database
    }

    private function updateLastSyncTime() {
        // Update config or database
    }
}
```

### Report Generation Job

```php
class ReportGenerationJob extends \Kyte\Core\CronJobBase
{
    private $reportData = [];

    public function execute() {
        $this->log("Generating monthly report...");

        // Collect data from multiple sources
        $this->reportData['sales'] = $this->getSalesData();
        $this->reportData['users'] = $this->getUserStats();
        $this->reportData['revenue'] = $this->getRevenueData();

        // Generate report
        $reportPath = $this->generatePDFReport();

        // Send report
        $this->emailReport($reportPath);
        $this->uploadToS3($reportPath);

        return "Report generated and sent: {$reportPath}";
    }

    private function getSalesData() {
        return DBI::query("
            SELECT DATE(created_at) as date, COUNT(*) as count, SUM(amount) as total
            FROM orders
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
        ");
    }

    private function getUserStats() {
        // Fetch user statistics
    }

    private function getRevenueData() {
        // Fetch revenue data
    }

    private function generatePDFReport() {
        // Generate PDF using library
        $pdf = new PDFGenerator();
        // ... populate PDF
        $path = '/tmp/report_' . date('Y-m-d') . '.pdf';
        $pdf->save($path);
        return $path;
    }

    private function emailReport($path) {
        // Email logic
    }

    private function uploadToS3($path) {
        // S3 upload logic
    }
}
```

### Cleanup/Maintenance Job

```php
class DatabaseCleanupJob extends \Kyte\Core\CronJobBase
{
    public function execute() {
        $cutoffDays = 90;
        $cutoffDate = time() - ($cutoffDays * 24 * 60 * 60);

        $tables = [
            'logs' => 'date_created',
            'temp_data' => 'created_at',
            'old_exports' => 'generated_at'
        ];

        $totalDeleted = 0;

        foreach ($tables as $table => $dateField) {
            $this->log("Cleaning table: {$table}...");

            $sql = "DELETE FROM {$table} WHERE {$dateField} < ?";
            DBI::prepared_query($sql, 'i', [$cutoffDate]);

            $deleted = DBI::affected_rows();
            $totalDeleted += $deleted;

            $this->log("Deleted {$deleted} rows from {$table}");
        }

        return "Total rows deleted: {$totalDeleted}";
    }
}
```

## Error Handling

### Exceptions

Throw exceptions for retryable failures:

```php
public function execute() {
    try {
        $result = $this->fetchFromAPI();
    } catch (NetworkException $e) {
        // Will trigger retry logic
        throw new \Exception("Network error: " . $e->getMessage());
    }
}
```

### Fatal Errors

Return error message for non-retryable failures:

```php
public function execute() {
    $config = $this->loadConfig();

    if (!$config['api_key']) {
        $this->log("ERROR: API key not configured");
        return "Failed: Missing API key configuration";
    }
}
```

### Graceful Degradation

```php
public function execute() {
    try {
        $primary = $this->fetchFromPrimarySource();
        return $this->process($primary);
    } catch (\Exception $e) {
        $this->log("Primary source failed, trying backup...");

        try {
            $backup = $this->fetchFromBackupSource();
            return $this->process($backup);
        } catch (\Exception $e2) {
            throw new \Exception("Both sources failed");
        }
    }
}
```

## Performance Best Practices

### Memory Management

```php
public function execute() {
    $offset = 0;
    $limit = 1000;

    while (true) {
        // Process in batches to avoid loading all data into memory
        $batch = $this->fetchBatch($offset, $limit);

        if (empty($batch)) {
            break;
        }

        $this->processBatch($batch);

        // Free memory
        unset($batch);

        $offset += $limit;

        // Heartbeat every 10 batches
        if ($offset % 10000 == 0) {
            $this->heartbeat();
            gc_collect_cycles(); // Force garbage collection
        }
    }
}
```

### Database Optimization

```php
public function execute() {
    // Use indexes
    $sql = "SELECT * FROM large_table WHERE indexed_column = ? AND date > ?";

    // Use prepared statements
    $results = DBI::prepared_query($sql, 'si', [$value, $date]);

    // Batch inserts instead of individual inserts
    $values = [];
    foreach ($data as $item) {
        $values[] = "({$item['a']}, {$item['b']}, {$item['c']})";
    }

    $sql = "INSERT INTO target (col_a, col_b, col_c) VALUES " . implode(',', $values);
    DBI::query($sql);
}
```

### External API Calls

```php
public function execute() {
    // Set timeouts
    $client = new HttpClient(['timeout' => 30]);

    // Batch API calls
    $ids = range(1, 100);
    $chunks = array_chunk($ids, 10); // Process 10 at a time

    foreach ($chunks as $chunk) {
        $results = $client->batchFetch($chunk);
        $this->processResults($results);

        // Rate limiting
        usleep(100000); // 100ms delay between batches
    }
}
```

## Testing Jobs

### Local Testing

Create a test script:

```php
<?php
require_once __DIR__ . '/path/to/autoload.php';

// Load job class
require_once __DIR__ . '/YourJobName.php';

// Mock execution context
$job = new YourJobName();
$job->kyte_cron_job = (object)[
    'id' => 1,
    'name' => 'Test Job'
];
$job->execution = [
    'id' => 1,
    'scheduled_time' => time()
];

// Initialize API context
$job->api = new \Kyte\Mvc\Api();

// Execute
try {
    $result = $job->execute();
    echo "Success: {$result}\n";
} catch (\Exception $e) {
    echo "Error: {$e->getMessage()}\n";
}
```

### Testing with Worker

```bash
# Create test job in database
php bin/test-cron.php create

# Run worker in foreground
php bin/cron-worker.php

# Watch for execution
```

## Security Considerations

### Input Validation

```php
public function execute() {
    $config = $this->getConfig();

    // Validate configuration
    if (!filter_var($config['email'], FILTER_VALIDATE_EMAIL)) {
        throw new \Exception("Invalid email configuration");
    }

    // Sanitize inputs
    $safeValue = filter_var($input, FILTER_SANITIZE_STRING);
}
```

### Credentials

```php
// Don't hardcode credentials
// Bad:
$apiKey = 'hardcoded_key_123';

// Good: Use environment variables
$apiKey = getenv('API_KEY');

// Or database configuration
$config = Model::one('Configuration', ['key' => 'api_key']);
$apiKey = $config->value;
```

### SQL Injection Prevention

```php
// Always use prepared statements
$sql = "SELECT * FROM users WHERE email = ?";
$results = DBI::prepared_query($sql, 's', [$email]);

// Never concatenate user input
// Bad:
$sql = "SELECT * FROM users WHERE email = '{$email}'";
```

## Debugging

### Enable Verbose Logging

```php
public function execute() {
    $debug = true;

    if ($debug) {
        $this->log("Debug: Starting execution");
        $this->log("Debug: Config: " . json_encode($config));
    }

    // ... job logic

    if ($debug) {
        $this->log("Debug: Memory usage: " . memory_get_usage(true));
    }
}
```

### Check Execution History

```sql
-- View recent executions
SELECT * FROM CronJobExecution
WHERE cron_job = [job_id]
ORDER BY date_created DESC
LIMIT 10;

-- View errors
SELECT error, stack_trace FROM CronJobExecution
WHERE status = 'failed'
AND cron_job = [job_id];
```

## Next Steps

- **[Execution Guide](execution.md)** - Learn about locking, retries, and notifications
- **[Scheduling Guide](scheduling.md)** - Master different schedule types
- **[Version Control](version-control.md)** - Manage job code versions
- **[Testing Guide](testing.md)** - Comprehensive testing strategies
