<?php
/**
 * Plugin Name: FF Warehouses
 * Description: Multi-warehouse management for WooCommerce with SHRMS auth integration.
 * Version: 1.0.1
 * Author: Abdulrahman Roston
 * Text Domain: ff-warehouses
 */
 
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Define plugin constants
 */
define('FFW_VERSION', '1.0.1'); // TEST: Changed from 1.0.0 to 1.0.1 for auto-release testing
define('FFW_FILE', __FILE__);
define('FFW_PATH', plugin_dir_path(__FILE__));
define('FFW_URL', plugin_dir_url(__FILE__));
define('FFW_DB_VERSION', '1.0.0');

/**
 * Setup automatic updates from GitHub
 */
require FFW_PATH . 'includes/plugin-update-checker-master/plugin-update-checker.php';


use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ffwUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/abdulrahmanroston/warehouses_manager_plugin/', 
    FFW_FILE,
    'ff-warehouses'
);

// Set the branch that contains stable releases
$ffwUpdateChecker->setBranch('main'); // 

// Optional: Enable release assets (recommended)
$ffwUpdateChecker->getVcsApi()->enableReleaseAssets();


/**
 * Main plugin class
 */
final class FF_Warehouses_Plugin {

    /**
     * Singleton instance
     *
     * @var FF_Warehouses_Plugin|null
     */
    private static $instance = null;

    /**
     * Get plugin instance (singleton)
     *
     * @return FF_Warehouses_Plugin
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - load dependencies and hooks
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required class files
     */
    private function load_dependencies() {
        require_once FFW_PATH . 'includes/class-ff-warehouses-core.php';
        require_once FFW_PATH . 'includes/class-ff-warehouses-auth.php';
        require_once FFW_PATH . 'includes/class-ff-warehouses-api.php';
        require_once FFW_PATH . 'includes/class-ff-warehouses-orders.php';
        require_once FFW_PATH . 'includes/class-ff-warehouses-admin.php';
    }

    /**
     * Register activation / deactivation hooks and runtime hooks
     */
    private function init_hooks() {
        register_activation_hook(FFW_FILE, [ 'FF_Warehouses_Core', 'activate' ]);
        register_deactivation_hook(FFW_FILE, [ 'FF_Warehouses_Core', 'deactivate' ]);

        // Initialize components when plugins are loaded
        add_action('plugins_loaded', [ $this, 'init' ], 20);
    }

    /**
     * Initialize plugin components
     */
    public function init() {
        // Core (DB schema, helpers)
        if (class_exists('FF_Warehouses_Core')) {
            FF_Warehouses_Core::init();
        }

        // Auth layer (using SHRMS token)
        if (class_exists('FF_Warehouses_Auth')) {
            FF_Warehouses_Auth::init();
        }

        // REST API
        if (class_exists('FF_Warehouses_API')) {
            FF_Warehouses_API::init();
        }

        // Orders / WooCommerce integration
        if (class_exists('FF_Warehouses_Orders')) {
            FF_Warehouses_Orders::init();
        }

        // Admin UI (only in dashboard)
        if (is_admin() && class_exists('FF_Warehouses_Admin')) {
            FF_Warehouses_Admin::init();
        }
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton FF_Warehouses_Plugin');
    }
}

/**
 * Helper function to access the plugin instance
 *
 * @return FF_Warehouses_Plugin
 */
function ff_warehouses() {
    return FF_Warehouses_Plugin::instance();
}

// Bootstrap the plugin
ff_warehouses();
