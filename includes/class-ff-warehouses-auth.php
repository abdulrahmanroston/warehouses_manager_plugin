<?php
/**
 * Auth layer for FF Warehouses
 * - Validates SHRMS token
 * - Maps to WordPress current_user
 * - Provides permission helpers for warehouses
 */

if (!defined('ABSPATH')) {
    exit;
}

class FF_Warehouses_Auth {

    /**
     * Initialize auth-related hooks
     */
    public static function init() {
        // Currently no global hooks required.
        // REST controllers will call helpers in this class directly.
    }

    /**
     * Extract Bearer token from request or global headers.
     *
     * @param WP_REST_Request|null $request
     * @return string
     */
    public static function get_bearer_token($request = null) {
        $auth_header = '';

        if ($request instanceof WP_REST_Request) {
            $auth_header = $request->get_header('authorization');
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (empty($auth_header)) {
            return '';
        }

        if (stripos($auth_header, 'Bearer ') === 0) {
            return trim(substr($auth_header, 7));
        }

        return trim($auth_header);
    }

    /**
     * Validate SHRMS token and return payload or false.
     *
     * @param WP_REST_Request|null $request
     * @return object|false
     */
    public static function validate_shrms_token($request = null) {
        if (!class_exists('SHRMS_API')) {
            return false;
        }

        $token = self::get_bearer_token($request);
        if (empty($token)) {
            return false;
        }

        // SHRMS_API::validate_token is made public in SHRMS plugin
        $payload = SHRMS_API::validate_token($token);
        return $payload ?: false;
    }

    /**
     * Get SHRMS employee row by payload.
     *
     * @param object $payload
     * @return object|null
     */
    public static function get_employee_from_payload($payload) {
        if (!class_exists('SHRMS_Core')) {
            return null;
        }

        $employee_id = isset($payload->sub) ? intval($payload->sub) : 0;
        if ($employee_id <= 0) {
            return null;
        }

        return SHRMS_Core::get_employee($employee_id);
    }

    /**
     * Set current WordPress user based on SHRMS employee mapping.
     *
     * @param object $employee
     * @return WP_User|null
     */
    public static function set_current_user_from_employee($employee) {
        if (!$employee || !class_exists('SHRMS_Core')) {
            return null;
        }

        $employee_id = intval($employee->id ?? 0);
        if ($employee_id <= 0) {
            return null;
        }

        $wp_user = SHRMS_Core::get_employee_wp_user($employee_id);
        if (!$wp_user instanceof WP_User) {
            return null;
        }

        wp_set_current_user($wp_user->ID);
        return $wp_user;
    }

    /**
     * Combined helper: validate token, get employee, set current_user.
     *
     * @param WP_REST_Request $request
     * @return array|WP_Error
     */
    public static function authenticate_request($request) {
        $payload = self::validate_shrms_token($request);
        if (!$payload) {
            return new WP_Error('ffw_unauthorized', 'Invalid or missing SHRMS token', ['status' => 401]);
        }

        $employee = self::get_employee_from_payload($payload);
        if (!$employee) {
            return new WP_Error('ffw_employee_not_found', 'Employee not found', ['status' => 404]);
        }

        $wp_user = self::set_current_user_from_employee($employee);
        if (!$wp_user) {
            return new WP_Error('ffw_no_wp_user', 'No linked WordPress user for this employee', ['status' => 403]);
        }

        return [
            'payload'  => $payload,
            'employee' => $employee,
            'wp_user'  => $wp_user,
        ];
    }

    /**
     * Get merged permissions for warehouses for given employee.
     * Priority:
     *  - SHRMS permissions_json -> plugins.ff_warehouses
     *  - fallback to ffw_employee_permissions row (if present)
     *
     * @param int $employee_id
     * @return array
     */
    public static function get_warehouse_permissions($employee_id) {
        $employee_id = intval($employee_id);
        if ($employee_id <= 0 || !class_exists('SHRMS_Core')) {
            return [];
        }

        $perms = SHRMS_Core::get_employee_permissions($employee_id);
        $plugin_perms = isset($perms['plugins']['ff_warehouses'])
            ? (array) $perms['plugins']['ff_warehouses']
            : [];

        // Normalize keys and ensure boolean values
        $normalized = [
            'can_view'           => !empty($plugin_perms['can_view']),
            'can_increase_stock' => !empty($plugin_perms['can_increase_stock']),
            'can_decrease_stock' => !empty($plugin_perms['can_decrease_stock']),
            'can_transfer'       => !empty($plugin_perms['can_transfer']),
            'can_pos_orders'     => !empty($plugin_perms['can_pos_orders']),
            'can_view_logs'      => !empty($plugin_perms['can_view_logs']),
        ];

        // Optional: merge with ffw_employee_permissions table if you decide to use it
        global $wpdb;
        $table = $wpdb->prefix . 'ffw_employee_permissions';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE employee_id = %d",
            $employee_id
        ));

        if ($row) {
            $normalized['can_view']           = $normalized['can_view']           || (bool) $row->can_view;
            $normalized['can_increase_stock'] = $normalized['can_increase_stock'] || (bool) $row->can_increase_stock;
            $normalized['can_decrease_stock'] = $normalized['can_decrease_stock'] || (bool) $row->can_decrease_stock;
            $normalized['can_transfer']       = $normalized['can_transfer']       || (bool) $row->can_transfer;
            $normalized['can_pos_orders']     = $normalized['can_pos_orders']     || (bool) $row->can_pos_orders;
            $normalized['can_view_logs']      = $normalized['can_view_logs']      || (bool) $row->can_view_logs;
        }

        return $normalized;
    }

    /**
     * Permission callback helper for REST routes.
     * Usage: pass required capability key (e.g. 'can_view', 'can_transfer', etc.)
     *
     * @param WP_REST_Request $request
     * @param string          $capability_key
     * @return bool|WP_Error
     */
    public static function check_permission($request, $capability_key) {
        $auth = self::authenticate_request($request);
        if (is_wp_error($auth)) {
            return $auth;
        }

        /** @var object $employee */
        $employee    = $auth['employee'];
        $employee_id = intval($employee->id);

        $perms = self::get_warehouse_permissions($employee_id);

        // Super admins in SHRMS can bypass checks
        if (isset($employee->role) && $employee->role === 'super_admin') {
            return true;
        }

        if (!isset($perms[$capability_key])) {
            return new WP_Error('ffw_forbidden', 'Permission key not defined', ['status' => 403]);
        }

        if (!$perms[$capability_key]) {
            return new WP_Error('ffw_forbidden', 'You do not have permission to perform this action', ['status' => 403]);
        }

        return true;
    }
}
