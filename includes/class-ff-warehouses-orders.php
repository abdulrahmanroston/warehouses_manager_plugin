<?php
/**
 * WooCommerce orders integration for FF Warehouses
 * - Create POS orders tied to a specific warehouse
 * - Later: update orders and stock adjustments on edits/refunds
 */

if (!defined('ABSPATH')) {
    exit;
}

class FF_Warehouses_Orders {

    /**
     * Initialize routes and hooks
     */
    public static function init() {
        add_action('rest_api_init', [ __CLASS__, 'register_routes' ]);
    }

    /**
     * Register REST routes related to orders
     */
    public static function register_routes() {
        $namespace = 'ff/v1';

        // Create POS order for a specific warehouse
        register_rest_route($namespace, '/warehouses/(?P<warehouse_id>\d+)/orders', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_order' ],
            'permission_callback' => function ($request) {
                // Only employees with POS permission can create orders
                return FF_Warehouses_Auth::check_permission($request, 'can_pos_orders');
            },
        ]);

        // Get order summary
        register_rest_route($namespace, '/orders/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_order' ],
            'permission_callback' => function ($request) {
                // View permission is enough to read orders
                return FF_Warehouses_Auth::check_permission($request, 'can_view');
            },
        ]);

        // Placeholder for future order updates (edit quantities/products/discounts)
        register_rest_route($namespace, '/orders/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ __CLASS__, 'update_order' ],
            'permission_callback' => function ($request) {
                // POS orders editing can reuse POS permission for now
                return FF_Warehouses_Auth::check_permission($request, 'can_pos_orders');
            },
        ]);
        // ✅ Send invoice to WhatsApp queue manually
        register_rest_route($namespace, '/orders/(?P<id>\d+)/send-invoice', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'send_invoice_to_queue' ],
            'permission_callback' => function ($request) {
                // Can view orders = can send invoice
                return FF_Warehouses_Auth::check_permission($request, 'can_view');
            },
        ]);

    }

/**
 * Create a POS/Delivery order for a specific warehouse.
 *
 * POST /ff/v1/warehouses/{warehouse_id}/orders
 *
 * Example body (POS order - كما هو):
 * {
 *   "customer_id": 123,
 *   "status": "processing",
 *   "line_items": [
 *     { "product_id": 10, "quantity": 2 }
 *   ]
 * }
 *
 * Example body (Delivery order with SCL):
 * {
 *   "customer_id": 123,
 *   "status": "processing",
 *   "line_items": [
 *     { "product_id": 10, "quantity": 2 }
 *   ],
 *   "scl_address_id": 7,
 *   "delivery_date": "2025-11-26",
 *   "delivery_time": "12 PM - 2 PM",
 *   "use_shipping": true,
 *   "payment_method": "cod",
 *   "customer_notes": "Please call before arrival"
 * }
 *
 * Example body (Guest order with address data):
 * {
 *   "status": "processing",
 *   "line_items": [
 *     { "product_id": 10, "quantity": 2 }
 *   ],
 *   "guest_data": {
 *     "name": "John Doe",
 *     "email": "john@example.com",
 *     "phone": "0100000000",
 *     "address": "123 Street",
 *     "city": "Cairo",
 *     "zone": "Cairo"
 *   },
 *   "delivery_date": "2025-11-26",
 *   "delivery_time": "12 PM - 2 PM",
 *   "use_shipping": true
 * }
 */


