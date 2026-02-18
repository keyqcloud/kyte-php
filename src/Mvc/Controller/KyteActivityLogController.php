<?php

namespace Kyte\Mvc\Controller;

class KyteActivityLogController extends ModelController
{
    // Valid actions
    private const VALID_ACTIONS = ['GET', 'POST', 'PUT', 'DELETE', 'LOGIN', 'LOGOUT', 'LOGIN_FAIL'];

    // Valid severity levels
    private const VALID_SEVERITIES = ['info', 'warning', 'critical'];

    // Valid event categories
    private const VALID_CATEGORIES = ['auth', 'data', 'config', 'system'];

    // Severity color mapping
    private const SEVERITY_COLORS = [
        'info' => '#0dcaf0',
        'warning' => '#ffc107',
        'critical' => '#dc3545'
    ];

    // Action color mapping
    private const ACTION_COLORS = [
        'GET' => '#0dcaf0',
        'POST' => '#10b981',
        'PUT' => '#f59e0b',
        'DELETE' => '#dc3545',
        'LOGIN' => '#6f42c1',
        'LOGOUT' => '#6c757d',
        'LOGIN_FAIL' => '#dc3545'
    ];

    public function hook_init() {
        $this->requireAccount = false;
        $this->dateformat = 'm/d/Y H:i:s';
    }

    /**
     * Filter support via custom headers and query params
     *
     * Supported filters:
     * - action: GET, POST, PUT, DELETE, LOGIN, LOGOUT, LOGIN_FAIL
     * - model: Model name
     * - user: User ID
     * - severity: info, warning, critical
     * - category: auth, data, config, system
     * - start_date: Unix timestamp
     * - end_date: Unix timestamp
     * - application: Application ID
     */
    public function hook_prequery($method, &$field, &$value, &$conditions, &$all, &$order) {
        if ($method !== 'get') {
            throw new \Exception("Unauthorized request method: {$method}");
        }

        $query = [];

        // Account scoping
        if (isset($this->api->account->id)) {
            $query[] = ['field' => 'kyte_account', 'value' => $this->api->account->id];
        }

        // Action filter
        $query = array_merge($query, $this->addActionFilter());

        // Model filter
        $query = array_merge($query, $this->addModelFilter());

        // User filter
        $query = array_merge($query, $this->addUserFilter());

        // Severity filter
        $query = array_merge($query, $this->addSeverityFilter());

        // Category filter
        $query = array_merge($query, $this->addCategoryFilter());

        // Date range filter
        $query = array_merge($query, $this->addDateRangeFilter());

        // Application filter
        $query = array_merge($query, $this->addApplicationFilter());

        // Apply conditions
        if (!empty($query)) {
            $conditions = is_array($conditions) ? array_merge($conditions, $query) : $query;
        }

        // Default ordering: newest first
        if (empty($order)) {
            $order = ['field' => 'date_created', 'direction' => 'DESC'];
        }
    }

    private function addActionFilter() {
        $action = isset($_GET['action_type']) ? $_GET['action_type'] : null;
        if (empty($action)) return [];

        $actions = array_map('trim', explode(',', strtoupper($action)));
        $actions = array_intersect($actions, self::VALID_ACTIONS);
        if (empty($actions)) return [];

        if (count($actions) === 1) {
            return [['field' => 'action', 'value' => $actions[0]]];
        }
        return [['field' => 'action', 'value' => array_values($actions), 'operator' => 'IN']];
    }

    private function addModelFilter() {
        $model = isset($_GET['model_name']) ? $_GET['model_name'] : null;
        if (empty($model)) return [];
        return [['field' => 'model_name', 'value' => $model]];
    }

    private function addUserFilter() {
        $user = isset($_GET['user_id']) ? $_GET['user_id'] : null;
        if (empty($user) || !is_numeric($user)) return [];
        return [['field' => 'user_id', 'value' => (int)$user]];
    }

    private function addSeverityFilter() {
        $severity = isset($_GET['severity']) ? $_GET['severity'] : null;
        if (empty($severity)) return [];

        $severities = array_map('trim', explode(',', strtolower($severity)));
        $severities = array_intersect($severities, self::VALID_SEVERITIES);
        if (empty($severities)) return [];

        if (count($severities) === 1) {
            return [['field' => 'severity', 'value' => $severities[0]]];
        }
        return [['field' => 'severity', 'value' => array_values($severities), 'operator' => 'IN']];
    }

    private function addCategoryFilter() {
        $category = isset($_GET['event_category']) ? $_GET['event_category'] : null;
        if (empty($category)) return [];

        if (!in_array($category, self::VALID_CATEGORIES, true)) return [];
        return [['field' => 'event_category', 'value' => $category]];
    }

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

    private function addApplicationFilter() {
        $appId = isset($_GET['application_id']) ? $_GET['application_id'] : null;
        if (empty($appId) || !is_numeric($appId)) return [];
        return [['field' => 'application_id', 'value' => (int)$appId]];
    }

    /**
     * Enhance response data with computed fields
     */
    public function hook_response_data($method, $o, &$r = null, &$d = null) {
        if ($method !== 'get' || $o === null || $r === null) {
            return;
        }

        // Add severity color
        if (isset($o->severity)) {
            $r['severity_color'] = self::SEVERITY_COLORS[$o->severity] ?? self::SEVERITY_COLORS['info'];
        }

        // Add action color
        if (isset($o->action)) {
            $r['action_color'] = self::ACTION_COLORS[$o->action] ?? '#6c757d';
        }

        // Decode request_data JSON if present
        $r['request_data_decoded'] = null;
        if (!empty($o->request_data)) {
            $decoded = json_decode($o->request_data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $r['request_data_decoded'] = $decoded;
            }
        }

        // Decode changes JSON if present
        $r['changes_decoded'] = null;
        if (!empty($o->changes)) {
            $decoded = json_decode($o->changes, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $r['changes_decoded'] = $decoded;
            }
        }
    }

    /* Only allow retrieval of logs */

    public function new($data) {
        throw new \Exception("Creating activity log entries via API is not allowed.");
    }

    public function update($field, $value, $data) {
        throw new \Exception("Updating activity log entries via API is not allowed.");
    }

    public function delete($field, $value) {
        throw new \Exception("Deleting activity log entries via API is not allowed.");
    }
}
