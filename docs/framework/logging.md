# Kyte-PHP Comprehensive Logging Configuration

## Overview

Kyte-PHP provides a multi-level structured logging system that captures errors, warnings, exceptions, notices, and even echo/print statements. This document explains how to configure and use the logging system.

## Core Configuration Constants

### USE_KYTE_ERROR_HANDLER

**Type:** Boolean
**Default:** `false`
**Description:** Enables the Kyte error and exception handler.

```php
define('USE_KYTE_ERROR_HANDLER', true);
```

**Important:** This must be enabled for any logging features to work.

---

### LOG_LEVEL

**Type:** String
**Default:** `'error'` (backward compatible)
**Options:** `'error'`, `'warning'`, `'notice'`, `'all'`
**Description:** Controls which PHP error levels are captured and logged.

```php
define('LOG_LEVEL', 'error');    // Only critical errors (E_ERROR, E_PARSE, etc.)
define('LOG_LEVEL', 'warning');  // Errors + warnings
define('LOG_LEVEL', 'notice');   // Errors + warnings + notices
define('LOG_LEVEL', 'all');      // Everything including deprecated
```

**Level Details:**

- **'error'** (Recommended for Production)
  - E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR
  - Most conservative - only logs critical failures

- **'warning'** (Recommended for Staging)
  - All error-level events
  - E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING
  - Good balance for catching issues before production

- **'notice'** (Recommended for Testing)
  - All warning-level events
  - E_NOTICE, E_USER_NOTICE
  - Helpful for catching potential issues

- **'all'** (Recommended for Development)
  - Everything including E_DEPRECATED, E_USER_DEPRECATED, E_STRICT
  - Maximum visibility for debugging

---

### LOG_OUTPUT_BUFFERING

**Type:** Boolean
**Default:** `false`
**Description:** Captures unexpected output from echo/print statements.

```php
define('LOG_OUTPUT_BUFFERING', true);
```

**When to Use:**
- Development environments to catch debug output
- Tracking down unexpected output before headers
- API endpoints that should have no output

**Performance Impact:** Minimal, but adds slight overhead to every request.

---

### LOG_OUTPUT_BUFFERING_THRESHOLD

**Type:** Integer (bytes)
**Default:** `100`
**Description:** Minimum output size to log when output buffering is enabled.

```php
define('LOG_OUTPUT_BUFFERING_THRESHOLD', 50);
```

**Use Cases:**
- Set lower (50-100) in development to catch everything
- Set higher (500-1000) in production to only catch significant output

---

### KYTE_LOGGER_ENABLED

**Type:** Boolean
**Default:** `false`
**Description:** Enables the structured Logger API for custom logging.

```php
define('KYTE_LOGGER_ENABLED', true);
```

**Important:** Enable this to use `Logger::info()`, `Logger::error()`, etc. in your code.

---

### SLACK_ERROR_WEBHOOK

**Type:** String (URL)
**Default:** Not defined
**Description:** Platform-level Slack webhook URL for error notifications.

