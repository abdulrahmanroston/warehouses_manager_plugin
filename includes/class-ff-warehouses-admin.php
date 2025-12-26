<?php
/**
 * Admin UI for FF Warehouses
 * - Dashboard (summary + quick actions)
 * - Warehouses list & CRUD
 * - Inventory per warehouse (products, qty, price, min_qty)
 * - Stock log viewer
 */

if (!defined('ABSPATH')) {
    exit;
}

class FF_Warehouses_Admin {

    /**
     * Initialize admin hooks
     */
    public static function init() {
    // Register admin menus
    add_action('admin_menu', [ __CLASS__, 'register_menu' ]);

    // Handle form submissions (non-AJAX) via admin-post.php
    add_action('admin_post_ffw_save_warehouse', [ __CLASS__, 'handle_save_warehouse' ]);
    add_action('admin_post_ffw_save_inventory_item', [ __CLASS__, 'handle_save_inventory_item' ]);

    // NEW: bulk inventory update handler
    add_action('admin_post_ffw_bulk_inventory_update', [ __CLASS__, 'handle_bulk_inventory_update' ]);

    // NEW: bulk stock transfer handler
    add_action('admin_post_ffw_bulk_transfer', [ __CLASS__, 'handle_bulk_transfer' ]);
    }


    /**
     * Register top-level admin menu and submenus
     */
    public static function register_menu() {
        $capability = 'manage_woocommerce';

        // Top-level menu → Dashboard
        add_menu_page(
            __('FF Warehouses', 'ff-warehouses'),         // Page title
            __('Warehouses', 'ff-warehouses'),           // Menu title
            $capability,                                 // Capability
            'ff-warehouses',                             // Menu slug
            [ __CLASS__, 'render_dashboard_page' ],      // Callback
            'dashicons-archive',                         // Icon
            56                                           // Position
        );

        // Warehouses list submenu
        add_submenu_page(
            'ff-warehouses',
            __('Warehouses List', 'ff-warehouses'),
            __('Warehouses List', 'ff-warehouses'),
            $capability,
            'ff-warehouses-list',
            [ __CLASS__, 'render_warehouses_page' ]
        );

        // Inventory submenu
        add_submenu_page(
            'ff-warehouses',
            __('Inventory', 'ff-warehouses'),
            __('Inventory', 'ff-warehouses'),
            $capability,
            'ff-warehouses-inventory',
            [ __CLASS__, 'render_inventory_page' ]
        );

        // NEW: Transfers submenu
        add_submenu_page(
            'ff-warehouses',
            __('Transfers', 'ff-warehouses'),
            __('Transfers', 'ff-warehouses'),
            $capability,
            'ff-warehouses-transfers',
            [ __CLASS__, 'render_transfers_page' ]
        );

        // Stock log submenu
        add_submenu_page(
            'ff-warehouses',
            __('Stock Log', 'ff-warehouses'),
            __('Stock Log', 'ff-warehouses'),
            $capability,
            'ff-warehouses-stock-log',
            [ __CLASS__, 'render_stock_log_page' ]
        );

    
    }

