<?php
/**
 * Plugin Name: Fastrac Shipping for WooCommerce
 * Plugin URI: https://fastrac.id
 * Description: Integrate Fastrac shipping rates into your WooCommerce store
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * Text Domain: fastrac-shipping
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 4.0
 * WC tested up to: 8.1.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Fastrac_Shipping
 */

// Define plugin version - needed for script enqueuing
if (!defined('FASTRAC_SHIPPING_VERSION')) {
    define('FASTRAC_SHIPPING_VERSION', '1.0.0');
}

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('FASTRAC_SHIPPING_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FASTRAC_SHIPPING_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FASTRAC_SHIPPING_MIN_WC_VERSION', '4.0');
define('FASTRAC_SHIPPING_MIN_PHP_VERSION', '7.2');

/**
 * Check if WooCommerce is active
 *
 * @return bool True if WooCommerce is active, false otherwise.
 */
function fastrac_shipping_is_woocommerce_active() {
    $active_plugins = (array) get_option('active_plugins', array());
    
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    
    return in_array('woocommerce/woocommerce.php', $active_plugins, true) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
}

/**
 * Display notice if WooCommerce is not active
 */
function fastrac_shipping_woocommerce_notice() {
    ?>
    <div class="error notice">
        <p><?php esc_html_e('Fastrac Shipping requires WooCommerce to be installed and active.', 'fastrac-shipping'); ?></p>
    </div>
    <?php
}

/**
 * Display PHP version notice
 */
function fastrac_shipping_php_version_notice() {
    ?>
    <div class="error notice">
        <p><?php echo sprintf(esc_html__('Fastrac Shipping requires PHP version %s or later. You are running version %s.', 'fastrac-shipping'), FASTRAC_SHIPPING_MIN_PHP_VERSION, PHP_VERSION); ?></p>
    </div>
    <?php
}

/**
 * Check if PHP version meets requirements
 *
 * @return bool
 */
function fastrac_shipping_check_php_version() {
    return version_compare(PHP_VERSION, FASTRAC_SHIPPING_MIN_PHP_VERSION, '>=');
}

/**
 * Initialize the plugin
 */
function fastrac_shipping_init() {
    // Include required files for core functionality
    require_once FASTRAC_SHIPPING_PLUGIN_DIR . 'includes/class-fastrac-shipping-logger.php';
    require_once FASTRAC_SHIPPING_PLUGIN_DIR . 'includes/class-fastrac-shipping-core.php';
    
    // Log plugin initialization
    error_log('Fastrac Shipping: Plugin initialized');
    
    // Enqueue scripts and styles
    add_action('wp_enqueue_scripts', 'fastrac_shipping_enqueue_scripts', 20);
    
    // Add settings link on plugin page
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'fastrac_shipping_plugin_action_links');
    
    // Load plugin text domain
    load_plugin_textdomain('fastrac-shipping', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * Add Fastrac Shipping method to WooCommerce
 *
 * @param array $methods Array of shipping methods.
 * @return array Modified array of shipping methods.
 */
function fastrac_shipping_add_method($methods) {
    // Log when shipping method is being added
    error_log('Fastrac Shipping: Adding shipping method to WooCommerce');
    $methods['fastrac_shipping'] = 'Fastrac_Shipping_Method';
    error_log('Fastrac Shipping: Methods array now contains: ' . print_r(array_keys($methods), true));
    return $methods;
}

/**
 * Add settings link on plugin page
 *
 * @param array $links Array of plugin action links.
 * @return array Modified array of plugin action links.
 */
function fastrac_shipping_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=fastrac_shipping') . '">' . __('Settings', 'fastrac-shipping') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

/**
 * Activation hook for Fastrac Shipping
 */
function fastrac_shipping_activate() {
    // Check if WooCommerce is active
    if (!fastrac_shipping_is_woocommerce_active()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html__('Fastrac Shipping requires WooCommerce to be installed and active.', 'fastrac-shipping'));
    }
    
    // Check PHP version
    if (!fastrac_shipping_check_php_version()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(sprintf(esc_html__('Fastrac Shipping requires PHP version %s or later. You are running version %s.', 'fastrac-shipping'), FASTRAC_SHIPPING_MIN_PHP_VERSION, PHP_VERSION));
    }
    
    // Create assets directory if it doesn't exist
    wp_mkdir_p(FASTRAC_SHIPPING_PLUGIN_DIR . 'assets/css');
    wp_mkdir_p(FASTRAC_SHIPPING_PLUGIN_DIR . 'assets/js');
    wp_mkdir_p(FASTRAC_SHIPPING_PLUGIN_DIR . 'assets/img');
}

/**
 * Deactivation hook for Fastrac Shipping
 */
function fastrac_shipping_deactivate() {
    // Clean up any temporary data
    // For now, we're only using session data which will expire naturally
}

/**
 * Enqueue scripts and styles for the plugin
 */