public static function create_order($request) {
    if (!class_exists('WC_Order') && !function_exists('wc_create_order')) {
        return new WP_Error('ffw_woocommerce_missing', 'WooCommerce is not loaded', ['status' => 500]);
    }

    // ✅✅✅ Add this hook to prevent WooCommerce from reducing stock
    add_filter('woocommerce_can_reduce_order_stock', function($can_reduce, $order) {
        // Check if this is an FF Warehouses order
        if ($order && $order->get_meta('_ffw_warehouse_id')) {
            return false; // Don't reduce stock, we handle it manually
        }
        return $can_reduce;
    }, 999, 2);
    
    // ✅ Also prevent on payment complete
    add_filter('woocommerce_payment_complete_reduce_order_stock', function($reduce, $order_id) {
        $order = wc_get_order($order_id);
        if ($order && $order->get_meta('_ffw_warehouse_id')) {
            return false;
        }
        return $reduce;
    }, 999, 2);

    $warehouse_id = intval($request['warehouse_id']);

    if ($warehouse_id <= 0) {
        return new WP_Error('ffw_invalid_warehouse', 'Invalid warehouse ID', ['status' => 400]);
    }

    // Ensure warehouse exists and is active
    $warehouse = FF_Warehouses_Core::get_warehouse($warehouse_id);
    if (!$warehouse || $warehouse->status !== 'active') {
        return new WP_Error('ffw_warehouse_not_found', 'Warehouse not found or inactive', ['status' => 404]);
    }

    $params         = $request->get_json_params();
    $customer_id    = isset($params['customer_id']) ? intval($params['customer_id']) : 0;
    $status         = !empty($params['status']) ? sanitize_text_field($params['status']) : 'processing';
    $line_items     = isset($params['line_items']) && is_array($params['line_items']) ? $params['line_items'] : [];
    $extra_meta     = isset($params['meta_data']) && is_array($params['meta_data']) ? $params['meta_data'] : [];
    
    // ✅ SCL Integration fields
    $scl_address_id = isset($params['scl_address_id']) ? intval($params['scl_address_id']) : 0;
    $delivery_date  = isset($params['delivery_date']) ? sanitize_text_field($params['delivery_date']) : '';
    $delivery_time  = isset($params['delivery_time']) ? sanitize_text_field($params['delivery_time']) : '';
    $use_shipping   = isset($params['use_shipping']) ? (bool)$params['use_shipping'] : false;
    $payment_method = isset($params['payment_method']) ? sanitize_text_field($params['payment_method']) : 'cod';
    $customer_notes = isset($params['customer_notes']) ? sanitize_textarea_field($params['customer_notes']) : '';
    $guest_data     = isset($params['guest_data']) && is_array($params['guest_data']) ? $params['guest_data'] : [];
    $coupon_lines = isset($params['coupon_lines']) && is_array($params['coupon_lines']) ? $params['coupon_lines'] : [];


    if (empty($line_items)) {
        return new WP_Error('ffw_no_items', 'No line_items provided', ['status' => 400]);
    }

    // Authenticate to get employee_id
    $auth = FF_Warehouses_Auth::authenticate_request($request);
    if (is_wp_error($auth)) {
        return $auth;
    }
    $employee    = $auth['employee'];
    $employee_id = intval($employee->id);

    // ✅ Load SCL address if provided
    $scl_address = null;
    if ($scl_address_id > 0 && class_exists('SCL_Address_Repository')) {
        require_once WP_PLUGIN_DIR . '/simple-checkout-location/includes/class-address-repository.php';
        $scl_repo = new SCL_Address_Repository();
        
        if ($customer_id > 0) {
            $scl_address = $scl_repo->get_address($scl_address_id, $customer_id);
        } else {
            $scl_address = $scl_repo->get_address($scl_address_id);
        }
        
        if (!$scl_address) {
            return new WP_Error('ffw_address_not_found', 'SCL address not found', ['status' => 404]);
        }
    }

    // Pre-validate stock per line item
    foreach ($line_items as $item) {
        $product_id = isset($item['product_id']) ? intval($item['product_id']) : 0;
        $qty        = isset($item['quantity']) ? (float) $item['quantity'] : 0.0;

        if ($product_id <= 0 || $qty <= 0) {
            return new WP_Error('ffw_invalid_line_item', 'Invalid product_id or quantity in line_items', ['status' => 400]);
        }

        $available = FF_Warehouses_Core::get_available_qty($warehouse_id, $product_id, null);
        if ($available < $qty) {
            return new WP_Error(
                'ffw_insufficient_stock',
                sprintf('Not enough stock for product_id %d in this warehouse', $product_id),
                ['status' => 409]
            );
        }
    }

    // Create WooCommerce order
    $order = wc_create_order();
    if (is_wp_error($order)) {
        return new WP_Error('ffw_order_create_error', 'Failed to create order', ['status' => 500]);
    }

    // ✅ Set customer
    if ($customer_id > 0) {
        $order->set_customer_id($customer_id);
    } elseif ($scl_address && isset($scl_address['user_id']) && $scl_address['user_id'] > 0) {
        $order->set_customer_id($scl_address['user_id']);
    }

    // ✅ Set address from SCL or guest data
    if ($scl_address) {
        // Use SCL address
        $order->set_billing_first_name($scl_address['customer_name']);
        $order->set_billing_last_name('');
        $order->set_billing_company($scl_address['address_name']);
        $order->set_billing_address_1($scl_address['address_details']);
        
        // ✅ Put customer notes in address_2 (like WooCommerce standard)
        $order->set_billing_address_2($scl_address['notes_customer']);
        
        $order->set_billing_city($scl_address['zone']);
        $order->set_billing_state('');
        $order->set_billing_postcode('00000');
        $order->set_billing_country('EG');  
        $order->set_billing_phone($scl_address['phone_primary']);
        
        // ✅ Set email from customer user data
        $billing_email = '';
        if ($customer_id > 0) {
            $customer = get_userdata($customer_id);
            if ($customer) {
                $billing_email = $customer->user_email;
            }
        } elseif (!empty($scl_address['user_id']) && $scl_address['user_id'] > 0) {
            $customer = get_userdata($scl_address['user_id']);
            if ($customer) {
                $billing_email = $customer->user_email;
            }
        }
        $order->set_billing_email($billing_email ?: 'noemail@pos.local');
        
        // Shipping same as billing
        $order->set_shipping_first_name($scl_address['customer_name']);
        $order->set_shipping_last_name('.');
        $order->set_shipping_company($scl_address['address_name']);
        $order->set_shipping_address_1($scl_address['address_details']);
        $order->set_shipping_address_2('');
        $order->set_shipping_city($scl_address['zone']);
        $order->set_shipping_state('');
        $order->set_shipping_postcode('00000');
        $order->set_shipping_country('EG');
        
        // ✅ Add ALL SCL meta data (ensures REST API returns complete address)
        $order->update_meta_data('_scl_address_id', $scl_address_id);
        $order->update_meta_data('_billing_phone_secondary', $scl_address['phone_secondary']);
        $order->update_meta_data('_billing_address_name', $scl_address['address_name']);
        $order->update_meta_data('_billing_location_url', $scl_address['location_url']);
        $order->update_meta_data('_billing_location_lat', $scl_address['location_lat']);
        $order->update_meta_data('_billing_location_lng', $scl_address['location_lng']);
        $order->update_meta_data('_billing_notes_customer', $scl_address['notes_customer']);
        $order->update_meta_data('_billing_notes_internal', $scl_address['notes_internal']);
        $order->update_meta_data('_billing_zone', $scl_address['zone']);
        
    } elseif (!empty($guest_data)) {
        // Use guest data
        $guest_name   = isset($guest_data['name']) ? sanitize_text_field($guest_data['name']) : 'Guest';
        $guest_email  = isset($guest_data['email']) ? sanitize_email($guest_data['email']) : 'guest@pos.local';
        $guest_phone  = isset($guest_data['phone']) ? sanitize_text_field($guest_data['phone']) : '';
        $guest_address= isset($guest_data['address']) ? sanitize_text_field($guest_data['address']) : '';
        $guest_city   = isset($guest_data['city']) ? sanitize_text_field($guest_data['city']) : '';
        $guest_zone   = isset($guest_data['zone']) ? sanitize_text_field($guest_data['zone']) : $guest_city;
        $guest_notes  = isset($guest_data['notes']) ? sanitize_textarea_field($guest_data['notes']) : '';
        
        $order->set_billing_first_name($guest_name);
        $order->set_billing_last_name('');
        $order->set_billing_company('');
        $order->set_billing_address_1($guest_address);
        
        // ✅ Put notes in address_2
        $order->set_billing_address_2($guest_notes);
        
        $order->set_billing_city($guest_zone);
        $order->set_billing_state('');
        $order->set_billing_postcode('00000');
        $order->set_billing_country('EG');
        $order->set_billing_phone($guest_phone);
        $order->set_billing_email($guest_email);
        
        $order->set_shipping_first_name($guest_name);
        $order->set_shipping_last_name('');
        $order->set_shipping_company('');
        $order->set_shipping_address_1($guest_address);
        $order->set_shipping_address_2('');
        $order->set_shipping_city($guest_zone);
        $order->set_shipping_state('');
        $order->set_shipping_postcode('00000');
        $order->set_shipping_country('EG');
        
        // ✅ Add guest meta data
        $order->update_meta_data('_billing_zone', $guest_zone);
        if ($guest_notes) {
            $order->update_meta_data('_billing_notes_customer', $guest_notes);
        }
    }

    // ✅ Add delivery schedule
    if ($delivery_date) {
        $order->update_meta_data('_scl_delivery_date', $delivery_date);
        $order->update_meta_data('_billing_delivery_date', $delivery_date);
    }
    
    if ($delivery_time) {
        $order->update_meta_data('_scl_delivery_time', $delivery_time);
        $order->update_meta_data('_billing_delivery_time', $delivery_time);
    }

    // Normalize status
    $normalized_status = $status;
    if (strpos($normalized_status, 'wc-') === 0) {
        $normalized_status = substr($normalized_status, 3);
    }

    // Add line items and apply stock logic
    foreach ($line_items as $item) {
        $product_id = intval($item['product_id']);
        $qty        = (float) $item['quantity'];

        $product = wc_get_product($product_id);
        if (!$product) {
            $order->delete(true);
            return new WP_Error('ffw_product_not_found', sprintf('Product %d not found', $product_id), ['status' => 404]);
        }

        // Determine price
        $inventory_row = FF_Warehouses_Core::get_inventory_row($warehouse_id, $product_id, null);
        if ($inventory_row && $inventory_row->price !== null && $inventory_row->price > 0) {
            $unit_price = (float) $inventory_row->price;
        } else {
            $unit_price = (float) $product->get_price();
        }

        $subtotal = $unit_price * $qty;

        $item_args = [
            'subtotal' => $subtotal,
            'total'    => $subtotal,
        ];

        if (isset($item['price']) && is_numeric($item['price'])) {
            $override_price       = (float) $item['price'];
            $item_args['subtotal'] = $override_price * $qty;
            $item_args['total']    = $override_price * $qty;
        }

        $order->add_product($product, $qty, $item_args);

        // Stock adjustment
        $row              = FF_Warehouses_Core::get_inventory_row($warehouse_id, $product_id, null);
        $current_qty      = $row ? (float) $row->qty : 0.0;
        $current_reserved = $row ? (float) $row->reserved_qty : 0.0;

        $is_completed = ($normalized_status === 'completed');

        if ($is_completed) {
            $new_qty = $current_qty - $qty;
            if ($new_qty < 0) {
                $new_qty = 0;
            }
            $new_reserved = $current_reserved;

            $res = FF_Warehouses_Core::upsert_inventory_row(
                $warehouse_id,
                $product_id,
                null,
                $new_qty,
                $new_reserved,
                null,
                null
            );

            if (is_wp_error($res)) {
                $order->delete(true);
                return new WP_Error('ffw_stock_adjust_error', $res->get_error_message(), ['status' => 500]);
            }

            FF_Warehouses_Core::log_stock_action(
                $warehouse_id,
                $product_id,
                'pos_sale',
                -$qty,
                $current_qty,
                $new_qty,
                $current_reserved,
                $new_reserved,
                null,
                $employee_id,
                'Order sale (completed status)'
            );
        } else {
            $new_qty = $current_qty - $qty;
            if ($new_qty < 0) {
                $new_qty = 0;
            }
            $new_reserved = $current_reserved + $qty;

            $res = FF_Warehouses_Core::upsert_inventory_row(
                $warehouse_id,
                $product_id,
                null,
                $new_qty,
                $new_reserved,
                null,
                null
            );

            if (is_wp_error($res)) {
                $order->delete(true);
                return new WP_Error('ffw_stock_adjust_error', $res->get_error_message(), ['status' => 500]);
            }

            FF_Warehouses_Core::log_stock_action(
                $warehouse_id,
                $product_id,
                'pos_reserve',
                -$qty,
                $current_qty,
                $new_qty,
                $current_reserved,
                $new_reserved,
                null,
                $employee_id,
                'Order reservation (non-completed status)'
            );
        }
    }

    // ✅ Add shipping if requested
    if ($use_shipping && class_exists('SCL_Zones_Repository')) {
        $zone_name = '';
        
        if ($scl_address && !empty($scl_address['zone'])) {
            $zone_name = $scl_address['zone'];
        } elseif (!empty($guest_data['zone'])) {
            $zone_name = $guest_data['zone'];
        }
        
        if ($zone_name) {
            require_once WP_PLUGIN_DIR . '/simple-checkout-location/includes/class-zones-repository.php';
            $zones_repo    = new SCL_Zones_Repository();
            $shipping_cost = $zones_repo->get_shipping_cost_by_zone_name($zone_name);
            
            if ($shipping_cost !== false && $shipping_cost > 0) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title(sprintf('Delivery to %s', $zone_name));
                $shipping_item->set_method_id('scl_zone_shipping');
                $shipping_item->set_total($shipping_cost);
                $order->add_item($shipping_item);
            }
        }
    }

    // Set payment method
    $order->set_payment_method($payment_method);
    $order->set_payment_method_title(ucfirst($payment_method));

        // Customer notes
    if ($customer_notes) {
        $order->set_customer_note($customer_notes);
    }

    // ✅✅✅ تطبيق الكوبونات قبل حساب الإجماليات
    if (!empty($coupon_lines)) {
        foreach ($coupon_lines as $coupon_data) {
            $coupon_code = isset($coupon_data['code']) ? sanitize_text_field($coupon_data['code']) : '';
            
            if (empty($coupon_code)) {
                continue;
            }
            
            // التحقق من وجود الكوبون في WooCommerce
            $coupon = new WC_Coupon($coupon_code);
            
            if (!$coupon->get_id()) {
                // الكوبون غير موجود، نتجاهله
                continue;
            }
            
            // التحقق من صلاحية الكوبون
            $is_valid = $coupon->is_valid();
            
            if (is_wp_error($is_valid)) {
                // الكوبون غير صالح (منتهي، محدود الاستخدام، إلخ)
                continue;
            }
            
            // إضافة الكوبون للطلب
            $result = $order->apply_coupon($coupon_code);
            
            if (is_wp_error($result)) {
                // فشل تطبيق الكوبون، نتجاهله
                continue;
            }
            
            // تسجيل ملاحظة على الطلب
            $order->add_order_note(
                sprintf('Coupon "%s" applied via POS API', $coupon_code)
            );
        }
    }

    // Set status
    $order->set_status($status);


    // Set status
    $order->set_status($status);

    // ✅✅✅ CRITICAL: Mark stock as reduced BEFORE setting status
    // This prevents WooCommerce from reducing stock automatically
    $order->update_meta_data('_order_stock_reduced', 'yes');

    // Add meta: warehouse + employee + source
    $order->update_meta_data('_ffw_warehouse_id', $warehouse_id);
    $order->update_meta_data('_ffw_employee_id', $employee_id);

    $source = 'pos';
    if ($scl_address_id > 0 || !empty($guest_data)) {
        $source = 'delivery';
    }
    $order->update_meta_data('_ffw_source', $source);

    // ✅ إضافة حالة المخزون بناءً على حالة الطلب
    if ($is_completed) {
        $order->update_meta_data('_ffw_stock_status', 'consumed');
    } else {
        $order->update_meta_data('_ffw_stock_status', 'reserved');
        $order->update_meta_data('_ffw_stock_reserved', 'yes');
    }

    foreach ($extra_meta as $key => $value) {
        $order->update_meta_data(sanitize_key($key), $value);
    }

    // ✅ Also add this to line items to prevent stock reduction
    foreach ($order->get_items() as $item_id => $item) {
        wc_update_order_item_meta($item_id, '_reduced_stock', '1');
    }
    // Calculate totals
    $order->calculate_totals();
    
    // Save BEFORE setting status
    $order->save();
    
    // Set final status
    $order->set_status($status);
    
    // Save again after status change
    $order->save();

    // ✅ Trigger WhatsApp invoices ONCE - AFTER order is fully ready and saved
    if ( class_exists( 'WA_Order_Invoices' ) ) {
        $invoices = WA_Order_Invoices::instance();
        
        // لا تحذف _wa_invoice_queued هنا - الحارس داخل queue_order_invoices سيمنع التكرار
        // استدعاء واحد فقط
        $invoices->handle_new_order_generic( $order->get_id(), $order );
    }

    // Add order note
    $note_parts   = [];
    $note_parts[] = sprintf('Order created via FF Warehouses API from warehouse: %s (ID: %d)', $warehouse->name, $warehouse_id);
    if ($scl_address_id > 0) {
        $note_parts[] = sprintf('SCL Address ID: %d', $scl_address_id);
    }
    if ($delivery_date) {
        $note_parts[] = sprintf('Delivery: %s %s', $delivery_date, $delivery_time);
    }
    $order->add_order_note(implode("\n", $note_parts));

    $data = [
        'id'           => $order->get_id(),
        'order_number' => $order->get_order_number(),
        'status'       => $order->get_status(),
        'total'        => $order->get_total(),
        'currency'     => $order->get_currency(),
        'warehouse_id' => $warehouse_id,
        'employee_id'  => $employee_id,
        'source'       => $source,
    ];

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Order created successfully',
        'data'    => $data,
    ], 200);
}


    /**
     * Get order summary
     *
     * GET /ff/v1/orders/{id}
     */
    public static function get_order($request) {
        if (!class_exists('WC_Order')) {
            return new WP_Error('ffw_woocommerce_missing', 'WooCommerce is not loaded', ['status' => 500]);
        }

        $order_id = intval($request['id']);
        if ($order_id <= 0) {
            return new WP_Error('ffw_invalid_order', 'Invalid order ID', ['status' => 400]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('ffw_order_not_found', 'Order not found', ['status' => 404]);
        }

        $items = [];
        foreach ($order->get_items() as $item_id => $item) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();

            $items[] = [
                'item_id'    => $item_id,
                'product_id' => $product ? $product->get_id() : 0,
                'name'       => $item->get_name(),
                'qty'        => (float) $item->get_quantity(),
                'total'      => (float) $item->get_total(),
                'subtotal'   => (float) $item->get_subtotal(),
            ];
        }

        $warehouse_id = (int) $order->get_meta('_ffw_warehouse_id', true);
        $employee_id  = (int) $order->get_meta('_ffw_employee_id', true);
        $source       = (string) $order->get_meta('_ffw_source', true);

        $data = [
            'id'           => $order->get_id(),
            'status'       => $order->get_status(),
            'total'        => $order->get_total(),
            'currency'     => $order->get_currency(),
            'warehouse_id' => $warehouse_id,
            'employee_id'  => $employee_id,
            'source'       => $source,
            'items'        => $items,
        ];

        return new WP_REST_Response([
            'success' => true,
            'data'    => $data,
        ], 200);
    }

    /**
     * Placeholder for future order update logic
     *
     * PUT /ff/v1/orders/{id}
     * - In future: accept changes in line_items and adjust warehouse stock based on deltas.
     */

   public static function update_order( $request ) {
    if ( ! class_exists( 'WC_Order' ) ) {
        return new WP_Error(
            'ffw_woocommerce_missing',
            'WooCommerce is not loaded',
            [ 'status' => 500 ]
        );
    }

    $order_id = intval( $request['id'] );
    if ( $order_id <= 0 ) {
        return new WP_Error(
            'ffw_invalid_order',
            'Invalid order ID',
            [ 'status' => 400 ]
        );
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_Error(
            'ffw_order_not_found',
            'Order not found',
            [ 'status' => 404 ]
        );
    }

    $params        = $request->get_json_params();
    $status        = ! empty( $params['status'] ) ? sanitize_text_field( $params['status'] ) : $order->get_status();
    $billing_data  = isset( $params['billing'] )  && is_array( $params['billing'] )  ? $params['billing']  : [];
    $shipping_data = isset( $params['shipping'] ) && is_array( $params['shipping'] ) ? $params['shipping'] : [];
    $line_items_in = isset( $params['line_items'] ) && is_array( $params['line_items'] ) ? $params['line_items'] : [];

    // zone القديمة قبل التعديل
    $old_zone = $order->get_billing_city();

    /*
     * 1) تحديث عنوان الفاتورة على الطلب + Meta SCL
     */
    if ( ! empty( $billing_data ) ) {
        if ( isset( $billing_data['first_name'] ) ) {
            $order->set_billing_first_name( sanitize_text_field( $billing_data['first_name'] ) );
        }
        if ( isset( $billing_data['last_name'] ) ) {
            $order->set_billing_last_name( sanitize_text_field( $billing_data['last_name'] ) );
        }
        if ( isset( $billing_data['company'] ) ) {
            $order->set_billing_company( sanitize_text_field( $billing_data['company'] ) );
        }
        if ( isset( $billing_data['phone'] ) ) {
            $order->set_billing_phone( sanitize_text_field( $billing_data['phone'] ) );
        }
        if ( isset( $billing_data['address_1'] ) ) {
            $order->set_billing_address_1( sanitize_text_field( $billing_data['address_1'] ) );
        }
        if ( isset( $billing_data['address_2'] ) ) {
            $order->set_billing_address_2( sanitize_text_field( $billing_data['address_2'] ) );
        }
        if ( isset( $billing_data['city'] ) ) {
            $order->set_billing_city( sanitize_text_field( $billing_data['city'] ) );
        }
        if ( isset( $billing_data['state'] ) ) {
            $order->set_billing_state( sanitize_text_field( $billing_data['state'] ) );
        }
        if ( isset( $billing_data['postcode'] ) ) {
            $order->set_billing_postcode( sanitize_text_field( $billing_data['postcode'] ) );
        }
        if ( isset( $billing_data['country'] ) ) {
            $order->set_billing_country( sanitize_text_field( $billing_data['country'] ) );
        }

        // ميتا SCL
        if ( isset( $billing_data['phone_secondary'] ) ) {
            $order->update_meta_data( '_billing_phone_secondary', sanitize_text_field( $billing_data['phone_secondary'] ) );
        }
        if ( isset( $billing_data['address_name'] ) ) {
            $order->update_meta_data( '_billing_address_name', sanitize_text_field( $billing_data['address_name'] ) );
        }
        if ( isset( $billing_data['location_url'] ) ) {
            $order->update_meta_data( '_billing_location_url', esc_url_raw( $billing_data['location_url'] ) );
        }
        if ( isset( $billing_data['location_lat'] ) ) {
            $order->update_meta_data( '_billing_location_lat', sanitize_text_field( $billing_data['location_lat'] ) );
        }
        if ( isset( $billing_data['location_lng'] ) ) {
            $order->update_meta_data( '_billing_location_lng', sanitize_text_field( $billing_data['location_lng'] ) );
        }
        if ( isset( $billing_data['notes_customer'] ) ) {
            $order->update_meta_data( '_billing_notes_customer', sanitize_textarea_field( $billing_data['notes_customer'] ) );
        }
        if ( isset( $billing_data['notes_internal'] ) ) {
            $order->update_meta_data( '_billing_notes_internal', sanitize_textarea_field( $billing_data['notes_internal'] ) );
        }
        if ( isset( $billing_data['zone'] ) ) {
            $order->update_meta_data( '_billing_zone', sanitize_text_field( $billing_data['zone'] ) );
        }
        if ( isset( $billing_data['scl_address_id'] ) ) {
            $order->update_meta_data( '_scl_address_id', (int) $billing_data['scl_address_id'] );
        }
        if ( isset( $billing_data['delivery_date'] ) && $billing_data['delivery_date'] !== '' ) {
            $date = sanitize_text_field( $billing_data['delivery_date'] );
            $order->update_meta_data( '_billing_delivery_date', $date );
            $order->update_meta_data( '_scl_delivery_date', $date );
        }
        if ( isset( $billing_data['delivery_time'] ) && $billing_data['delivery_time'] !== '' ) {
            $time = sanitize_text_field( $billing_data['delivery_time'] );
            $order->update_meta_data( '_billing_delivery_time', $time );
            $order->update_meta_data( '_scl_delivery_time', $time );
        }
    }

    /*
     * 2) تحديث عنوان الشحن على الطلب
     */
    if ( ! empty( $shipping_data ) ) {
        if ( isset( $shipping_data['first_name'] ) ) {
            $order->set_shipping_first_name( sanitize_text_field( $shipping_data['first_name'] ) );
        }
        if ( isset( $shipping_data['last_name'] ) ) {
            $order->set_shipping_last_name( sanitize_text_field( $shipping_data['last_name'] ) );
        }
        if ( isset( $shipping_data['company'] ) ) {
            $order->set_shipping_company( sanitize_text_field( $shipping_data['company'] ) );
        }
        if ( isset( $shipping_data['phone'] ) ) {
            $order->set_shipping_phone( sanitize_text_field( $shipping_data['phone'] ) );
        }
        if ( isset( $shipping_data['address_1'] ) ) {
            $order->set_shipping_address_1( sanitize_text_field( $shipping_data['address_1'] ) );
        }
        if ( isset( $shipping_data['address_2'] ) ) {
            $order->set_shipping_address_2( sanitize_text_field( $shipping_data['address_2'] ) );
        }
        if ( isset( $shipping_data['city'] ) ) {
            $order->set_shipping_city( sanitize_text_field( $shipping_data['city'] ) );
        }
        if ( isset( $shipping_data['state'] ) ) {
            $order->set_shipping_state( sanitize_text_field( $shipping_data['state'] ) );
        }
        if ( isset( $shipping_data['postcode'] ) ) {
            $order->set_shipping_postcode( sanitize_text_field( $shipping_data['postcode'] ) );
        }
        if ( isset( $shipping_data['country'] ) ) {
            $order->set_shipping_country( sanitize_text_field( $shipping_data['country'] ) );
        }
    }

    /*
     * 2.5) مزامنة Woo user meta
     */
    $customer_id = $order->get_customer_id();
    if ( $customer_id > 0 && ! empty( $billing_data ) ) {
        if ( isset( $billing_data['first_name'] ) ) {
            $first_name = sanitize_text_field( $billing_data['first_name'] );
            update_user_meta( $customer_id, 'billing_first_name', $first_name );
            update_user_meta( $customer_id, 'first_name', $first_name );
        }
        if ( isset( $billing_data['last_name'] ) ) {
            $last_name = sanitize_text_field( $billing_data['last_name'] );
            update_user_meta( $customer_id, 'billing_last_name', $last_name );
            update_user_meta( $customer_id, 'last_name', $last_name );
        }
        if ( isset( $billing_data['phone'] ) ) {
            update_user_meta( $customer_id, 'billing_phone', sanitize_text_field( $billing_data['phone'] ) );
        }
        if ( isset( $billing_data['address_1'] ) ) {
            update_user_meta( $customer_id, 'billing_address_1', sanitize_text_field( $billing_data['address_1'] ) );
        }
        if ( isset( $billing_data['city'] ) ) {
            update_user_meta( $customer_id, 'billing_city', sanitize_text_field( $billing_data['city'] ) );
        }
        if ( isset( $billing_data['zone'] ) ) {
            update_user_meta( $customer_id, 'billing_zone', sanitize_text_field( $billing_data['zone'] ) );
        }
    }

    /*
     * 2.6) لا نلمس جدول scl_addresses هنا
     */

    /*
     * 2.7) إعادة حساب الشحن لو الـ zone اتغيّرت
     */
    $new_zone = '';
    if ( ! empty( $billing_data['zone'] ) ) {
        $new_zone = sanitize_text_field( $billing_data['zone'] );
    } elseif ( ! empty( $billing_data['city'] ) ) {
        $new_zone = sanitize_text_field( $billing_data['city'] );
    }

    if ( $new_zone && $new_zone !== $old_zone ) {
        if ( ! class_exists( 'SCL_Zones_Repository' ) && defined( 'SCL_PLUGIN_DIR' ) ) {
            require_once SCL_PLUGIN_DIR . 'includes/class-zones-repository.php';
        }

        if ( class_exists( 'SCL_Zones_Repository' ) ) {
            $zones_repo    = new SCL_Zones_Repository();
            $shipping_cost = $zones_repo->get_shipping_cost_by_zone_name( $new_zone );

            if ( $shipping_cost !== false ) {
                foreach ( $order->get_items( 'shipping' ) as $item_id => $shipping_item ) {
                    $order->remove_item( $item_id );
                }

                $shipping = new WC_Order_Item_Shipping();
                $shipping->set_method_title( sprintf( 'Delivery to %s', $new_zone ) );
                $shipping->set_method_id( 'scl_zone_shipping' );
                $shipping->set_total( $shipping_cost );
                $shipping->add_meta_data( 'zone_name', $new_zone );

                $order->add_item( $shipping );

                $order->set_billing_city( $new_zone );
                $order->set_shipping_city( $new_zone );
                $order->update_meta_data( '_billing_zone', $new_zone );
            }
        }
    }

    /*
     * 3) تجهيز line_items من الـ payload:
     *    - new_qty_map لأسطر موجودة
     *    - new_products لمنتجات جديدة {product_id, quantity}
     */
    $new_qty_map  = [];
    $new_products = [];

    foreach ( $line_items_in as $li ) {
        if ( ! empty( $li['id'] ) ) {
            $line_id = intval( $li['id'] );
            $qty     = isset( $li['quantity'] ) ? (float) $li['quantity'] : 0.0;
            if ( $qty < 0 ) {
                $qty = 0;
            }
            $new_qty_map[ $line_id ] = $qty;
        } elseif ( ! empty( $li['product_id'] ) ) {
            $product_id = intval( $li['product_id'] );
            $qty        = isset( $li['quantity'] ) ? (float) $li['quantity'] : 0.0;
            if ( $product_id > 0 && $qty > 0 ) {
                $new_products[] = [
                    'product_id' => $product_id,
                    'quantity'   => $qty,
                ];
            }
        }
    }

    /*
     * 4) تعديل أسطر الطلب:
     *    - الكميات الجديدة: نحسب delta ونُحدّث مخزون FF يدويًا.
     *    - حذف سطر: delta = -old_qty.
     *    - إضافة منتج جديد: delta = qty الجديدة.
     *    - أثناء ذلك نوقف هوكات الكمية العامة عشان ما يحصلش تكرار.
     */
    if ( ! empty( $new_qty_map ) || ! empty( $new_products ) ) {

        // عطل هوكات الكمية أثناء التعديل عبر REST
        if ( class_exists( 'FF_Warehouses_Core' ) ) {
            FF_Warehouses_Core::set_suppress_order_item_hooks( true );
        }

        // تعديل الأسطر الموجودة
        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            if ( ! isset( $new_qty_map[ $item_id ] ) ) {
                continue;
            }

            $new_qty = $new_qty_map[ $item_id ];

            $product = $item->get_product();
            $old_qty = (float) $item->get_quantity();

            if ( ! $product ) {
                // لو المنتج مش موجود لأي سبب، نعدّل السطر بدون تعامل مخزون
                if ( $new_qty <= 0 ) {
                    if ( $old_qty > 0 ) {
                        $item->set_quantity( 0 );
                        $item->save();
                    }
                    $order->remove_item( $item_id );
                } else {
                    $item->set_quantity( $new_qty );
                    $item->save();
                }
                continue;
            }

            $product_id = $product->get_id();
            if ( $product_id <= 0 ) {
                continue;
            }

            // حذف السطر
            if ( $new_qty <= 0 ) {
                if ( $old_qty > 0 && class_exists( 'FF_Warehouses_Core' ) ) {
                    $delta = 0 - $old_qty; // تقليل الكمية في الطلب
                    FF_Warehouses_Core::apply_order_item_stock_delta( $order, $product_id, $delta );
                }

                if ( $old_qty > 0 ) {
                    $item->set_quantity( 0 );
                    $item->save();
                }
                $order->remove_item( $item_id );
                continue;
            }

            // تعديل الكمية مع المحافظة على سعر الوحدة
            $old_subtotal = (float) $item->get_subtotal();
            $old_total    = (float) $item->get_total();

            if ( $old_qty > 0 && ( $old_subtotal > 0 || $old_total > 0 ) ) {
                $unit_subtotal = $old_subtotal / $old_qty;
                $unit_total    = $old_total    / $old_qty;
            } else {
                $price         = (float) $product->get_price();
                $unit_subtotal = $price;
                $unit_total    = $price;
            }

            $item->set_quantity( $new_qty );
            $item->set_subtotal( $unit_subtotal * $new_qty );
            $item->set_total(    $unit_total    * $new_qty );
            $item->save();

            // حساب delta وتطبيقه على المخزون
            $delta = $new_qty - $old_qty;
            if ( $delta != 0 && class_exists( 'FF_Warehouses_Core' ) ) {
                FF_Warehouses_Core::apply_order_item_stock_delta( $order, $product_id, $delta );
            }
        }

        // إضافة منتجات جديدة
        if ( ! empty( $new_products ) ) {
            foreach ( $new_products as $np ) {
                $product_id = $np['product_id'];
                $qty        = (float) $np['quantity'];

                if ( $product_id <= 0 || $qty <= 0 ) {
                    continue;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    continue;
                }

                $unit_price = (float) $product->get_price();
                $subtotal   = $unit_price * $qty;

                $order->add_product(
                    $product,
                    $qty,
                    [
                        'subtotal' => $subtotal,
                        'total'    => $subtotal,
                    ]
                );

                // المنتج جديد بالكامل في الطلب → delta = qty
                if ( class_exists( 'FF_Warehouses_Core' ) ) {
                    FF_Warehouses_Core::apply_order_item_stock_delta( $order, $product_id, $qty );
                }
            }
        }

        // إعادة تمكين الهوكات بعد الانتهاء
        if ( class_exists( 'FF_Warehouses_Core' ) ) {
            FF_Warehouses_Core::set_suppress_order_item_hooks( false );
        }
    }

    /*
     * 5) تحديث الحالة
     */
    if ( ! empty( $status ) ) {
        $order->set_status( $status );
    }

    /*
     * 6) إعادة حساب الضرائب والإجماليات وحفظ الطلب
     */
    $order->calculate_taxes();
    $order->calculate_totals();
    $order->save();

    // ⚠️ لا نرسل فواتير عند التحديث - فقط عند الإنشاء
    // إن أردت إعادة إرسال فاتورة عند التحديث، ضعها هنا
    // لكن الأفضل عدم إرسال رسائل متكررة عند كل تعديل

    $data = [
        'id'       => $order->get_id(),
        'status'   => $order->get_status(),
        'total'    => $order->get_total(),
        'currency' => $order->get_currency(),
    ];

    return new WP_REST_Response(
        [
            'success' => true,
            'message' => 'Order updated successfully',
            'data'    => $data,
        ],
        200
    );
}
/**
 * Send order invoice to WhatsApp queue
 *
 * POST /ff/v1/orders/{id}/send-invoice
 * 
 * This endpoint allows manually triggering invoice sending after order updates.
 * It ensures the invoice reflects the latest order data before queuing.
 */