    /**
     * Handle warehouse add/update form submission
     */
    public static function handle_save_warehouse() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'ff-warehouses'));
        }

        check_admin_referer('ffw_save_warehouse');

        $warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
        $name         = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $slug         = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
        $status       = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'active';
        $redirect_tab = isset($_POST['ffw_redirect_tab']) ? sanitize_text_field($_POST['ffw_redirect_tab']) : 'dashboard';

        if (empty($name)) {
            wp_redirect(add_query_arg([
                'page'    => $redirect_tab === 'warehouses' ? 'ff-warehouses-list' : 'ff-warehouses',
                'ffw_msg' => 'missing_name',
            ], admin_url('admin.php')));
            exit;
        }

        $payload = [
            'name'   => $name,
            'slug'   => $slug,
            'status' => $status,
        ];

        if ($warehouse_id > 0) {
            $payload['id'] = $warehouse_id;
        }

        // Use FF_Warehouses_API logic to keep behavior unified with REST
        $request = new WP_REST_Request('POST', '/ff/v1/warehouses');
        $request->set_body_params($payload);

        $response = FF_Warehouses_API::create_or_update_warehouse($request);

        if (is_wp_error($response)) {
            wp_redirect(add_query_arg([
                'page'    => $redirect_tab === 'warehouses' ? 'ff-warehouses-list' : 'ff-warehouses',
                'ffw_msg' => 'error',
                'ffw_err' => $response->get_error_message(),
            ], admin_url('admin.php')));
            exit;
        }

        wp_redirect(add_query_arg([
            'page'    => $redirect_tab === 'warehouses' ? 'ff-warehouses-list' : 'ff-warehouses',
            'ffw_msg' => 'saved',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handle inventory item upsert form submission
     */
    public static function handle_save_inventory_item() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'ff-warehouses'));
        }

        check_admin_referer('ffw_save_inventory_item');

        $warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
        $product_id   = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $qty          = isset($_POST['qty']) ? floatval($_POST['qty']) : null;
        $reserved_qty = isset($_POST['reserved_qty']) ? floatval($_POST['reserved_qty']) : null;
        $price        = isset($_POST['price']) ? floatval($_POST['price']) : null;
        $min_qty      = isset($_POST['min_qty']) ? floatval($_POST['min_qty']) : null;
        $redirect_tab = isset($_POST['ffw_redirect_tab']) ? sanitize_text_field($_POST['ffw_redirect_tab']) : 'inventory';

        if ($warehouse_id <= 0 || $product_id <= 0) {
            wp_redirect(add_query_arg([
                'page'        => $redirect_tab === 'dashboard' ? 'ff-warehouses' : 'ff-warehouses-inventory',
                'ffw_msg'     => 'invalid_params',
                'warehouse_id'=> $warehouse_id,
            ], admin_url('admin.php')));
            exit;
        }

        $result = FF_Warehouses_Core::upsert_inventory_row(
            $warehouse_id,
            $product_id,
            null,
            $qty,
            $reserved_qty,
            $price,
            $min_qty
        );

        if (is_wp_error($result)) {
            wp_redirect(add_query_arg([
                'page'        => $redirect_tab === 'dashboard' ? 'ff-warehouses' : 'ff-warehouses-inventory',
                'ffw_msg'     => 'error',
                'ffw_err'     => $result->get_error_message(),
                'warehouse_id'=> $warehouse_id,
            ], admin_url('admin.php')));
            exit;
        }

        wp_redirect(add_query_arg([
            'page'        => $redirect_tab === 'dashboard' ? 'ff-warehouses' : 'ff-warehouses-inventory',
            'ffw_msg'     => 'saved',
            'warehouse_id'=> $warehouse_id,
        ], admin_url('admin.php')));
        exit;
    }


    
        /**
     * Handle bulk inventory update from the admin table
     *
     * - For each enabled product:
     *   - Read current qty & reserved from warehouse inventory.
     *   - Apply delta_qty (±) and delta_reserved (±).
     *   - Save new values via FF_Warehouses_Core::upsert_inventory_row.
     *   - Log the change in ffw_stock_log.
     */
    public static function handle_bulk_inventory_update() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'ff-warehouses'));
        }

        check_admin_referer('ffw_bulk_inventory_update');

        $warehouse_id = isset($_POST['warehouse_id']) ? intval($_POST['warehouse_id']) : 0;
        if ($warehouse_id <= 0) {
            wp_redirect(add_query_arg([
                'page'        => 'ff-warehouses-inventory',
                'ffw_msg'     => 'invalid_params',
            ], admin_url('admin.php')));
            exit;
        }

        $enabled      = isset($_POST['enabled']) && is_array($_POST['enabled']) ? $_POST['enabled'] : [];
        $delta_qty    = isset($_POST['delta_qty']) && is_array($_POST['delta_qty']) ? $_POST['delta_qty'] : [];
        $delta_res    = isset($_POST['delta_reserved']) && is_array($_POST['delta_reserved']) ? $_POST['delta_reserved'] : [];
        $price_data   = isset($_POST['price']) && is_array($_POST['price']) ? $_POST['price'] : [];
        $min_data     = isset($_POST['min_qty']) && is_array($_POST['min_qty']) ? $_POST['min_qty'] : [];

        if (empty($enabled)) {
            wp_redirect(add_query_arg([
                'page'        => 'ff-warehouses-inventory',
                'ffw_msg'     => 'no_items',
                'warehouse_id'=> $warehouse_id,
            ], admin_url('admin.php')));
            exit;
        }

        $errors = [];

        foreach ($enabled as $product_id_str => $flag) {
            $product_id = intval($product_id_str);
            if ($product_id <= 0) {
                continue;
            }

            $delta = isset($delta_qty[$product_id]) && $delta_qty[$product_id] !== ''
                ? (float) $delta_qty[$product_id]
                : 0.0;

            $delta_reserved = isset($delta_res[$product_id]) && $delta_res[$product_id] !== ''
                ? (float) $delta_res[$product_id]
                : 0.0;

            $price = isset($price_data[$product_id]) && $price_data[$product_id] !== ''
                ? (float) $price_data[$product_id]
                : null;

            $min_qty = isset($min_data[$product_id]) && $min_data[$product_id] !== ''
                ? (float) $min_data[$product_id]
                : null;

            // Read current values
            $row = FF_Warehouses_Core::get_inventory_row($warehouse_id, $product_id, null);
            $current_qty      = $row ? (float) $row->qty : 0.0;
            $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

            // New values
            $new_qty       = $current_qty + $delta;
            $new_reserved  = $current_reserved + $delta_reserved;

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
                $errors[] = $res->get_error_message();
                continue;
            }

            // Determine action_type for logging
            $action_type = null;
            if ($delta != 0 && $delta_reserved != 0) {
                $action_type = 'manual_adjust';
            } elseif ($delta != 0) {
                $action_type = $delta > 0 ? 'manual_increase' : 'manual_decrease';
            } elseif ($delta_reserved != 0) {
                $action_type = $delta_reserved > 0 ? 'manual_reserve_increase' : 'manual_reserve_decrease';
            }

            if ($action_type !== null) {
                FF_Warehouses_Core::log_stock_action(
                    $warehouse_id,
                    $product_id,
                    $action_type,
                    $delta,
                    $current_qty,
                    $new_qty,
                    $current_reserved,
                    $new_reserved,
                    null,
                    null,
                    'Admin bulk inventory adjustment'
                );
            }
        }

        $args = [
            'page'        => 'ff-warehouses-inventory',
            'warehouse_id'=> $warehouse_id,
        ];

        if (!empty($errors)) {
            $args['ffw_msg'] = 'error';
            $args['ffw_err'] = implode(' | ', $errors);
        } else {
            $args['ffw_msg'] = 'saved';
        }

        wp_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }


        /**
     * Handle bulk stock transfer between warehouses from admin
     *
     * Expected POST:
     * - from_warehouse_id
     * - to_warehouse_id
     * - transfer_qty[product_id] = number > 0
     */
    public static function handle_bulk_transfer() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to perform this action.', 'ff-warehouses'));
        }

        check_admin_referer('ffw_bulk_transfer');

        $from_warehouse_id = isset($_POST['from_warehouse_id']) ? intval($_POST['from_warehouse_id']) : 0;
        $to_warehouse_id   = isset($_POST['to_warehouse_id']) ? intval($_POST['to_warehouse_id']) : 0;

        if ($from_warehouse_id <= 0 || $to_warehouse_id <= 0 || $from_warehouse_id === $to_warehouse_id) {
            wp_redirect(add_query_arg([
                'page'              => 'ff-warehouses-transfers',
                'ffw_msg'           => 'invalid_warehouses',
                'from_warehouse_id' => $from_warehouse_id,
                'to_warehouse_id'   => $to_warehouse_id,
            ], admin_url('admin.php')));
            exit;
        }

        // نستخدم فقط transfer_qty، أي صف فيه كمية > 0 يعتبر مطلوب تحويله
        $transfer_qty = isset($_POST['transfer_qty']) && is_array($_POST['transfer_qty']) ? $_POST['transfer_qty'] : [];

        if (empty($transfer_qty)) {
            wp_redirect(add_query_arg([
                'page'              => 'ff-warehouses-transfers',
                'ffw_msg'           => 'no_items',
                'from_warehouse_id' => $from_warehouse_id,
                'to_warehouse_id'   => $to_warehouse_id,
            ], admin_url('admin.php')));
            exit;
        }

        $errors   = [];
        $has_item = false;

        foreach ($transfer_qty as $product_id_str => $qty_raw) {
            $product_id = intval($product_id_str);
            if ($product_id <= 0) {
                continue;
            }

            $qty = $qty_raw !== '' ? (float) $qty_raw : 0.0;
            if ($qty <= 0) {
                continue;
            }

            $has_item = true;

            // استخدم منطق Core للتحويل + logging + transaction
            $res = FF_Warehouses_Core::transfer_stock(
                $from_warehouse_id,
                $to_warehouse_id,
                $product_id,
                $qty,
                null // employee_id في سياق admin
            );

            if (is_wp_error($res)) {
                $errors[] = sprintf(
                    __('Product %d: %s', 'ff-warehouses'),
                    $product_id,
                    $res->get_error_message()
                );
            }
        }

        // if no valid items were processed
        if (!$has_item && empty($errors)) {
            wp_redirect(add_query_arg([
                'page'              => 'ff-warehouses-transfers',
                'ffw_msg'           => 'no_items',
                'from_warehouse_id' => $from_warehouse_id,
                'to_warehouse_id'   => $to_warehouse_id,
            ], admin_url('admin.php')));
            exit;
        }

        $args = [
            'page'              => 'ff-warehouses-transfers',
            'from_warehouse_id' => $from_warehouse_id,
            'to_warehouse_id'   => $to_warehouse_id,
        ];

        if (!empty($errors)) {
            $args['ffw_msg'] = 'error';
            $args['ffw_err'] = implode(' | ', $errors);
        } else {
            $args['ffw_msg'] = 'saved';
        }

        wp_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }


    /**
     * Dashboard page: summary + quick actions
     */
    public static function render_dashboard_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'ff-warehouses'));
        }

        global $wpdb;

        // Warehouses summary
        $warehouses_table = $wpdb->prefix . 'ffw_warehouses';
        $inventory_table  = $wpdb->prefix . 'ffw_warehouse_products';
        $log_table        = $wpdb->prefix . 'ffw_stock_log';

        $warehouses = $wpdb->get_results(
            "SELECT id, name, slug, is_primary, status, created_at, updated_at
             FROM {$warehouses_table}
             ORDER BY is_primary DESC, name ASC"
        );

        $total_wh    = count($warehouses);
        $active_wh   = 0;
        $inactive_wh = 0;

        foreach ($warehouses as $wh) {
            if ($wh->status === 'active') {
                $active_wh++;
            } else {
                $inactive_wh++;
            }
        }

        // Inventory summary
        $total_inventory_rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$inventory_table}");
        $low_stock_count      = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$inventory_table}
             WHERE min_qty > 0 AND qty <= min_qty"
        );

        // Recent stock log (limit 10)
        $stock_log = $wpdb->get_results(
            "SELECT id, warehouse_id, product_id, action_type, qty_change, qty_before, qty_after,
                    reserved_before, reserved_after, employee_id, order_id, notes, created_at
             FROM {$log_table}
             ORDER BY created_at DESC
             LIMIT 10"
        );

        $message = '';
        if (!empty($_GET['ffw_msg'])) {
            if ($_GET['ffw_msg'] === 'saved') {
                $message = __('Saved successfully.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'missing_name') {
                $message = __('Name is required.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'invalid_params') {
                $message = __('Invalid parameters.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'error' && !empty($_GET['ffw_err'])) {
                $message = sprintf(__('Error: %s', 'ff-warehouses'), esc_html($_GET['ffw_err']));
            }
        }

        // For quick inventory form
        $selected_warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
        if ($selected_warehouse_id <= 0 && !empty($warehouses)) {
            $selected_warehouse_id = (int) $warehouses[0]->id;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('FF Warehouses Dashboard', 'ff-warehouses'); ?></h1>

            <?php if ($message) : ?>
                <div class="notice notice-info is-dismissible" style="margin-top:15px;">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Summary cards -->
            <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">
                <div style="flex:1;min-width:200px;padding:15px;border:1px solid #ddd;background:#fff;border-radius:4px;">
                    <h3><?php esc_html_e('Warehouses', 'ff-warehouses'); ?></h3>
                    <p><?php printf(esc_html__('%d total', 'ff-warehouses'), $total_wh); ?></p>
                    <p><?php printf(esc_html__('%d active / %d inactive', 'ff-warehouses'), $active_wh, $inactive_wh); ?></p>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=ff-warehouses-list')); ?>" class="button button-small">
                        <?php esc_html_e('Manage Warehouses', 'ff-warehouses'); ?>
                    </a></p>
                </div>

                <div style="flex:1;min-width:200px;padding:15px;border:1px solid #ddd;background:#fff;border-radius:4px;">
                    <h3><?php esc_html_e('Inventory', 'ff-warehouses'); ?></h3>
                    <p><?php printf(esc_html__('%d inventory records', 'ff-warehouses'), $total_inventory_rows); ?></p>
                    <p><?php printf(esc_html__('%d low stock items', 'ff-warehouses'), $low_stock_count); ?></p>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=ff-warehouses-inventory')); ?>" class="button button-small">
                        <?php esc_html_e('Manage Inventory', 'ff-warehouses'); ?>
                    </a></p>
                </div>

                <div style="flex:1;min-width:200px;padding:15px;border:1px solid #ddd;background:#fff;border-radius:4px;">
                    <h3><?php esc_html_e('Stock Log', 'ff-warehouses'); ?></h3>
                    <p><?php esc_html_e('Latest movements at a glance.', 'ff-warehouses'); ?></p>
                    <p><a href="<?php echo esc_url(admin_url('admin.php?page=ff-warehouses-stock-log')); ?>" class="button button-small">
                        <?php esc_html_e('View Full Log', 'ff-warehouses'); ?>
                    </a></p>
                </div>
            </div>

            <!-- Warehouses list (compact) -->
            <h2 style="margin-top:30px;"><?php esc_html_e('Warehouses Overview', 'ff-warehouses'); ?></h2>
            <table class="widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Name', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Slug', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Primary', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Status', 'ff-warehouses'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($warehouses)) : ?>
                        <?php foreach ($warehouses as $row) : ?>
                            <tr>
                                <td><?php echo (int) $row->id; ?></td>
                                <td><?php echo esc_html($row->name); ?></td>
                                <td><?php echo esc_html($row->slug); ?></td>
                                <td>
                                    <?php
                                    if ((int) $row->is_primary === 1) {
                                        echo '<span style="color:green;font-weight:bold;">' . esc_html__('Yes', 'ff-warehouses') . '</span>';
                                    } else {
                                        echo esc_html__('No', 'ff-warehouses');
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ($row->status === 'active') {
                                        echo '<span style="color:green;">' . esc_html__('Active', 'ff-warehouses') . '</span>';
                                    } else {
                                        echo '<span style="color:#999;">' . esc_html__('Inactive', 'ff-warehouses') . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5">
                                <?php esc_html_e('No warehouses found.', 'ff-warehouses'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Quick add warehouse -->
            <h2 style="margin-top:30px;"><?php esc_html_e('Quick Add Warehouse', 'ff-warehouses'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:500px;margin-top:10px;">
                <?php wp_nonce_field('ffw_save_warehouse'); ?>
                <input type="hidden" name="action" value="ffw_save_warehouse">
                <input type="hidden" name="warehouse_id" value="0">
                <input type="hidden" name="ffw_redirect_tab" value="dashboard">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ffw_name"><?php esc_html_e('Name', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="name" id="ffw_name" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_slug"><?php esc_html_e('Slug', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="slug" id="ffw_slug" class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Leave empty to auto-generate from name.', 'ff-warehouses'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_status"><?php esc_html_e('Status', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <select name="status" id="ffw_status">
                                <option value="active"><?php esc_html_e('Active', 'ff-warehouses'); ?></option>
                                <option value="inactive"><?php esc_html_e('Inactive', 'ff-warehouses'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Add Warehouse', 'ff-warehouses')); ?>
            </form>

            <!-- Quick inventory form -->
            <h2 style="margin-top:30px;"><?php esc_html_e('Quick Inventory Update', 'ff-warehouses'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:600px;margin-top:10px;">
                <?php wp_nonce_field('ffw_save_inventory_item'); ?>
                <input type="hidden" name="action" value="ffw_save_inventory_item">
                <input type="hidden" name="ffw_redirect_tab" value="dashboard">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ffw_quick_warehouse"><?php esc_html_e('Warehouse', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <select name="warehouse_id" id="ffw_quick_warehouse">
                                <?php foreach ($warehouses as $wh) : ?>
                                    <option value="<?php echo (int) $wh->id; ?>" <?php selected($selected_warehouse_id, $wh->id); ?>>
                                        <?php
                                        echo esc_html($wh->name);
                                        if ((int) $wh->is_primary === 1) {
                                            echo ' (' . esc_html__('Primary', 'ff-warehouses') . ')';
                                        }
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_product_id"><?php esc_html_e('Product ID', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="product_id" id="ffw_product_id" class="regular-text" required min="1">
                            <p class="description">
                                <?php esc_html_e('Enter WooCommerce product ID.', 'ff-warehouses'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_qty"><?php esc_html_e('Qty (available)', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="number" step="0.001" name="qty" id="ffw_qty" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_reserved_qty"><?php esc_html_e('Reserved Qty', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="number" step="0.001" name="reserved_qty" id="ffw_reserved_qty" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_price"><?php esc_html_e('Warehouse Price', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="number" step="0.01" name="price" id="ffw_price" class="regular-text">
                            <p class="description">
                                <?php esc_html_e('Leave empty to use default WooCommerce product price.', 'ff-warehouses'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_min_qty"><?php esc_html_e('Min Qty', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="number" step="0.001" name="min_qty" id="ffw_min_qty" class="regular-text">
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save Inventory Item', 'ff-warehouses')); ?>
            </form>

            <!-- Recent stock log -->
            <h2 style="margin-top:30px;"><?php esc_html_e('Recent Stock Movements', 'ff-warehouses'); ?></h2>
            <table class="widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Warehouse', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Product ID', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Action', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Qty Change', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Qty Before → After', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Employee ID', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Order ID', 'ff-warehouses'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($stock_log)) : ?>
                        <?php foreach ($stock_log as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($row->created_at); ?></td>
                                <td><?php echo (int) $row->warehouse_id; ?></td>
                                <td><?php echo (int) $row->product_id; ?></td>
                                <td><?php echo esc_html($row->action_type); ?></td>
                                <td><?php echo (float) $row->qty_change; ?></td>
                                <td><?php echo esc_html((string) $row->qty_before . ' → ' . (string) $row->qty_after); ?></td>
                                <td><?php echo $row->employee_id ? (int) $row->employee_id : '-'; ?></td>
                                <td><?php echo $row->order_id ? (int) $row->order_id : '-'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="8">
                                <?php esc_html_e('No recent stock movements.', 'ff-warehouses'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Full Warehouses page (list + edit/add)
     * (كما كانت سابقاً، للاستخدام التفصيلي)
     */
    public static function render_warehouses_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'ff-warehouses'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ffw_warehouses';

        $rows = $wpdb->get_results(
            "SELECT id, name, slug, is_primary, status, created_at, updated_at
             FROM {$table}
             ORDER BY is_primary DESC, name ASC"
        );

        $message = '';
        if (!empty($_GET['ffw_msg'])) {
            if ($_GET['ffw_msg'] === 'saved') {
                $message = __('Warehouse saved successfully.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'missing_name') {
                $message = __('Name is required.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'error' && !empty($_GET['ffw_err'])) {
                $message = sprintf(__('Error: %s', 'ff-warehouses'), esc_html($_GET['ffw_err']));
            }
        }

        $edit_id = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : 0;
        $edit_row = null;
        if ($edit_id > 0) {
            foreach ($rows as $r) {
                if ((int) $r->id === $edit_id) {
                    $edit_row = $r;
                    break;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Warehouses List', 'ff-warehouses'); ?></h1>

            <?php if ($message) : ?>
                <div class="notice notice-info is-dismissible" style="margin-top:15px;">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <table class="widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Name', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Slug', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Primary', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Status', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Actions', 'ff-warehouses'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($rows)) : ?>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <td><?php echo (int) $row->id; ?></td>
                                <td><?php echo esc_html($row->name); ?></td>
                                <td><?php echo esc_html($row->slug); ?></td>
                                <td>
                                    <?php
                                    if ((int) $row->is_primary === 1) {
                                        echo '<span style="color:green;font-weight:bold;">' . esc_html__('Yes', 'ff-warehouses') . '</span>';
                                    } else {
                                        echo esc_html__('No', 'ff-warehouses');
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ($row->status === 'active') {
                                        echo '<span style="color:green;">' . esc_html__('Active', 'ff-warehouses') . '</span>';
                                    } else {
                                        echo '<span style="color:#999;">' . esc_html__('Inactive', 'ff-warehouses') . '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ((int) $row->is_primary !== 1) : ?>
                                        <a href="<?php echo esc_url(add_query_arg([
                                            'page'    => 'ff-warehouses-list',
                                            'edit_id' => (int) $row->id,
                                        ], admin_url('admin.php'))); ?>" class="button button-small">
                                            <?php esc_html_e('Edit', 'ff-warehouses'); ?>
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#999;"><?php esc_html_e('System', 'ff-warehouses'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6">
                                <?php esc_html_e('No warehouses found.', 'ff-warehouses'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:30px;">
                <?php echo $edit_row ? esc_html__('Edit Warehouse', 'ff-warehouses') : esc_html__('Add New Warehouse', 'ff-warehouses'); ?>
            </h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:500px;margin-top:10px;">
                <?php wp_nonce_field('ffw_save_warehouse'); ?>
                <input type="hidden" name="action" value="ffw_save_warehouse">
                <input type="hidden" name="warehouse_id" value="<?php echo $edit_row ? (int) $edit_row->id : 0; ?>">
                <input type="hidden" name="ffw_redirect_tab" value="warehouses">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ffw_name"><?php esc_html_e('Name', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="name" id="ffw_name" class="regular-text"
                                   value="<?php echo $edit_row ? esc_attr($edit_row->name) : ''; ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_slug"><?php esc_html_e('Slug', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="slug" id="ffw_slug" class="regular-text"
                                   value="<?php echo $edit_row ? esc_attr($edit_row->slug) : ''; ?>">
                            <p class="description">
                                <?php esc_html_e('Leave empty to auto-generate from name.', 'ff-warehouses'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ffw_status"><?php esc_html_e('Status', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <select name="status" id="ffw_status">
                                <option value="active" <?php selected($edit_row ? $edit_row->status : 'active', 'active'); ?>>
                                    <?php esc_html_e('Active', 'ff-warehouses'); ?>
                                </option>
                                <option value="inactive" <?php selected($edit_row ? $edit_row->status : 'active', 'inactive'); ?>>
                                    <?php esc_html_e('Inactive', 'ff-warehouses'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button($edit_row ? __('Update Warehouse', 'ff-warehouses') : __('Add Warehouse', 'ff-warehouses')); ?>
            </form>
        </div>
        <?php
    }


    /**
     * Inventory page: manage products per warehouse
     * - Existing inventory list (للمراجعة)
     * - Bulk editor table (checkbox + delta qty + price + min_qty)
     */
    public static function render_inventory_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'ff-warehouses'));
        }

        if (!function_exists('wc_get_products')) {
            wp_die(__('WooCommerce must be active to manage inventory.', 'ff-warehouses'));
        }

        global $wpdb;

        // Get all warehouses for dropdown
        $warehouses = $wpdb->get_results(
            "SELECT id, name, is_primary, status FROM {$wpdb->prefix}ffw_warehouses ORDER BY is_primary DESC, name ASC"
        );

        $selected_warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
        if ($selected_warehouse_id <= 0 && !empty($warehouses)) {
            $selected_warehouse_id = (int) $warehouses[0]->id;
        }

        $message = '';
        if (!empty($_GET['ffw_msg'])) {
            if ($_GET['ffw_msg'] === 'saved') {
                $message = __('Inventory saved.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'invalid_params') {
                $message = __('Invalid inventory parameters.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'no_items') {
                $message = __('No products selected for update.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'error' && !empty($_GET['ffw_err'])) {
                $message = sprintf(__('Error: %s', 'ff-warehouses'), esc_html($_GET['ffw_err']));
            }
        }

        // Load existing inventory for selected warehouse (for summary table)
        $inventory = [];
        if ($selected_warehouse_id > 0) {
            $table = $wpdb->prefix . 'ffw_warehouse_products';
            $inventory = $wpdb->get_results($wpdb->prepare(
                "SELECT id, product_id, qty, reserved_qty, price, min_qty, created_at, updated_at
                 FROM {$table}
                 WHERE warehouse_id = %d
                 ORDER BY product_id ASC",
                $selected_warehouse_id
            ));
        }

        // Bulk editor: load WooCommerce products with pagination + optional search
        $search   = isset($_GET['product_search']) ? sanitize_text_field($_GET['product_search']) : '';
        $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset   = ($paged - 1) * $per_page;

        $product_args = [
            'limit'  => $per_page,
            'offset' => $offset,
            'status' => 'publish',
            'orderby'=> 'title',
            'order'  => 'ASC',
        ];

        if ($search !== '') {
            $product_args['search'] = '*' . $search . '*';
        }

        $products      = wc_get_products($product_args);
        $total_products = wc_get_products(array_merge($product_args, [
            'limit'  => -1,
            'offset' => 0,
            'return' => 'ids',
        ]));
        $total_count   = is_array($total_products) ? count($total_products) : 0;
        $total_pages   = $per_page > 0 ? ceil($total_count / $per_page) : 1;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Warehouse Inventory', 'ff-warehouses'); ?></h1>

            <?php if ($message) : ?>
                <div class="notice notice-info is-dismissible" style="margin-top:15px;">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Warehouse selector -->
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:10px;">
                <input type="hidden" name="page" value="ff-warehouses-inventory">
                <label for="ffw_warehouse_select">
                    <?php esc_html_e('Select Warehouse:', 'ff-warehouses'); ?>
                </label>
                <select name="warehouse_id" id="ffw_warehouse_select" onchange="this.form.submit();">
                    <?php foreach ($warehouses as $wh) : ?>
                        <option value="<?php echo (int) $wh->id; ?>" <?php selected($selected_warehouse_id, $wh->id); ?>>
                            <?php
                            echo esc_html($wh->name);
                            if ((int) $wh->is_primary === 1) {
                                echo ' (' . esc_html__('Primary', 'ff-warehouses') . ')';
                            }
                            if ($wh->status !== 'active') {
                                echo ' [' . esc_html__('Inactive', 'ff-warehouses') . ']';
                            }
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ($selected_warehouse_id > 0) : ?>

                <!-- Existing inventory summary -->
                <h2 style="margin-top:20px;"><?php esc_html_e('Existing Inventory (Summary)', 'ff-warehouses'); ?></h2>

                <table class="widefat fixed striped" style="margin-top:10px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Product ID', 'ff-warehouses'); ?></th>
                            <th><?php esc_html_e('Product', 'ff-warehouses'); ?></th>
                            <th><?php esc_html_e('Qty (available)', 'ff-warehouses'); ?></th>
                            <th><?php esc_html_e('Reserved Qty', 'ff-warehouses'); ?></th>
                            <th><?php esc_html_e('Warehouse Price', 'ff-warehouses'); ?></th>
                            <th><?php esc_html_e('Min Qty', 'ff-warehouses'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($inventory)) : ?>
                            <?php foreach ($inventory as $row) : ?>
                                <?php $product = wc_get_product($row->product_id); ?>
                                <tr>
                                    <td><?php echo (int) $row->product_id; ?></td>
                                    <td>
                                        <?php
                                        if ($product) {
                                            echo esc_html($product->get_name());
                                        } else {
                                            echo '<em>' . esc_html__('Product not found', 'ff-warehouses') . '</em>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo (float) $row->qty; ?></td>
                                    <td><?php echo (float) $row->reserved_qty; ?></td>
                                    <td>
                                        <?php
                                        if ($row->price !== null) {
                                            echo esc_html(wc_price($row->price));
                                        } else {
                                            echo '<em>' . esc_html__('Inherit product price', 'ff-warehouses') . '</em>';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo (float) $row->min_qty; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="6">
                                    <?php esc_html_e('No inventory items for this warehouse.', 'ff-warehouses'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Bulk editor -->
                <h2 style="margin-top:30px;"><?php esc_html_e('Bulk Inventory Editor', 'ff-warehouses'); ?></h2>

                <!-- Product search -->
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:10px;margin-bottom:10px;">
                    <input type="hidden" name="page" value="ff-warehouses-inventory">
                    <input type="hidden" name="warehouse_id" value="<?php echo (int) $selected_warehouse_id; ?>">
                    <label for="ffw_product_search"><?php esc_html_e('Search products:', 'ff-warehouses'); ?></label>
                    <input type="search" name="product_search" id="ffw_product_search" value="<?php echo esc_attr($search); ?>" class="regular-text" placeholder="<?php esc_attr_e('ID or name', 'ff-warehouses'); ?>">
                    <input type="submit" class="button" value="<?php esc_attr_e('Search', 'ff-warehouses'); ?>">
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                    <?php wp_nonce_field('ffw_bulk_inventory_update'); ?>
                    <input type="hidden" name="action" value="ffw_bulk_inventory_update">
                    <input type="hidden" name="warehouse_id" value="<?php echo (int) $selected_warehouse_id; ?>">

                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width:40px;"><?php esc_html_e('Enable', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Product', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Current Qty', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Adjust Qty (±)', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Adjust Reserved (±)', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Warehouse Price', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Min Qty', 'ff-warehouses'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($products)) : ?>
                                <?php foreach ($products as $product) : ?>
                                    <?php
                                    $product_id = $product->get_id();
                                    $inv_row    = FF_Warehouses_Core::get_inventory_row($selected_warehouse_id, $product_id, null);
                                    $current_qty = $inv_row ? (float) $inv_row->qty : 0.0;
                                    $price_val   = $inv_row && $inv_row->price !== null ? (float) $inv_row->price : '';
                                    $min_val     = $inv_row ? (float) $inv_row->min_qty : '';
                                    $enabled     = $inv_row !== null;
                                    ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="enabled[<?php echo (int) $product_id; ?>]" value="1" <?php checked($enabled); ?>>
                                        </td>
                                        <td>
                                            <?php echo esc_html($product->get_name()); ?>
                                            <br>
                                            <small><?php printf(esc_html__('ID: %d', 'ff-warehouses'), $product_id); ?></small>
                                        </td>
                                        <td>
                                            <?php echo esc_html($current_qty); ?>
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="delta_qty[<?php echo (int) $product_id; ?>]" class="small-text" placeholder="0">
                                            <p class="description">
                                                <?php esc_html_e('Positive to increase, negative to decrease.', 'ff-warehouses'); ?>
                                            </p>
                                        </td>

                                        <td>
                                            <input type="number" step="0.001" name="delta_reserved[<?php echo (int) $product_id; ?>]" class="small-text" placeholder="0">
                                            <p class="description">
                                                <?php esc_html_e('Positive to increase reserved, negative to decrease.', 'ff-warehouses'); ?>
                                            </p>
                                        </td>
                                        <td>
                                            <input type="number" step="0.01" name="price[<?php echo (int) $product_id; ?>]" class="small-text"
                                                   value="<?php echo $price_val !== '' ? esc_attr($price_val) : ''; ?>">
                                        </td>
                                        <td>
                                            <input type="number" step="0.001" name="min_qty[<?php echo (int) $product_id; ?>]" class="small-text"
                                                   value="<?php echo $min_val !== '' ? esc_attr($min_val) : ''; ?>">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="6">
                                        <?php esc_html_e('No products found for this query.', 'ff-warehouses'); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php submit_button(__('Apply Bulk Inventory Changes', 'ff-warehouses')); ?>
                </form>

                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav" style="margin-top:10px;">
                        <div class="tablenav-pages">
                            <?php
                            $base_url = remove_query_arg('paged', $_SERVER['REQUEST_URI']);
                            for ($p = 1; $p <= $total_pages; $p++) {
                                $url = esc_url(add_query_arg([
                                    'paged'         => $p,
                                    'warehouse_id'  => $selected_warehouse_id,
                                    'product_search'=> $search,
                                ], $base_url));
                                if ($p == $paged) {
                                    echo '<span class="tablenav-page-navspan" style="margin-right:5px;"><strong>' . $p . '</strong></span>';
                                } else {
                                    echo '<a class="tablenav-page-navspan" style="margin-right:5px;" href="' . $url . '">' . $p . '</a>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }


    /**
     * Transfers page: move stock between warehouses
     */
    public static function render_transfers_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'ff-warehouses'));
        }

        if (!function_exists('wc_get_products')) {
            wp_die(__('WooCommerce must be active to manage transfers.', 'ff-warehouses'));
        }

        global $wpdb;

        $table_warehouses = $wpdb->prefix . 'ffw_warehouses';

        // Load warehouses for dropdowns
        $warehouses = $wpdb->get_results(
            "SELECT id, name, is_primary, status FROM {$table_warehouses} ORDER BY is_primary DESC, name ASC"
        );

        $from_warehouse_id = isset($_GET['from_warehouse_id']) ? intval($_GET['from_warehouse_id']) : 0;
        $to_warehouse_id   = isset($_GET['to_warehouse_id']) ? intval($_GET['to_warehouse_id']) : 0;

        // لو مفيش اختيار، خليه ياخد أول مخزن كـ source وثاني واحد كـ dest لو متاح
        if ($from_warehouse_id <= 0 && !empty($warehouses)) {
            $from_warehouse_id = (int) $warehouses[0]->id;
        }

        if ($to_warehouse_id <= 0 && count($warehouses) > 1) {
            $to_warehouse_id = (int) $warehouses[1]->id;
        }

        $message = '';
        if (!empty($_GET['ffw_msg'])) {
            if ($_GET['ffw_msg'] === 'saved') {
                $message = __('Transfer(s) completed successfully.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'no_items') {
                $message = __('No products selected for transfer.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'invalid_warehouses') {
                $message = __('Please select valid and different source and destination warehouses.', 'ff-warehouses');
            } elseif ($_GET['ffw_msg'] === 'error' && !empty($_GET['ffw_err'])) {
                $message = sprintf(__('Error: %s', 'ff-warehouses'), esc_html($_GET['ffw_err']));
            }
        }

        // Product search + pagination
        $search   = isset($_GET['product_search']) ? sanitize_text_field($_GET['product_search']) : '';
        $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset   = ($paged - 1) * $per_page;

        $product_args = [
            'limit'  => $per_page,
            'offset' => $offset,
            'status' => 'publish',
            'orderby'=> 'title',
            'order'  => 'ASC',
        ];

        if ($search !== '') {
            $product_args['search'] = '*' . $search . '*';
        }

        $products       = wc_get_products($product_args);
        $total_products = wc_get_products(array_merge($product_args, [
            'limit'  => -1,
            'offset' => 0,
            'return' => 'ids',
        ]));
        $total_count    = is_array($total_products) ? count($total_products) : 0;
        $total_pages    = $per_page > 0 ? ceil($total_count / $per_page) : 1;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Warehouse Transfers', 'ff-warehouses'); ?></h1>

            <?php if ($message) : ?>
                <div class="notice notice-info is-dismissible" style="margin-top:15px;">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <!-- Warehouse selectors -->
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:10px;margin-bottom:15px;">
                <input type="hidden" name="page" value="ff-warehouses-transfers">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ffw_from_warehouse"><?php esc_html_e('From warehouse (source)', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <select name="from_warehouse_id" id="ffw_from_warehouse">
                                <?php foreach ($warehouses as $wh) : ?>
                                    <option value="<?php echo (int) $wh->id; ?>" <?php selected($from_warehouse_id, $wh->id); ?>>
                                        <?php
                                        echo esc_html($wh->name);
                                        if ((int) $wh->is_primary === 1) {
                                            echo ' (' . esc_html__('Primary', 'ff-warehouses') . ')';
                                        }
                                        if ($wh->status !== 'active') {
                                            echo ' [' . esc_html__('Inactive', 'ff-warehouses') . ']';
                                        }
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="ffw_to_warehouse"><?php esc_html_e('To warehouse (destination)', 'ff-warehouses'); ?></label>
                        </th>
                        <td>
                            <select name="to_warehouse_id" id="ffw_to_warehouse">
                                <?php foreach ($warehouses as $wh) : ?>
                                    <option value="<?php echo (int) $wh->id; ?>" <?php selected($to_warehouse_id, $wh->id); ?>>
                                        <?php
                                        echo esc_html($wh->name);
                                        if ((int) $wh->is_primary === 1) {
                                            echo ' (' . esc_html__('Primary', 'ff-warehouses') . ')';
                                        }
                                        if ($wh->status !== 'active') {
                                            echo ' [' . esc_html__('Inactive', 'ff-warehouses') . ']';
                                        }
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <p>
                    <input type="submit" class="button" value="<?php esc_attr_e('Apply warehouses', 'ff-warehouses'); ?>">
                </p>
            </form>

            <?php if ($from_warehouse_id > 0 && $to_warehouse_id > 0 && $from_warehouse_id !== $to_warehouse_id) : ?>

                <!-- Product search for transfers -->
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:10px;margin-bottom:10px;">
                    <input type="hidden" name="page" value="ff-warehouses-transfers">
                    <input type="hidden" name="from_warehouse_id" value="<?php echo (int) $from_warehouse_id; ?>">
                    <input type="hidden" name="to_warehouse_id" value="<?php echo (int) $to_warehouse_id; ?>">

                    <label for="ffw_transfer_product_search"><?php esc_html_e('Search products:', 'ff-warehouses'); ?></label>
                    <input type="search" name="product_search" id="ffw_transfer_product_search" value="<?php echo esc_attr($search); ?>" class="regular-text" placeholder="<?php esc_attr_e('ID or name', 'ff-warehouses'); ?>">
                    <input type="submit" class="button" value="<?php esc_attr_e('Search', 'ff-warehouses'); ?>">
                </form>

                <!-- Transfers table -->
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
                    <?php wp_nonce_field('ffw_bulk_transfer'); ?>
                    <input type="hidden" name="action" value="ffw_bulk_transfer">
                    <input type="hidden" name="from_warehouse_id" value="<?php echo (int) $from_warehouse_id; ?>">
                    <input type="hidden" name="to_warehouse_id" value="<?php echo (int) $to_warehouse_id; ?>">

                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Product', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Available in source', 'ff-warehouses'); ?></th>
                                <th><?php esc_html_e('Transfer Qty', 'ff-warehouses'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($products)) : ?>
                                <?php
                                $has_rows = false;
                                foreach ($products as $product) :
                                    $product_id         = $product->get_id();
                                    $available_in_source = FF_Warehouses_Core::get_available_qty($from_warehouse_id, $product_id, null);

                                    // لو مفيش رصيد متاح في مخزن المصدر، لا تعرض الصف
                                    if ($available_in_source <= 0) {
                                        continue;
                                    }

                                    $has_rows = true;
                                ?>
                                    <tr>
                                        <td>
                                            <?php echo esc_html($product->get_name()); ?>
                                            <br>
                                            <small><?php printf(esc_html__('ID: %d', 'ff-warehouses'), $product_id); ?></small>
                                        </td>
                                        <td><?php echo esc_html($available_in_source); ?></td>
                                        <td>
                                            <input type="number"
                                                step="0.001"
                                                name="transfer_qty[<?php echo (int) $product_id; ?>]"
                                                class="small-text"
                                                placeholder="0">
                                            <p class="description">
                                                <?php esc_html_e('Must be > 0 and not exceed available in source warehouse.', 'ff-warehouses'); ?>
                                            </p>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (!$has_rows) : ?>
                                    <tr>
                                        <td colspan="3">
                                            <?php esc_html_e('No products with available stock in source warehouse.', 'ff-warehouses'); ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>

                            <?php else : ?>
                                <tr>
                                    <td colspan="3">
                                        <?php esc_html_e('No products found for this query.', 'ff-warehouses'); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>

                    </table>

                    <?php submit_button(__('Apply Transfers', 'ff-warehouses')); ?>
                </form>

                <!-- Pagination -->
                <?php if ($total_pages > 1) : ?>
                    <div class="tablenav" style="margin-top:10px;">
                        <div class="tablenav-pages">
                            <?php
                            $base_url = remove_query_arg('paged', $_SERVER['REQUEST_URI']);
                            for ($p = 1; $p <= $total_pages; $p++) {
                                $url = esc_url(add_query_arg([
                                    'paged'              => $p,
                                    'from_warehouse_id'  => $from_warehouse_id,
                                    'to_warehouse_id'    => $to_warehouse_id,
                                    'product_search'     => $search,
                                ], $base_url));
                                if ($p == $paged) {
                                    echo '<span class="tablenav-page-navspan" style="margin-right:5px;"><strong>' . $p . '</strong></span>';
                                } else {
                                    echo '<a class="tablenav-page-navspan" style="margin-right:5px;" href="' . $url . '">' . $p . '</a>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else : ?>
                <p class="description" style="margin-top:20px;">
                    <?php esc_html_e('Please select two different warehouses to enable transfers.', 'ff-warehouses'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }



    /**
     * Stock log page: show last N entries
     */
        /**
     * Stock log page: filterable + basic summary report
     */
public static function render_stock_log_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have permission to access this page.', 'ff-warehouses'));
    }

    if (!function_exists('wc_get_products')) {
        wp_die(__('WooCommerce must be active to view reports.', 'ff-warehouses'));
    }

    global $wpdb;

    $table_logs       = $wpdb->prefix . 'ffw_stock_log';
    $table_warehouses = $wpdb->prefix . 'ffw_warehouses';

    // Load warehouses for filter dropdown
    $warehouses = $wpdb->get_results(
        "SELECT id, name FROM {$table_warehouses} ORDER BY is_primary DESC, name ASC"
    );

    // Load products for dropdown filter (limit to 200 for performance)
    $product_filter_args = [
        'limit'  => 200,
        'status' => 'publish',
        'orderby'=> 'title',
        'order'  => 'ASC',
    ];
    $products_for_filter = wc_get_products($product_filter_args);

    // Read filters from GET
    $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
    $product_id   = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $order_id     = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
    $action_type  = isset($_GET['action_type']) ? sanitize_text_field($_GET['action_type']) : '';
    $date_from    = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
    $date_to      = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
    $limit        = isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 100;

    // Base WHERE
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

    // Date range filter (created_at BETWEEN date_from 00:00:00 AND date_to 23:59:59)
    if ($date_from !== '') {
        $where   .= ' AND created_at >= %s';
        $params[] = $date_from . ' 00:00:00';
    }

    if ($date_to !== '') {
        $where   .= ' AND created_at <= %s';
        $params[] = $date_to . ' 23:59:59';
    }

    // Current stock maps (available + reserved)
    $current_qty_map      = [];
    $current_reserved_map = [];

    if ($warehouse_id > 0) {
        $inv_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, qty, reserved_qty
             FROM {$wpdb->prefix}ffw_warehouse_products
             WHERE warehouse_id = %d",
            $warehouse_id
        ));
    } else {
        // كل المخازن: نجمع الكميات من جميع المخازن
        $inv_rows = $wpdb->get_results(
            "SELECT product_id, SUM(qty) AS qty, SUM(reserved_qty) AS reserved_qty
             FROM {$wpdb->prefix}ffw_warehouse_products
             GROUP BY product_id"
        );
    }

    foreach ($inv_rows as $r) {
        $pid = (int) $r->product_id;
        $current_qty_map[$pid]      = (float) $r->qty;
        $current_reserved_map[$pid] = (float) $r->reserved_qty;
    }



    // Build main query (detailed log)
    $sql_logs = "SELECT id, warehouse_id, product_id, action_type, qty_change, qty_before, qty_after,
                        reserved_before, reserved_after, employee_id, order_id, notes, created_at
                 FROM {$table_logs}
                 WHERE {$where}
                 ORDER BY created_at DESC
                 LIMIT %d";

    $params_logs = array_merge($params, [ $limit ]);
    $rows        = $wpdb->get_results($wpdb->prepare($sql_logs, ...$params_logs));

    // Basic summary report: total in/out per product for current filters
        $sql_summary = "SELECT 
                       product_id,

                       -- كل الحركات الداخلة/الخارجة (للاستخدام العام إن احتجناها)
                       SUM(CASE WHEN qty_change > 0 THEN qty_change ELSE 0 END) AS total_in,
                       SUM(CASE WHEN qty_change < 0 THEN -qty_change ELSE 0 END) AS total_out,

                       -- دخول مخزون \"حقيقي\" (initial / adjustments IN)
                       SUM(
                         CASE 
                           WHEN action_type IN ('manual_increase','manual_adjust') 
                                AND qty_change > 0 
                           THEN qty_change 
                           ELSE 0 
                         END
                       ) AS entries_in,

                       -- خروج بسبب بيع (طلبات ويب + POS)
                       SUM(
                         CASE 
                           WHEN action_type IN ('wc_order_reserve','pos_sale','pos_reserve','wc_order_sale') 
                                AND qty_change < 0 
                           THEN -qty_change 
                           ELSE 0 
                         END
                       ) AS sales_out,

                       -- مرتجعات / إلغاء طلبات (ترجع مخزون بسبب بيع سابق)
                       SUM(
                         CASE 
                           WHEN action_type = 'wc_order_restore' 
                                AND qty_change > 0 
                           THEN qty_change 
                           ELSE 0 
                         END
                       ) AS sales_returns,

                       -- تحويلات بين المخازن (دخول)
                       SUM(
                         CASE 
                           WHEN action_type = 'transfer_in' 
                                AND qty_change > 0 
                           THEN qty_change 
                           ELSE 0 
                         END
                       ) AS transfer_in,

                       -- تحويلات بين المخازن (خروج)
                       SUM(
                         CASE 
                           WHEN action_type = 'transfer_out' 
                                AND qty_change < 0 
                           THEN -qty_change 
                           ELSE 0 
                         END
                       ) AS transfer_out

                    FROM {$table_logs}
                    WHERE {$where}
                    GROUP BY product_id
                    ORDER BY product_id ASC
                    LIMIT 200";

    $summary_rows = $wpdb->get_results($wpdb->prepare($sql_summary, ...$params));


    // Build summary map for advanced report
    $summary_map = [];
    foreach ($summary_rows as $r) {
        $summary_map[(int) $r->product_id] = [
            'total_in'  => (float) $r->total_in,
            'total_out' => (float) $r->total_out,
        ];
    }

    // Advanced balance report (requires warehouse + date range)
    $advanced_report = [];
    if ($warehouse_id > 0 && $date_from !== '' && $date_to !== '') {
        $from_start = $date_from . ' 00:00:00';
        $to_end     = $date_to . ' 23:59:59';

        // Sum of qty_change AFTER start of range
        $sql_after_from = "SELECT product_id, SUM(qty_change) AS sum_after_from
                           FROM {$table_logs}
                           WHERE warehouse_id = %d AND created_at > %s
                           GROUP BY product_id";
        $rows_after_from = $wpdb->get_results($wpdb->prepare(
            $sql_after_from,
            $warehouse_id,
            $from_start
        ));

        $after_from_map = [];
        foreach ($rows_after_from as $r) {
            $after_from_map[(int) $r->product_id] = (float) $r->sum_after_from;
        }

        // Sum of qty_change AFTER end of range
        $sql_after_to = "SELECT product_id, SUM(qty_change) AS sum_after_to
                         FROM {$table_logs}
                         WHERE warehouse_id = %d AND created_at > %s
                         GROUP BY product_id";
        $rows_after_to = $wpdb->get_results($wpdb->prepare(
            $sql_after_to,
            $warehouse_id,
            $to_end
        ));

        $after_to_map = [];
        foreach ($rows_after_to as $r) {
            $after_to_map[(int) $r->product_id] = (float) $r->sum_after_to;
        }

        // Unified list of products involved
        $product_ids = array_unique(array_merge(
            array_keys($current_qty_map),
            array_keys($summary_map),
            array_keys($after_from_map),
            array_keys($after_to_map)
        ));

        foreach ($product_ids as $pid) {
            $pid            = (int) $pid;
            $current_qty    = isset($current_qty_map[$pid]) ? $current_qty_map[$pid] : 0.0;
            $sum_after_from = isset($after_from_map[$pid]) ? $after_from_map[$pid] : 0.0;
            $sum_after_to   = isset($after_to_map[$pid]) ? $after_to_map[$pid] : 0.0;

            $opening_qty = $current_qty - $sum_after_from; // stock at start of range
            $closing_qty = $current_qty - $sum_after_to;   // stock at end of range

            $total_in  = isset($summary_map[$pid]) ? $summary_map[$pid]['total_in'] : 0.0;
            $total_out = isset($summary_map[$pid]) ? $summary_map[$pid]['total_out'] : 0.0;

            // Theoretical closing based on movements in range
            $expected_closing = $opening_qty + $total_in - $total_out;

            // Discrepancy between expected closing and current real stock
            $discrepancy = $expected_closing - $current_qty;

            $advanced_report[$pid] = [
                'opening_qty'      => $opening_qty,
                'total_in'         => $total_in,
                'total_out'        => $total_out,
                'closing_qty_calc' => $expected_closing,
                'current_qty'      => $current_qty,
                'discrepancy'      => $discrepancy,
            ];
        }
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Stock Log', 'ff-warehouses'); ?></h1>

        <!-- Filters form -->
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin-top:10px;margin-bottom:15px;">
            <input type="hidden" name="page" value="ff-warehouses-stock-log">

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="ffw_filter_warehouse"><?php esc_html_e('Warehouse', 'ff-warehouses'); ?></label>
                    </th>
                    <td>
                        <select name="warehouse_id" id="ffw_filter_warehouse">
                            <option value="0"><?php esc_html_e('All warehouses', 'ff-warehouses'); ?></option>
                            <?php foreach ($warehouses as $wh) : ?>
                                <option value="<?php echo (int) $wh->id; ?>" <?php selected($warehouse_id, $wh->id); ?>>
                                    <?php echo esc_html($wh->name . ' (ID ' . $wh->id . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="ffw_filter_product"><?php esc_html_e('Product', 'ff-warehouses'); ?></label>
                    </th>
                    <td>
                        <select name="product_id" id="ffw_filter_product">
                            <option value="0"><?php esc_html_e('All products', 'ff-warehouses'); ?></option>
                            <?php foreach ($products_for_filter as $p) : ?>
                                <?php $pid = $p->get_id(); ?>
                                <option value="<?php echo (int) $pid; ?>" <?php selected($product_id, $pid); ?>>
                                    <?php echo esc_html($p->get_name() . ' (ID ' . $pid . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="ffw_filter_order"><?php esc_html_e('Order ID', 'ff-warehouses'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="order_id" id="ffw_filter_order" value="<?php echo $order_id ? (int) $order_id : ''; ?>" class="regular-text">
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="ffw_filter_action"><?php esc_html_e('Action type', 'ff-warehouses'); ?></label>
                    </th>
                    <td>
                        <select name="action_type" id="ffw_filter_action">
                            <option value=""><?php esc_html_e('All actions', 'ff-warehouses'); ?></option>
                            <option value="wc_order_reserve" <?php selected($action_type, 'wc_order_reserve'); ?>>wc_order_reserve</option>
                            <option value="wc_order_complete" <?php selected($action_type, 'wc_order_complete'); ?>>wc_order_complete</option>
                            <option value="wc_order_restore" <?php selected($action_type, 'wc_order_restore'); ?>>wc_order_restore</option>
                            <option value="pos_sale" <?php selected($action_type, 'pos_sale'); ?>>pos_sale</option>
                            <option value="pos_reserve" <?php selected($action_type, 'pos_reserve'); ?>>pos_reserve</option>
                            <option value="manual_increase" <?php selected($action_type, 'manual_increase'); ?>>manual_increase</option>
                            <option value="manual_decrease" <?php selected($action_type, 'manual_decrease'); ?>>manual_decrease</option>
                            <option value="manual_reserve_increase" <?php selected($action_type, 'manual_reserve_increase'); ?>>manual_reserve_increase</option>
                            <option value="manual_reserve_decrease" <?php selected($action_type, 'manual_reserve_decrease'); ?>>manual_reserve_decrease</option>
                            <option value="manual_adjust" <?php selected($action_type, 'manual_adjust'); ?>>manual_adjust</option>
                            <option value="transfer_in" <?php selected($action_type, 'transfer_in'); ?>>transfer_in</option>
                            <option value="transfer_out" <?php selected($action_type, 'transfer_out'); ?>>transfer_out</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Date range', 'ff-warehouses'); ?></label>
                    </th>
                    <td>
                        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                        &nbsp;<?php esc_html_e('to', 'ff-warehouses'); ?>&nbsp;
                        <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                        <p class="description">
                            <?php esc_html_e('Filter by created_at between these dates (inclusive).', 'ff-warehouses'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="ffw_filter_limit"><?php esc_html_e('Max records', 'ff-warehouses'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="limit" id="ffw_filter_limit" value="<?php echo (int) $limit; ?>" min="1" max="1000">
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Filter log', 'ff-warehouses')); ?>
        </form>


                <!-- Summary report (per product) -->
        <h2 style="margin-top:20px;"><?php esc_html_e('Summary (per product, current filters)', 'ff-warehouses'); ?></h2>
        <table class="widefat fixed striped" style="margin-top:10px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Product ID', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Product', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Entries In', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Net Sales (out - returns)', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Transfers In', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Transfers Out', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Reserved Now', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Available Now', 'ff-warehouses'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($summary_rows)) : ?>
                    <?php
                    // Cache products to avoid multiple DB calls
                    $product_cache_summary = [];
                    foreach ($summary_rows as $row) :
                        $pid = (int) $row->product_id;

                        // أرقام من SQL
                        $entries_in     = isset($row->entries_in) ? (float) $row->entries_in : 0.0;
                        $sales_out      = isset($row->sales_out) ? (float) $row->sales_out : 0.0;
                        $sales_returns  = isset($row->sales_returns) ? (float) $row->sales_returns : 0.0;
                        $transfer_in    = isset($row->transfer_in) ? (float) $row->transfer_in : 0.0;
                        $transfer_out   = isset($row->transfer_out) ? (float) $row->transfer_out : 0.0;

                        // صافي المبيعات = اللي خرج بسبب بيع - اللي رجع من مبيعات
                        $net_sales = $sales_out - $sales_returns;

                        // الكميات الحالية من الخرائط اللي حسبناها قبل كده
                        $available_now = isset($current_qty_map[$pid]) ? (float) $current_qty_map[$pid] : 0.0;
                        $reserved_now  = isset($current_reserved_map[$pid]) ? (float) $current_reserved_map[$pid] : 0.0;

                        if (!isset($product_cache_summary[$pid])) {
                            $product_cache_summary[$pid] = wc_get_product($pid);
                        }
                        $prod = $product_cache_summary[$pid];
                    ?>
                        <tr>
                            <td><?php echo $pid; ?></td>
                            <td>
                                <?php
                                if ($prod) {
                                    echo esc_html($prod->get_name());
                                } else {
                                    echo '<em>' . esc_html__('Product not found', 'ff-warehouses') . '</em>';
                                }
                                ?>
                            </td>
                            <td><?php echo $entries_in; ?></td>
                            <td><?php echo $net_sales; ?></td>
                            <td><?php echo $transfer_in; ?></td>
                            <td><?php echo $transfer_out; ?></td>
                            <td><?php echo $reserved_now; ?></td>
                            <td><?php echo $available_now; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="8">
                            <?php esc_html_e('No summary data for current filters.', 'ff-warehouses'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>


        <!-- Advanced balance report -->
        <?php if ($warehouse_id > 0 && $date_from !== '' && $date_to !== '') : ?>
            <h2 style="margin-top:30px;"><?php esc_html_e('Detailed Balance Report (per product, current filters)', 'ff-warehouses'); ?></h2>
            <p class="description">
                <?php esc_html_e('Opening = stock at start of period; Closing (calc) = Opening + In - Out; Discrepancy = Closing (calc) - Current stock.', 'ff-warehouses'); ?>
            </p>
            <table class="widefat fixed striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Product ID', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Product', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Opening Qty', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Total In', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Total Out', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Closing Qty (calc)', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Current Qty', 'ff-warehouses'); ?></th>
                        <th><?php esc_html_e('Discrepancy', 'ff-warehouses'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($advanced_report)) : ?>
                        <?php
                        $product_cache2 = [];
                        foreach ($advanced_report as $pid => $data) :
                            $pid = (int) $pid;
                            if (!isset($product_cache2[$pid])) {
                                $product_cache2[$pid] = wc_get_product($pid);
                            }
                            $p = $product_cache2[$pid];
                        ?>
                            <tr>
                                <td><?php echo $pid; ?></td>
                                <td>
                                    <?php
                                    if ($p) {
                                        echo esc_html($p->get_name());
                                    } else {
                                        echo '<em>' . esc_html__('Product not found', 'ff-warehouses') . '</em>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo (float) $data['opening_qty']; ?></td>
                                <td><?php echo (float) $data['total_in']; ?></td>
                                <td><?php echo (float) $data['total_out']; ?></td>
                                <td><?php echo (float) $data['closing_qty_calc']; ?></td>
                                <td><?php echo (float) $data['current_qty']; ?></td>
                                <td>
                                    <?php
                                    $disc = (float) $data['discrepancy'];
                                    if (abs($disc) > 0.0001) {
                                        echo '<span style="color:red;font-weight:bold;">' . $disc . '</span>';
                                    } else {
                                        echo '<span style="color:green;">' . $disc . '</span>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="8">
                                <?php esc_html_e('No data for detailed balance report with current filters.', 'ff-warehouses'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="description" style="margin-top:20px;">
                <?php esc_html_e('To see the detailed balance report, please select a specific warehouse and a date range.', 'ff-warehouses'); ?>
            </p>
        <?php endif; ?>

        <!-- Detailed log table -->
        <h2 style="margin-top:30px;"><?php esc_html_e('Detailed Log', 'ff-warehouses'); ?></h2>
        <table class="widefat fixed striped" style="margin-top:10px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Date', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Warehouse', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Product ID', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Product', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Action', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Qty Change', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Qty Before → After', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Reserved Before → After', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Employee ID', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Order ID', 'ff-warehouses'); ?></th>
                    <th><?php esc_html_e('Notes', 'ff-warehouses'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)) : ?>
                    <?php
                    $product_cache = [];
                    foreach ($rows as $row) :
                    ?>
                        <tr>
                            <td><?php echo (int) $row->id; ?></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo (int) $row->warehouse_id; ?></td>
                            <td><?php echo (int) $row->product_id; ?></td>
                            <td>
                                <?php
                                $pid = (int) $row->product_id;
                                if (!isset($product_cache[$pid])) {
                                    $product_cache[$pid] = wc_get_product($pid);
                                }
                                $p = $product_cache[$pid];
                                if ($p) {
                                    echo esc_html($p->get_name());
                                } else {
                                    echo '<em>' . esc_html__('Product not found', 'ff-warehouses') . '</em>';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($row->action_type); ?></td>
                            <td><?php echo (float) $row->qty_change; ?></td>
                            <td><?php echo esc_html((string) $row->qty_before . ' → ' . (string) $row->qty_after); ?></td>
                            <td><?php echo esc_html((string) $row->reserved_before . ' → ' . (string) $row->reserved_after); ?></td>
                            <td><?php echo $row->employee_id ? (int) $row->employee_id : '-'; ?></td>
                            <td><?php echo $row->order_id ? (int) $row->order_id : '-'; ?></td>
                            <td><?php echo esc_html($row->notes); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="12">
                            <?php esc_html_e('No stock log records found for current filters.', 'ff-warehouses'); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

}
