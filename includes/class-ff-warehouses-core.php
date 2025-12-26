<?php
/**
 * Core class for FF Warehouses
 * - Database schema
 * - Basic helpers
 */

if (!defined('ABSPATH')) {
    exit;
}

class FF_Warehouses_Core {

    /**
     * Initialize core logic (runtime hooks if needed)
    */
    public static function init() {
    // Hooks related to WooCommerce stock
    if (class_exists('WooCommerce')) {
        // ✅ NEW: Hook قبل تقليل المخزون للتحقق من الحالة
        add_filter('woocommerce_can_reduce_order_stock', [ __CLASS__, 'can_reduce_order_stock' ], 10, 2);
        
        add_action('woocommerce_reduce_order_stock', [ __CLASS__, 'handle_wc_reduce_order_stock' ], 10, 1);
        add_action('woocommerce_order_status_completed', [ __CLASS__, 'handle_wc_order_completed' ], 10, 1);
        add_action('woocommerce_restore_order_stock', [ __CLASS__, 'handle_wc_restore_order_stock' ], 10, 1);

        // Track changes to order items
        add_action('woocommerce_order_item_quantity_changed', [ __CLASS__, 'handle_order_item_quantity_changed' ], 10, 4);
        add_action('woocommerce_before_delete_order_item', [ __CLASS__, 'handle_before_delete_order_item' ], 10, 1);
        
        // ✅ NEW: Hook لتتبع تغييرات حالة الطلب
        add_action('woocommerce_order_status_changed', [ __CLASS__, 'handle_order_status_changed' ], 10, 4);
    }
}


/**
 * ✅ NEW: منع WooCommerce من تقليل المخزون إذا كان قد تم التعامل معه
 */
public static function can_reduce_order_stock($can_reduce, $order) {
    if (!$order instanceof WC_Order) {
        return $can_reduce;
    }

    // إذا كان الطلب قد تم التعامل مع مخزونه من قبل، لا تسمح بالتقليل
    $stock_status = $order->get_meta('_ffw_stock_status', true);
    
    if (in_array($stock_status, ['reserved', 'consumed', 'restored'], true)) {
        return false;
    }

    return $can_reduce;
}



/**
 * ✅ NEW: تتبع تغييرات حالة الطلب والتعامل الذكي مع المخزون
 */
public static function handle_order_status_changed($order_id, $old_status, $new_status, $order) {
    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order_id);
    }
    
    if (!$order) {
        return;
    }

    $primary = self::get_primary_warehouse();
    if (!$primary) {
        return;
    }

    $warehouse_id = (int) $primary->id;
    $stock_status = $order->get_meta('_ffw_stock_status', true);

    // ✅ تحديد الحالات التي تحتاج حجز مخزون (أضفنا pending)
    $reserve_statuses = ['pending', 'processing', 'on-hold'];
    // ✅ تحديد الحالات التي تحتاج استهلاك مخزون
    $consume_statuses = ['completed'];
    // ✅ تحديد الحالات التي تحتاج استرجاع مخزون
    $restore_statuses = ['cancelled', 'refunded', 'failed', 'trash'];

    self::$suppress_wc_stock_sync = true;

    // ════════════════════════════════════════════════════════════
    // التحول إلى حالة تحتاج حجز (pending, processing, on-hold)
    // ════════════════════════════════════════════════════════════
    if (in_array($new_status, $reserve_statuses, true)) {
        
        // لو المخزون محجوز بالفعل، لا نفعل شيء
        if ($stock_status === 'reserved') {
            self::$suppress_wc_stock_sync = false;
            return;
        }

        // لو المخزون مستهلك (completed → processing/pending)، نرجعه للحجز
        if ($stock_status === 'consumed') {
            foreach ($order->get_items() as $item_id => $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $product = $item->get_product();
                if (!$product) {
                    continue;
                }

                $product_id = $product->get_id();
                $qty        = (float) $item->get_quantity();

                if ($product_id <= 0 || $qty <= 0) {
                    continue;
                }

                $row = self::get_inventory_row($warehouse_id, $product_id, null);
                $current_qty      = $row ? (float) $row->qty : 0.0;
                $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

                // نحول من مستهلك إلى محجوز (بدون تغيير المجموع الفيزيائي)
                $new_reserved = $current_reserved + $qty;

                $res = self::upsert_inventory_row(
                    $warehouse_id,
                    $product_id,
                    null,
                    null, // qty تبقى كما هي
                    $new_reserved,
                    null,
                    null
                );

                if (!is_wp_error($res)) {
                    self::log_stock_action(
                        $warehouse_id,
                        $product_id,
                        'wc_status_change_to_reserve',
                        0,
                        $current_qty,
                        $current_qty,
                        $current_reserved,
                        $new_reserved,
                        $order->get_id(),
                        null,
                        sprintf('Status changed from %s to %s (consumed → reserved)', $old_status, $new_status)
                    );
                }
            }

            $order->update_meta_data('_ffw_stock_status', 'reserved');
            $order->save();
        }
        
        // لو المخزون مسترجع أو فارغ (cancelled → pending/processing)، نحجزه من جديد
        elseif ($stock_status === 'restored' || $stock_status === 'none' || empty($stock_status)) {
            // هنا wc_order_reserve سيتم استدعاؤه تلقائياً
            // لكن نتأكد أنه لن يتكرر
            if ($order->get_meta('_ffw_stock_reserved') !== 'yes') {
                // السماح للهوك الطبيعي بالعمل
            }
        }
    }

    // ════════════════════════════════════════════════════════════
    // التحول إلى حالة مكتملة (completed)
    // ════════════════════════════════════════════════════════════
    elseif (in_array($new_status, $consume_statuses, true)) {
        
        // لو المخزون مستهلك بالفعل، لا نفعل شيء
        if ($stock_status === 'consumed') {
            self::$suppress_wc_stock_sync = false;
            return;
        }

        // لو المخزون محجوز (pending/processing → completed)
        // الهوك handle_wc_order_completed سيتولى الأمر

        // لو المخزون مسترجع أو فارغ (cancelled → completed)، نحتاج استهلاك مباشر
        if ($stock_status === 'restored' || $stock_status === 'none' || empty($stock_status)) {
            foreach ($order->get_items() as $item_id => $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $product = $item->get_product();
                if (!$product) {
                    continue;
                }

                $product_id = $product->get_id();
                $qty        = (float) $item->get_quantity();

                if ($product_id <= 0 || $qty <= 0) {
                    continue;
                }

                $row = self::get_inventory_row($warehouse_id, $product_id, null);
                $current_qty      = $row ? (float) $row->qty : 0.0;
                $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

                // نخصم من المتاح مباشرة (بدون حجز)
                $new_qty = $current_qty - $qty;
                if ($new_qty < 0) {
                    $new_qty = 0;
                }

                $res = self::upsert_inventory_row(
                    $warehouse_id,
                    $product_id,
                    null,
                    $new_qty,
                    null, // reserved تبقى كما هي
                    null,
                    null
                );

                if (!is_wp_error($res)) {
                    self::log_stock_action(
                        $warehouse_id,
                        $product_id,
                        'wc_status_change_to_complete',
                        -$qty,
                        $current_qty,
                        $new_qty,
                        $current_reserved,
                        $current_reserved,
                        $order->get_id(),
                        null,
                        sprintf('Status changed from %s to %s (direct consume)', $old_status, $new_status)
                    );
                }
            }

            $order->update_meta_data('_ffw_stock_status', 'consumed');
            $order->update_meta_data('_ffw_stock_reserved', 'yes');
            $order->update_meta_data('_order_stock_reduced', 'yes');
            $order->save();
        }
    }

    // ════════════════════════════════════════════════════════════
    // التحول إلى حالة ملغاة/مرفوضة
    // ════════════════════════════════════════════════════════════
    elseif (in_array($new_status, $restore_statuses, true)) {
        
        // لو المخزون مسترجع بالفعل، لا نفعل شيء
        if ($stock_status === 'restored' || $stock_status === 'none') {
            self::$suppress_wc_stock_sync = false;
            return;
        }

        // الهوك handle_wc_restore_order_stock سيتولى الأمر
    }

    self::$suppress_wc_stock_sync = false;
}



    /**
     * Plugin activation callback
     * - Creates/updates database tables
     * - Sets default options
     */
    public static function activate() {
        self::create_tables();
        self::set_default_options();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation callback
     * - Currently only flushes rewrite rules
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Internal flag to prevent infinite loops between Woo stock sync
     * when updating stock from Woo actions.
     *
     * When true, upsert_inventory_row will NOT call sync_wc_stock_from_primary.
     */
    protected static $suppress_wc_stock_sync = false;

    /**
     * Create or update required database tables
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Warehouses table
        $sql_warehouses = "CREATE TABLE {$prefix}ffw_warehouses (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY idx_status (status),
            KEY idx_primary (is_primary)
        ) $charset_collate;";

        // Warehouse products table (inventory per warehouse)
        $sql_inventory = "CREATE TABLE {$prefix}ffw_warehouse_products (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            warehouse_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            variation_id BIGINT(20) UNSIGNED NULL,
            qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            reserved_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            price DECIMAL(12,2) NULL,
            min_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_warehouse_product (warehouse_id, product_id, variation_id),
            KEY idx_warehouse (warehouse_id),
            KEY idx_product (product_id)
        ) $charset_collate;";

        // Stock log table for full traceability
        $sql_stock_log = "CREATE TABLE {$prefix}ffw_stock_log (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            warehouse_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            variation_id BIGINT(20) UNSIGNED NULL,
            order_id BIGINT(20) UNSIGNED NULL,
            employee_id BIGINT(20) UNSIGNED NULL,
            action_type VARCHAR(50) NOT NULL,
            qty_change DECIMAL(12,3) NOT NULL,
            qty_before DECIMAL(12,3) NULL,
            qty_after DECIMAL(12,3) NULL,
            reserved_before DECIMAL(12,3) NULL,
            reserved_after DECIMAL(12,3) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_warehouse (warehouse_id),
            KEY idx_product (product_id),
            KEY idx_order (order_id),
            KEY idx_employee (employee_id),
            KEY idx_action_type (action_type)
        ) $charset_collate;";

        // Employee permissions table (optional if you prefer separate from SHRMS JSON)
        $sql_employee_permissions = "CREATE TABLE {$prefix}ffw_employee_permissions (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT(20) UNSIGNED NOT NULL,
            can_view TINYINT(1) NOT NULL DEFAULT 0,
            can_increase_stock TINYINT(1) NOT NULL DEFAULT 0,
            can_decrease_stock TINYINT(1) NOT NULL DEFAULT 0,
            can_transfer TINYINT(1) NOT NULL DEFAULT 0,
            can_pos_orders TINYINT(1) NOT NULL DEFAULT 0,
            can_view_logs TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_employee (employee_id),
            KEY idx_employee (employee_id)
        ) $charset_collate;";

        dbDelta($sql_warehouses);
        dbDelta($sql_inventory);
        dbDelta($sql_stock_log);
        dbDelta($sql_employee_permissions);

        update_option('ffw_db_version', FFW_DB_VERSION);
    }

    /**
     * Set default plugin options
     */
    public static function set_default_options() {
        $defaults = [
            'ffw_enable_plugin'          => 1,
            'ffw_default_primary_slug'   => 'primary',
            'ffw_default_primary_name'   => 'Main WooCommerce Warehouse',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                update_option($key, $value);
            }
        }

        // Ensure there is at least one primary warehouse record
        self::ensure_primary_warehouse();
    }

    /**
     * Ensure we have a primary warehouse row that represents the main WooCommerce stock
     */
    public static function ensure_primary_warehouse() {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $slug   = get_option('ffw_default_primary_slug', 'primary');
        $name   = get_option('ffw_default_primary_name', 'Main WooCommerce Warehouse');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$prefix}ffw_warehouses WHERE is_primary = 1 LIMIT 1"
        ));

        if ($existing) {
            return;
        }

        $wpdb->insert("{$prefix}ffw_warehouses", [
            'name'       => $name,
            'slug'       => sanitize_title($slug),
            'is_primary' => 1,
            'status'     => 'active',
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Basic helper to get warehouse by ID
     */
    public static function get_warehouse($warehouse_id) {
        global $wpdb;

        $warehouse_id = intval($warehouse_id);
        if ($warehouse_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'ffw_warehouses';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $warehouse_id
        ));
    }

    /**
     * Basic helper to get primary warehouse
     */
    public static function get_primary_warehouse() {
        global $wpdb;

        $table = $wpdb->prefix . 'ffw_warehouses';

        return $wpdb->get_row("SELECT * FROM {$table} WHERE is_primary = 1 LIMIT 1");
    }

        /**
     * Get inventory row for a specific warehouse + product (+ variation)
     *
     * @param int      $warehouse_id
     * @param int      $product_id
     * @param int|null $variation_id
     * @return object|null
     */
    public static function get_inventory_row($warehouse_id, $product_id, $variation_id = null) {
        global $wpdb;

        $warehouse_id = intval($warehouse_id);
        $product_id   = intval($product_id);
        $variation_id = $variation_id ? intval($variation_id) : null;

        if ($warehouse_id <= 0 || $product_id <= 0) {
            return null;
        }

        $table = $wpdb->prefix . 'ffw_warehouse_products';

        if ($variation_id) {
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE warehouse_id = %d AND product_id = %d AND variation_id = %d",
                $warehouse_id,
                $product_id,
                $variation_id
            ));
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE warehouse_id = %d AND product_id = %d AND variation_id IS NULL",
            $warehouse_id,
            $product_id
        ));
    }

    /**
     * Upsert inventory row for a warehouse/product
     *
     * @param int        $warehouse_id
     * @param int        $product_id
     * @param int|null   $variation_id
     * @param float|null $qty
     * @param float|null $reserved_qty
     * @param float|null $price
     * @param float|null $min_qty
     * @return int|WP_Error  Row ID or error
     */
    public static function upsert_inventory_row($warehouse_id, $product_id, $variation_id = null, $qty = null, $reserved_qty = null, $price = null, $min_qty = null) {
        global $wpdb;

        $warehouse_id = intval($warehouse_id);
        $product_id   = intval($product_id);
        $variation_id = $variation_id ? intval($variation_id) : null;

        if ($warehouse_id <= 0 || $product_id <= 0) {
            return new WP_Error('ffw_invalid_params', 'Invalid warehouse or product ID');
        }

        $now   = current_time('mysql');
        $table = $wpdb->prefix . 'ffw_warehouse_products';

        $existing = self::get_inventory_row($warehouse_id, $product_id, $variation_id);

        $data = [];
        $formats = [];

        if ($qty !== null) {
            $data['qty'] = (float) $qty;
            $formats[]   = '%f';
        }
        if ($reserved_qty !== null) {
            $data['reserved_qty'] = (float) $reserved_qty;
            $formats[]            = '%f';
        }
        if ($price !== null) {
            $data['price'] = (float) $price;
            $formats[]     = '%f';
        }
        if ($min_qty !== null) {
            $data['min_qty'] = (float) $min_qty;
            $formats[]       = '%f';
        }

        if (empty($data)) {
            return new WP_Error('ffw_no_data', 'No inventory fields provided');
        }

        if ($existing) {
            $data['updated_at'] = $now;
            $formats[]          = '%s';

            $where   = [ 'id' => $existing->id ];
            $wformat = [ '%d' ];

            $updated = $wpdb->update($table, $data, $where, $formats, $wformat);

            if ($updated === false) {
                return new WP_Error('ffw_db_error', 'Failed to update inventory row');
            }

            // If this is the primary warehouse and qty was provided, sync WooCommerce stock
            $primary = self::get_primary_warehouse();
            if ($primary && (int) $primary->id === $warehouse_id && $qty !== null) {
                self::sync_wc_stock_from_primary($product_id, $variation_id, $qty);
            }

            return (int) $existing->id;
        }


        // Insert new row
        $insert_data = array_merge($data, [
            'warehouse_id' => $warehouse_id,
            'product_id'   => $product_id,
            'variation_id' => $variation_id ? $variation_id : null,
            'created_at'   => $now,
        ]);

        $insert_formats = array_merge(
            $formats,
            [ '%d', '%d', '%d', '%s' ]
        );

        $inserted = $wpdb->insert($table, $insert_data, $insert_formats);

        if ($inserted === false) {
            return new WP_Error('ffw_db_error', 'Failed to insert inventory row');
        }

        $row_id = (int) $wpdb->insert_id;

        // If this is the primary warehouse and qty was provided, sync WooCommerce stock
        $primary = self::get_primary_warehouse();
        if ($primary && (int) $primary->id === $warehouse_id && $qty !== null && !self::$suppress_wc_stock_sync) {
            self::sync_wc_stock_from_primary($product_id, $variation_id, $qty);
        }

        return $row_id;

    }

    /**
     * Adjust available qty (qty field) for a warehouse/product
     *
     * @param int   $warehouse_id
     * @param int   $product_id
     * @param float $delta_qty  Positive to increase, negative to decrease
     * @param int|null $variation_id
     * @return bool|WP_Error
     */
    public static function adjust_available_qty($warehouse_id, $product_id, $delta_qty, $variation_id = null) {
        global $wpdb;

        $row = self::get_inventory_row($warehouse_id, $product_id, $variation_id);
        $current_qty = $row ? (float) $row->qty : 0.0;

        $new_qty = $current_qty + (float) $delta_qty;

        $result = self::upsert_inventory_row($warehouse_id, $product_id, $variation_id, $new_qty, null, null, null);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Adjust reserved qty for a warehouse/product
     *
     * @param int   $warehouse_id
     * @param int   $product_id
     * @param float $delta_reserved  Positive to increase, negative to decrease
     * @param int|null $variation_id
     * @return bool|WP_Error
     */
    public static function adjust_reserved_qty($warehouse_id, $product_id, $delta_reserved, $variation_id = null) {
        global $wpdb;

        $row = self::get_inventory_row($warehouse_id, $product_id, $variation_id);
        $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

        $new_reserved = $current_reserved + (float) $delta_reserved;

        $result = self::upsert_inventory_row($warehouse_id, $product_id, $variation_id, null, $new_reserved, null, null);
        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Get available qty (qty) for a warehouse/product
     *
     * @param int      $warehouse_id
     * @param int      $product_id
     * @param int|null $variation_id
     * @return float
     */
    public static function get_available_qty($warehouse_id, $product_id, $variation_id = null) {
        $row = self::get_inventory_row($warehouse_id, $product_id, $variation_id);
        return $row ? (float) $row->qty : 0.0;
    }

    /**
     * Get total physical qty (qty + reserved_qty) for a warehouse/product
     *
     * @param int      $warehouse_id
     * @param int      $product_id
     * @param int|null $variation_id
     * @return float
     */
    public static function get_total_physical_qty($warehouse_id, $product_id, $variation_id = null) {
        $row = self::get_inventory_row($warehouse_id, $product_id, $variation_id);
        if (!$row) {
            return 0.0;
        }
        return (float) $row->qty + (float) $row->reserved_qty;
    }


        /**
     * Sync WooCommerce product stock from primary warehouse qty.
     *
     * This will:
     * - Enable manage_stock for the product.
     * - Set stock quantity equal to the primary warehouse qty.
     * - Set stock status based on qty (instock / outofstock).
     *
     * @param int        $product_id
     * @param int|null   $variation_id (reserved for future use)
     * @param float|null $qty          If null, read from warehouse inventory.
     */
    public static function sync_wc_stock_from_primary($product_id, $variation_id = null, $qty = null) {
        if (!function_exists('wc_get_product')) {
            return;
        }

        $product_id   = intval($product_id);
        $variation_id = $variation_id ? intval($variation_id) : null;

        if ($product_id <= 0) {
            return;
        }

        // If qty not provided, read from primary warehouse inventory
        if ($qty === null) {
            $primary = self::get_primary_warehouse();
            if (!$primary) {
                return;
            }

            $inv = self::get_inventory_row((int) $primary->id, $product_id, $variation_id);
            if (!$inv) {
                return;
            }

            $qty = (float) $inv->qty;
        }

        // Get WooCommerce product (simple product for now)
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) {
            return;
        }

        $qty = (float) $qty;

        // Enable stock management and set quantity
        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);

        // Set stock status
        if ($qty > 0) {
            $product->set_stock_status('instock');
        } else {
            $product->set_stock_status('outofstock');
        }

        $product->save();
    }



        /**
     * Log a stock action in ffw_stock_log
     *
     * @param int        $warehouse_id
     * @param int        $product_id
     * @param string     $action_type
     * @param float      $qty_change
     * @param float|null $qty_before
     * @param float|null $qty_after
     * @param float|null $reserved_before
     * @param float|null $reserved_after
     * @param int|null   $order_id
     * @param int|null   $employee_id
     * @param string     $notes
     * @return int|false Log row ID or false on failure
     */
    public static function log_stock_action(
        $warehouse_id,
        $product_id,
        $action_type,
        $qty_change,
        $qty_before = null,
        $qty_after = null,
        $reserved_before = null,
        $reserved_after = null,
        $order_id = null,
        $employee_id = null,
        $notes = ''
    ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ffw_stock_log';

        $data = [
            'warehouse_id'    => intval($warehouse_id),
            'product_id'      => intval($product_id),
            'variation_id'    => null, // reserved for future use
            'order_id'        => $order_id ? intval($order_id) : null,
            'employee_id'     => $employee_id ? intval($employee_id) : null,
            'action_type'     => sanitize_text_field($action_type),
            'qty_change'      => (float) $qty_change,
            'qty_before'      => $qty_before !== null ? (float) $qty_before : null,
            'qty_after'       => $qty_after !== null ? (float) $qty_after : null,
            'reserved_before' => $reserved_before !== null ? (float) $reserved_before : null,
            'reserved_after'  => $reserved_after !== null ? (float) $reserved_after : null,
            'notes'           => $notes,
            'created_at'      => current_time('mysql'),
        ];

        $formats = [
            '%d', // warehouse_id
            '%d', // product_id
            '%d', // variation_id
            '%d', // order_id
            '%d', // employee_id
            '%s', // action_type
            '%f', // qty_change
            '%f', // qty_before
            '%f', // qty_after
            '%f', // reserved_before
            '%f', // reserved_after
            '%s', // notes
            '%s', // created_at
        ];

        $inserted = $wpdb->insert($table, $data, $formats);
        if ($inserted === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    
        /**
     * Transfer stock between two warehouses for a given product.
     *
     * - This only adjusts available qty (qty field), not reserved_qty.
     * - If you need to move reserved stock, handle that at order-level logic.
     *
     * @param int      $from_warehouse_id
     * @param int      $to_warehouse_id
     * @param int      $product_id
     * @param float    $qty
     * @param int|null $employee_id
     * @return bool|WP_Error
     */
    public static function transfer_stock($from_warehouse_id, $to_warehouse_id, $product_id, $qty, $employee_id = null) {
        global $wpdb;

        $from_warehouse_id = intval($from_warehouse_id);
        $to_warehouse_id   = intval($to_warehouse_id);
        $product_id        = intval($product_id);
        $qty               = (float) $qty;

        if ($from_warehouse_id <= 0 || $to_warehouse_id <= 0 || $product_id <= 0 || $qty <= 0) {
            return new WP_Error('ffw_invalid_params', 'Invalid transfer parameters');
        }

        if ($from_warehouse_id === $to_warehouse_id) {
            return new WP_Error('ffw_same_warehouse', 'Source and destination warehouses must be different');
        }

        // Check available stock in source warehouse
        $available_in_source = self::get_available_qty($from_warehouse_id, $product_id, null);
        if ($available_in_source < $qty) {
            return new WP_Error('ffw_insufficient_stock', 'Not enough stock in source warehouse');
        }

        // Get warehouse names for logging
        $from_wh = self::get_warehouse($from_warehouse_id);
        $to_wh   = self::get_warehouse($to_warehouse_id);

        $from_name = $from_wh ? $from_wh->name : ('ID ' . $from_warehouse_id);
        $to_name   = $to_wh ? $to_wh->name   : ('ID ' . $to_warehouse_id);

        // Pre-build notes for log rows
        $note_out = sprintf(
            'Stock transfer OUT from %s (ID %d) to %s (ID %d)',
            $from_name,
            $from_warehouse_id,
            $to_name,
            $to_warehouse_id
        );

        $note_in = sprintf(
            'Stock transfer IN to %s (ID %d) from %s (ID %d)',
            $to_name,
            $to_warehouse_id,
            $from_name,
            $from_warehouse_id
        );

        // Start transaction to keep data consistent
        $wpdb->query('START TRANSACTION');

        // Decrease from source
        $source_row_before = self::get_inventory_row($from_warehouse_id, $product_id, null);
        $source_qty_before = $source_row_before ? (float) $source_row_before->qty : 0.0;

        $res1 = self::adjust_available_qty($from_warehouse_id, $product_id, -$qty, null);
        if (is_wp_error($res1)) {
            $wpdb->query('ROLLBACK');
            return $res1;
        }

        $source_row_after = self::get_inventory_row($from_warehouse_id, $product_id, null);
        $source_qty_after = $source_row_after ? (float) $source_row_after->qty : 0.0;

        self::log_stock_action(
            $from_warehouse_id,
            $product_id,
            'transfer_out',
            -$qty,
            $source_qty_before,
            $source_qty_after,
            $source_row_before ? (float) $source_row_before->reserved_qty : null,
            $source_row_after ? (float) $source_row_after->reserved_qty : null,
            null,
            $employee_id,
            $note_out
        );

        // Increase in destination
        $dest_row_before = self::get_inventory_row($to_warehouse_id, $product_id, null);
        $dest_qty_before = $dest_row_before ? (float) $dest_row_before->qty : 0.0;

        $res2 = self::adjust_available_qty($to_warehouse_id, $product_id, $qty, null);
        if (is_wp_error($res2)) {
            $wpdb->query('ROLLBACK');
            return $res2;
        }

        $dest_row_after = self::get_inventory_row($to_warehouse_id, $product_id, null);
        $dest_qty_after = $dest_row_after ? (float) $dest_row_after->qty : 0.0;

        self::log_stock_action(
            $to_warehouse_id,
            $product_id,
            'transfer_in',
            $qty,
            $dest_qty_before,
            $dest_qty_after,
            $dest_row_before ? (float) $dest_row_before->reserved_qty : null,
            $dest_row_after ? (float) $dest_row_after->reserved_qty : null,
            null,
            $employee_id,
            $note_in
        );

        $wpdb->query('COMMIT');

        return true;
    }

        /**
     * Apply a quantity delta for an order item to warehouse inventory,
     * depending on order status (reserved vs consumed).
     *
     * @param WC_Order $order
     * @param int      $product_id
     * @param float    $delta_qty      Positive if quantity increased, negative if decreased
     */
    public static function apply_order_item_stock_delta($order, $product_id, $delta_qty) {
        if (! $order instanceof WC_Order) {
        return;
    }

    $product_id = intval($product_id);
    $delta_qty  = (float) $delta_qty;

    if ($product_id <= 0 || $delta_qty == 0.0) {
        return;
    }

        // Determine which warehouse this order affects
        // POS orders يمكن أن يكون لها meta مخصص مثل _ffw_warehouse_id
        $warehouse_id = (int) $order->get_meta('_ffw_warehouse_id');
        if ($warehouse_id <= 0) {
            $primary = self::get_primary_warehouse();
            if (! $primary) {
                return;
            }
            $warehouse_id = (int) $primary->id;
        }

        // نعمل فقط على المنتجات البسيطة/الـ product_id الأساسي (variation support يمكن إضافته لاحقاً)
        $status = $order->get_status(); // e.g. 'processing', 'completed'
        $status = strtolower($status);

        // Load current inventory row
        $row = self::get_inventory_row($warehouse_id, $product_id, null);
        $current_qty      = $row ? (float) $row->qty : 0.0;
        $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

        $new_qty      = $current_qty;
        $new_reserved = $current_reserved;

        // نمنع مزامنة Woo أثناء التعديل لأن Woo بالفعل عدّل مخزون المنتج
        self::$suppress_wc_stock_sync = true;

        if ($status === 'completed') {
            // الكميات تعتبر مباعة / مستهلكة => نعدل المتاح فقط
            $new_qty = $current_qty - $delta_qty; // لو delta_qty موجب يعني زيادة بيع => إنقاص المتاح
        } else {
            // حالات مثل processing / on-hold عندما يكون المخزون محجوزاً
            // delta_qty موجب => زيادة الكمية في الطلب => ننقل المزيد من المتاح إلى المحجوز
            // delta_qty سالب => تقليل الكمية في الطلب => نرجع الكمية من المحجوز إلى المتاح
            $new_qty      = $current_qty - $delta_qty;
            $new_reserved = $current_reserved + $delta_qty;
        }

        // منع القيم السالبة
        if ($new_qty < 0) {
            $new_qty = 0.0;
        }
        if ($new_reserved < 0) {
            $new_reserved = 0.0;
        }

        $res = self::upsert_inventory_row(
            $warehouse_id,
            $product_id,
            null,
            $new_qty,
            $new_reserved,
            null,
            null
        );

        self::$suppress_wc_stock_sync = false;

        if (is_wp_error($res)) {
            return;
        }

        // نكتب Log مخصص لتعديل عناصر الطلب
        $action_type = 'wc_order_item_edit';

        self::log_stock_action(
            $warehouse_id,
            $product_id,
            $action_type,
            $delta_qty, // التغيير في الكمية على مستوى الطلب
            $current_qty,
            $new_qty,
            $current_reserved,
            $new_reserved,
            $order->get_id(),
            null,
            'Order item quantity adjusted (delta: ' . $delta_qty . ', status: ' . $status . ')'
        );
    }


        /**
     * Handle changes in order item quantity (in admin or programmatically).
     *
     * Hooked to: woocommerce_order_item_quantity_changed
     *
     * @param int      $item_id
     * @param int|float $old_qty
     * @param int|float $new_qty
     * @param WC_Order $order
     */
    public static function handle_order_item_quantity_changed($item_id, $old_qty, $new_qty, $order) {
        if (! class_exists('WC_Order_Item_Product')) {
            return;
        }

        if (! $order instanceof WC_Order) {
            return;
        }

        $old_qty = (float) $old_qty;
        $new_qty = (float) $new_qty;
        $delta   = $new_qty - $old_qty;

        if ($delta == 0.0) {
            return;
        }

        $item = $order->get_item($item_id);
        if (! $item instanceof WC_Order_Item_Product) {
            return;
        }

        $product = $item->get_product();
        if (! $product) {
            return;
        }

        $product_id = $product->get_id();
        if ($product_id <= 0) {
            return;
        }

        self::apply_order_item_stock_delta($order, $product_id, $delta);
    }




    /**
 * Handle WooCommerce stock reduction from orders
 * (woocommerce_reduce_order_stock action).
 */

    public static function handle_wc_reduce_order_stock($order) {
    if (!class_exists('WC_Order')) {
        return;
    }

    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order);
    }
    if (!$order) {
        return;
    }

    // ✅ منع المعالجة المكررة
    $stock_status = $order->get_meta('_ffw_stock_status', true);
    if (in_array($stock_status, ['reserved', 'consumed'], true)) {
        return;
    }

    $primary = self::get_primary_warehouse();
    if (!$primary) {
        return;
    }

    $warehouse_id = (int) $primary->id;

    if (!$order->get_meta('_ffw_warehouse_id', true)) {
        $order->update_meta_data('_ffw_warehouse_id', $warehouse_id);
        if (!$order->get_meta('_ffw_source', true)) {
            $order->update_meta_data('_ffw_source', 'delivery');
        }
    }

    self::$suppress_wc_stock_sync = true;

    foreach ($order->get_items() as $item_id => $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }

        $product = $item->get_product();
        if (!$product) {
            continue;
        }

        $product_id = $product->get_id();
        $qty        = (float) $item->get_quantity();

        if ($product_id <= 0 || $qty <= 0) {
            continue;
        }

        $row = self::get_inventory_row($warehouse_id, $product_id, null);
        $current_qty      = $row ? (float) $row->qty : 0.0;
        $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

        $new_qty = $current_qty - $qty;
        if ($new_qty < 0) {
            $new_qty = 0;
        }
        $new_reserved = $current_reserved + $qty;

        $res = self::upsert_inventory_row(
            $warehouse_id,
            $product_id,
            null,
            $new_qty,
            $new_reserved,
            null,
            null
        );

        if (is_wp_error($res)) {
            continue;
        }

        self::log_stock_action(
            $warehouse_id,
            $product_id,
            'wc_order_reserve',
            -$qty,
            $current_qty,
            $new_qty,
            $current_reserved,
            $new_reserved,
            $order->get_id(),
            null,
            'WooCommerce order stock reserved'
        );
    }

    self::$suppress_wc_stock_sync = false;

    $order->update_meta_data('_ffw_stock_reserved', 'yes');
    $order->update_meta_data('_ffw_stock_status', 'reserved');
    $order->save();
}




