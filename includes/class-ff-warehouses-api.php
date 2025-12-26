<?php
/**
 * REST API for FF Warehouses
 * - Warehouses management
 * - Inventory per warehouse
 * - Stock transfers between warehouses
 */

if (!defined('ABSPATH')) {
    exit;
}

class FF_Warehouses_API {

    /**
     * Initialize REST routes
     */
    public static function init() {
        add_action('rest_api_init', [ __CLASS__, 'register_routes' ]);
    }

    /**
     * Register API routes
     */
    public static function register_routes() {
        $namespace = 'ff/v1';

        // Simple ping route
        register_rest_route($namespace, '/ping', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'ping' ],
            'permission_callback' => '__return_true',
        ]);

        // Warehouses listing / management
        register_rest_route($namespace, '/warehouses', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_warehouses' ],
                'permission_callback' => function ($request) {
                    return FF_Warehouses_Auth::check_permission($request, 'can_view');
                },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_or_update_warehouse' ],
                'permission_callback' => function ($request) {
                    return FF_Warehouses_Auth::check_permission($request, 'can_transfer');
                },
            ],
        ]);

        // Get single warehouse by ID
        register_rest_route($namespace, '/warehouses/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_warehouse' ],
            'permission_callback' => function ($request) {
                return FF_Warehouses_Auth::check_permission($request, 'can_view');
            },
        ]);

        // Delete (soft-disable) warehouse
        register_rest_route($namespace, '/warehouses/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [ __CLASS__, 'disable_warehouse' ],
            'permission_callback' => function ($request) {
                return FF_Warehouses_Auth::check_permission($request, 'can_transfer');
            },
        ]);

        // Inventory per warehouse
        register_rest_route($namespace, '/warehouses/(?P<id>\d+)/products', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_warehouse_products' ],
                'permission_callback' => function ($request) {
                    return FF_Warehouses_Auth::check_permission($request, 'can_view');
                },
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'upsert_warehouse_products' ],
                'permission_callback' => function ($request) {
                    return FF_Warehouses_Auth::check_permission($request, 'can_increase_stock');
                },
            ],
        ]);

        // Stock transfers between warehouses
        register_rest_route($namespace, '/transfers', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'transfer_stock' ],
            'permission_callback' => function ($request) {
                return FF_Warehouses_Auth::check_permission($request, 'can_transfer');
            },
        ]);

        // Inventory adjustments (delta-based) per warehouse
        register_rest_route($namespace, '/warehouses/(?P<id>\d+)/inventory-adjustments', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'adjust_inventory' ],
            'permission_callback' => function ($request) {
                // نفس صلاحية تعديل المخزون في الـ UI
                return FF_Warehouses_Auth::check_permission($request, 'can_increase_stock');
            },
        ]);

        // Stock log with filters
        register_rest_route($namespace, '/stock-log', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_stock_log' ],
            'permission_callback' => function ($request) {
                // استخدم صلاحية can_view_logs أو can_view حسب ما تحب
                return FF_Warehouses_Auth::check_permission($request, 'can_view_logs');
            },
        ]);


    }
    

    /**
     * Simple test endpoint
     */
    public static function ping($request) {
        return new WP_REST_Response([
            'success' => true,
            'message' => 'FF Warehouses API is alive',
        ], 200);
    }

    // ------- Warehouses management -------

    public static function get_warehouses($request) {
        global $wpdb;

        $status = $request->get_param('status');
        $status = $status ? sanitize_text_field($status) : 'active';

        $table  = $wpdb->prefix . 'ffw_warehouses';
        $where  = '1=1';
        $params = [];

        if ($status === 'active' || $status === 'inactive') {
            $where   .= ' AND status = %s';
            $params[] = $status;
        }

        $sql = "SELECT id, name, slug, is_primary, status, created_at, updated_at
                FROM {$table}
                WHERE {$where}
                ORDER BY is_primary DESC, name ASC";

        $rows = empty($params)
            ? $wpdb->get_results($sql)
            : $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id'         => (int) $row->id,
                'name'       => $row->name,
                'slug'       => $row->slug,
                'is_primary' => (bool) $row->is_primary,
                'status'     => $row->status,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], 200);
    }

    public static function get_warehouse($request) {
        global $wpdb;

        $id    = intval($request['id']);
        $table = $wpdb->prefix . 'ffw_warehouses';

        if ($id <= 0) {
            return new WP_Error('ffw_invalid_id', 'Invalid warehouse ID', ['status' => 400]);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, slug, is_primary, status, created_at, updated_at
             FROM {$table}
             WHERE id = %d",
            $id
        ));

        if (!$row) {
            return new WP_Error('ffw_not_found', 'Warehouse not found', ['status' => 404]);
        }

        $data = [
            'id'         => (int) $row->id,
            'name'       => $row->name,
            'slug'       => $row->slug,
            'is_primary' => (bool) $row->is_primary,
            'status'     => $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], 200);
    }


        /**
     * Create or update a warehouse
     */
    public static function create_or_update_warehouse($request) {
        global $wpdb;

        // IMPORTANT: use get_param so it works with both JSON REST and form body
        $id     = intval($request->get_param('id'));
        $name   = $request->get_param('name') ? sanitize_text_field($request->get_param('name')) : '';
        $slug   = $request->get_param('slug') ? sanitize_title($request->get_param('slug')) : '';
        $status = $request->get_param('status') ? sanitize_text_field($request->get_param('status')) : 'active';

        if (empty($name)) {
            return new WP_Error('ffw_missing_name', 'Warehouse name is required', ['status' => 400]);
        }

        if ($status !== 'active' && $status !== 'inactive') {
            $status = 'active';
        }

        if (empty($slug)) {
            $slug = sanitize_title($name);
        }

        $table = $wpdb->prefix . 'ffw_warehouses';

        // If updating, ensure warehouse exists and not primary
        if ($id > 0) {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, is_primary FROM {$table} WHERE id = %d",
                $id
            ));

            if (!$existing) {
                return new WP_Error('ffw_not_found', 'Warehouse not found', ['status' => 404]);
            }

            if ((int) $existing->is_primary === 1) {
                return new WP_Error('ffw_cannot_edit_primary', 'Primary warehouse cannot be edited via this endpoint', ['status' => 403]);
            }
        }

        // Ensure slug is unique (except current warehouse when updating)
        $slug_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE slug = %s AND (%d = 0 OR id != %d) LIMIT 1",
            $slug,
            $id,
            $id
        ));

        if ($slug_exists) {
            $slug = $slug . '-' . uniqid();
        }

        $now = current_time('mysql');

        if ($id > 0) {
            // Update existing
            $updated = $wpdb->update(
                $table,
                [
                    'name'       => $name,
                    'slug'       => $slug,
                    'status'     => $status,
                    'updated_at' => $now,
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ($updated === false) {
                return new WP_Error('ffw_db_error', 'Failed to update warehouse', ['status' => 500]);
            }
        } else {
            // Insert new
            $inserted = $wpdb->insert(
                $table,
                [
                    'name'       => $name,
                    'slug'       => $slug,
                    'is_primary' => 0,
                    'status'     => $status,
                    'created_at' => $now,
                ],
                [ '%s', '%s', '%d', '%s', '%s' ]
            );

            if ($inserted === false) {
                return new WP_Error('ffw_db_error', 'Failed to create warehouse', ['status' => 500]);
            }

            $id = (int) $wpdb->insert_id;
        }

        // Reload row
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, slug, is_primary, status, created_at, updated_at
             FROM {$table}
             WHERE id = %d",
            $id
        ));

        if (!$row) {
            return new WP_Error('ffw_not_found', 'Warehouse not found after save', ['status' => 500]);
        }

        $data = [
            'id'         => (int) $row->id,
            'name'       => $row->name,
            'slug'       => $row->slug,
            'is_primary' => (bool) $row->is_primary,
            'status'     => $row->status,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], 200);
    }



    public static function disable_warehouse($request) {
        global $wpdb;

        $id    = intval($request['id']);
        $table = $wpdb->prefix . 'ffw_warehouses';

        if ($id <= 0) {
            return new WP_Error('ffw_invalid_id', 'Invalid warehouse ID', ['status' => 400]);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_primary, status FROM {$table} WHERE id = %d",
            $id
        ));

        if (!$row) {
            return new WP_Error('ffw_not_found', 'Warehouse not found', ['status' => 404]);
        }

        if ((int) $row->is_primary === 1) {
            return new WP_Error('ffw_cannot_disable_primary', 'Primary warehouse cannot be disabled', ['status' => 403]);
        }

        if ($row->status === 'inactive') {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Warehouse already inactive',
            ], 200);
        }

        $updated = $wpdb->update(
            $table,
            [
                'status'     => 'inactive',
                'updated_at' => current_time('mysql'),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        if ($updated === false) {
            return new WP_Error('ffw_db_error', 'Failed to disable warehouse', ['status' => 500]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Warehouse disabled successfully',
        ], 200);
    }

    // ------- Inventory per warehouse -------

    public static function get_warehouse_products($request) {
        global $wpdb;

        $warehouse_id = intval($request['id']);
        if ($warehouse_id <= 0) {
            return new WP_Error('ffw_invalid_warehouse', 'Invalid warehouse ID', ['status' => 400]);
        }

        $product_id = $request->get_param('product_id') ? intval($request->get_param('product_id')) : 0;

        $table = $wpdb->prefix . 'ffw_warehouse_products';

        $where  = 'warehouse_id = %d';
        $params = [ $warehouse_id ];

        if ($product_id > 0) {
            $where   .= ' AND product_id = %d';
            $params[] = $product_id;
        }

        $sql = "SELECT id, warehouse_id, product_id, variation_id, qty, reserved_qty, price, min_qty, created_at, updated_at
                FROM {$table}
                WHERE {$where}
                ORDER BY product_id ASC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id'           => (int) $row->id,
                'warehouse_id' => (int) $row->warehouse_id,
                'product_id'   => (int) $row->product_id,
                'variation_id' => $row->variation_id ? (int) $row->variation_id : null,
                'qty'          => (float) $row->qty,
                'reserved_qty' => (float) $row->reserved_qty,
                'price'        => $row->price !== null ? (float) $row->price : null,
                'min_qty'      => (float) $row->min_qty,
                'created_at'   => $row->created_at,
                'updated_at'   => $row->updated_at,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], 200);
    }

    public static function upsert_warehouse_products($request) {
        $warehouse_id = intval($request['id']);
        if ($warehouse_id <= 0) {
            return new WP_Error('ffw_invalid_warehouse', 'Invalid warehouse ID', ['status' => 400]);
        }

        $params = $request->get_json_params();
        $items  = isset($params['items']) && is_array($params['items']) ? $params['items'] : [];

        if (empty($items)) {
            return new WP_Error('ffw_no_items', 'No items provided', ['status' => 400]);
        }

        $results = [];
        foreach ($items as $item) {
            $product_id   = isset($item['product_id']) ? intval($item['product_id']) : 0;
            $variation_id = isset($item['variation_id']) ? intval($item['variation_id']) : null;

            if ($product_id <= 0) {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'error',
                    'message'    => 'Invalid product_id',
                ];
                continue;
            }

            $qty          = array_key_exists('qty', $item)          ? (float) $item['qty']          : null;
            $reserved_qty = array_key_exists('reserved_qty', $item) ? (float) $item['reserved_qty'] : null;
            $price        = array_key_exists('price', $item)        ? (float) $item['price']        : null;
            $min_qty      = array_key_exists('min_qty', $item)      ? (float) $item['min_qty']      : null;

            $row_id = FF_Warehouses_Core::upsert_inventory_row(
                $warehouse_id,
                $product_id,
                $variation_id,
                $qty,
                $reserved_qty,
                $price,
                $min_qty
            );

            if (is_wp_error($row_id)) {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'error',
                    'message'    => $row_id->get_error_message(),
                ];
            } else {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'ok',
                    'row_id'     => (int) $row_id,
                ];
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'results' => $results,
        ], 200);
    }


    /**
     * Adjust inventory for a warehouse using deltas (with logging).
     *
     * POST /ff/v1/warehouses/{id}/inventory-adjustments
     *
     * Body example:
     * {
     *   "items": [
     *     {
     *       "product_id": 123,
     *       "delta_qty": 5,          // optional, can be negative
     *       "delta_reserved": 0,     // optional, can be negative
     *       "price": 100.50,         // optional, null to ignore
     *       "min_qty": 2             // optional, null to ignore
     *     }
     *   ]
     * }
     *
     * This mirrors the logic of the admin Bulk Inventory Editor:
     * - Reads current qty/reserved.
     * - Applies deltas.
     * - Uses FF_Warehouses_Core::upsert_inventory_row().
     * - Logs movement with manual_* action types.
     */
    public static function adjust_inventory($request) {
        $warehouse_id = intval($request['id']);
        if ($warehouse_id <= 0) {
            return new WP_Error('ffw_invalid_warehouse', 'Invalid warehouse ID', ['status' => 400]);
        }

        $params = $request->get_json_params();
        $items  = isset($params['items']) && is_array($params['items']) ? $params['items'] : [];

        if (empty($items)) {
            return new WP_Error('ffw_no_items', 'No items provided for adjustment', ['status' => 400]);
        }

        // Get employee_id from SHRMS token if present
        $payload     = FF_Warehouses_Auth::validate_shrms_token($request);
        $employee_id = $payload && isset($payload->sub) ? intval($payload->sub) : null;

        $results = [];

        foreach ($items as $item) {
            $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
            if ($product_id <= 0) {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'error',
                    'message'    => 'Invalid product_id',
                ];
                continue;
            }

            $delta_qty = isset($item['delta_qty']) && $item['delta_qty'] !== null
                ? (float) $item['delta_qty']
                : 0.0;

            $delta_reserved = isset($item['delta_reserved']) && $item['delta_reserved'] !== null
                ? (float) $item['delta_reserved']
                : 0.0;

            $price = array_key_exists('price', $item) ? ( ($item['price'] !== null) ? (float) $item['price'] : null ) : null;
            $min_qty = array_key_exists('min_qty', $item) ? ( ($item['min_qty'] !== null) ? (float) $item['min_qty'] : null ) : null;

            // Read current inventory
            $row = FF_Warehouses_Core::get_inventory_row($warehouse_id, $product_id, null);
            $current_qty      = $row ? (float) $row->qty : 0.0;
            $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

            $new_qty      = $current_qty + $delta_qty;
            $new_reserved = $current_reserved + $delta_reserved;

            if ($new_qty < 0) {
                $new_qty = 0;
            }
            if ($new_reserved < 0) {
                $new_reserved = 0;
            }

            $res = FF_Warehouses_Core::upsert_inventory_row(
                $warehouse_id,
                $product_id,
                null,
                $new_qty,
                $new_reserved,
                $price,
                $min_qty
            );

            if (is_wp_error($res)) {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'error',
                    'message'    => $res->get_error_message(),
                ];
                continue;
            }

            // Determine action_type based on deltas
            $action_type = null;
            if ($delta_qty != 0 && $delta_reserved != 0) {
                $action_type = 'manual_adjust';
            } elseif ($delta_qty != 0) {
                $action_type = $delta_qty > 0 ? 'manual_increase' : 'manual_decrease';
            } elseif ($delta_reserved != 0) {
                $action_type = $delta_reserved > 0 ? 'manual_reserve_increase' : 'manual_reserve_decrease';
            }

            if ($action_type !== null) {
                FF_Warehouses_Core::log_stock_action(
                    $warehouse_id,
                    $product_id,
                    $action_type,
                    $delta_qty,
                    $current_qty,
                    $new_qty,
                    $current_reserved,
                    $new_reserved,
                    null,
                    $employee_id,
                    'API inventory adjustment'
                );
            }

            $results[] = [
                'product_id' => $product_id,
                'status'     => 'ok',
                'qty_before' => $current_qty,
                'qty_after'  => $new_qty,
                'reserved_before' => $current_reserved,
                'reserved_after'  => $new_reserved,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'results' => $results,
        ], 200);
    }



    // ------- Stock transfer -------

    /**
     * Transfer stock between warehouses
     *
     * POST /ff/v1/transfers
     * Body example:
     * {
     *   "from_warehouse_id": 1,
     *   "to_warehouse_id": 2,
     *   "items": [
     *     { "product_id": 123, "qty": 5 },
     *     { "product_id": 456, "qty": 2.5 }
     *   ]
     * }
     */
    public static function transfer_stock($request) {
        $params            = $request->get_json_params();
        $from_warehouse_id = isset($params['from_warehouse_id']) ? intval($params['from_warehouse_id']) : 0;
        $to_warehouse_id   = isset($params['to_warehouse_id'])   ? intval($params['to_warehouse_id'])   : 0;
        $items             = isset($params['items']) && is_array($params['items']) ? $params['items'] : [];

        if ($from_warehouse_id <= 0 || $to_warehouse_id <= 0) {
            return new WP_Error('ffw_invalid_warehouses', 'Invalid from/to warehouse IDs', ['status' => 400]);
        }

        if ($from_warehouse_id === $to_warehouse_id) {
            return new WP_Error('ffw_same_warehouses', 'Source and destination warehouses must be different', ['status' => 400]);
        }

        if (empty($items)) {
            return new WP_Error('ffw_no_items', 'No items provided for transfer', ['status' => 400]);
        }

        // Get employee_id from SHRMS payload if available
        $payload = FF_Warehouses_Auth::validate_shrms_token($request);
        $employee_id = $payload && isset($payload->sub) ? intval($payload->sub) : null;

        $results = [];
        foreach ($items as $item) {
            $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
            $qty        = isset($item['qty'])        ? (float) $item['qty']        : 0.0;

            if ($product_id <= 0 || $qty <= 0) {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'error',
                    'message'    => 'Invalid product_id or qty',
                ];
                continue;
            }

            $res = FF_Warehouses_Core::transfer_stock(
                $from_warehouse_id,
                $to_warehouse_id,
                $product_id,
                $qty,
                $employee_id
            );

            if (is_wp_error($res)) {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'error',
                    'message'    => $res->get_error_message(),
                ];
            } else {
                $results[] = [
                    'product_id' => $product_id,
                    'status'     => 'ok',
                ];
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'results' => $results,
        ], 200);
    }

        /**
     * Get stock log with filters (JSON version of admin Stock Log).
     *
     * GET /ff/v1/stock-log
     * Query params:
     * - warehouse_id (int)
     * - product_id   (int)
     * - order_id     (int)
     * - action_type  (string)
     * - date_from    (Y-m-d)
     * - date_to      (Y-m-d)
     * - limit        (int, default 100)
     */
    public static function get_stock_log($request) {
        global $wpdb;

        $table_logs = $wpdb->prefix . 'ffw_stock_log';

        $warehouse_id = $request->get_param('warehouse_id') ? intval($request->get_param('warehouse_id')) : 0;
        $product_id   = $request->get_param('product_id')   ? intval($request->get_param('product_id'))   : 0;
        $order_id     = $request->get_param('order_id')     ? intval($request->get_param('order_id'))     : 0;
        $action_type  = $request->get_param('action_type')  ? sanitize_text_field($request->get_param('action_type')) : '';
        $date_from    = $request->get_param('date_from')    ? sanitize_text_field($request->get_param('date_from'))   : '';
        $date_to      = $request->get_param('date_to')      ? sanitize_text_field($request->get_param('date_to'))     : '';
        $limit        = $request->get_param('limit')        ? max(1, intval($request->get_param('limit'))) : 100;

        $where  = '1=1';
        $params = [];

        if ($warehouse_id > 0) {
            $where   .= ' AND warehouse_id = %d';
            $params[] = $warehouse_id;
        }

        if ($product_id > 0) {
            $where   .= ' AND product_id = %d';
            $params[] = $product_id;
        }

        if ($order_id > 0) {
            $where   .= ' AND order_id = %d';
            $params[] = $order_id;
        }

        if ($action_type !== '') {
            $where   .= ' AND action_type = %s';
            $params[] = $action_type;
        }

        if ($date_from !== '') {
            $where   .= ' AND created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }

        if ($date_to !== '') {
            $where   .= ' AND created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        $sql = "SELECT id, warehouse_id, product_id, variation_id, order_id, employee_id,
                       action_type, qty_change, qty_before, qty_after,
                       reserved_before, reserved_after, notes, created_at
                FROM {$table_logs}
                WHERE {$where}
                ORDER BY created_at DESC
                LIMIT %d";

        $params_logs = array_merge($params, [ $limit ]);

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params_logs));

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id'              => (int) $row->id,
                'warehouse_id'    => (int) $row->warehouse_id,
                'product_id'      => (int) $row->product_id,
                'variation_id'    => $row->variation_id ? (int) $row->variation_id : null,
                'order_id'        => $row->order_id ? (int) $row->order_id : null,
                'employee_id'     => $row->employee_id ? (int) $row->employee_id : null,
                'action_type'     => $row->action_type,
                'qty_change'      => (float) $row->qty_change,
                'qty_before'      => $row->qty_before !== null ? (float) $row->qty_before : null,
                'qty_after'       => $row->qty_after !== null ? (float) $row->qty_after : null,
                'reserved_before' => $row->reserved_before !== null ? (float) $row->reserved_before : null,
                'reserved_after'  => $row->reserved_after !== null ? (float) $row->reserved_after : null,
                'notes'           => $row->notes,
                'created_at'      => $row->created_at,
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], 200);
    }


}