function fastrac_shipping_enqueue_scripts() {
    // Debug log current page and WooCommerce state
    error_log('Fastrac Shipping Debug - Current page: ' . (is_checkout() ? 'Checkout' : 'Other'));
    
    if (function_exists('WC') && WC()->shipping()) {
        error_log('Fastrac Shipping Debug - WC()->shipping() available: Yes');
    } else {
        error_log('Fastrac Shipping Debug - WC()->shipping() available: No');
    }
    
    // Only load on checkout page
    if (!is_checkout()) {
        error_log('Fastrac Shipping: Not on checkout page, skipping script enqueue');
        return;
    }
    
    // Log script enqueuing for debugging
    error_log('Fastrac Shipping: Attempting to enqueue checkout scripts');
    
    // Get the shipping method instance to access settings
    $shipping_methods = WC()->shipping()->get_shipping_methods();
    $fastrac_method = isset($shipping_methods['fastrac_shipping']) ? $shipping_methods['fastrac_shipping'] : null;
    
    // Default settings
    $settings = array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fastrac-shipping-nonce'),
        'debug_mode' => 'yes', // Force debug mode on for troubleshooting
        'plugin_url' => FASTRAC_SHIPPING_PLUGIN_URL,
        'current_page' => 'checkout',
        'i18n' => array(
            'enter_postcode' => __('Please enter your postcode to see shipping rates.', 'fastrac-shipping'),
            'updating' => __('Updating shipping rates...', 'fastrac-shipping'),
            'postcode_error' => __('Unable to find shipping rates for this postcode.', 'fastrac-shipping')
        )
    );
    
    // If we have an instance of the shipping method, get actual settings
    if ($fastrac_method && method_exists($fastrac_method, 'get_option')) {
        // Add API settings if available
        $has_access_key = !empty($fastrac_method->get_option('access_key'));
        $has_secret_key = !empty($fastrac_method->get_option('secret_key'));
        $has_origin_id = !empty($fastrac_method->get_option('origin_id'));
        
        $settings['has_api_credentials'] = ($has_access_key && $has_secret_key) ? 'yes' : 'no';
        $settings['has_origin_id'] = $has_origin_id ? 'yes' : 'no';
        
        // Log settings status
        error_log('Fastrac Shipping Debug - API credentials status:');
        error_log('Fastrac Shipping Debug - Access key: ' . ($has_access_key ? 'Set' : 'Missing'));
        error_log('Fastrac Shipping Debug - Secret key: ' . ($has_secret_key ? 'Set' : 'Missing'));
        error_log('Fastrac Shipping Debug - Origin ID: ' . ($has_origin_id ? 'Set' : 'Missing'));
    } else {
        error_log('Fastrac Shipping Debug - No shipping method instance available or missing get_option method');
    }
    
    // Check if JS directory exists
    $js_dir = FASTRAC_SHIPPING_PLUGIN_DIR . 'assets/js';
    if (!file_exists($js_dir)) {
        wp_mkdir_p($js_dir);
        error_log('Fastrac Shipping: Created JS directory: ' . $js_dir);
    }
    
    // Check if the JS file exists
    $js_file = $js_dir . '/checkout.js';
    if (file_exists($js_file)) {
        error_log('Fastrac Shipping: JS file exists at: ' . $js_file);
    } else {
        error_log('Fastrac Shipping: JS file DOES NOT exist at: ' . $js_file);
    }
    
    // Enqueue the script with version timestamp to prevent caching
    $version = FASTRAC_SHIPPING_VERSION . '.' . time();
    
    // Enqueue checkout script
    wp_enqueue_script(
        'fastrac-shipping-checkout',
        FASTRAC_SHIPPING_PLUGIN_URL . 'assets/js/checkout.js',
        array('jquery', 'wc-checkout'),
        $version,
        true  // Load in footer
    );
    
    // Localize script with settings
    wp_localize_script(
        'fastrac-shipping-checkout',
        'fastracShipping',
        $settings
    );
    
    // Enqueue CSS
    wp_enqueue_style(
        'fastrac-shipping-style',
        FASTRAC_SHIPPING_PLUGIN_URL . 'assets/css/fastrac-shipping.css',
        array(),
        $version
    );
    
    error_log('Fastrac Shipping: Scripts enqueued and localized');
}

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'fastrac_shipping_activate');
register_deactivation_hook(__FILE__, 'fastrac_shipping_deactivate');

// Check requirements before initializing
$requirements_met = true;

// Check PHP version
if (!fastrac_shipping_check_php_version()) {
    add_action('admin_notices', 'fastrac_shipping_php_version_notice');
    $requirements_met = false;
}

// Check if WooCommerce is active
if (!fastrac_shipping_is_woocommerce_active()) {
    add_action('admin_notices', 'fastrac_shipping_woocommerce_notice');
    $requirements_met = false;
}

// Only initialize the plugin if all requirements are met
if ($requirements_met) {
    // Initialize the plugin earlier in the WordPress lifecycle
    add_action('plugins_loaded', 'fastrac_shipping_init');
    
    // Register shipping method after WooCommerce is loaded
    add_action('woocommerce_shipping_init', function() {
        error_log('Fastrac Shipping: Loading shipping method class');
        require_once FASTRAC_SHIPPING_PLUGIN_DIR . 'includes/class-fastrac-shipping-method.php';
    });
    
    // Add the shipping method to WooCommerce
    add_filter('woocommerce_shipping_methods', 'fastrac_shipping_add_method');
}