/**
 * When an order is marked as completed, we convert reserved stock to consumed.
 */

public static function handle_wc_order_completed($order_id) {
    if (!class_exists('WC_Order')) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $primary = self::get_primary_warehouse();
    if (!$primary) {
        return;
    }

    $warehouse_id = (int) $primary->id;
    $stock_status = $order->get_meta('_ffw_stock_status', true);

    // لو المخزون مستهلك بالفعل، لا نفعل شيء
    if ($stock_status === 'consumed') {
        return;
    }

    // لو المخزون غير محجوز، تم التعامل معه في handle_order_status_changed
    if ($stock_status !== 'reserved') {
        return;
    }

    self::$suppress_wc_stock_sync = true;

    foreach ($order->get_items() as $item_id => $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }

        $product = $item->get_product();
        if (!$product) {
            continue;
        }

        $product_id = $product->get_id();
        $qty        = (float) $item->get_quantity();

        if ($product_id <= 0 || $qty <= 0) {
            continue;
        }

        $row = self::get_inventory_row($warehouse_id, $product_id, null);
        if (!$row) {
            continue;
        }

        $current_qty      = (float) $row->qty;
        $current_reserved = (float) $row->reserved_qty;

        if ($current_reserved <= 0) {
            continue;
        }

        $new_reserved = $current_reserved - $qty;
        if ($new_reserved < 0) {
            $new_reserved = 0;
        }

        $res = self::upsert_inventory_row(
            $warehouse_id,
            $product_id,
            null,
            $current_qty,
            $new_reserved,
            null,
            null
        );

        if (is_wp_error($res)) {
            continue;
        }

        self::log_stock_action(
            $warehouse_id,
            $product_id,
            'wc_order_complete',
            0,
            $current_qty,
            $current_qty,
            $current_reserved,
            $new_reserved,
            $order->get_id(),
            null,
            'WooCommerce order completed (reserved consumed)'
        );
    }

    self::$suppress_wc_stock_sync = false;

    $order->update_meta_data('_ffw_stock_status', 'consumed');
    $order->update_meta_data('_ffw_reserved_cleared', 'yes');
    $order->save();
}





