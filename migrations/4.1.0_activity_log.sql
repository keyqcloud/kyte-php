-- =========================================================================
-- Kyte v4.1.0 - Activity/Audit Logging System
-- =========================================================================
-- IMPORTANT: Backup your database before running this migration
-- This creates the KyteActivityLog table for comprehensive activity tracking
-- =========================================================================

CREATE TABLE IF NOT EXISTS KyteActivityLog (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- WHO
    user_id BIGINT UNSIGNED DEFAULT NULL,
    user_email VARCHAR(255) DEFAULT NULL,
    user_name VARCHAR(255) DEFAULT NULL,
    account_id BIGINT UNSIGNED DEFAULT NULL,
    account_name VARCHAR(255) DEFAULT NULL,
    application_id BIGINT UNSIGNED DEFAULT NULL,
    application_name VARCHAR(255) DEFAULT NULL,

    -- WHAT
    action VARCHAR(20) DEFAULT NULL COMMENT 'GET, POST, PUT, DELETE, LOGIN, LOGOUT, LOGIN_FAIL',
    model_name VARCHAR(255) DEFAULT NULL,
    record_id BIGINT UNSIGNED DEFAULT NULL,
    field VARCHAR(255) DEFAULT NULL,
    value VARCHAR(255) DEFAULT NULL,
    request_data LONGTEXT DEFAULT NULL COMMENT 'JSON request payload (sensitive fields redacted)',
    changes LONGTEXT DEFAULT NULL COMMENT 'JSON diff of old vs new values (PUT only)',

    -- RESULT
    response_code INT DEFAULT NULL,
    response_status VARCHAR(20) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,

    -- WHERE
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(512) DEFAULT NULL,
    session_token VARCHAR(255) DEFAULT NULL COMMENT 'Masked - shows only last 8 chars',
    request_uri VARCHAR(2048) DEFAULT NULL,
    request_method VARCHAR(10) DEFAULT NULL,

    -- META
    severity VARCHAR(20) DEFAULT 'info' COMMENT 'info, warning, critical',
    event_category VARCHAR(50) DEFAULT NULL COMMENT 'auth, data, config, system',
    duration_ms INT DEFAULT NULL,
    kyte_account BIGINT UNSIGNED DEFAULT NULL,

    -- Audit attributes
    created_by INT DEFAULT NULL,
    date_created INT DEFAULT NULL,
    modified_by INT DEFAULT NULL,
    date_modified INT DEFAULT NULL,
    deleted_by INT DEFAULT NULL,
    date_deleted INT DEFAULT NULL,
    deleted INT UNSIGNED DEFAULT 0,

    -- Indexes for common query patterns
    INDEX idx_account_date (kyte_account, date_created),
    INDEX idx_user_id (user_id),
    INDEX idx_model_action (model_name, action),
    INDEX idx_application_id (application_id),
    INDEX idx_severity (severity),
    INDEX idx_event_category (event_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