public static function send_invoice_to_queue($request) {
    if (!class_exists('WC_Order')) {
        return new WP_Error(
            'ffw_woocommerce_missing',
            'WooCommerce is not loaded',
            ['status' => 500]
        );
    }

    $order_id = intval($request['id']);
    if ($order_id <= 0) {
        return new WP_Error(
            'ffw_invalid_order',
            'Invalid order ID',
            ['status' => 400]
        );
    }

    // ✅ Load the order
    $order = wc_get_order($order_id);
    if (!$order) {
        return new WP_Error(
            'ffw_order_not_found',
            'Order not found',
            ['status' => 404]
        );
    }

    // ✅ Check if WA Order Invoices plugin is active
    if (!class_exists('WA_Order_Invoices')) {
        return new WP_Error(
            'ffw_wa_invoices_missing',
            'WA Order Invoices plugin is not active',
            ['status' => 503]
        );
    }

    // ✅ Recalculate totals to ensure invoice has latest data
    $order->calculate_totals();
    $order->save();

    // ✅ Queue the invoice for sending
    $invoices = WA_Order_Invoices::instance();
    
    // Remove any existing queue flag to allow re-sending
    $order->delete_meta_data('_wa_invoice_queued');
    $order->save();
    
    // Trigger the invoice queue
    $invoices->handle_new_order_generic($order_id, $order);

    // ✅ Add order note
    $order->add_order_note('Invoice manually queued for WhatsApp sending via API');

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Invoice queued for WhatsApp sending successfully',
        'data'    => [
            'order_id'     => $order_id,
            'order_number' => $order->get_order_number(),
            'status'       => $order->get_status(),
            'total'        => $order->get_total(),
        ],
    ], 200);
}

}
