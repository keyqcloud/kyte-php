<?php

namespace Kyte\Mvc\Controller;

class KyteErrorController extends ModelController
{
    // Valid log levels
    private const VALID_LOG_LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    // Valid log sources
    private const VALID_SOURCES = ['error_handler', 'exception_handler', 'logger', 'output_buffer'];

    // Log level color mapping
    private const LOG_LEVEL_COLORS = [
        'debug' => '#6c757d',    // Gray
        'info' => '#0dcaf0',     // Cyan
        'warning' => '#ffc107',  // Orange
        'error' => '#dc3545',    // Red
        'critical' => '#6f42c1'  // Purple
    ];

    public function hook_init() {
        $this->requireAccount = false;
        $this->dateformat = 'm/d/Y H:i:s';
    }

    /**
     * Enhanced hook_prequery with filtering support
     *
     * Supported filters:
     * - log_level: Single level or comma-separated (e.g., 'error,critical')
     * - log_type: 'system' or 'application'
     * - source: 'error_handler', 'exception_handler', 'logger', 'output_buffer'
     * - start_date: Unix timestamp for date range start
     * - end_date: Unix timestamp for date range end
     * - app_idx: Application ID (converted to app_id)
     *
     * System logs: log_type='system' AND app_id IS NULL
     * Application logs: log_type='application' AND app_id = {current app}
     */
    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        if ($method !== 'get') {
            throw new \Exception("Unauthorized request method: {$method}");
        }

        // Convert app_idx to app_id (backward compatible)
        if ($field === 'app_idx') {
            $field = 'app_id';
            $value = $this->convertAppIdxToIdentifier($value);
        }

        $query = $this->buildQueryConditions($field, $value);

        // Apply query conditions
        if (!empty($query)) {
            $conditions = is_array($conditions) ? array_merge($conditions, $query) : $query;
        }

        // Default ordering: newest first
        if (empty($order)) {
            $order = ['field' => 'date_created', 'direction' => 'DESC'];
        }
    }

    /**
     * Convert numeric app_idx to app identifier
     *
     * @param mixed $appIdx Numeric application ID
     * @return string Application identifier
     * @throws \Exception If application not found
     */
    private function convertAppIdxToIdentifier($appIdx) {
        $app = new \Kyte\Core\ModelObject(Application);
        if (!$app->retrieve('id', $appIdx)) {
            throw new \Exception("Application with ID {$appIdx} not found.");
        }
        return $app->identifier;
    }

    /**
     * Build query conditions based on filters
     *
     * @param string|null $field Query field
     * @param mixed $value Query value
     * @return array Query conditions
     */
    private function buildQueryConditions($field, $value) {
        $query = [];

        // Handle log_type filter
        $logType = $_GET['log_type'] ?? null;

        if ($logType === 'system') {
            $query = $this->addSystemLogConditions();
        } elseif ($logType === 'application') {
            $query = $this->addApplicationLogConditions($field, $value);
        }

        // Add additional filters
        $query = array_merge(
            $query,
            $this->addLogLevelFilter(),
            $this->addSourceFilter(),
            $this->addDateRangeFilter()
        );

        return $query;
    }

    /**
     * Add system log conditions
     *
     * @return array Query conditions for system logs
     */
    private function addSystemLogConditions() {
        $query = [
            ['field' => 'log_type', 'value' => 'system'],
            ['field' => 'app_id', 'value' => null, 'operator' => 'IS']
        ];

        // Account scoping for system logs
        if (isset($this->api->account->id)) {
            $query[] = ['field' => 'kyte_account', 'value' => $this->api->account->id];
        }

        return $query;
    }

    /**
     * Add application log conditions
     *
     * @param string|null $field Query field
     * @param mixed $value Query value
     * @return array Query conditions for application logs
     */
    private function addApplicationLogConditions($field, $value) {
        $query = [
            ['field' => 'log_type', 'value' => 'application']
        ];

        // Use the converted app_id from $value if field is 'app_id', otherwise use API context
        if ($field === 'app_id' && !empty($value)) {
            $query[] = ['field' => 'app_id', 'value' => $value];
        } elseif (!empty($this->api->appId)) {
            $query[] = ['field' => 'app_id', 'value' => $this->api->appId];
        }

        return $query;
    }

    /**
     * Add log level filter
     *
     * @return array Query conditions for log level
     */
    private function addLogLevelFilter() {
        if (empty($_GET['log_level'])) {
            return [];
        }

        $logLevels = array_map('trim', explode(',', $_GET['log_level']));
        $logLevels = array_intersect($logLevels, self::VALID_LOG_LEVELS);

        if (empty($logLevels)) {
            return [];
        }

        if (count($logLevels) === 1) {
            return [['field' => 'log_level', 'value' => $logLevels[0]]];
        }

        return [['field' => 'log_level', 'value' => $logLevels, 'operator' => 'IN']];
    }

    /**
     * Add source filter
     *
     * @return array Query conditions for source
     */
    private function addSourceFilter() {
        $source = $_GET['source'] ?? null;

        if (empty($source) || !in_array($source, self::VALID_SOURCES, true)) {
            return [];
        }

        return [['field' => 'source', 'value' => $source]];
    }

    /**
     * Add date range filter
     *
     * @return array Query conditions for date range
     */
    private function addDateRangeFilter() {
        $query = [];

        if (isset($_GET['start_date']) && is_numeric($_GET['start_date'])) {
            $query[] = ['field' => 'date_created', 'value' => (int)$_GET['start_date'], 'operator' => '>='];
        }

        if (isset($_GET['end_date']) && is_numeric($_GET['end_date'])) {
            $query[] = ['field' => 'date_created', 'value' => (int)$_GET['end_date'], 'operator' => '<='];
        }

        return $query;
    }

    /**
     * Enhance response data with computed fields
     */
    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if ($method !== 'get' || $o === null || $r === null) {
            return;
        }

        // Add log level color for frontend display
        if (isset($o->log_level)) {
            $r['log_level_color'] = self::LOG_LEVEL_COLORS[$o->log_level] ?? self::LOG_LEVEL_COLORS['error'];
        }

        // Decode context JSON if present
        $r['context_decoded'] = null;
        if (!empty($o->context)) {
            $decoded = json_decode($o->context, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $r['context_decoded'] = $decoded;
            }
        }
    }

    /* Only allow retrieval of logs */

    public function new($data) {
        throw new \Exception("Creating log entries via API is not allowed.");
    }

    public function update($field, $value, $data) {
        throw new \Exception("Updating log entries via API is not allowed.");
    }

    public function delete($field, $value) {
        throw new \Exception("Deleting log entries via API is not allowed.");
    }
}