/**
 * Mirror WooCommerce stock restoration into primary warehouse.
 * ✅ معالجة ذكية بناءً على حالة المخزون الحالية
 */
/**
 * Mirror WooCommerce stock restoration into primary warehouse.
 * ✅ معالجة ذكية بناءً على حالة المخزون الحالية
 */
public static function handle_wc_restore_order_stock($order) {
    if (!class_exists('WC_Order')) {
        return;
    }

    if (!$order instanceof WC_Order) {
        $order = wc_get_order($order);
    }
    if (!$order) {
        return;
    }

    $primary = self::get_primary_warehouse();
    if (!$primary) {
        return;
    }

    $warehouse_id = (int) $primary->id;

    // ✅ التحقق من الحالة الحالية للمخزون
    $stock_status = $order->get_meta('_ffw_stock_status', true);

    // لو المخزون تم استرجاعه من قبل، لا نفعل شيء
    if ($stock_status === 'restored' || $stock_status === 'none') {
        return;
    }

    // ✅ لو لم يكن هناك stock_status (طلبات قديمة أو API بدون meta)
    // نفحص إذا كان هناك _ffw_stock_reserved أو نفترض أنه محجوز
    if (empty($stock_status)) {
        if ($order->get_meta('_ffw_stock_reserved') === 'yes') {
            $stock_status = 'reserved';
        } else {
            // نفترض أنه محجوز إذا كان من مخزن FF
            $ffw_warehouse = $order->get_meta('_ffw_warehouse_id', true);
            if ($ffw_warehouse) {
                $stock_status = 'reserved';
            } else {
                // طلب Woo عادي، نتركه للمنطق الافتراضي
                $stock_status = 'reserved';
            }
        }
    }

    self::$suppress_wc_stock_sync = true;

    foreach ($order->get_items() as $item_id => $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }

        $product = $item->get_product();
        if (!$product) {
            continue;
        }

        $product_id = $product->get_id();
        $qty        = (float) $item->get_quantity();

        if ($product_id <= 0 || $qty <= 0) {
            continue;
        }

        $row = self::get_inventory_row($warehouse_id, $product_id, null);
        $current_qty      = $row ? (float) $row->qty : 0.0;
        $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

        $new_qty      = $current_qty;
        $new_reserved = $current_reserved;

        // ✅ استرجاع بناءً على الحالة
        if ($stock_status === 'consumed') {
            // المخزون كان مستهلك (completed) → نرجع للمتاح مباشرة
            $new_qty = $current_qty + $qty;
            // المحجوز يبقى كما هو (صفر غالباً)
            
        } elseif ($stock_status === 'reserved') {
            // المخزون كان محجوز (processing/on-hold/pos) → نرجع من المحجوز للمتاح
            $new_qty      = $current_qty + $qty;
            $new_reserved = $current_reserved - $qty;
            if ($new_reserved < 0) {
                $new_reserved = 0;
            }
        }

        $res = self::upsert_inventory_row(
            $warehouse_id,
            $product_id,
            null,
            $new_qty,
            $new_reserved,
            null,
            null
        );

        if (is_wp_error($res)) {
            continue;
        }

        self::log_stock_action(
            $warehouse_id,
            $product_id,
            'wc_order_restore',
            $qty,
            $current_qty,
            $new_qty,
            $current_reserved,
            $new_reserved,
            $order->get_id(),
            null,
            sprintf('WooCommerce order stock restored (from %s state)', $stock_status)
        );
    }

    self::$suppress_wc_stock_sync = false;

    // ✅ تحديث حالة المخزون إلى مسترجع
    $order->update_meta_data('_ffw_stock_status', 'restored');
    $order->delete_meta_data('_ffw_stock_reserved');
    $order->delete_meta_data('_ffw_reserved_cleared');
    $order->save();
}

    /**
     * Handle deletion of an order item: revert its quantity impact.
     *
     * Hooked to: woocommerce_before_delete_order_item
     *
     * @param int $item_id
     */
    public static function handle_before_delete_order_item($item_id) {
        if (! class_exists('WC_Order_Item_Product')) {
            return;
        }

        $item = WC_Order_Factory::get_order_item($item_id);
        if (! $item instanceof WC_Order_Item_Product) {
            return;
        }

        $order = $item->get_order();
        if (! $order) {
            return;
        }

        $product = $item->get_product();
        if (! $product) {
            return;
        }

        $product_id = $product->get_id();
        if ($product_id <= 0) {
            return;
        }

        $qty = (float) $item->get_quantity();
        if ($qty <= 0) {
            return;
        }

        // حذف العنصر يعني delta سالب بقيمة الكمية الحالية
        $delta = -$qty;

        self::apply_order_item_stock_delta($order, $product_id, $delta);
    }


}
