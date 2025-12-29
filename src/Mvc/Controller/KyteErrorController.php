<?php

namespace Kyte\Mvc\Controller;

class KyteErrorController extends ModelController
{
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
        switch ($method) {
            case 'get':
                // Convert app_idx to app_id (backward compatible)
                if ($field == 'app_idx') {
                    $app = new \Kyte\Core\ModelObject(Application);
                    if (!$app->retrieve('id', $value)) {
                        throw new \Exception("Failed to retrieve application.");
                    }
                    $field = 'app_id';
                    $value = $app->identifier;
                }

                $query = [];

                // Handle log_type filter
                $logType = isset($_GET['log_type']) ? $_GET['log_type'] : null;

                if ($logType === 'system') {
                    // System logs: log_type='system' AND app_id IS NULL
                    $query[] = ['field' => 'log_type', 'value' => 'system'];
                    $query[] = ['field' => 'app_id', 'value' => null, 'operator' => 'IS'];

                    // Account scoping for system logs
                    if (isset($this->api->account->id)) {
                        $query[] = ['field' => 'account_id', 'value' => $this->api->account->id];
                    }
                } elseif ($logType === 'application') {
                    // Application logs: log_type='application' AND app_id = {current app}
                    $query[] = ['field' => 'log_type', 'value' => 'application'];

                    if (isset($this->api->appId) && strlen($this->api->appId) > 0) {
                        $query[] = ['field' => 'app_id', 'value' => $this->api->appId];
                    }
                } else {
                    // Default behavior (backward compatible): filter by app_id if present
                    if (isset($this->api->appId) && strlen($this->api->appId) > 0) {
                        $query[] = ['field' => 'app_id', 'value' => $this->api->appId];
                    }
                }

                // Handle log_level filter (single or comma-separated)
                if (isset($_GET['log_level']) && !empty($_GET['log_level'])) {
                    $logLevels = explode(',', $_GET['log_level']);
                    $logLevels = array_map('trim', $logLevels);
                    $validLevels = ['debug', 'info', 'warning', 'error', 'critical'];
                    $logLevels = array_intersect($logLevels, $validLevels);

                    if (count($logLevels) === 1) {
                        $query[] = ['field' => 'log_level', 'value' => $logLevels[0]];
                    } elseif (count($logLevels) > 1) {
                        $query[] = ['field' => 'log_level', 'value' => $logLevels, 'operator' => 'IN'];
                    }
                }

                // Handle source filter
                if (isset($_GET['source']) && !empty($_GET['source'])) {
                    $validSources = ['error_handler', 'exception_handler', 'logger', 'output_buffer'];
                    if (in_array($_GET['source'], $validSources)) {
                        $query[] = ['field' => 'source', 'value' => $_GET['source']];
                    }
                }

                // Handle date range filters
                if (isset($_GET['start_date']) && is_numeric($_GET['start_date'])) {
                    $query[] = ['field' => 'date_created', 'value' => (int)$_GET['start_date'], 'operator' => '>='];
                }

                if (isset($_GET['end_date']) && is_numeric($_GET['end_date'])) {
                    $query[] = ['field' => 'date_created', 'value' => (int)$_GET['end_date'], 'operator' => '<='];
                }

                // Apply query conditions
                if (count($query) > 0) {
                    if (is_array($conditions)) {
                        $conditions = array_merge($conditions, $query);
                    } else {
                        $conditions = $query;
                    }
                }

                // Default ordering: newest first
                if (empty($order)) {
                    $order = ['field' => 'date_created', 'direction' => 'DESC'];
                }

                break;

            default:
                throw new \Exception("Unauthorized request method made.");
                break;
        }
    }

    /**
     * Enhance response data with computed fields
     */
    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if ($method === 'get' && $o !== null) {
            // Add log level color for frontend display
            if (isset($o->log_level)) {
                $colorMap = [
                    'debug' => '#6c757d',    // Gray
                    'info' => '#0dcaf0',     // Cyan
                    'warning' => '#ffc107',  // Orange
                    'error' => '#dc3545',    // Red
                    'critical' => '#6f42c1'  // Purple
                ];
                $o->log_level_color = isset($colorMap[$o->log_level]) ? $colorMap[$o->log_level] : '#6c757d';
            }

            // Decode context JSON if present
            if (isset($o->context) && !empty($o->context)) {
                $decoded = json_decode($o->context, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $o->context_decoded = $decoded;
                } else {
                    $o->context_decoded = null;
                }
            } else {
                $o->context_decoded = null;
            }

            // Add formatted date field
            // Note: ModelController already formats date_created based on 'date' => true in model
            if (isset($o->date_created)) {
                $o->date_created_formatted = $o->date_created;
            }
        }
    }

    /* Only allow retrieval of logs */

    // new
    public function new($data)
    {
        throw new \Exception("Unauthorized request method.");
    }

    // update
    public function update($field, $value, $data)
    {
        throw new \Exception("Unauthorized request method.");
    }

    // delete
    public function delete($field, $value)
    {
        throw new \Exception("Unauthorized request method.");
    }
}
