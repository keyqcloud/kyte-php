<?php
/**
 * English (en) Translation File
 *
 * This file contains all translatable strings for the Kyte backend.
 * Key format: category.subcategory.key
 *
 * Parameter substitution: Use {param} syntax
 * Example: 'User {name} created' with ['name' => 'John'] -> 'User John created'
 *
 * @package Kyte
 * @version 4.0.0
 * @language English (en)
 */

return [
    // =========================================================================
    // COMMON ERRORS
    // =========================================================================
    'error.not_found' => 'Record not found',
    'error.unauthorized' => 'Unauthorized access',
    'error.forbidden' => 'Access forbidden',
    'error.invalid_request' => 'Invalid request',
    'error.validation_failed' => 'Validation failed: {field}',
    'error.required_field' => '{field} is required',
    'error.invalid_format' => 'Invalid format for {field}',
    'error.duplicate_entry' => '{field} already exists',
    'error.database_error' => 'Database error occurred',
    'error.server_error' => 'Internal server error',
    'error.not_implemented' => 'Feature not implemented',
    'error.rate_limit_exceeded' => 'Rate limit exceeded. Please try again later',
    'error.service_unavailable' => 'Service temporarily unavailable',
    'error.invalid_credentials' => 'Invalid credentials',
    'error.session_expired' => 'Your session has expired. Please login again',
    'error.permission_denied' => 'Permission denied',
    'error.invalid_token' => 'Invalid or expired token',
    'error.file_too_large' => 'File size exceeds maximum allowed',
    'error.unsupported_file_type' => 'Unsupported file type',
    'error.upload_failed' => 'File upload failed',

    // =========================================================================
    // SUCCESS MESSAGES
    // =========================================================================
    'success.created' => '{model} created successfully',
    'success.updated' => '{model} updated successfully',
    'success.deleted' => '{model} deleted successfully',
    'success.saved' => 'Changes saved successfully',
    'success.sent' => '{item} sent successfully',
    'success.uploaded' => 'File uploaded successfully',
    'success.operation_complete' => 'Operation completed successfully',

    // =========================================================================
    // AUTHENTICATION & SESSIONS
    // =========================================================================
    'auth.login_success' => 'Login successful',
    'auth.logout_success' => 'Logout successful',
    'auth.invalid_credentials' => 'Invalid email or password',
    'auth.account_disabled' => 'Your account has been disabled',
    'auth.session_expired' => 'Your session has expired',
    'auth.session_invalid' => 'Invalid session',
    'auth.password_reset_sent' => 'Password reset instructions sent to your email',
    'auth.password_reset_success' => 'Password reset successful',
    'auth.password_reset_invalid' => 'Invalid or expired password reset token',
    'auth.email_not_found' => 'Email address not found',

    // =========================================================================
    // VALIDATION
    // =========================================================================
    'validation.required' => '{field} is required',
    'validation.email' => 'Invalid email address',
    'validation.min_length' => '{field} must be at least {min} characters',
    'validation.max_length' => '{field} must not exceed {max} characters',
    'validation.numeric' => '{field} must be a number',
    'validation.integer' => '{field} must be an integer',
    'validation.positive' => '{field} must be positive',
    'validation.url' => 'Invalid URL',
    'validation.date' => 'Invalid date format',
    'validation.unique' => '{field} already exists',
    'validation.in' => 'Invalid value for {field}',
    'validation.confirmed' => 'Password confirmation does not match',
    'validation.min_value' => '{field} must be at least {min}',
    'validation.max_value' => '{field} must not exceed {max}',

    // =========================================================================
    // MODELS & CRUD
    // =========================================================================
    'model.create_failed' => 'Failed to create {model}',
    'model.update_failed' => 'Failed to update {model}',
    'model.delete_failed' => 'Failed to delete {model}',
    'model.not_found' => '{model} not found',
    'model.already_exists' => '{model} already exists',
    'model.invalid_id' => 'Invalid {model} ID',

    // =========================================================================
    // CRON JOBS
    // =========================================================================
    'cron.job_not_found' => 'Cron job not found',
    'cron.job_disabled' => 'Cron job is disabled',
    'cron.job_running' => 'Cron job is already running',
    'cron.job_triggered' => 'Cron job triggered successfully',
    'cron.job_in_dlq' => 'Cron job is in dead letter queue',
    'cron.job_recovered' => 'Cron job recovered from dead letter queue',
    'cron.execution_failed' => 'Cron job execution failed',
    'cron.timeout' => 'Cron job execution timed out',
    'cron.invalid_schedule' => 'Invalid schedule configuration',
    'cron.code_invalid' => 'Invalid job code',

    // =========================================================================
    // FILES & STORAGE
    // =========================================================================
    'file.upload_success' => 'File uploaded successfully',
    'file.upload_failed' => 'File upload failed',
    'file.not_found' => 'File not found',
    'file.delete_success' => 'File deleted successfully',
    'file.delete_failed' => 'File deletion failed',
    'file.invalid_type' => 'Invalid file type',
    'file.too_large' => 'File is too large. Maximum size: {max}',
    'file.empty' => 'File is empty',

    // =========================================================================
    // AWS SERVICES
    // =========================================================================
    'aws.invalid_credentials' => 'Invalid AWS credentials',
    'aws.operation_failed' => 'AWS operation failed: {operation}',
    'aws.s3_upload_failed' => 'S3 upload failed',
    'aws.s3_delete_failed' => 'S3 delete failed',
    'aws.ses_send_failed' => 'Email sending failed',
    'aws.cloudfront_invalid' => 'CloudFront distribution not found',

    // =========================================================================
    // API & REQUESTS
    // =========================================================================
    'api.invalid_signature' => 'Invalid API signature',
    'api.missing_header' => 'Missing required header: {header}',
    'api.invalid_method' => 'Invalid HTTP method. Expected: {expected}',
    'api.rate_limited' => 'API rate limit exceeded',
    'api.invalid_json' => 'Invalid JSON in request body',
    'api.missing_parameter' => 'Missing required parameter: {param}',
    'api.invalid_parameter' => 'Invalid parameter: {param}',

    // =========================================================================
    // DATABASE
    // =========================================================================
    'db.connection_failed' => 'Database connection failed',
    'db.query_failed' => 'Database query failed',
    'db.transaction_failed' => 'Database transaction failed',
    'db.integrity_violation' => 'Database integrity constraint violation',
    'db.duplicate_key' => 'Duplicate key error',

    // =========================================================================
    // USER & ACCOUNT
    // =========================================================================
    'user.not_found' => 'User not found',
    'user.created' => 'User created successfully',
    'user.updated' => 'User updated successfully',
    'user.deleted' => 'User deleted successfully',
    'user.email_exists' => 'Email address already exists',
    'user.invalid_email' => 'Invalid email address',
    'user.password_too_short' => 'Password must be at least {min} characters',
    'user.password_mismatch' => 'Passwords do not match',
    'account.not_found' => 'Account not found',
    'account.suspended' => 'Account is suspended',

    // =========================================================================
    // GENERAL ACTIONS
    // =========================================================================
    'action.confirm_delete' => 'Are you sure you want to delete this?',
    'action.confirm_action' => 'Are you sure you want to proceed?',
    'action.cannot_undo' => 'This action cannot be undone',
    'action.processing' => 'Processing...',
    'action.please_wait' => 'Please wait...',
    'action.loading' => 'Loading...',
    'action.saving' => 'Saving...',
    'action.deleting' => 'Deleting...',

    // =========================================================================
    // DATES & TIMES
    // =========================================================================
    'date.today' => 'Today',
    'date.yesterday' => 'Yesterday',
    'date.tomorrow' => 'Tomorrow',
    'date.last_week' => 'Last week',
    'date.next_week' => 'Next week',
    'date.last_month' => 'Last month',
    'date.next_month' => 'Next month',

    // =========================================================================
    // PAGINATION
    // =========================================================================
    'pagination.showing' => 'Showing {from} to {to} of {total} entries',
    'pagination.no_results' => 'No results found',
    'pagination.per_page' => 'Per page',
    'pagination.next' => 'Next',
    'pagination.previous' => 'Previous',
    'pagination.first' => 'First',
    'pagination.last' => 'Last',
];