```php
define('SLACK_ERROR_WEBHOOK', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

**Behavior:**
- Sends notifications for `error` and `critical` level logs
- Works at both platform and application level
- Application-specific webhooks can be configured per app

---

## Environment-Specific Configurations

### Development Environment

**Goal:** Maximum visibility for debugging

```php
// Enable all logging features
define('USE_KYTE_ERROR_HANDLER', true);
define('LOG_LEVEL', 'all');
define('LOG_OUTPUT_BUFFERING', true);
define('LOG_OUTPUT_BUFFERING_THRESHOLD', 50);
define('KYTE_LOGGER_ENABLED', true);
```

**Why:**
- Catch every possible issue during development
- Output buffering helps find stray debug statements
- Low threshold catches even small outputs

---

### Staging Environment

**Goal:** Catch issues before production without overwhelming logs

```php
// Moderate logging
define('USE_KYTE_ERROR_HANDLER', true);
define('LOG_LEVEL', 'warning');
define('LOG_OUTPUT_BUFFERING', true);
define('LOG_OUTPUT_BUFFERING_THRESHOLD', 100);
define('KYTE_LOGGER_ENABLED', true);
```

**Why:**
- Warnings often indicate real problems that need fixing
- Output buffering still active to catch API output issues
- Standard threshold balances coverage and noise

---

### Production Environment

**Goal:** Capture critical issues without performance impact

```php
// Conservative logging with notifications
define('USE_KYTE_ERROR_HANDLER', true);
define('LOG_LEVEL', 'error');
define('LOG_OUTPUT_BUFFERING', false);
define('KYTE_LOGGER_ENABLED', true);
define('SLACK_ERROR_WEBHOOK', 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL');
```

**Why:**
- Only critical errors logged (minimal DB writes)
- Output buffering disabled (no performance overhead)
- Slack notifications for immediate awareness
- Logger API still available for custom logging

---

## Logger API Usage

The Logger API provides PSR-3 compatible methods for structured logging.

### Basic Usage

```php
use Kyte\Core\Logger;

// Debug - detailed diagnostic information
Logger::debug('Cache miss for key', ['key' => 'user:123', 'ttl' => 3600]);

// Info - general informational messages
Logger::info('User logged in', ['user_id' => 123, 'ip' => $_SERVER['REMOTE_ADDR']]);

// Warning - non-critical issues that should be addressed
Logger::warning('API rate limit approaching', ['remaining' => 95, 'limit' => 1000]);

// Error - runtime errors that don't stop execution
Logger::error('Failed to send email', [
    'to' => 'user@example.com',
    'error' => $e->getMessage()
]);

// Critical - serious failures requiring immediate attention
Logger::critical('Database connection lost', [
    'host' => DB_HOST,
    'attempts' => 3
]);
```

### Context Data

The `$context` array is stored as JSON and can contain any structured data:

```php
Logger::info('Payment processed', [
    'order_id' => 12345,
    'amount' => 99.99,
    'currency' => 'USD',
    'payment_method' => 'credit_card',
    'customer' => [
        'id' => 789,
        'email' => 'customer@example.com'
    ]
]);
```

### Request Correlation

Every log entry automatically includes a `request_id` for tracking related logs:

```php
// All logs in the same request share the same request_id
Logger::info('Request started');
Logger::debug('Processing step 1');
Logger::debug('Processing step 2');
Logger::info('Request completed');

// Later, query by request_id to see the full request flow
```

---

## Log Levels and Severity

### Automatic Mapping

PHP errors are automatically mapped to log levels:

| PHP Error Type | Log Level | Example |
|---|---|---|
| E_ERROR, E_PARSE | critical | Fatal runtime error |
| E_WARNING | warning | Warning that doesn't stop execution |
| E_NOTICE | info | Runtime notice |
| E_DEPRECATED | warning | Deprecated function used |
| E_STRICT | info | Strict standards notice |

### Custom Logging Levels

When using the Logger API, choose the appropriate level:

- **debug**: Detailed information for diagnosing issues (SQL queries, cache operations)
- **info**: General operational information (user actions, API calls)
- **warning**: Issues that should be addressed but aren't critical (deprecated usage, rate limits)
- **error**: Runtime errors that need attention (failed API calls, validation errors)
- **critical**: System failures requiring immediate action (DB down, disk full)

---

## Log Types and Sources

### Log Types

**system**: Platform/framework logs (app_id IS NULL)
- Framework errors
- Database errors
- Server configuration issues

**application**: Application-specific logs (app_id IS NOT NULL)
- Application code errors
- Business logic issues
- User-facing errors

### Log Sources

**error_handler**: PHP error handler captured this
**exception_handler**: Exception handler captured this
**logger**: Explicit Logger API call
**output_buffer**: Captured from echo/print statements

---

## Viewing Logs

### Application Logs

View logs for a specific application in Kyte Shipyard:

1. Navigate to **Application Log** in the menu
2. Logs are automatically filtered by your current app_id
3. Use filters for log level, date range, and source

### System Logs

View platform-level logs in Kyte Shipyard:

1. Navigate to **System Log** in the menu
2. Shows all system-level errors for your account
3. Enhanced filtering by log level, source, and date range

---

## Best Practices

### DO:

✅ Use appropriate log levels (don't log everything as `error`)
✅ Include relevant context data for debugging
✅ Use request_id for correlating related logs
✅ Enable Slack notifications in production
✅ Set LOG_LEVEL='error' in production
✅ Use Logger API for custom structured logging

### DON'T:

❌ Log sensitive data (passwords, API keys, credit cards)
❌ Use LOG_LEVEL='all' in production (performance impact)
❌ Enable output buffering in production (unless needed)
❌ Log in tight loops (causes DB bloat)
❌ Use echo/var_dump for debugging (use Logger::debug instead)

---

## Performance Considerations

### Database Impact

Each log entry writes to the KyteError table. Consider:

- Use appropriate LOG_LEVEL to avoid excessive writes
- Logs auto-rotate based on your retention policy
- Indexes optimize query performance (log_level, log_type, request_id)

### Output Buffering Overhead

- Adds `ob_start()` callback to every request
- Minimal impact (<1ms) when threshold not reached
- Disable in production unless tracking output issues

---

## Troubleshooting

### Logs not appearing?

1. Check `USE_KYTE_ERROR_HANDLER` is `true`
2. Verify `LOG_LEVEL` allows the error type
3. For Logger API, ensure `KYTE_LOGGER_ENABLED` is `true`
4. Check database permissions on KyteError table

### Too many logs?

1. Increase `LOG_LEVEL` to filter out noise ('all' → 'notice' → 'warning' → 'error')
2. Disable output buffering if not needed
3. Review Logger API calls - remove debug logs from production

### Slack notifications not working?

1. Verify `SLACK_ERROR_WEBHOOK` URL is correct
2. Test webhook URL with curl
3. Check error logs for curl errors
4. Notifications only sent for `error` and `critical` levels

---

## Migration from Legacy System

If you're upgrading from the old error handler:

1. **Backward Compatible**: All new fields have defaults
2. **Existing Records**: Old logs display correctly (log_level='error', log_type='system')
3. **Gradual Adoption**: Enable features one at a time
4. **No Code Changes Required**: Existing error handling works unchanged

### Migration Steps:

1. Run database migration: `001_extend_kyte_error_table.sql`
2. Update configuration constants (start with just `USE_KYTE_ERROR_HANDLER`)
3. Verify logs appear correctly
4. Enable additional features (Logger API, output buffering) as needed
5. Update frontend to see new fields

---

## Configuration Reference

Quick reference table:

| Constant | Type | Default | Production | Development |
|---|---|---|---|---|
| USE_KYTE_ERROR_HANDLER | bool | false | true | true |
| LOG_LEVEL | string | 'error' | 'error' | 'all' |
| LOG_OUTPUT_BUFFERING | bool | false | false | true |
| LOG_OUTPUT_BUFFERING_THRESHOLD | int | 100 | - | 50 |
| KYTE_LOGGER_ENABLED | bool | false | true | true |
| SLACK_ERROR_WEBHOOK | string | - | required | optional |

---

## Support

For questions or issues with the logging system:

1. Check this documentation
2. Review the implementation plan
3. Examine existing logs for patterns
4. Contact the development team

---

**Last Updated:** 2025-12-29
**Version:** 1.0.0
