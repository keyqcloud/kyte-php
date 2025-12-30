# Performance Optimization Guide

## Table of Contents
1. [Overview](#overview)
2. [Query Caching](#query-caching)
3. [Model Memory Cache](#model-memory-cache)
4. [Eager Loading](#eager-loading)
5. [Batch Operations](#batch-operations)
6. [Performance Monitoring](#performance-monitoring)
7. [Best Practices](#best-practices)
8. [Troubleshooting](#troubleshooting)

---

## Overview

Kyte-PHP includes several performance optimization features designed to dramatically improve your application's speed and efficiency. These features are:

- **100% Backward Compatible** - All features are opt-in and won't break existing code
- **Production Ready** - Tested and optimized for high-traffic environments
- **Easy to Enable** - Simple configuration changes to activate features

### Performance Improvements You Can Expect

| Feature | Typical Improvement | Best For |
|---------|-------------------|----------|
| Query Caching | 50-80% faster repeated queries | Read-heavy applications |
| Model Memory Cache | 90%+ faster object access | APIs with frequent model lookups |
| Eager Loading | 80-95% reduction in queries | Pages with foreign key relationships |
| Batch Operations | 10-50x faster bulk inserts/updates | Importing data, bulk updates |

---

## Query Caching

Query caching stores the results of SELECT queries in memory, dramatically reducing database load for repeated queries.

### How It Works

When query caching is enabled:
1. The first time a SELECT query runs, the result is cached in memory
2. Subsequent identical queries return the cached result instantly
3. Cache is automatically invalidated when data changes (INSERT, UPDATE, DELETE)
4. Cache persists for the entire request lifecycle

### Enabling Query Caching

Add this method call to your configuration file (usually `config.php`):

```php
// Enable query caching with 300 second TTL
\Kyte\Core\DBI::enableQueryCache(300);
```

**That's it!** Query caching is now active for all SELECT queries.

**Parameters:**
- `$ttl` (optional) - Time to live in seconds (default: 60). How long cached queries remain valid.

### Example: Before and After

**Without Query Caching:**
```php
// First query - hits database (50ms)
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);

// Same query again - hits database again (50ms)
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);

// Same query third time - hits database again (50ms)
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);

// Total time: ~150ms
```

**With Query Caching:**
```php
// First query - hits database, then caches result (50ms)
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);

// Same query again - returns from cache (0.1ms)
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);

// Same query third time - returns from cache (0.1ms)
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);

// Total time: ~50ms (67% faster!)
```

### Automatic Cache Invalidation

The cache automatically clears when data changes:

```php
// Query is cached
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);

// Update a user - cache for User table is cleared
\Kyte\Core\DBI::update('User', 123, ['status' => 'inactive']);

// Next query hits database again (cache was cleared)
$users = \Kyte\Core\DBI::select('User', ['status' => 'active']);
```

### Checking Cache Statistics

Monitor cache effectiveness:

```php
// Enable query caching
\Kyte\Core\DBI::enableQueryCache(300);

// Run some queries
$users->retrieve();
$products->retrieve();

// Get cache statistics
$stats = \Kyte\Core\DBI::getCacheStats();

echo "Cache hits: " . $stats['hits'] . "\n";
echo "Cache misses: " . $stats['misses'] . "\n";
echo "Cache size: " . $stats['size'] . " queries\n";
echo "Hit rate: " . round(($stats['hits'] / max(1, $stats['hits'] + $stats['misses'])) * 100, 2) . "%\n";
```

---

## Model Memory Cache

Model memory cache stores frequently accessed model definitions in memory, eliminating repeated file reads.

### How It Works

Model definitions are typically stored in PHP files that must be read and parsed. Model memory cache:
1. Loads model definition on first access
2. Stores it in memory for the request
3. Returns cached version on subsequent accesses
4. Optionally persists to disk for cross-request caching

### Enabling Model Memory Cache

Add this constant to your configuration:

```php
// Enable model memory caching
define('MODEL_CACHE', true);
```

### Optional: Persistent File Cache

For even better performance across requests, enable file-based caching:

```php
// Enable memory cache
define('MODEL_CACHE', true);

// Enable persistent file cache (optional)
define('MODEL_CACHE_FILE', true);
```

**Important:** File cache is disabled by default because it requires additional considerations:
- **Load balancers:** Multiple servers need shared cache storage or should keep this disabled
- **Model changes:** When you modify a model, clear the cache manually (see below)
- **Storage:** Requires write permissions to cache directory

### Clearing Model Cache

When you update model definitions, clear the cache:

```php
// Clear the entire model cache
\Kyte\Core\DBI::clearModelCache();

// Or clear cache for a specific model
\Kyte\Core\DBI::clearModelCache('User');
```

### Example: Performance Impact

```php
// Without model cache:
// Each model access reads and parses the file
$user1 = new \Kyte\Core\ModelObject(constant('User')); // 2ms
$user2 = new \Kyte\Core\ModelObject(constant('User')); // 2ms
$user3 = new \Kyte\Core\ModelObject(constant('User')); // 2ms
// Total: 6ms

// With model cache:
$user1 = new \Kyte\Core\ModelObject(constant('User')); // 2ms (first access)
$user2 = new \Kyte\Core\ModelObject(constant('User')); // 0.01ms (cached)
$user3 = new \Kyte\Core\ModelObject(constant('User')); // 0.01ms (cached)
// Total: 2ms (67% faster)
```

---

## Eager Loading

Eager loading solves the N+1 query problem by loading related foreign key data in a single query instead of multiple individual queries.

### The N+1 Query Problem

Without eager loading, foreign key lookups create many queries:

```php
// Get 100 comments
$comments = new \Kyte\Core\Model(constant('Comment'));
$comments->retrieve();

// In your controller, each comment loads its user via foreign key
// This results in:
// - 1 query to get comments
// - 100 queries to get each user
// Total: 101 queries!
```

### Enabling Eager Loading

Use the `with()` method to specify which foreign keys to eager load:

```php
$comments = new \Kyte\Core\Model(constant('Comment'));

// Eager load the user_id foreign key
$comments->with(['user_id'])->retrieve();

// This results in:
// - 1 query to get comments
// - 1 query to get all users (WHERE id IN (...))
// Total: 2 queries! (98% reduction!)

// Access the eager-loaded data
foreach ($comments->objects as $comment) {
    // The user object is already loaded in user_id_object
    echo $comment->user_id_object->first_name;
}
```

### Multiple Foreign Keys

Eager load multiple relationships at once:

```php
// Comment model has both user_id and blog_id foreign keys
$comments = new \Kyte\Core\Model(constant('Comment'));

// Eager load both
$comments->with(['user_id', 'blog_id'])->retrieve();

// Results in only 3 queries:
// - 1 for comments
// - 1 for users
// - 1 for blogs
```

### How It Works

When you use `with()`:
1. Kyte retrieves the main records (e.g., comments)
2. Collects all unique foreign key IDs (e.g., all user_ids)
3. Runs a single query: `SELECT * FROM User WHERE id IN (1,2,3,4,5...)`
4. Attaches the loaded objects to your records with `{field}_object` suffix

### Accessing Eager-Loaded Data

The eager-loaded object is stored in a property named `{field}_object`:

```php
$comments = new \Kyte\Core\Model(constant('Comment'));
$comments->with(['user_id'])->retrieve();

foreach ($comments->objects as $comment) {
    // Foreign key ID (the actual number)
    echo $comment->user_id;  // e.g., 123

    // Eager-loaded user object
    if (isset($comment->user_id_object)) {
        echo $comment->user_id_object->first_name;
        echo $comment->user_id_object->last_name;
        echo $comment->user_id_object->email;
    }
}
```

### Automatic Controller Integration

If you're using ModelController with `getFKTables = true`, eager-loaded data is automatically detected and used:

```php
// In your controller
class CommentController extends \Kyte\Mvc\Controller\ModelController {
    protected $getFKTables = true;

    // When you retrieve comments with eager loading, the controller
    // automatically uses the eager-loaded data instead of making
    // additional queries
}
```

### Real-World Example

**Scenario:** Display 50 blog posts with author information

**Without Eager Loading:**
```php
$posts = new \Kyte\Core\Model(constant('BlogPost'));
$posts->retrieve();  // 51 queries total (1 + 50 for authors)
// Response time: ~500ms
```

**With Eager Loading:**
```php
$posts = new \Kyte\Core\Model(constant('BlogPost'));
$posts->with(['author_id'])->retrieve();  // 2 queries total
// Response time: ~50ms (90% faster!)
```

---

## Batch Operations

Batch operations allow you to insert or update multiple records in a single database query, dramatically improving performance for bulk operations.

### Batch Insert

Insert multiple records at once:

```php
// Prepare data for multiple users
$users = [
    [
        'first_name' => 'Alice',
        'last_name' => 'Johnson',
        'email' => 'alice@example.com',
        'status' => 'active',
    ],
    [
        'first_name' => 'Bob',
        'last_name' => 'Smith',
        'email' => 'bob@example.com',
        'status' => 'active',
    ],
    [
        'first_name' => 'Carol',
        'last_name' => 'Williams',
        'email' => 'carol@example.com',
        'status' => 'active',
    ],
];

// Define types for the fields (same as prepared statement types)
$types = 'ssss';  // s=string, i=integer, d=double

// Insert all at once
$ids = \Kyte\Core\DBI::batchInsert('User', $users, $types);

// Returns array of inserted IDs
echo "Inserted " . count($ids) . " users\n";
echo "First ID: " . $ids[0] . "\n";
```

**Type Codes:**
- `s` - String
- `i` - Integer
- `d` - Double/Decimal
- `b` - Blob (rarely used)

### Batch Update

Update multiple records with the same values:

```php
// Update multiple users at once
$userIds = [101, 102, 103, 104, 105];

$updates = [
    'status' => 'verified',
    'date_modified' => time(),
];

$types = 'si';  // status is string, date_modified is integer

// Update all at once
$affected = \Kyte\Core\DBI::batchUpdate('User', $userIds, $updates, $types);

echo "Updated $affected users\n";
```

### Performance Comparison

**Individual Inserts (Traditional Way):**
```php
// Insert 1000 users one at a time
$start = microtime(true);

for ($i = 0; $i < 1000; $i++) {
    $user = new \Kyte\Core\ModelObject(constant('User'));
    $user->create([
        'first_name' => "User$i",
        'last_name' => "Test",
        'email' => "user$i@example.com",
        'status' => 'active',
    ]);
}

$time = microtime(true) - $start;
echo "Time: " . round($time, 2) . "s\n";  // ~25 seconds
```

**Batch Insert (Optimized Way):**
```php
// Insert 1000 users in batches
$start = microtime(true);

$users = [];
for ($i = 0; $i < 1000; $i++) {
    $users[] = [
        'first_name' => "User$i",
        'last_name' => "Test",
        'email' => "user$i@example.com",
        'status' => 'active',
    ];
}

$ids = \Kyte\Core\DBI::batchInsert('User', $users, 'ssss');

$time = microtime(true) - $start;
echo "Time: " . round($time, 2) . "s\n";  // ~0.5 seconds (50x faster!)
```

### Practical Use Cases

**1. CSV Import**
```php
function importUsersFromCSV($csvFile) {
    $users = [];
    $file = fopen($csvFile, 'r');

    // Skip header row
    fgetcsv($file);

    // Read all rows
    while (($row = fgetcsv($file)) !== false) {
        $users[] = [
            'first_name' => $row[0],
            'last_name' => $row[1],
            'email' => $row[2],
            'status' => 'pending',
        ];

        // Insert in batches of 500
        if (count($users) >= 500) {
            $ids = \Kyte\Core\DBI::batchInsert('User', $users, 'ssss');
            echo "Inserted batch of " . count($ids) . " users\n";
            $users = [];
        }
    }

    // Insert remaining
    if (!empty($users)) {
        $ids = \Kyte\Core\DBI::batchInsert('User', $users, 'ssss');
        echo "Inserted final batch of " . count($ids) . " users\n";
    }

    fclose($file);
}
```

**2. Bulk Status Update**
```php
function approveAllPendingUsers() {
    // Get all pending user IDs
    $users = new \Kyte\Core\Model(constant('User'));
    $users->retrieve('status', 'pending');

    $ids = [];
    foreach ($users->objects as $user) {
        $ids[] = $user->id;
    }

    if (!empty($ids)) {
        // Approve all at once
        $affected = \Kyte\Core\DBI::batchUpdate(
            'User',
            $ids,
            ['status' => 'approved', 'date_modified' => time()],
            'si'
        );

        echo "Approved $affected users\n";
    }
}
```

### Important Notes

**Security:** Batch operations use prepared statements, so they're safe from SQL injection.

**Automatic Cache Invalidation:** Query cache is automatically cleared for affected tables.

**Transaction Safety:** All operations happen within a single query, ensuring data consistency.

---

## Performance Monitoring

Performance monitoring lets you track query counts, execution times, cache performance, and memory usage in real-time.

### Enabling Performance Monitoring

Add these lines to your configuration:

```php
// Enable query logging (tracks db_queries and db_time)
\Kyte\Core\DBI::enableQueryLogging();

// Enable performance monitoring in API responses
define('DEBUG_PERFORMANCE', true);
```

**Important:** Only enable this in development or testing. In production, only enable when debugging performance issues.

**Note:** `DEBUG_PERFORMANCE` shows the `_performance` object in responses, but you also need `enableQueryLogging()` to actually track query counts and execution times.

### What Gets Tracked

When enabled, every API response includes a `_performance` object with:

```json
{
    "success": true,
    "data": { ... },
    "_performance": {
        "total_time": 156.23,           // Total request time (ms)
        "db_queries": 12,                // Number of queries executed
        "db_time": 89.45,                // Time spent in database (ms)
        "memory_peak": 4194304,          // Peak memory usage (bytes)
        "memory_current": 3145728,       // Current memory usage (bytes)
        "cache": {
            "hits": 8,                   // Cache hits
            "misses": 4,                 // Cache misses
            "size": 12,                  // Queries in cache
            "hit_rate": "66.67%"         // Cache effectiveness
        }
    }
}
```

### Interpreting the Metrics

**total_time** - How long the entire request took
- Target: < 200ms for simple requests
- If > 500ms: Investigate slow queries or external API calls

**db_queries** - How many database queries ran
- Target: < 10 for simple pages, < 50 for complex pages
- If > 100: You likely have an N+1 problem - use eager loading

**db_time** - Time spent in database operations
- If db_time ≈ total_time: Database is the bottleneck
- If db_time << total_time: Look for slow PHP code or external APIs

**cache hit_rate** - How effective query caching is
- Target: > 50% for read-heavy applications
- Low hit rate: Enable query caching or use eager loading

**memory_peak** - Peak memory usage
- Normal: 2-8 MB for typical API requests
- If > 32 MB: Investigate memory leaks or loading too much data

### Example: Before and After Optimization

**Before Optimization:**
```json
{
    "_performance": {
        "total_time": 1234.56,
        "db_queries": 152,
        "db_time": 1100.23,
        "memory_peak": 8388608,
        "cache": {
            "hits": 0,
            "misses": 152,
            "size": 0,
            "hit_rate": "0%"
        }
    }
}
```
**Problems:** Too many queries, no caching, slow database time

**After Enabling Query Cache + Eager Loading:**
```json
{
    "_performance": {
        "total_time": 89.12,
        "db_queries": 5,
        "db_time": 45.67,
        "memory_peak": 4194304,
        "cache": {
            "hits": 147,
            "misses": 5,
            "size": 5,
            "hit_rate": "96.71%"
        }
    }
}
```
**Results:** 93% faster, 97% fewer queries, excellent cache hit rate

### Using in Development

Enable performance monitoring during development to catch issues early:

```php
// config.php
if (getenv('ENVIRONMENT') === 'development') {
    define('DEBUG_PERFORMANCE', true);
    define('QUERY_CACHE', true);
    define('MODEL_CACHE', true);
}
```

Check the `_performance` object after each API call to ensure optimizations are working.

---

## Best Practices

### 1. Enable Query Caching for Production

Query caching is safe for production and provides significant performance benefits:

```php
// config.php
\Kyte\Core\DBI::enableQueryCache(300);  // 300 second TTL
```

**Why:** 50-80% faster repeated queries with zero downside.

### 2. Use Eager Loading for Foreign Keys

Always use `with()` when displaying foreign key relationships:

```php
// GOOD - 2 queries
$orders = new \Kyte\Core\Model(constant('Order'));
$orders->with(['user_id', 'product_id'])->retrieve();

// BAD - 1 + N queries (N+1 problem)
$orders = new \Kyte\Core\Model(constant('Order'));
$orders->retrieve();
```

**Why:** Eliminates N+1 query problem, reduces query count by 80-95%.

### 3. Batch Operations for Bulk Data

Use batch operations when inserting or updating multiple records:

```php
// GOOD - 1 query for 1000 records
$ids = \Kyte\Core\DBI::batchInsert('User', $users, 'ssss');

// BAD - 1000 queries for 1000 records
foreach ($users as $userData) {
    $user = new \Kyte\Core\ModelObject(constant('User'));
    $user->create($userData);
}
```

**Why:** 10-50x faster for bulk operations.

### 4. Monitor Performance in Development

Enable performance monitoring during development:

```php
// config.php
\Kyte\Core\DBI::enableQueryLogging();
define('DEBUG_PERFORMANCE', true);
```

**Why:** Catch performance issues before they reach production.

### 5. Model File Cache Considerations

Only enable `MODEL_CACHE_FILE` if:
- You're on a single server (not load balanced)
- You have write permissions to cache directory
- You're willing to clear cache when models change

```php
// Only enable file cache if requirements are met
define('MODEL_CACHE', true);
define('MODEL_CACHE_FILE', false);  // Disabled for load-balanced environments
```

**Why:** File cache can cause issues in multi-server environments.

### 6. Combine Multiple Optimizations

Stack optimizations for maximum benefit:

```php
// config.php
\Kyte\Core\DBI::enableQueryCache(300);  // Enable query caching
define('MODEL_CACHE', true);             // Enable model caching

// In your code
$orders = new \Kyte\Core\Model(constant('Order'));
$orders->with(['user_id', 'product_id'])  // Eager load relationships
       ->retrieve();
```

**Result:** Multiple performance improvements compound for dramatic speed increases.

### 7. Cache Statistics for Verification

Regularly check cache statistics to ensure optimizations are working:

```php
$stats = \Kyte\Core\DBI::getCacheStats();

// Aim for > 50% hit rate
if ($stats['hits'] + $stats['misses'] > 0) {
    $hitRate = ($stats['hits'] / ($stats['hits'] + $stats['misses'])) * 100;
    if ($hitRate < 50) {
        // Consider enabling query caching or using eager loading
        error_log("Low cache hit rate: {$hitRate}%");
    }
}
```

---

## Troubleshooting

### Query Cache Not Working

**Symptoms:** High query count, low cache hit rate, or `cache.hits: 0` in performance data

**Solutions:**
1. Verify `\Kyte\Core\DBI::enableQueryCache()` is called (a constant alone won't work)
2. Check that queries are SELECT statements (INSERT/UPDATE/DELETE don't cache)
3. Ensure identical queries (different WHERE clauses = different cache keys)

```php
// WRONG - constant doesn't enable caching
define('QUERY_CACHE', true);

// CORRECT - method call enables caching
\Kyte\Core\DBI::enableQueryCache(300);
```

```php
// These are DIFFERENT cache entries
\Kyte\Core\DBI::select('User', ['status' => 'active']);
\Kyte\Core\DBI::select('User', ['status' => 'inactive']);

// These are the SAME cache entry
\Kyte\Core\DBI::select('User', ['status' => 'active']);
\Kyte\Core\DBI::select('User', ['status' => 'active']);
```

### Eager Loading Not Reducing Queries

**Symptoms:** Still seeing many queries with `with()` enabled

**Solutions:**
1. Ensure you're calling `with()` BEFORE `retrieve()`
2. Verify the field name is correct (must match model struct exactly)
3. Check that the field has `fk` defined in the model

```php
// WRONG - with() called after retrieve()
$comments->retrieve();
$comments->with(['user_id']);  // Too late!

// CORRECT - with() called before retrieve()
$comments->with(['user_id'])->retrieve();
```

### Stale Model Cache Data

**Symptoms:** Model changes not reflected in application

**Solutions:**
1. Clear model cache after changing model definitions
2. Disable `MODEL_CACHE_FILE` in development
3. Use cache versioning

```php
// After modifying a model
\Kyte\Core\DBI::clearModelCache('User');

// Or clear all model caches
\Kyte\Core\DBI::clearModelCache();
```

### Batch Insert Failures

**Symptoms:** `batchInsert()` throws errors

**Common Issues:**
1. **Type string length mismatch:** Ensure types match number of columns
2. **Inconsistent columns:** All rows must have identical keys
3. **Wrong type codes:** Verify s/i/d match your data types

```php
// WRONG - type string too short
$users = [
    ['first_name' => 'Alice', 'last_name' => 'Smith', 'email' => 'alice@example.com'],
];
\Kyte\Core\DBI::batchInsert('User', $users, 'ss');  // Missing email type!

// CORRECT - types match columns
\Kyte\Core\DBI::batchInsert('User', $users, 'sss');  // All three fields
```

### High Memory Usage

**Symptoms:** `memory_peak` in performance monitoring is very high

**Solutions:**
1. Reduce page size for Model queries
2. Use `limit` parameter in `retrieve()`
3. Process large datasets in batches

```php
// BAD - loads 10,000 records into memory
$users = new \Kyte\Core\Model(constant('User'));
$users->retrieve();

// GOOD - process in smaller batches
$pageSize = 100;
for ($page = 1; $page <= 100; $page++) {
    $users = new \Kyte\Core\Model(constant('User'), $pageSize, $page);
    $users->retrieve();

    foreach ($users->objects as $user) {
        // Process user
    }

    unset($users);  // Free memory
}
```

### Performance Monitoring Not Appearing or Showing Zero Queries

**Symptoms:** No `_performance` object in API response, or `db_queries: 0` when queries are running

**Solutions:**
1. Verify `DEBUG_PERFORMANCE` constant is defined and set to `true`
2. **Enable query logging** with `\Kyte\Core\DBI::enableQueryLogging()` to track query counts
3. Ensure you're extending from the correct controller base class
4. Check that you're not removing it in your controller

```php
// config.php
\Kyte\Core\DBI::enableQueryLogging();  // Required to track db_queries
define('DEBUG_PERFORMANCE', true);      // Shows _performance in response

// In controller
class MyController extends \Kyte\Mvc\Controller\ModelController {
    // Don't override response building without preserving _performance
}
```

**Common Issue:** Having `DEBUG_PERFORMANCE` enabled but not calling `enableQueryLogging()` will result in:
```json
{
    "_performance": {
        "db_queries": 0,    // Shows 0 because logging is disabled
        "db_time": 0,
        "cache": {
            "hits": 0,
            "misses": 0
        }
    }
}
```

---

## Summary

Kyte-PHP's performance features provide dramatic speed improvements with minimal code changes:

### Quick Wins (Enable These First)

1. **Query Caching** - `define('QUERY_CACHE', true);`
   - 50-80% faster repeated queries
   - Safe for production
   - Zero code changes needed

2. **Model Memory Cache** - `define('MODEL_CACHE', true);`
   - 90%+ faster model access
   - Safe for production
   - Zero code changes needed

3. **Eager Loading** - `$model->with(['foreign_key'])->retrieve();`
   - 80-95% fewer queries
   - Requires code changes but simple
   - Eliminates N+1 problem

### Advanced Optimizations

4. **Batch Operations** - For bulk inserts/updates
   - 10-50x faster
   - Requires code refactoring
   - Worth it for import/export features

5. **Performance Monitoring** - For development/debugging
   - Real-time metrics
   - Identify bottlenecks
   - Verify optimizations working

### Recommended Configuration

```php
// config.php - Production
\Kyte\Core\DBI::enableQueryCache(300);   // Enable query caching
define('MODEL_CACHE', true);              // Enable model caching
define('MODEL_CACHE_FILE', false);        // Disable for load-balanced environments
define('DEBUG_PERFORMANCE', false);       // Disable in production
// Don't enable query logging in production (performance overhead)

// config.php - Development
\Kyte\Core\DBI::enableQueryLogging();    // Track all queries
\Kyte\Core\DBI::enableQueryCache(300);   // Enable query caching
define('MODEL_CACHE', true);              // Enable model caching
define('MODEL_CACHE_FILE', false);        // Easier cache management
define('DEBUG_PERFORMANCE', true);        // Monitor everything
```

By combining these features, you can achieve 10-100x performance improvements for common operations while maintaining full backward compatibility with existing code.

---

Next, read the [Controllers Guide](03-controllers.md) to learn how to implement these optimizations in your API endpoints.
