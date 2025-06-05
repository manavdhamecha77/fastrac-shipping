<?php
/**
 * Fastrac Shipping Method Class
 *
 * @package Fastrac_Shipping
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the logger class
if (!class_exists('Fastrac_Shipping_Logger')) {
    require_once 'class-fastrac-shipping-logger.php';
}


/**
 * Fastrac_Shipping_Method class.
 *
 * @extends WC_Shipping_Method
 */
class Fastrac_Shipping_Method extends WC_Shipping_Method {

    /**
     * API Endpoint for searching regions
     *
     * @var string
     */
    // Update to match example: 'https://b2b-api-stg.fastrac.id/apiRegion/search-region'
    private $api_search_region_endpoint = 'https://b2b-api-stg.fastrac.id/apiRegion/search-region';
    
    /**
     * API Endpoint for tariff calculation
     *
     * @var string
     */
    private $api_tariff_endpoint = 'https://b2b-api-stg.fastrac.id/apiTariff/tariffExpress';

    /**
     * Logger instance
     *
     * @var Fastrac_Shipping_Logger
     */
    private $logger;

    /**
     * Constructor for your shipping class
     *
     * @access public
     * @return void
     */
    public function __construct($instance_id = 0) {
        $this->id                 = 'fastrac_shipping';
        $this->instance_id        = absint($instance_id);
        $this->method_title       = __('Fastrac Shipping', 'fastrac-shipping');
        $this->method_description = __('Shipping rates calculated using Fastrac Shipping API', 'fastrac-shipping');
        $this->supports           = array(
            'shipping-zones',
            'instance-settings',
            'instance-settings-modal',
        );

        $this->init();
    }

    /**
     * Initialize the shipping method
     *
     * @access private
     * @return void
     */
    private function init() {
        // Create directory for assets if it doesn't exist
        $img_dir = plugin_dir_path(dirname(__FILE__)) . 'assets/img';
        if (!file_exists($img_dir)) {
            wp_mkdir_p($img_dir);
        }
        
        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Always enable logger for troubleshooting the "no shipping methods" issue
        $this->logger = new Fastrac_Shipping_Logger(true);
        $this->logger->log('Fastrac Shipping Method initializing', 'info');

        // Define user set variables
        $this->title           = $this->get_option('title');
        $this->enabled         = $this->get_option('enabled');
        $this->access_key      = $this->get_option('access_key');
        $this->secret_key      = $this->get_option('secret_key');
        $this->debug           = true; // Force debug mode to ON for troubleshooting
        
        // Get origin ID from store postcode
        $this->origin_id       = $this->get_store_origin_id();
        if (!$this->origin_id) {
            $this->logger->log_error("Could not initialize origin ID from store settings");
            $this->enabled = 'no'; // Disable shipping method if origin ID cannot be determined
        }
        
        $this->logger->log('Fastrac Shipping Method initialized', 'info');
        
        // Log initialization details
        $this->logger->log('Fastrac Shipping: Method class initialized with debug ON', 'debug');
        $this->logger->log('Fastrac Shipping: Access key set: ' . (!empty($this->access_key) ? 'YES' : 'NO'), 'debug');
        $this->logger->log('Fastrac Shipping: Secret key set: ' . (!empty($this->secret_key) ? 'YES' : 'NO'), 'debug');
        $this->logger->log('Fastrac Shipping: Origin ID set: ' . (!empty($this->origin_id) ? $this->origin_id : 'NO'), 'debug');

        // Actions
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'verify_saved_settings'));
        
        // Hook for updating shipping during checkout
        add_action('woocommerce_checkout_update_order_review', array($this, 'update_shipping_on_checkout_update'));
        
        // Add AJAX action for postcode validation
        add_action('wp_ajax_fastrac_validate_postcode', array($this, 'ajax_validate_postcode'));
        add_action('wp_ajax_nopriv_fastrac_validate_postcode', array($this, 'ajax_validate_postcode'));
        
        // Enqueue scripts for checkout with high priority to ensure proper execution
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'), 99);
        
        // Add another hook for the footer to verify script loading
        add_action('wp_footer', array($this, 'verify_script_loading'), 99);
        
        // Save the calculated rates
        add_action('woocommerce_cart_calculate_fees', array($this, 'maybe_save_destination_id'));
        
        // Add customer notices
        add_action('woocommerce_before_checkout_form', array($this, 'show_checkout_notices'), 10);
        add_action('woocommerce_before_cart', array($this, 'show_checkout_notices'), 10);
        
        // Debug display for admins
        if (current_user_can('manage_options')) {
            add_action('admin_notices', array($this, 'show_admin_debug_notice'));
        }
    }

    /**
     * Enqueue scripts and styles for checkout page
     *
     * @access public
     * @return void
     */
    public function enqueue_checkout_scripts() {
        // Only load on checkout page
        if (!is_checkout()) {
            return;
        }

        // Create necessary directories if they don't exist
        $js_dir = plugin_dir_path(dirname(__FILE__)) . 'assets/js';
        $css_dir = plugin_dir_path(dirname(__FILE__)) . 'assets/css';
        
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
            $this->logger->log("Created JS directory: " . $js_dir, 'info');
        }
        
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
            $this->logger->log("Created CSS directory: " . $css_dir, 'info');
        }

        // Log script enqueueing
        $this->logger->log("Attempting to enqueue checkout scripts", 'info');
        $this->logger->log('Attempting to enqueue checkout scripts', 'debug');
        
        // Get script path and check if file exists
        $script_path = plugin_dir_path(dirname(__FILE__)) . 'assets/js/checkout.js';
        $script_url = plugins_url('assets/js/checkout.js', dirname(__FILE__));
        
        $this->logger->log("Script path: " . $script_path, 'info');
        $this->logger->log("Script URL: " . $script_url, 'info');
        
        if (file_exists($script_path)) {
            $this->logger->log("Script file exists at: " . $script_path, 'info');
            $this->logger->log('Script file exists at: ' . $script_path, 'debug');
        } else {
            $this->logger->log_error("Script file DOES NOT exist at: " . $script_path);
            $this->logger->log_error('Script file DOES NOT exist at: ' . $script_path);
        }
        
        // Enqueue JavaScript with version timestamp to prevent caching
        $version = defined('FASTRAC_SHIPPING_VERSION') ? FASTRAC_SHIPPING_VERSION : '1.0.0';
        $version .= '.' . time(); // Add timestamp to force reload
        
        wp_enqueue_script(
            'fastrac-shipping-checkout',
            $script_url,
            array('jquery', 'wc-checkout'),
            $version,
            true
        );

        // Localize script with more helpful data
        wp_localize_script(
            'fastrac-shipping-checkout',
            'fastracShipping',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fastrac-shipping-nonce'),
                'debug_mode' => 'yes', // Force debug mode on
                'plugin_url' => plugins_url('', dirname(__FILE__)),
                'current_page' => is_checkout() ? 'checkout' : 'not_checkout',
                'i18n' => array(
                    'enter_postcode' => __('Please enter your postcode to see shipping rates.', 'fastrac-shipping'),
                    'updating' => __('Updating shipping rates...', 'fastrac-shipping'),
                    'postcode_error' => __('Unable to find shipping rates for this postcode.', 'fastrac-shipping')
                )
            )
        );
        
        $this->logger->log("Scripts enqueued and localized", 'info');
        $this->logger->log('Scripts enqueued and localized', 'debug');

        // Enqueue CSS
        wp_enqueue_style(
            'fastrac-shipping-style',
            plugins_url('assets/css/fastrac-shipping.css', dirname(__FILE__)),
            array(),
            defined('FASTRAC_SHIPPING_VERSION') ? FASTRAC_SHIPPING_VERSION : '1.0.0'
        );
        
        // Log that scripts were enqueued
        $this->logger->log("Fastrac shipping scripts enqueued for checkout", 'info');
    }
    
    /**
     * Verify script loading in footer
     */
    public function verify_script_loading() {
        if (!is_checkout()) {
            return;
        }
        
        // Check if our script was enqueued
        $script_enqueued = wp_script_is('fastrac-shipping-checkout', 'enqueued');
        $script_done = wp_script_is('fastrac-shipping-checkout', 'done');
        
        $this->logger->log('Script enqueued status: ' . ($script_enqueued ? 'YES' : 'NO'), 'debug');
        $this->logger->log('Script done status: ' . ($script_done ? 'YES' : 'NO'), 'debug');
        
        // Add debug info to page as HTML comments
        echo "<!-- Fastrac Shipping Debug: Script enqueued: " . ($script_enqueued ? 'YES' : 'NO') . " -->\n";
        echo "<!-- Fastrac Shipping Debug: Script done: " . ($script_done ? 'YES' : 'NO') . " -->\n";
        
        // Log jQuery status to server logs rather than client console
        $this->logger->log('Footer verification running - checking for jQuery', 'debug');
    }

    /**
     * Initialize form fields
     *
     * @access public
     * @return void
     */
    public function init_form_fields() {
        $this->instance_form_fields = array(
            'title' => array(
                'title'       => __('Method Title', 'fastrac-shipping'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'fastrac-shipping'),
                'default'     => __('Fastrac Shipping', 'fastrac-shipping'),
                'desc_tip'    => true,
            ),
            'enabled' => array(
                'title'   => __('Enable', 'fastrac-shipping'),
                'type'    => 'checkbox',
                'label'   => __('Enable this shipping method', 'fastrac-shipping'),
                'default' => 'yes',
            ),
            'access_key' => array(
                'title'       => __('API Access Key', 'fastrac-shipping'),
                'type'        => 'text',
                'description' => __('Enter your Fastrac API access key.', 'fastrac-shipping'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'secret_key' => array(
                'title'       => __('API Secret Key', 'fastrac-shipping'),
                'type'        => 'password',
                'description' => __('Enter your Fastrac API secret key.', 'fastrac-shipping'),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug Mode', 'fastrac-shipping'),
                'type'        => 'checkbox',
                'label'       => __('Enable debug mode', 'fastrac-shipping'),
                'description' => __('Enable debug logging for this shipping method. Logs will be stored in WooCommerce > Status > Logs.', 'fastrac-shipping'),
                'default'     => 'no',
            ),
        );
    }

    /**
     * Calculate shipping rates
     *
     * @access public
     * @param array $package Package information
     * @return void
     */
    public function calculate_shipping($package = array()) {
        $this->logger->log("\n\n============================================", 'info');
        $this->logger->log("===== STARTING SHIPPING CALCULATION =====", 'info');
        $this->logger->log("============================================", 'info');
        $this->logger->log("Package details: " . print_r($package, true), 'debug');
        
        // Log shipping calculation details
        $this->logger->log('Calculate shipping method called', 'debug');
        $this->logger->log('Package destination - ' . json_encode($package['destination']), 'debug');
        
        // Log current settings
        $settings_info = array(
            'Access Key Set' => !empty($this->access_key),
            'Secret Key Set' => !empty($this->secret_key),
            'Origin ID' => $this->origin_id,
            'Enabled' => $this->enabled,
            'Debug Mode' => $this->debug
        );
        $this->logger->log("Current settings: " . print_r($settings_info, true), 'info');
        
        // Check if the Shipping Core class exists, if not include it
        if (!class_exists('Fastrac_Shipping_Core')) {
            require_once dirname(__FILE__) . '/class-fastrac-shipping-core.php';
        }
        
        // STEP 1: API CREDENTIALS CHECK
        $this->logger->log("------ STEP 1: API CREDENTIALS CHECK ------", 'info');
        
        if (empty($this->access_key)) {
            $this->logger->log_error("Missing required setting: access_key");
            $this->add_error_notice('configuration', __('Shipping configuration is incomplete: Access Key missing. Please contact the store administrator.', 'fastrac-shipping'));
            return;
        }
        
        if (empty($this->secret_key)) {
            $this->logger->log_error("Missing required setting: secret_key");
            $this->add_error_notice('configuration', __('Shipping configuration is incomplete: Secret Key missing. Please contact the store administrator.', 'fastrac-shipping'));
            return;
        }
        
        if (empty($this->origin_id)) {
            $this->logger->log_error("Missing required setting: origin_id");
            $this->add_error_notice('configuration', __('Shipping configuration is incomplete: Origin ID missing. Please contact the store administrator.', 'fastrac-shipping'));
            return;
        }
        
        $this->logger->log("API credentials check passed", 'info');

        // STEP 2: POSTCODE VALIDATION
        $this->logger->log("------ STEP 2: POSTCODE VALIDATION ------", 'info');
        
        // Get destination postcode
        $destination_postcode = $package['destination']['postcode'];
        if (empty($destination_postcode)) {
            $this->logger->log_error("Missing destination postcode in package");
            $this->add_error_notice('postcode', __('Please enter a valid postcode to calculate shipping.', 'fastrac-shipping'));
            return;
        }
        
        // Basic validation for postcode
        $postcode = trim($destination_postcode);
        if (empty($postcode)) {
            $this->logger->log_error("Empty postcode provided");
            $this->add_error_notice('postcode', __('Please enter a postcode to calculate shipping.', 'fastrac-shipping'));
            return;
        }
        
        // Remove non-numeric validation to support all formats
        // The example uses postcode 10110 which is numeric, but we should support all formats
        
        $this->logger->log("Destination postcode is valid: " . $destination_postcode, 'info');
        
        // STEP 3: GET DESTINATION ID
        $this->logger->log("------ STEP 3: GET DESTINATION ID ------", 'info');
        
        // Get destination ID
        $destination_id = $this->get_destination_id($destination_postcode);
        if (empty($destination_id)) {
            $this->logger->log_error("Could not get destination ID for postcode: " . $destination_postcode);
            $this->add_error_notice('postcode', sprintf(__('We could not find a shipping location for postcode %s. Please verify your postcode.', 'fastrac-shipping'), $destination_postcode));
            return;
        }
        
        $this->logger->log("Destination ID found: " . $destination_id, 'info');
        
        // Store origin and destination for reference and debugging
        if (WC()->session) {
            WC()->session->set('fastrac_origin_id', $this->origin_id);
            WC()->session->set('fastrac_destination_id', $destination_id);
            
            $shipping_info = sprintf(
                __('DEBUG: Shipping from origin ID %s to destination ID %s', 'fastrac-shipping'),
                $this->origin_id,
                $destination_id
            );
            WC()->session->set('fastrac_shipping_info', $shipping_info);
        }
        
        // Get product dimensions and weight from cart
        $dimensions = $this->get_cart_dimensions($package);
        $dimensions_valid = $this->validate_dimensions($dimensions);
        
        if (!$dimensions) {
            $this->logger->log_error("Could not calculate package dimensions");
            $this->add_error_notice('dimensions', __('Some products in your cart are missing dimensions or weight. Please contact the store administrator.', 'fastrac-shipping'));
            // Continue anyway with default dimensions
            $dimensions = array(
                'weight' => 1, // 1 kg default
                'length' => 10, // 10 cm default
                'width'  => 10, // 10 cm default
                'height' => 10, // 10 cm default
            );
            $this->logger->log("Using default dimensions: " . print_r($dimensions, true));
        } elseif (!$dimensions_valid) {
            $this->logger->log_error("Package dimensions validation failed");
            $this->add_error_notice('dimensions', __('Some products in your cart have invalid dimensions or weight. Please contact the store administrator.', 'fastrac-shipping'));
            // Continue with corrected dimensions
            $dimensions = array(
                'weight' => max(1, $dimensions['weight']), // At least 1 kg
                'length' => max(10, $dimensions['length']), // At least 10 cm
                'width'  => max(10, $dimensions['width']),  // At least 10 cm
                'height' => max(10, $dimensions['height']), // At least 10 cm
            );
            $this->logger->log("Using corrected dimensions: " . print_r($dimensions, true));
        } else {
            $this->logger->log("Package dimensions: " . print_r($dimensions, true));
        }
        
        // Store package dimensions for reference
        if (WC()->session) {
            WC()->session->set('fastrac_package_dimensions', $dimensions);
        }
        
        // STEP 4: CALCULATE SHIPPING RATES
        $this->logger->log("------ STEP 4: CALCULATE SHIPPING RATES ------", 'info');
        $this->logger->log("At this point, we have origin_id: " . $this->origin_id . " and destination_id: " . $destination_id, 'info');
        
        // Initialize core shipping calculator for simplified integration
        $shipping_core = new Fastrac_Shipping_Core(
            $this->access_key,
            $this->secret_key,
            $this->origin_id,
            $this->debug
        );
        
        // Get shipping rates using the core implementation
        $shipping_rates = $shipping_core->calculate_shipping_rates(
            $dimensions['weight'],
            $dimensions['length'],
            $dimensions['width'],
            $dimensions['height'],
            $destination_id
        );
        
        $rates_added = false;
        
        if ($shipping_rates && is_array($shipping_rates)) {
            $this->logger->log("Found " . count($shipping_rates) . " shipping rates", 'info');
            
            // Sort rates by price (lowest first)
            usort($shipping_rates, function($a, $b) {
                return $a['cost'] <=> $b['cost'];
            });
            
            foreach ($shipping_rates as $rate) {
                $this->logger->log("Adding rate: " . $rate['label'] . " - " . $rate['cost'], 'info');
                $this->add_rate($rate);
                $rates_added = true;
            }
        } else {
            $this->logger->log_error("No shipping rates returned from core calculator");
            
            // Fall back to legacy calculation method
            $this->logger->log("Falling back to legacy calculation method", 'info');
            $rates_added = $this->calculate_tariff($dimensions, $this->origin_id, $destination_id);
        }
        
        // STEP 5: PROCESS RESULT
        $this->logger->log("------ STEP 5: PROCESS RESULT ------", 'info');
        
        if (!$rates_added) {
            // Fallback to dummy rate if the API call failed or returned no rates
            $this->logger->log("API call failed or returned no rates, using fallback rate");
            
            $fallback_rate = array(
                'id'    => $this->id . '_fallback',
                'label' => $this->title . ' ' . __('(Fallback Rate)', 'fastrac-shipping'),
                'cost'  => 10000, // Fixed cost for now
                'calc_tax' => 'per_item'
            );
            
            $this->logger->log("Using fallback shipping rate: 10000");
            
            // Register the fallback rate
            $this->add_rate($fallback_rate);
        }
        
        $this->logger->log_shipping_calculation($package, $rate);
        $this->logger->log("Shipping calculation completed successfully");
    }

    /**
     * Get dimensions and weight of items in cart
     *
     * @access private
     * @param array $package Package of items
     * @return array|bool Array of dimensions or false on failure
     */
    private function get_cart_dimensions($package) {
        if (empty($package['contents'])) {
            return false;
        }
        
        $weight = 0;
        $length = 0;
        $width = 0;
        $height = 0;
        $missing_dimensions = false;
        
        foreach ($package['contents'] as $item) {
            if (empty($item['data'])) {
                continue;
            }
            
            $product = $item['data'];
            $qty = $item['quantity'];
            $product_name = $product->get_name();
            
            // Add product weight
            $item_weight = (float) $product->get_weight();
            if ($item_weight <= 0) {
                $this->logger->log_error("Product '{$product_name}' is missing weight");
                $missing_dimensions = true;
            } else {
                $weight += $item_weight * $qty;
            }
            
            // Get product dimensions
            $item_length = (float) $product->get_length();
            $item_width = (float) $product->get_width();
            $item_height = (float) $product->get_height();
            
            if ($item_length <= 0 || $item_width <= 0 || $item_height <= 0) {
                $this->logger->log_error("Product '{$product_name}' is missing one or more dimensions");
                $missing_dimensions = true;
            }
            
            // Use the largest dimensions
            if ($item_length > $length) {
                $length = $item_length;
            }
            if ($item_width > $width) {
                $width = $item_width;
            }
            if ($item_height > $height) {
                $height = $item_height;
            }
        }
        
        // If we're missing dimensions but continue anyway
        if ($missing_dimensions) {
            $this->logger->log_error("Some products are missing dimensions or weight");
            // We'll continue with whatever dimensions we have
        }
        
        return array(
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height
        );
    }
    
    /**
     * Validate package dimensions against minimum and maximum values
     *
     * @access private
     * @param array $dimensions Package dimensions
     * @return bool True if dimensions are valid
     */
    private function validate_dimensions($dimensions) {
        if (!is_array($dimensions)) {
            return false;
        }
        
        // Check if all required dimensions are present
        if (!isset($dimensions['weight']) || !isset($dimensions['length']) || 
            !isset($dimensions['width']) || !isset($dimensions['height'])) {
            $this->logger->log_error("Missing required dimensions in package");
            return false;
        }
        
        // Validate minimum values
        if ($dimensions['weight'] <= 0) {
            $this->logger->log_error("Invalid package weight: " . $dimensions['weight']);
            return false;
        }
        
        if ($dimensions['length'] <= 0) {
            $this->logger->log_error("Invalid package length: " . $dimensions['length']);
            return false;
        }
        
        if ($dimensions['width'] <= 0) {
            $this->logger->log_error("Invalid package width: " . $dimensions['width']);
            return false;
        }
        
        if ($dimensions['height'] <= 0) {
            $this->logger->log_error("Invalid package height: " . $dimensions['height']);
            return false;
        }
        
        // Maximum values (these would need to be adjusted based on Fastrac's actual limits)
        $max_weight = 100; // 100 kg
        $max_dimension = 300; // 300 cm
        
        if ($dimensions['weight'] > $max_weight) {
            $this->logger->log_error("Package weight exceeds maximum: " . $dimensions['weight']);
            return false;
        }
        
        if ($dimensions['length'] > $max_dimension || 
            $dimensions['width'] > $max_dimension || 
            $dimensions['height'] > $max_dimension) {
            $this->logger->log_error("Package dimensions exceed maximum: L:" . 
                $dimensions['length'] . " W:" . $dimensions['width'] . " H:" . $dimensions['height']);
            return false;
        }
        
        return true;
    }

    /**
     * Update shipping when checkout form is updated
     *
     * @access public
     * @param string $posted_data Form data
     * @return void
     */
    public function update_shipping_on_checkout_update($posted_data) {
        if (empty($posted_data)) {
            $this->logger->log("Empty posted data in checkout update, skipping", 'debug');
            return;
        }

        $this->logger->log("\n\n==================================================", 'info');
        $this->logger->log("===== CHECKOUT FORM UPDATE DETECTED =====", 'info');
        $this->logger->log("==================================================", 'info');

        parse_str($posted_data, $data);
        
        // Capture full address from form data
        $address = array();
        
        // Shipping address fields if available
        if (!empty($data['ship_to_different_address'])) {
            $address['first_name'] = !empty($data['shipping_first_name']) ? $data['shipping_first_name'] : '';
            $address['last_name'] = !empty($data['shipping_last_name']) ? $data['shipping_last_name'] : '';
            $address['company'] = !empty($data['shipping_company']) ? $data['shipping_company'] : '';
            $address['address_1'] = !empty($data['shipping_address_1']) ? $data['shipping_address_1'] : '';
            $address['address_2'] = !empty($data['shipping_address_2']) ? $data['shipping_address_2'] : '';
            $address['city'] = !empty($data['shipping_city']) ? $data['shipping_city'] : '';
            $address['state'] = !empty($data['shipping_state']) ? $data['shipping_state'] : '';
            $address['postcode'] = !empty($data['shipping_postcode']) ? $data['shipping_postcode'] : '';
            $address['country'] = !empty($data['shipping_country']) ? $data['shipping_country'] : '';
            
            // Log which address type we're using
            $this->logger->log("Using shipping address from checkout form", 'info');
        } else {
            // Billing address as shipping address
            $address['first_name'] = !empty($data['billing_first_name']) ? $data['billing_first_name'] : '';
            $address['last_name'] = !empty($data['billing_last_name']) ? $data['billing_last_name'] : '';
            $address['company'] = !empty($data['billing_company']) ? $data['billing_company'] : '';
            $address['address_1'] = !empty($data['billing_address_1']) ? $data['billing_address_1'] : '';
            $address['address_2'] = !empty($data['billing_address_2']) ? $data['billing_address_2'] : '';
            $address['city'] = !empty($data['billing_city']) ? $data['billing_city'] : '';
            $address['state'] = !empty($data['billing_state']) ? $data['billing_state'] : '';
            $address['postcode'] = !empty($data['billing_postcode']) ? $data['billing_postcode'] : '';
            $address['country'] = !empty($data['billing_country']) ? $data['billing_country'] : '';
            
            // Log which address type we're using
            $this->logger->log("Using billing address as shipping address", 'info');
        }
        
        // Check if we have a postcode
        if (empty($address['postcode'])) {
            $this->logger->log_error("No postcode found in checkout data - shipping rates cannot be calculated");
            if (WC()->session) {
                // Save error information to display to customer
                WC()->session->set('fastrac_postcode_missing', true);
                WC()->session->set('fastrac_checkout_error', __('Please enter a postcode/ZIP to calculate shipping rates.', 'fastrac-shipping'));
            }
            return;
        }
        
        // Validate the postcode format (basic validation)
        $postcode = trim($address['postcode']);
        if (empty($postcode)) {
            $this->logger->log_error("Empty postcode after trimming whitespace");
            if (WC()->session) {
                WC()->session->set('fastrac_postcode_error', __('Please enter a valid postcode/ZIP.', 'fastrac-shipping'));
            }
            return;
        }
        
        // Log the postcode that was entered
        $this->logger->log("Postcode entered in checkout: " . $postcode, 'info');
        
        // Check if postcode has changed since last calculation
        $previous_postcode = WC()->session ? WC()->session->get('fastrac_last_postcode', '') : '';
        $postcode_changed = ($previous_postcode !== $postcode);
        
        if ($postcode_changed) {
            $this->logger->log("Postcode changed from '{$previous_postcode}' to '{$postcode}' - recalculating rates", 'info');
            
            // Store current postcode for future change detection
            if (WC()->session) {
                WC()->session->set('fastrac_last_postcode', $postcode);
                
                // Clear any previous errors
                WC()->session->set('fastrac_postcode_missing', false);
                WC()->session->set('fastrac_postcode_error', '');
                WC()->session->set('fastrac_checkout_error', '');
            }
        } else {
            $this->logger->log("Postcode unchanged ({$postcode}) - checking if update is needed", 'info');
        }
        
        // Store full address in session for reference
        if (WC()->session) {
            WC()->session->set('fastrac_shipping_address', $address);
            $this->logger->log("Address captured from checkout: " . print_r($address, true));
            
            // Get destination ID from postcode
            $destination_id = $this->get_destination_id($postcode);
            if ($destination_id) {
                $this->logger->log("Destination ID set for checkout: " . $destination_id);
                
                // Save timestamp for rate calculation
                WC()->session->set('fastrac_rate_calculation_time', current_time('timestamp'));
            } else {
                $this->logger->log_error("Failed to get destination ID for postcode: " . $postcode);
                // This will be handled by the get_destination_id method for error display
            }
        }

        // Always trigger shipping recalculation to ensure rates are updated
        $this->logger->log("Triggering shipping recalculation for checkout", 'info');
        WC()->cart->calculate_shipping();
    }

    /**
     * Save destination ID during cart calculation
     *
     * @access public
     * @return void
     */
    public function maybe_save_destination_id() {
        // Get customer postcode
        $customer = WC()->customer;
        if (!$customer) {
            return;
        }
        
        $postcode = $customer->get_shipping_postcode();
        if (empty($postcode)) {
            $postcode = $customer->get_billing_postcode();
        }
        
        if (!empty($postcode)) {
            $this->get_destination_id($postcode);
        }
    }

    /**
     * Get destination ID from postcode using API
     *
     * @access public
     * @param string $postcode Destination postcode
     * @return string|bool Destination ID or false on failure
     */
    /**
     * Get region ID from postcode using Fastrac API
     *
     * @param string $postcode The postcode to search for
     * @param string $access_key The API access key
     * @param string $secret_key The API secret key
     * @return int|false The region ID if found, false otherwise
     */
    public function get_region_id_from_postcode($postcode, $access_key = null, $secret_key = null) {
        // Use provided credentials or fall back to class properties
        $access_key = $access_key ?: $this->access_key;
        $secret_key = $secret_key ?: $this->secret_key;
        
        // Validate inputs
        $postcode = trim($postcode);
        if (empty($postcode)) {
            $this->logger->log_error("Empty postcode");
            return false;
        }
        
        if (empty($access_key) || empty($secret_key)) {
            $this->logger->log_error("Missing API credentials");
            return false;
        }
        
        $this->logger->log("Searching for region ID with postcode: " . $postcode, 'info');
        
        // API endpoint for searching regions
        $url = 'https://b2b-api-stg.fastrac.id/apiRegion/search-region';
        
        // Prepare request body with postcode
        $request_body = array(
            'search' => $postcode,
            'limit'  => 10,
            'offset' => 0
        );
        
        // Prepare request arguments
        $args = array(
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => array(
                'access-key' => $access_key,
                'secret-key' => $secret_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'sslverify' => false
        );
        
        $this->logger->log("Making POST API request to: " . $url, 'info');
        $this->logger->log("Request body: " . json_encode($request_body), 'debug');
        
        // Make the API request
        $response = wp_remote_post($url, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_error("API request error: " . $error_message);
            return false;
        }
        
        // Get response details
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        // Check HTTP status code
        if ($status_code < 200 || $status_code >= 300) {
            $this->logger->log_error("HTTP error: Status code " . $status_code);
            return false;
        }
        
        // Parse the response
        $data = json_decode($body, true);
        
        // Check for JSON parsing errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->log_error("JSON parsing error: " . json_last_error_msg());
            return false;
        }
        
        // Check for API success
        if (!isset($data['success']) || $data['success'] !== true) {
            $error = isset($data['message']) ? $data['message'] : 'Unknown API error';
            $this->logger->log_error("API error: " . $error);
            return false;
        }
        
        // Check if we have results
        if (empty($data['data'])) {
            $this->logger->log_error("No regions found for postcode: " . $postcode);
            return false;
        }
        
        $this->logger->log("Found " . count($data['data']) . " regions for postcode: " . $postcode, 'info');
        
        // Look for exact match on postcode
        foreach ($data['data'] as $region) {
            if (isset($region['id']) && isset($region['name'])) {
                $this->logger->log("Found region: ID=" . $region['id'] . ", Name=" . $region['name'], 'info');
                
                // Log region lookup details to server logs
                $this->logger->log("REGION ID LOOKUP RESULT - Postcode: " . $postcode . ", Region ID: " . $region['id'] . ", Region Name: " . $region['name'] . ", Method: API Direct Match", 'info');
                
                return $region['id'];
            }
        }
        
        // If no region with 'id' field was found, return the first result's ID if available
        if (isset($data['data'][0]['id'])) {
            $this->logger->log("Using first available region ID: " . $data['data'][0]['id'], 'info');
            
            // Log fallback region lookup to server logs
            $this->logger->log("REGION ID FALLBACK - Postcode: {$postcode}, Region ID: {$data['data'][0]['id']}, Method: API Fallback (First Result)", 'warning');
            
            return $data['data'][0]['id'];
        }
        
        // Log region lookup failure to server logs
        $this->logger->log_error("REGION ID LOOKUP FAILED - Postcode: {$postcode}, Error: No valid region ID found in API response, Method: API Direct");
        
        $this->logger->log_error("No valid region ID found in response");
        return false;
    }

    public function get_destination_id($postcode) {
        // Basic validation
        $postcode = trim($postcode);
        if (empty($postcode)) {
            $this->logger->log_error("Empty postcode");
            return false;
        }
        
        // Allow non-numeric postcodes for international support
        if (!is_numeric($postcode)) {
            $this->logger->log("Non-numeric postcode format: " . $postcode, 'debug');
            // Continue processing as some countries may have alphanumeric postcodes
        }

        // Check if we already have the destination ID for this postcode in session
        $session_key = 'fastrac_destination_id_' . $postcode;
        $destination_id = null;
        
        if (WC()->session) {
            $destination_id = WC()->session->get($session_key);
        }
        
        if (!empty($destination_id)) {
            $this->logger->log("Using cached destination ID for postcode: " . $postcode . ", ID: " . $destination_id);
            return $destination_id;
        }
        
        $this->logger->log("No cached destination ID found for postcode: " . $postcode . ", calling API");
        
        // Try the new function first, if it fails fall back to the original method
        $destination_id = $this->get_region_id_from_postcode($postcode);
        if (!$destination_id) {
            $this->logger->log("New method failed, falling back to original method", 'info');
            $destination_id = $this->search_region_by_postcode($postcode);
        }
        
        if ($destination_id) {
            // Save in session for future use
            if (WC()->session) {
                WC()->session->set($session_key, $destination_id);
                
                // Save a debug message for verification
                $debug_message = sprintf(
                    __('DEBUG: Successfully found destination ID %s for postcode %s', 'fastrac-shipping'),
                    $destination_id,
                    $postcode
                );
                WC()->session->set('fastrac_debug_message', $debug_message);
            }
            $this->logger->log("New destination ID saved for postcode: " . $postcode . ", ID: " . $destination_id);
            return $destination_id;
        }
        
        $this->logger->log_error("Could not find destination ID for postcode: " . $postcode);
        return false;
    }

    /**
     * Search region by postcode using Fastrac API
     *
     * @access private
     * @param string $postcode Postcode to search
     * @return string|bool Subdistrict ID or false on failure
     */
    private function search_region_by_postcode($postcode) {
        $this->logger->log("\n\n==================================================", 'info');
        $this->logger->log("===== STARTING REGION SEARCH FOR POSTCODE: " . $postcode . " =====", 'info');
        $this->logger->log("==================================================", 'info');
        
        if (empty($this->access_key) || empty($this->secret_key)) {
            $this->logger->log_error("CRITICAL ERROR: Missing API credentials");
            if (WC()->session) {
                WC()->session->set('fastrac_critical_error', "Missing API credentials. Please configure the shipping method in WooCommerce settings.");
            }
            return false;
        }
        
        $this->logger->log("Searching region by postcode: " . $postcode, 'info');
        
        // Use the API endpoint for search-region
        $url = 'https://b2b-api-stg.fastrac.id/apiRegion/search-region';
        
        $this->logger->log("Using API endpoint: " . $url, 'info');
        
        // Save debug info for checkout display with timestamp to show recency
        if (WC()->session) {
            $timestamp = current_time('Y-m-d H:i:s');
            WC()->session->set('fastrac_region_search', "Searching for regions with postcode: " . $postcode . " (at " . $timestamp . ")");
        }
        
        // Log additional debug information to help identify the issue
        $this->logger->log("Making API request to: " . $url, 'info');
        $this->logger->log("Postcode being searched: " . $postcode, 'info');
        
        // Use POST request format with JSON body
        $this->logger->log("----- Using POST request for region search -----", 'info');
        
        // Prepare request arguments
        $args = array(
            'method'  => 'POST',   // Use POST method
            'timeout' => 45,       // Increased timeout for potentially slow API
            'headers' => array(
                'access-key' => $this->access_key,  // Required API key
                'secret-key' => $this->secret_key,  // Required API secret
                'Content-Type' => 'application/json' // Specify JSON content type
            ),
            'body' => json_encode(array(
                'search' => $postcode,
                'limit'  => 10,
                'offset' => 0
            )),
            'sslverify' => false   // Sometimes needed for local development
        );
        
        // Log the request details
        $this->logger->log("API URL: " . $url, 'info');
        $this->logger->log("POST Body: " . json_encode(array(
            'search' => $postcode,
            'limit'  => 10,
            'offset' => 0
        )), 'info');
        
        // Log API credentials (partial for security)
        $this->logger->log("API Key (first 4 chars): " . substr($this->access_key, 0, 4) . "...", 'info');
        $this->logger->log("Secret Key (first 4 chars): " . substr($this->secret_key, 0, 4) . "...", 'info');
        
        // SIMPLIFIED: Just print the full request for debugging
        $this->logger->log("Making API request with: ", 'info');
        $this->logger->log("URL: " . $url, 'info');
        $this->logger->log("Method: POST", 'info');
        $this->logger->log("Headers: ", 'info');
        $this->logger->log("  access-key: " . substr($this->access_key, 0, 4) . '****', 'info');
        $this->logger->log("  secret-key: " . substr($this->secret_key, 0, 4) . '****', 'info');
        $this->logger->log("  Content-Type: application/json", 'info');
        $this->logger->log("Body: Search for '" . $postcode . "' with limit 10, offset 0", 'info');
        
        // Store debug information
        if (WC()->session) {
            $request_info = array(
                'URL' => $url,
                'Method' => 'POST',
                'Postcode' => $postcode,
                'Content-Type' => 'application/json',
                'Timestamp' => $timestamp
            );
            WC()->session->set('fastrac_api_request_info', $request_info);
        }
        
        // Make the API request using wp_remote_post
        $this->logger->log("Making POST request to: " . $url, 'info');
        $this->logger->log("Request headers: access-key=" . substr($this->access_key, 0, 4) . "..., secret-key=" . substr($this->secret_key, 0, 4) . "...", 'info');
        
        // For the specific test postcode, use a separate code path with enhanced logging
        if ($postcode == '10110') {
            $this->logger->log("SPECIAL HANDLING: Using test postcode 10110 with enhanced logging", 'info');
            
            // Create and execute a direct cURL request for test postcode
            if (function_exists('curl_init')) {
                $this->logger->log("Using direct cURL for test postcode", 'info');
                
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
                    'search' => $postcode,
                    'limit'  => 10,
                    'offset' => 0
                )));
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'access-key: ' . $this->access_key,
                    'secret-key: ' . $this->secret_key,
                    'Content-Type: application/json'
                ));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 45);
                
                $body = curl_exec($ch);
                $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                if ($curl_error) {
                    $this->logger->log_error("cURL error: " . $curl_error);
                    $response = new WP_Error('http_request_failed', $curl_error);
                } else {
                    $response = array(
                        'body' => $body,
                        'response' => array('code' => $status_code)
                    );
                    $this->logger->log("Direct cURL response for 10110: " . substr($body, 0, 500) . "...", 'info');
                }
            } else {
                // Fall back to wp_remote_post
                $this->logger->log("cURL not available, falling back to wp_remote_post", 'info');
                $response = wp_remote_post($url, $args);
            }
        } else {
            // Standard processing for all other postcodes
            $response = wp_remote_post($url, $args);
        }
        
        // Check for errors in the request
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $error_data = $response->get_error_data();
            $this->logger->log_error("API request error: " . $error);
            if ($error_data) {
                $this->logger->log_error("Error details: " . print_r($error_data, true));
            }
            
            // Save detailed error for checkout display
            if (WC()->session) {
                $error_info = "API Request Error: " . $error;
                if ($error_data) {
                    $error_info .= " (Details: " . json_encode($error_data) . ")";
                }
                WC()->session->set('fastrac_region_search_error', $error_info);
            }
            
            return false;
        }
        
        // Get response details
        $status_code = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $body = wp_remote_retrieve_body($response);
        
        // Log the response
        $this->logger->log("Response Status Code: " . $status_code, 'info');
        $this->logger->log("Response Headers: " . print_r($headers, true), 'debug');
        $this->logger->log("Raw Response Body: " . $body, 'debug');
        
        // Detailed logging for this specific postcode to help debug
        if ($postcode == '10110') {
            $this->logger->log("=================================================", 'info');
            $this->logger->log("SPECIAL DEBUG FOR POSTCODE 10110", 'info');
            $this->logger->log("URL: " . $url, 'info');
            $this->logger->log("Response Code: " . $status_code, 'info');
            $this->logger->log("Response Body: " . $body, 'info');
            $this->logger->log("=================================================", 'info');
        }
        
        // Add additional logging for debugging the exact response format
        $this->logger->log("-------------------------", 'info');
        $this->logger->log("RESPONSE ANALYSIS", 'info');
        $this->logger->log("-------------------------", 'info');
        $this->logger->log("HTTP Status: " . $status_code, 'info');
        
        // Check response body format
        if (empty($body)) {
            $this->logger->log("Empty response body!", 'error');
        } else {
            $first_char = substr($body, 0, 1);
            $this->logger->log("Response starts with: " . $first_char, 'info');
            
            if ($first_char == '{') {
                $this->logger->log("Response appears to be JSON", 'info');
            } else {
                $this->logger->log("Response may not be JSON! First 20 chars: " . substr($body, 0, 20), 'warning');
            }
        }
        
        // Store raw response for debugging
        if (WC()->session) {
            WC()->session->set('fastrac_raw_response', substr($body, 0, 1000) . (strlen($body) > 1000 ? '...' : ''));
        }
        
        // Check HTTP status code
        if ($status_code < 200 || $status_code >= 300) {
            $this->logger->log_error("HTTP error: Status code " . $status_code);
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error', "HTTP error: Status code " . $status_code);
            }
            return false;
        }
        
        // Process the response
        $response_data = json_decode($body, true);
        $json_error = json_last_error();
        
        // Check for JSON parsing errors
        if ($json_error !== JSON_ERROR_NONE) {
            $error_msg = "JSON parsing error: " . json_last_error_msg();
            $this->logger->log_error($error_msg);
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error', $error_msg);
            }
            return false;
        }
        
        // Log detailed response information
        $this->logger->log("Decoded response: " . print_r($response_data, true), 'debug');
        
        // Store response summary
        if (WC()->session) {
            $response_summary = array(
                'Status' => $status_code,
                'Success' => isset($response_data['success']) ? ($response_data['success'] ? 'Yes' : 'No') : 'Unknown',
                'Results' => isset($response_data['data']) ? count($response_data['data']) : 0,
                'Timestamp' => current_time('Y-m-d H:i:s')
            );
            WC()->session->set('fastrac_api_response_info', $response_summary);
        }
        
        // Check for API success
        if (!isset($response_data['success']) || $response_data['success'] !== true) {
            $error = isset($response_data['message']) ? $response_data['message'] : 'Unknown API error';
            $this->logger->log_error("API error: " . $error);
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error', "API error: " . $error);
            }
            return false;
        }
        
        // Check for data in response
        if (empty($response_data['data'])) {
            $this->logger->log_error("API returned success but no data");
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error', "No regions found for postcode: " . $postcode);
            }
            return false;
        }
        
        // Store all regions for debugging
        $regions_found = array();
        $all_regions_data = array();
        $post_code_found = false;
        $subdistrict_id_found = false;
        
        // Log all available fields in first region
        if (!empty($response_data['data'][0])) {
            $this->logger->log("First region fields: " . implode(", ", array_keys($response_data['data'][0])), 'info');
            if (WC()->session) {
                WC()->session->set('fastrac_available_fields', implode(", ", array_keys($response_data['data'][0])));
            }
        }
        
        // Process each region in the response
        foreach ($response_data['data'] as $index => $region) {
            $this->logger->log("Processing region #" . ($index + 1) . ": " . print_r($region, true), 'debug');
            
            // Extract region data for debugging
            $region_info = array();
            if (isset($region['post_code'])) {
                $region_info['post_code'] = $region['post_code'];
                $post_code_found = true;
            }
            if (isset($region['subdistrict_id'])) {
                $region_info['subdistrict_id'] = $region['subdistrict_id'];
                $subdistrict_id_found = true;
            }
            if (isset($region['subdistrict'])) $region_info['subdistrict'] = $region['subdistrict'];
            if (isset($region['district'])) $region_info['district'] = $region['district'];
            if (isset($region['city_name'])) $region_info['city_name'] = $region['city_name'];
            if (isset($region['province_name'])) $region_info['province_name'] = $region['province_name'];
            
            $regions_found[] = $region_info;
            
            // Create a summary for detailed debugging
            $region_summary = array('index' => $index + 1);
            foreach (array('subdistrict_id', 'post_code', 'province_id', 'province_name', 
                           'city_id', 'city_name', 'district_id', 'district', 'subdistrict') as $field) {
                if (isset($region[$field])) {
                    $region_summary[$field] = $region[$field];
                }
            }
            $all_regions_data[] = $region_summary;
        }
        
        // Store region data for debugging
        if (WC()->session) {
            WC()->session->set('fastrac_regions_found', $regions_found);
            WC()->session->set('fastrac_all_regions', $all_regions_data);
            if (!empty($response_data['data'][0])) {
                WC()->session->set('fastrac_first_region', $response_data['data'][0]);
            }
            WC()->session->set('fastrac_api_success_format', 'json');
        }
        
        // Log field presence
        $this->logger->log("Post code field found in response: " . ($post_code_found ? 'Yes' : 'No'), 'info');
        $this->logger->log("Subdistrict ID field found in response: " . ($subdistrict_id_found ? 'Yes' : 'No'), 'info');
        
        // STEP 1: Look for exact match on postcode (with string comparison)
        $this->logger->log("Looking for exact postcode match", 'info');
        foreach ($response_data['data'] as $region) {
            if (isset($region['subdistrict_id']) && isset($region['post_code'])) {
                $this->logger->log("Comparing - Input: " . $postcode . ", Region: " . $region['post_code'], 'info');
                
                // String comparison to ensure exact match
                if ((string)$region['post_code'] === (string)$postcode) {
                    $this->logger->log("SUCCESS! Found exact postcode match: " . print_r($region, true), 'info');
                    
                    // Save match info
                    if (WC()->session) {
                        $region_info = sprintf(
                            "Found region: %s, %s, %s (%s)",
                            isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            isset($region['district']) ? $region['district'] : 'Unknown',
                            isset($region['city_name']) ? $region['city_name'] : 'Unknown',
                            isset($region['province_name']) ? $region['province_name'] : 'Unknown'
                        );
                        WC()->session->set('fastrac_region_found', $region_info);
                        WC()->session->set('fastrac_match_type', 'Exact match');
                        
                        // ADDED: Debug found ID
                        $this->logger->log("SUCCESS: Found destination ID: " . $region['subdistrict_id'] . " for postcode: " . $postcode, 'info');
                    }
                    
                    return (string)$region['subdistrict_id'];
                }
            }
        }
        
        // STEP 2: If no exact match, try numeric comparison
        $this->logger->log("Trying numeric comparison", 'info');
        $numeric_postcode = (int)$postcode;
        foreach ($response_data['data'] as $region) {
            if (isset($region['subdistrict_id']) && isset($region['post_code'])) {
                $region_numeric_postcode = (int)$region['post_code'];
                $this->logger->log("Comparing numerically - Input: " . $numeric_postcode . ", Region: " . $region_numeric_postcode, 'info');
                
                if ($region_numeric_postcode === $numeric_postcode) {
                    $this->logger->log("SUCCESS! Found numeric match: " . print_r($region, true), 'info');
                    
                    // Save match info
                    if (WC()->session) {
                        $region_info = sprintf(
                            "Found region (numeric match): %s, %s, %s (%s)",
                            isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            isset($region['district']) ? $region['district'] : 'Unknown',
                            isset($region['city_name']) ? $region['city_name'] : 'Unknown',
                            isset($region['province_name']) ? $region['province_name'] : 'Unknown'
                        );
                        WC()->session->set('fastrac_region_found', $region_info);
                        WC()->session->set('fastrac_match_type', 'Numeric match');
                    }
                    
                    return (string)$region['subdistrict_id'];
                }
            }
        }
        
        // STEP 3: Use first available region as fallback
        $this->logger->log("Using first available region as fallback", 'info');
        foreach ($response_data['data'] as $region) {
            if (isset($region['subdistrict_id'])) {
                $this->logger->log("Using first available region: " . print_r($region, true), 'info');
                
                // Save region info for checkout display
                if (WC()->session) {
                    $region_info = sprintf(
                        "Using region (fallback): %s, %s, %s (%s)",
                        isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                        isset($region['district']) ? $region['district'] : 'Unknown',
                        isset($region['city_name']) ? $region['city_name'] : 'Unknown',
                        isset($region['province_name']) ? $region['province_name'] : 'Unknown'
                    );
                    WC()->session->set('fastrac_region_found', $region_info);
                    WC()->session->set('fastrac_match_type', 'Fallback - first available');
                }
                
                return (string)$region['subdistrict_id'];
            }
        }
        
        // If we get here, no usable region was found
        $this->logger->log_error("No matching or usable subdistrict found for postcode: " . $postcode);
        
        // Save error for checkout display
        if (WC()->session) {
            WC()->session->set('fastrac_region_search_error', "No region found for postcode: " . $postcode . ". Please verify the postcode is correct.");
        }
        
        return false;
    }
    
    /**
     * Process API response from region search
     *
     * @access private
     * @param array $response API response
     * @param string $postcode Postcode that was searched
     * @param string $format Format used for the request (json or form)
     * @return string|bool Subdistrict ID or false on failure
     */
    private function process_api_response($response, $postcode, $format = 'json') {
        
        // Get the response status code
        $status_code = wp_remote_retrieve_response_code($response);
        $this->logger->log("Response Status Code (" . $format . "): " . $status_code, 'info');
        
        // Get response headers
        $headers = wp_remote_retrieve_headers($response);
        $this->logger->log("Response Headers (" . $format . "): " . print_r($headers, true), 'debug');
        
        // Get and log the raw response body
        $body = wp_remote_retrieve_body($response);
        $this->logger->log("Raw Response Body (" . $format . "): " . $body, 'debug');
        
        // Save raw response for direct inspection
        if (WC()->session) {
            WC()->session->set('fastrac_raw_response_' . $format, substr($body, 0, 1000) . (strlen($body) > 1000 ? '...' : ''));
        }
        
        // Check HTTP status code first
        if ($status_code < 200 || $status_code >= 300) {
            $this->logger->log_error("HTTP error: Status code " . $status_code . " (" . $format . ")");
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error_' . $format, "HTTP error: Status code " . $status_code);
            }
            return false;
        }
        
        // Try to decode the response
        $data = json_decode($body, true);
        $json_error = json_last_error();
        
        if ($json_error !== JSON_ERROR_NONE) {
            $error_msg = "JSON parsing error (" . $format . "): " . json_last_error_msg();
            $this->logger->log_error($error_msg);
            
            // Save error for checkout display
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error_' . $format, $error_msg);
                WC()->session->set('fastrac_raw_response_' . $format, substr($body, 0, 1000) . (strlen($body) > 1000 ? '...' : ''));
            }
            
            return false;
        }
        
        // Store response in session for debug display
        if (WC()->session) {
            $response_summary = array(
                'Format' => $format,
                'Status' => $status_code,
                'Success' => isset($data['success']) ? ($data['success'] ? 'Yes' : 'No') : 'Unknown',
                'Results' => isset($data['data']) ? count($data['data']) : 0,
                'Timestamp' => current_time('Y-m-d H:i:s'),
            );
            WC()->session->set('fastrac_api_response_info_' . $format, $response_summary);
        }
        
        // Check if the response is valid
        if (empty($data)) {
            $this->logger->log_error("Empty response data (" . $format . ")");
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error_' . $format, "Empty response from API");
            }
            return false;
        }
        
        // Check if the request was successful
        if (!isset($data['success']) || !$data['success']) {
            $error = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->log_error("API error (" . $format . "): " . $error);
            
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error_' . $format, "API error: " . $error);
            }
            
            return false;
        }
        
        // Check if we have data in the response
        if (empty($data['data'])) {
            $this->logger->log_error("No regions found in response (" . $format . ")");
            
            if (WC()->session) {
                WC()->session->set('fastrac_region_search_error_' . $format, "No regions found for postcode: " . $postcode);
            }
            
            return false;
        }
        
        // Log the number of regions found
        $region_count = count($data['data']);
        $this->logger->log("SUCCESS! Found " . $region_count . " regions in response (" . $format . ")", 'info');
        
        // Store the regions for debugging
        $regions_found = array();
        foreach ($data['data'] as $region) {
            $region_info = array();
            if (isset($region['post_code'])) $region_info['post_code'] = $region['post_code'];
            if (isset($region['subdistrict_id'])) $region_info['subdistrict_id'] = $region['subdistrict_id'];
            if (isset($region['subdistrict'])) $region_info['subdistrict'] = $region['subdistrict'];
            if (isset($region['district'])) $region_info['district'] = $region['district'];
            if (isset($region['city_name'])) $region_info['city_name'] = $region['city_name'];
            if (isset($region['province_name'])) $region_info['province_name'] = $region['province_name'];
            $regions_found[] = $region_info;
        }
        
        if (WC()->session) {
            WC()->session->set('fastrac_regions_found', $regions_found);
            WC()->session->set('fastrac_api_success_format', $format); // Remember which format worked
        }
        
        // Response data is valid, proceed with processing
        $response_data = $data;
        
        $this->logger->log("Successfully received valid response from API (" . $format . ")", 'info');
        
        // Process the successful response data
        $this->logger->log("Processing " . count($response_data['data']) . " regions from response", 'info');
        
        // Enhanced logging - Show detailed API response structure
        $this->logger->log("==========================================", 'info');
        $this->logger->log("DETAILED API RESPONSE ANALYSIS", 'info');
        $this->logger->log("==========================================", 'info');
        
        // Log the structure of all regions to understand the fields
        if (!empty($response_data['data'])) {
            // Store all region data for detailed debugging
            $all_regions_data = array();
            
            foreach ($response_data['data'] as $index => $region) {
                $this->logger->log("Region #" . ($index + 1) . " structure: " . print_r($region, true), 'info');
                
                // Extract important fields for easier debugging
                $region_summary = array(
                    'index' => $index + 1,
                );
                
                // Check for key fields and add them to the summary
                $important_fields = array('subdistrict_id', 'post_code', 'province_id', 'province_name', 
                                         'city_id', 'city_name', 'district_id', 'district', 'subdistrict');
                
                foreach ($important_fields as $field) {
                    if (isset($region[$field])) {
                        $region_summary[$field] = $region[$field];
                    }
                }
                
                $all_regions_data[] = $region_summary;
            }
            
            // Store first region in session for debugging
            if (WC()->session && !empty($response_data['data'][0])) {
                WC()->session->set('fastrac_first_region', $response_data['data'][0]);
                WC()->session->set('fastrac_all_regions', $all_regions_data);
            }
        }
        
        // Log available fields in the first region
        if (!empty($response_data['data'][0])) {
            $this->logger->log("All available fields in first region: " . implode(", ", array_keys($response_data['data'][0])), 'info');
            
            // Store this information for debug display
            if (WC()->session) {
                WC()->session->set('fastrac_available_fields', implode(", ", array_keys($response_data['data'][0])));
            }
        }
        
        // Detailed check for post_code field
        $post_code_field_name = null;
        $subdistrict_id_field_name = null;
        
        // Check what field names are actually used in the API response
        if (!empty($response_data['data'][0])) {
            foreach ($response_data['data'][0] as $field => $value) {
                // Look for postcode field (might be post_code, postcode, postal_code, etc.)
                if (stripos($field, 'post') !== false && stripos($field, 'code') !== false) {
                    $post_code_field_name = $field;
                    $this->logger->log("Found postcode field name: " . $field, 'info');
                }
                
                // Look for subdistrict ID field
                if (stripos($field, 'subdistrict') !== false && stripos($field, 'id') !== false) {
                    $subdistrict_id_field_name = $field;
                    $this->logger->log("Found subdistrict ID field name: " . $field, 'info');
                }
            }
            
            // Store detected field names
            if (WC()->session) {
                WC()->session->set('fastrac_detected_fields', array(
                    'postcode_field' => $post_code_field_name,
                    'subdistrict_id_field' => $subdistrict_id_field_name
                ));
            }
        }
        
        // Check specifically for post_code field
        $post_code_found = false;
        foreach ($response_data['data'] as $region) {
            if (isset($region['post_code'])) {
                $post_code_found = true;
                $this->logger->log("Sample post_code found: " . $region['post_code'], 'info');
                break;
            }
        }
        
        if (!$post_code_found) {
            $this->logger->log("WARNING: No standard 'post_code' field found in response data", 'warning');
            // Log all available fields in the first region
            if (!empty($response_data['data'][0])) {
                $this->logger->log("Available fields: " . implode(", ", array_keys($response_data['data'][0])), 'info');
            }
        }
        
        // Log postcode comparison for each region
        $this->logger->log("Postcode Comparison Details:", 'info');
        foreach ($response_data['data'] as $index => $region) {
            $region_postcode = isset($region['post_code']) ? $region['post_code'] : 'N/A';
            $comparison = "Region #" . ($index + 1) . " - Input postcode: {$postcode}, Region postcode: {$region_postcode}";
            
            if ($region_postcode == $postcode) {
                $comparison .= " => MATCH";
            } else {
                $comparison .= " => NO MATCH";
            }
            
            $this->logger->log($comparison, 'info');
        }
        
        // STEP 1: Look for exact match on postcode
        $this->logger->log("STEP 1: Looking for exact postcode match", 'info');
        
        // Try different approaches to find a match
        // 1. First try with standard field names
        $this->logger->log("1.1 - Trying with standard field names", 'info');
        foreach ($response_data['data'] as $region) {
            // Check the field exists and matches our postcode
            if (isset($region['post_code']) && isset($region['subdistrict_id'])) {
                $this->logger->log("Comparing - Input: " . $postcode . ", Region: " . $region['post_code'], 'info');
                
                if ($region['post_code'] == $postcode) {
                    $this->logger->log("SUCCESS! Found exact postcode match: " . print_r($region, true), 'info');
                    
                    // Save region info for checkout display
                    if (WC()->session) {
                        $region_info = sprintf(
                            "Found region: %s, %s, %s (%s)",
                            isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            isset($region['district']) ? $region['district'] : 'Unknown',
                            isset($region['city_name']) ? $region['city_name'] : 'Unknown',
                            isset($region['province_name']) ? $region['province_name'] : 'Unknown'
                        );
                        WC()->session->set('fastrac_region_found', $region_info);
                        WC()->session->set('fastrac_match_type', 'Exact match with standard fields (using ' . $format . ' format)');
                        
                        // Store specific subdistrict info for future reference
                        WC()->session->set('fastrac_found_subdistrict', array(
                            'id' => $region['subdistrict_id'],
                            'name' => isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            'postcode' => $region['post_code'],
                            'match_type' => 'exact_standard',
                            'format' => $format
                        ));
                    }
                    
                    return (string) $region['subdistrict_id'];
                }
            }
        }
        
        // 2. Try with detected field names if they're different
        if ($post_code_field_name && $post_code_field_name !== 'post_code' && 
            $subdistrict_id_field_name && $subdistrict_id_field_name !== 'subdistrict_id') {
            
            $this->logger->log("1.2 - Trying with detected field names: " . $post_code_field_name . " and " . $subdistrict_id_field_name, 'info');
            
            foreach ($response_data['data'] as $region) {
                if (isset($region[$post_code_field_name]) && isset($region[$subdistrict_id_field_name])) {
                    $this->logger->log("Comparing with detected fields - Input: " . $postcode . ", Region: " . $region[$post_code_field_name], 'info');
                    
                    if ($region[$post_code_field_name] == $postcode) {
                        $this->logger->log("SUCCESS! Found exact match with detected fields: " . print_r($region, true), 'info');
                        
                        // Save region info for checkout display
                        if (WC()->session) {
                            $region_info = sprintf(
                                "Found region (using detected fields): %s",
                                isset($region[$subdistrict_id_field_name]) ? $region[$subdistrict_id_field_name] : 'Unknown'
                            );
                            WC()->session->set('fastrac_region_found', $region_info);
                            WC()->session->set('fastrac_match_type', 'Exact match with detected fields (using ' . $format . ' format)');
                            
                            // Store specific subdistrict info for future reference
                            WC()->session->set('fastrac_found_subdistrict', array(
                                'id' => $region[$subdistrict_id_field_name],
                                'postcode' => $region[$post_code_field_name],
                                'match_type' => 'exact_detected',
                                'format' => $format
                            ));
                        }
                        
                        return (string) $region[$subdistrict_id_field_name];
                    }
                }
            }
        }
        
        // 3. Try numeric comparison
        $this->logger->log("1.3 - Trying with numeric comparison", 'info');
        $numeric_postcode = (int) $postcode;
        
        foreach ($response_data['data'] as $region) {
            if (isset($region['post_code']) && isset($region['subdistrict_id'])) {
                $region_numeric_postcode = (int) $region['post_code'];
                $this->logger->log("Comparing numerically - Input: " . $numeric_postcode . ", Region: " . $region_numeric_postcode, 'info');
                
                if ($region_numeric_postcode === $numeric_postcode) {
                    $this->logger->log("SUCCESS! Found exact numeric match: " . print_r($region, true), 'info');
                    
                    // Save region info for checkout display
                    if (WC()->session) {
                        $region_info = sprintf(
                            "Found region (numeric match): %s, %s, %s (%s)",
                            isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            isset($region['district']) ? $region['district'] : 'Unknown',
                            isset($region['city_name']) ? $region['city_name'] : 'Unknown',
                            isset($region['province_name']) ? $region['province_name'] : 'Unknown'
                        );
                        WC()->session->set('fastrac_region_found', $region_info);
                        WC()->session->set('fastrac_match_type', 'Exact numeric match (using ' . $format . ' format)');
                        
                        // Store specific subdistrict info for future reference
                        WC()->session->set('fastrac_found_subdistrict', array(
                            'id' => $region['subdistrict_id'],
                            'name' => isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            'postcode' => $region['post_code'],
                            'match_type' => 'exact_numeric',
                            'format' => $format
                        ));
                    }
                    
                    return (string) $region['subdistrict_id'];
                }
            }
        }
        
        // STEP 2: If no exact match, check for a similar postcode
        $this->logger->log("STEP 2: Looking for similar postcode", 'info');
        foreach ($response_data['data'] as $region) {
            if (isset($region['post_code']) && isset($region['subdistrict_id'])) {
                // Log the comparison to help debug
                $this->logger->log("Comparing postcodes - Input: " . $postcode . ", Region: " . $region['post_code'], 'debug');
                
                // Try different matching techniques
                if ($region['post_code'] == $postcode || 
                    strpos($region['post_code'], $postcode) !== false || 
                    strpos($postcode, $region['post_code']) !== false ||
                    levenshtein($region['post_code'], $postcode) <= 2) {  // Allow for slight typos
                    
                    $this->logger->log("Found similar postcode: " . $region['post_code'], 'info');
                    
                    // Save region info for checkout display
                    if (WC()->session) {
                        $region_info = sprintf(
                            "Found region (similar postcode): %s, %s, %s (%s)",
                            isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            isset($region['district']) ? $region['district'] : 'Unknown',
                            isset($region['city_name']) ? $region['city_name'] : 'Unknown',
                            isset($region['province_name']) ? $region['province_name'] : 'Unknown'
                        );
                        WC()->session->set('fastrac_region_found', $region_info);
                        WC()->session->set('fastrac_match_type', 'Similar postcode match (using ' . $format . ' format)');
                        
                        // Store specific subdistrict info for future reference
                        WC()->session->set('fastrac_found_subdistrict', array(
                            'id' => $region['subdistrict_id'],
                            'name' => isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                            'postcode' => $region['post_code'],
                            'match_type' => 'similar',
                            'format' => $format
                        ));
                    }
                    
                    return (string) $region['subdistrict_id'];
                }
            }
        }
        
        // STEP 3: Last resort - use the first available region with a subdistrict_id
        $this->logger->log("STEP 3: Using first available region as fallback", 'info');
        foreach ($response_data['data'] as $region) {
            if (isset($region['subdistrict_id'])) {
                $this->logger->log("Using first available region: " . print_r($region, true), 'info');
                
                // Save region info for checkout display
                if (WC()->session) {
                    $region_info = sprintf(
                        "Using region (no match): %s, %s, %s (%s)",
                        isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                        isset($region['district']) ? $region['district'] : 'Unknown',
                        isset($region['city_name']) ? $region['city_name'] : 'Unknown',
                        isset($region['province_name']) ? $region['province_name'] : 'Unknown'
                    );
                    WC()->session->set('fastrac_region_found', $region_info);
                    WC()->session->set('fastrac_match_type', 'Fallback - first available (using ' . $format . ' format)');
                    
                    // Store specific subdistrict info for future reference
                    WC()->session->set('fastrac_found_subdistrict', array(
                        'id' => $region['subdistrict_id'],
                        'name' => isset($region['subdistrict']) ? $region['subdistrict'] : 'Unknown',
                        'postcode' => isset($region['post_code']) ? $region['post_code'] : 'Unknown',
                        'match_type' => 'fallback',
                        'format' => $format
                    ));
                }
                
                return (string) $region['subdistrict_id'];
            }
        }
        
        // If we get here, no usable region was found
        $this->logger->log_error("No matching or usable subdistrict found for postcode: " . $postcode . " (" . $format . ")");
        
        // Save error for checkout display
        if (WC()->session) {
            WC()->session->set('fastrac_region_search_error_' . $format, "No region found for postcode: " . $postcode);
        }
        
        return false;
    }
    
    /**
     * AJAX endpoint for postcode validation
     *
     * @access public
     * @return void
     */
    public function ajax_validate_postcode() {
        // Check nonce
        check_ajax_referer('fastrac-shipping-nonce', 'nonce');
        
        $postcode = isset($_POST['postcode']) ? sanitize_text_field($_POST['postcode']) : '';
        
        // Log the postcode being processed
        $this->logger->log('Processing postcode ' . $postcode . ' in AJAX endpoint', 'debug');
        
        if (empty($postcode)) {
            wp_send_json_error(array('message' => __('Please enter a valid postcode.', 'fastrac-shipping')));
            return;
        }
        
        // Get region ID from postcode
        $region_id = $this->get_region_id_from_postcode($postcode);
        
        if ($region_id) {
            $this->logger->log('Found region ID ' . $region_id . ' for postcode ' . $postcode, 'info');
            
            wp_send_json_success(array(
                'region_id' => $region_id,
                'message' => sprintf(__('Found region ID: %s for postcode: %s', 'fastrac-shipping'), $region_id, $postcode),
                'debug_info' => 'Postcode processed: ' . $postcode . ', Region ID found: ' . $region_id
            ));
        } else {
            $this->logger->log_error('No region ID found for postcode ' . $postcode);
            
            wp_send_json_error(array(
                'message' => sprintf(__('No region found for postcode: %s', 'fastrac-shipping'), $postcode),
                'debug_info' => 'Postcode processed: ' . $postcode . ', No region ID found'
            ));
        }
    }
    
    /**
     * Calculate shipping tariff using Fastrac API
     * 
     * @access private
     * @param array $dimensions Package dimensions
     * @param string $origin_id Origin subdistrict ID
     * @param string $destination_id Destination subdistrict ID
     * @return array|bool Shipping rate or false on failure
     */
    private function calculate_tariff($dimensions, $origin_id, $destination_id) {
        $this->logger->log("\n\n------------------------------------------", 'info');
        $this->logger->log("=== STARTING TARIFF CALCULATION ===", 'info');
        $this->logger->log("------------------------------------------", 'info');
        $this->logger->log("Origin ID: " . $origin_id, 'info');
        $this->logger->log("Destination ID: " . $destination_id, 'info');
        $this->logger->log("Dimensions: " . print_r($dimensions, true), 'info');
        
        if (empty($this->access_key) || empty($this->secret_key)) {
            $this->logger->log_error("Missing API credentials for tariff calculation");
            return false;
        }
        
        if (empty($origin_id) || empty($destination_id)) {
            $this->logger->log_error("Missing origin or destination ID for tariff calculation");
            return false;
        }
        
        // Debug validity of IDs
        if (!is_numeric($origin_id)) {
            $this->logger->log_error("Origin ID is not numeric: " . $origin_id);
            return false;
        }
        
        if (!is_numeric($destination_id)) {
            $this->logger->log_error("Destination ID is not numeric: " . $destination_id);
            return false;
        }
        
        // If dimensions are not valid, we'll use default values
        if (!$dimensions || !$this->validate_dimensions($dimensions)) {
            $this->logger->log_error("Invalid dimensions for tariff calculation, using defaults");
            $dimensions = array(
                'weight' => 1, // 1 kg
                'length' => 10, // 10 cm
                'width'  => 10, // 10 cm
                'height' => 10, // 10 cm
            );
        }
        
        $this->logger->log("Calculating tariff for origin: " . $origin_id . ", destination: " . $destination_id);
        
        // Convert weight from kg to grams (API expects weight in grams)
        $weight_in_grams = $dimensions['weight'] * 1000;
        
        // Prepare request body for tariffExpress API
        $request_body = array(
            'origin' => (int) $origin_id,
            'destination' => (int) $destination_id,
            'weight' => (int) $weight_in_grams,  // in grams
            'length' => (int) $dimensions['length'], // in cm
            'width' => (int) $dimensions['width'],   // in cm
            'height' => (int) $dimensions['height'], // in cm
        );
        
        $this->logger->log("Tariff calculation request: " . print_r($request_body, true));
        
        // Log detailed tariff request parameters to server logs
        $this->logger->log("TARIFF API REQUEST - Request Time: " . date('Y-m-d H:i:s'), 'info');
        $this->logger->log("TARIFF API REQUEST - Origin ID: {$origin_id}, Source: Dynamic from WooCommerce Store Settings", 'info');
        $this->logger->log("TARIFF API REQUEST - Destination ID: {$destination_id}, Source: Derived from customer postcode via API", 'info');
        $this->logger->log("TARIFF API REQUEST - Package Dimensions: Weight (kg): {$dimensions['weight']}, Weight (g): {$weight_in_grams}, Length: {$dimensions['length']}cm, Width: {$dimensions['width']}cm, Height: {$dimensions['height']}cm", 'info');
        $this->logger->log("TARIFF API REQUEST - Complete Request Body: " . json_encode($request_body), 'debug');
        $this->logger->log("TARIFF API REQUEST - API Endpoint: tariffExpress, Method: POST", 'info');
        
        // Log origin and destination details
        $store_postcode = get_option('woocommerce_store_postcode');
        $this->logger->log("ORIGIN ID DETAILS - ID: {$origin_id}, Store Postcode: {$store_postcode}, Dynamically derived from store postcode via API", 'info');
        $customer_postcode = WC()->customer ? WC()->customer->get_shipping_postcode() : 'N/A';
        $this->logger->log("DESTINATION ID DETAILS - ID: {$destination_id}, Customer Postcode: {$customer_postcode}, Dynamically derived from customer postcode via API", 'info');
        
        $url = $this->api_tariff_endpoint;
        
        $args = array(
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => array(
                'access-key' => $this->access_key,
                'secret-key' => $this->secret_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($request_body),
        );
        
        // Log the API request
        $this->logger->log_api_request($url, $args);
        
        // Log the actual JSON being sent
        $this->logger->log("API Request JSON: " . json_encode($request_body, JSON_PRETTY_PRINT), 'debug');
        
        // Debug message to verify the request
        $debug_info = sprintf(
            "Sending tariff request - Origin: %s, Destination: %s, Weight: %s g, L: %s cm, W: %s cm, H: %s cm",
            $request_body['origin'],
            $request_body['destination'],
            $request_body['weight'],
            $request_body['length'],
            $request_body['width'],
            $request_body['height']
        );
        $this->logger->log($debug_info, 'info');

        // Create a detailed request description for debug display
        $request_details = array(
            'API Endpoint' => $url,
            'Origin ID' => $request_body['origin'],
            'Destination ID' => $request_body['destination'],
            'Weight (g)' => $request_body['weight'],
            'Length (cm)' => $request_body['length'],
            'Width (cm)' => $request_body['width'],
            'Height (cm)' => $request_body['height'],
            'Content-Type' => 'application/json',
            'Request Method' => 'POST'
        );
        
        // Store in session for debug display
        if (WC()->session) {
            WC()->session->set('fastrac_tariff_request', $debug_info);
            WC()->session->set('fastrac_tariff_request_details', $request_details);
        }
        
        $response = wp_remote_post($url, $args);
        
        // Log response status to server logs
        $status = is_wp_error($response) ? 'ERROR' : wp_remote_retrieve_response_code($response);
        $request_id = uniqid();
        $this->logger->log("TARIFF API RESPONSE STATUS - Response Time: " . date('Y-m-d H:i:s') . ", Status: {$status}, Request ID: {$request_id}", 'info');
        
        if (is_wp_error($response)) {
            $this->logger->log_api_response($response, true);
            $error_message = $response->get_error_message();
            $error_data = $response->get_error_data();
            $this->logger->log_error("Tariff API error: " . $error_message);
            if ($error_data) {
                $this->logger->log_error("Error details: " . print_r($error_data, true));
            }
            
            // Store in session for debug display
            if (WC()->session) {
                WC()->session->set('fastrac_tariff_error', "API Error: " . $error_message);
            }
            
            return false;
        }
        
        // Log the API response
        $this->logger->log_api_response($response);
        
        $body = wp_remote_retrieve_body($response);
        
        // Log the raw response body
        $this->logger->log("Raw API response body: " . $body, 'debug');
        
        if (empty($body)) {
            $this->logger->log_error("Empty response body from tariff API");
            if (WC()->session) {
                WC()->session->set('fastrac_tariff_error', "Empty response from shipping API");
            }
            return false;
        }
        
        $data = json_decode($body, true);
        
        // Log response data to server logs
        $this->logger->log("TARIFF API RESPONSE DATA: " . (!empty($body) ? $body : '{}'), 'debug');
        
        if (empty($data)) {
            $this->logger->log_error("Invalid JSON response from tariff API");
            if (WC()->session) {
                WC()->session->set('fastrac_tariff_error', "Invalid response format from shipping API");
            }
            return false;
        }
        
        if (!isset($data['success'])) {
            $this->logger->log_error("Response missing 'success' field from tariff API");
            if (WC()->session) {
                WC()->session->set('fastrac_tariff_error', "Invalid response structure from shipping API");
            }
            return false;
        }
        
        if (!$data['success']) {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->logger->log_error("Tariff API error: " . $error_message);
            if (WC()->session) {
                WC()->session->set('fastrac_tariff_error', "API error: " . $error_message);
            }
            return false;
        }
        
        if (empty($data['data'])) {
            $this->logger->log_error("API returned success but no data");
            if (WC()->session) {
                WC()->session->set('fastrac_tariff_error', "No shipping options found for this address");
            }
            return false;
        }
        
        // Get shipping methods from the response
        $shipping_categories = array(
            'nextday' => __('Next Day', 'fastrac-shipping'),
            'regular' => __('Regular', 'fastrac-shipping'),
            'sameday' => __('Same Day', 'fastrac-shipping'),
            'cargo' => __('Cargo', 'fastrac-shipping')
        );
        
        // Track if we found any rates
        $found_rates = false;
        
        // Process response data - parse shipping rates
        try {
            // Add rates for each shipping method type
            foreach ($shipping_categories as $category_key => $category_label) {
                // Skip if this category is not in the response or is empty
                if (!isset($data['data'][$category_key]) || empty($data['data'][$category_key])) {
                    continue;
                }
                
                // Get the rates for this category
                $rates = $data['data'][$category_key];
                
                // Process each rate option
                foreach ($rates as $index => $rate_data) {
                    // Skip if required data is missing
                    if (!isset($rate_data['total']) || !isset($rate_data['courier_name']) || 
                        !isset($rate_data['service']) || !isset($rate_data['etd'])) {
                        continue;
                    }
                    
                    // Create a unique ID for this rate
                    $rate_id = $this->id . '_' . $category_key . '_' . $index;
                    
                    // Format the rate label
                    $rate_label = sprintf(
                        '%s: %s - %s (%s)',
                        $this->title,
                        $rate_data['courier_name'],
                        $rate_data['service'],
                        $rate_data['etd']
                    );
                    
                    // Create the rate array
                    $rate = array(
                        'id'       => $rate_id,
                        'label'    => $rate_label,
                        'cost'     => (float) $rate_data['total'],
                        'calc_tax' => 'per_item',
                        'meta_data' => array(
                            'courier'     => $rate_data['courier_name'],
                            'service'     => $rate_data['service'],
                            'etd'         => $rate_data['etd'],
                            'category'    => $category_label,
                        ),
                    );
                    
                    // Log this rate
                    $this->logger->log("Adding shipping rate: " . $rate_label . " - " . $rate_data['total']);
                    
                    // Add the rate to WooCommerce
                    $this->add_rate($rate);
                    
                    $found_rates = true;
                }
            }
            
            // Return true if we found rates
            if ($found_rates) {
                return true;
            }
            
            // If we get here, no rates were found in the API response
            $this->logger->log_error("No shipping rates found in API response");
            return false;
            
        } catch (Exception $e) {
            $this->logger->log_error("Error processing tariff API response: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add error notice for display to customer
     *
     * @access private
     * @param string $type Error type
     * @param string $message Error message
     * @return void
     */
    private function add_error_notice($type, $message) {
        // Store the error in session
        if (WC()->session) {
            $notices = WC()->session->get('fastrac_shipping_notices', array());
            $notices[$type] = $message;
            WC()->session->set('fastrac_shipping_notices', $notices);
        }
    }
    
    /**
     * Display customer notices
     *
     * @access public
     * @return void
     */
    public function show_checkout_notices() {
        if (!WC()->session) {
            return;
        }
        
        $notices = WC()->session->get('fastrac_shipping_notices', array());
        
        // Get any debug messages that might have been saved
        $debug_message = WC()->session->get('fastrac_debug_message', '');
        $shipping_info = WC()->session->get('fastrac_shipping_info', '');
        $tariff_request = WC()->session->get('fastrac_tariff_request', '');
        $tariff_error = WC()->session->get('fastrac_tariff_error', '');
        
        // Combine debug information
        $debug_notices = array();
        
        // Add a header for debug info - make it more prominent
        $debug_notices[] = '<div style="background-color: #f8f9fa; padding: 15px; border: 2px solid #e74c3c; border-radius: 5px; margin-bottom: 15px; box-shadow: 0 0 10px rgba(0,0,0,0.1);">';
        $debug_notices[] = '<h3 style="color: #e74c3c; margin-top: 0; border-bottom: 1px solid #e74c3c; padding-bottom: 10px;">' . __('FASTRAC SHIPPING DEBUG INFORMATION', 'fastrac-shipping') . '</h3>';
        
        // Show a summary of what worked/failed
        $critical_error = WC()->session->get('fastrac_critical_error', '');
        if (!empty($critical_error)) {
            $debug_notices[] = '<div style="padding: 10px; background-color: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px; color: #721c24;">';
            $debug_notices[] = '<strong>CRITICAL ERROR:</strong> ' . $critical_error;
            $debug_notices[] = '</div>';
        }
        
        $api_success_format = WC()->session->get('fastrac_api_success_format', '');
        if (!empty($api_success_format)) {
            $debug_notices[] = '<div style="padding: 10px; background-color: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px; color: #155724;">';
            $debug_notices[] = '<strong>API SUCCESS:</strong> Successfully communicated with API using ' . $api_success_format . ' format.';
            $debug_notices[] = '</div>';
        } else {
            $debug_notices[] = '<div style="padding: 10px; background-color: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px; color: #856404;">';
            $debug_notices[] = '<strong>API STATUS:</strong> No successful API communication detected.';
            $debug_notices[] = '</div>';
        }
        
        // SECTION 1: Region Search
        $debug_notices[] = '<div style="margin-top: 10px; border-left: 3px solid #3498db; padding-left: 10px;">';
        $debug_notices[] = '<strong style="color: #3498db;">' . __('1. Region Search Process:', 'fastrac-shipping') . '</strong>';
        
        $region_search = WC()->session->get('fastrac_region_search', '');
        $region_found = WC()->session->get('fastrac_region_found', '');
        $region_error = WC()->session->get('fastrac_region_search_error', '');
        $match_type = WC()->session->get('fastrac_match_type', '');
        $api_request_info = WC()->session->get('fastrac_api_request_info', array());
        $api_response_info = WC()->session->get('fastrac_api_response_info', array());
        $regions_found = WC()->session->get('fastrac_regions_found', array());
        $raw_response = WC()->session->get('fastrac_raw_response', '');
        
        if (!empty($region_search)) {
            $debug_notices[] = '<div>' . $region_search . '</div>';
        }
        
        // Display API request info
        if (!empty($api_request_info)) {
            $debug_notices[] = '<details style="margin-top: 5px;">';
            $debug_notices[] = '<summary style="cursor: pointer; color: #3498db;">API Request Details</summary>';
            $debug_notices[] = '<div style="margin-top: 5px; padding: 5px; background-color: #f9f9f9; border: 1px solid #ddd;">';
            foreach ($api_request_info as $key => $value) {
                $debug_notices[] = '<div><strong>' . esc_html($key) . '</strong>: ' . esc_html($value) . '</div>';
            }
            $debug_notices[] = '</div>';
            $debug_notices[] = '</details>';
        }
        
        // Display API response info
        if (!empty($api_response_info)) {
            $debug_notices[] = '<details style="margin-top: 5px;">';
            $debug_notices[] = '<summary style="cursor: pointer; color: #3498db;">API Response Summary</summary>';
            $debug_notices[] = '<div style="margin-top: 5px; padding: 5px; background-color: #f9f9f9; border: 1px solid #ddd;">';
            foreach ($api_response_info as $key => $value) {
                $debug_notices[] = '<div><strong>' . esc_html($key) . '</strong>: ' . esc_html($value) . '</div>';
            }
            $debug_notices[] = '</div>';
            $debug_notices[] = '</details>';
        }
        
        // Show raw response if parsing failed
        if (!empty($raw_response)) {
            $debug_notices[] = '<details style="margin-top: 5px;">';
            $debug_notices[] = '<summary style="cursor: pointer; color: #e74c3c;">Raw Response (Invalid Format)</summary>';
            $debug_notices[] = '<div style="margin-top: 5px; padding: 5px; background-color: #f9f9f9; border: 1px solid #ddd; word-break: break-all;">';
            $debug_notices[] = esc_html($raw_response);
            $debug_notices[] = '</div>';
            $debug_notices[] = '</details>';
        }
        
        // Show detected field names
        $detected_fields = WC()->session->get('fastrac_detected_fields', array());
        if (!empty($detected_fields)) {
            $debug_notices[] = '<div style="margin-top: 10px; padding: 8px; background-color: #e8f4f8; border: 1px solid #b8e6ff; border-radius: 4px;">';
            $debug_notices[] = '<strong style="color: #0366d6;">Detected Field Names:</strong>';
            $debug_notices[] = '<ul style="margin: 5px 0 0 20px; padding: 0;">';
            foreach ($detected_fields as $key => $value) {
                $debug_notices[] = '<li><strong>' . esc_html($key) . '</strong>: ' . esc_html($value ? $value : 'Not detected') . '</li>';
            }
            $debug_notices[] = '</ul>';
            $debug_notices[] = '</div>';
        }
        
        // Show all available fields
        $available_fields = WC()->session->get('fastrac_available_fields', '');
        if (!empty($available_fields)) {
            $debug_notices[] = '<div style="margin-top: 10px; padding: 8px; background-color: #f8f8e8; border: 1px solid #f0e68c; border-radius: 4px;">';
            $debug_notices[] = '<strong style="color: #856404;">Available API Response Fields:</strong> ' . esc_html($available_fields);
            $debug_notices[] = '</div>';
        }
        
        // Show regions found
        if (!empty($regions_found)) {
            $debug_notices[] = '<details style="margin-top: 5px;">';
            $debug_notices[] = '<summary style="cursor: pointer; color: #3498db;">Regions Found (' . count($regions_found) . ')</summary>';
            $debug_notices[] = '<div style="margin-top: 5px; padding: 5px; background-color: #f9f9f9; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;">';
            foreach ($regions_found as $index => $region) {
                $debug_notices[] = '<div style="margin-bottom: 5px; padding-bottom: 5px; border-bottom: 1px solid #eee;">';
                $debug_notices[] = '<strong>Region #' . ($index + 1) . '</strong><br>';
                foreach ($region as $key => $value) {
                    $debug_notices[] = '<div><strong>' . esc_html($key) . '</strong>: ' . esc_html($value) . '</div>';
                }
                $debug_notices[] = '</div>';
            }
            $debug_notices[] = '</div>';
            $debug_notices[] = '</details>';
        }
        
        // Show all regions with more details
        $all_regions = WC()->session->get('fastrac_all_regions', array());
        if (!empty($all_regions)) {
            $debug_notices[] = '<details style="margin-top: 5px;">';
            $debug_notices[] = '<summary style="cursor: pointer; color: #3498db;">All Regions Details (' . count($all_regions) . ')</summary>';
            $debug_notices[] = '<div style="margin-top: 5px; padding: 5px; background-color: #f9f9f9; border: 1px solid #ddd; max-height: 300px; overflow-y: auto;">';
            $debug_notices[] = '<table style="width: 100%; border-collapse: collapse;">';
            $debug_notices[] = '<tr>';
            $debug_notices[] = '<th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Index</th>';
            $debug_notices[] = '<th style="border: 1px solid #ddd; padding: 8px; text-align: left;">ID</th>';
            $debug_notices[] = '<th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Postcode</th>';
            $debug_notices[] = '<th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Subdistrict</th>';
            $debug_notices[] = '<th style="border: 1px solid #ddd; padding: 8px; text-align: left;">District</th>';
            $debug_notices[] = '<th style="border: 1px solid #ddd; padding: 8px; text-align: left;">City</th>';
            $debug_notices[] = '</tr>';
            
            foreach ($all_regions as $region) {
                $debug_notices[] = '<tr>';
                $debug_notices[] = '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($region['index']) . '</td>';
                $debug_notices[] = '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html(isset($region['subdistrict_id']) ? $region['subdistrict_id'] : 'N/A') . '</td>';
                $debug_notices[] = '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html(isset($region['post_code']) ? $region['post_code'] : 'N/A') . '</td>';
                $debug_notices[] = '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html(isset($region['subdistrict']) ? $region['subdistrict'] : 'N/A') . '</td>';
                $debug_notices[] = '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html(isset($region['district']) ? $region['district'] : 'N/A') . '</td>';
                $debug_notices[] = '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html(isset($region['city_name']) ? $region['city_name'] : 'N/A') . '</td>';
                $debug_notices[] = '</tr>';
            }
            
            $debug_notices[] = '</table>';
            $debug_notices[] = '</div>';
            $debug_notices[] = '</details>';
        }
        
        // Result of the search
        if (!empty($region_found)) {
            $debug_notices[] = '<span style="color: green; font-weight: bold;">✓ ' . $region_found . '</span>';
            if (!empty($match_type)) {
                $debug_notices[] = '<div style="color: green; font-style: italic;">Match type: ' . $match_type . '</div>';
            }
        } else if (!empty($region_error)) {
            $debug_notices[] = '<span style="color: red; font-weight: bold;">✗ ' . $region_error . '</span>';
        }
        
        // Also show destination ID if found
        if (!empty($debug_message)) {
            $debug_notices[] = '<span style="color: green; margin-top: 5px;">✓ ' . $debug_message . '</span>';
        } else if (empty($region_error)) {
            $debug_notices[] = '<span style="color: red; margin-top: 5px;">✗ ' . __('No destination ID found', 'fastrac-shipping') . '</span>';
        }
        
        $debug_notices[] = '</div>';
        
        // SECTION 2: Shipping Info
        $debug_notices[] = '<div style="margin-top: 10px; border-left: 3px solid #3498db; padding-left: 10px;">';
        $debug_notices[] = '<strong style="color: #3498db;">' . __('2. Origin/Destination Check:', 'fastrac-shipping') . '</strong>';
        
        if (!empty($shipping_info)) {
            $debug_notices[] = '<span style="color: green;">✓ ' . $shipping_info . '</span>';
        } else {
            $debug_notices[] = '<span style="color: red;">✗ ' . __('Origin/Destination information not available', 'fastrac-shipping') . '</span>';
        }
        $debug_notices[] = '</div>';
        
        // SECTION 3: Package Dimensions
        $debug_notices[] = '<div style="margin-top: 10px; border-left: 3px solid #3498db; padding-left: 10px;">';
        $debug_notices[] = '<strong style="color: #3498db;">' . __('3. Package Dimensions Check:', 'fastrac-shipping') . '</strong>';
        
        // Show package dimensions if available
        $dimensions = WC()->session->get('fastrac_package_dimensions', array());
        if (!empty($dimensions)) {
            $dimension_info = sprintf(
                __('Weight: %s kg, Length: %s cm, Width: %s cm, Height: %s cm', 'fastrac-shipping'),
                $dimensions['weight'],
                $dimensions['length'],
                $dimensions['width'],
                $dimensions['height']
            );
            $debug_notices[] = '<span style="color: green;">✓ ' . $dimension_info . '</span>';
        } else {
            $debug_notices[] = '<span style="color: red;">✗ ' . __('Package dimensions not available', 'fastrac-shipping') . '</span>';
        }
        $debug_notices[] = '</div>';
        
        // SECTION 4: Tariff API Request
        $debug_notices[] = '<div style="margin-top: 10px; border-left: 3px solid #3498db; padding-left: 10px;">';
        $debug_notices[] = '<strong style="color: #3498db;">' . __('4. Tariff API Request:', 'fastrac-shipping') . '</strong>';
        
        // Show tariff request details
        if (!empty($tariff_request)) {
            $debug_notices[] = '<span style="color: green;">✓ ' . $tariff_request . '</span>';
            
            // Show detailed request parameters
            $request_details = WC()->session->get('fastrac_tariff_request_details', array());
            if (!empty($request_details)) {
                $debug_notices[] = '<details style="margin-left: 10px; margin-top: 5px;">';
                $debug_notices[] = '<summary style="cursor: pointer; color: #3498db;">' . __('Show Request Details', 'fastrac-shipping') . '</summary>';
                $debug_notices[] = '<div style="margin-top: 5px; padding: 5px; background-color: #f9f9f9; border: 1px solid #ddd;">';
                
                foreach ($request_details as $key => $value) {
                    $debug_notices[] = '<div><strong>' . esc_html($key) . '</strong>: ' . esc_html($value) . '</div>';
                }
                
                $debug_notices[] = '</div>';
                $debug_notices[] = '</details>';
            }
        } else {
            $debug_notices[] = '<span style="color: red;">✗ ' . __('No tariff request was made', 'fastrac-shipping') . '</span>';
        }
        $debug_notices[] = '</div>';
        
        // SECTION 5: Tariff API Response/Error
        $debug_notices[] = '<div style="margin-top: 10px; border-left: 3px solid #3498db; padding-left: 10px;">';
        $debug_notices[] = '<strong style="color: #3498db;">' . __('5. Tariff API Response:', 'fastrac-shipping') . '</strong>';
        
        // Show any tariff errors
        if (!empty($tariff_error)) {
            $debug_notices[] = '<span style="color: red;">✗ ' . $tariff_error . '</span>';
        } else if (!empty($tariff_request)) {
            $debug_notices[] = '<span style="color: red;">✗ ' . __('Request sent but no shipping rates were returned', 'fastrac-shipping') . '</span>';
        } else {
            $debug_notices[] = '<span style="color: gray;">' . __('No API response information available', 'fastrac-shipping') . '</span>';
        }
        $debug_notices[] = '</div>';
        
        // SECTION 6: Configuration Check
        $debug_notices[] = '<div style="margin-top: 10px; border-left: 3px solid #3498db; padding-left: 10px;">';
        $debug_notices[] = '<strong style="color: #3498db;">' . __('6. Configuration Check:', 'fastrac-shipping') . '</strong>';
        
        // Add settings information - API credentials
        $debug_notices[] = sprintf(
            __('API Access Key: %s', 'fastrac-shipping'),
            !empty($this->access_key) ? '<span style="color: green;">✓ Set</span>' : '<span style="color: red;">✗ Not Set</span>'
        );
        
        $debug_notices[] = sprintf(
            __('API Secret Key: %s', 'fastrac-shipping'),
            !empty($this->secret_key) ? '<span style="color: green;">✓ Set</span>' : '<span style="color: red;">✗ Not Set</span>'
        );
        
        // Add origin ID information
        $debug_notices[] = sprintf(
            __('Origin ID: %s', 'fastrac-shipping'),
            !empty($this->origin_id) ? '<span style="color: green;">✓ Set (' . esc_html($this->origin_id) . ')</span>' : '<span style="color: red;">✗ Not Set</span>'
        );
        $debug_notices[] = '</div>';
        
        // SECTION 7: Debug Log
        $debug_notices[] = '<div style="margin-top: 10px; border-left: 3px solid #3498db; padding-left: 10px;">';
        $debug_notices[] = '<strong style="color: #3498db;">' . __('7. Debug Log:', 'fastrac-shipping') . '</strong>';
        
        // Add a note about debug log
        $debug_notices[] = __('Full details in log file: wp-content/uploads/wc-logs/fastrac-shipping-debug.log', 'fastrac-shipping');
        $debug_notices[] = '</div>';
        
        // Close the debug info container
        $debug_notices[] = '</div>';
        
        // Display all debug notices
        foreach ($debug_notices as $notice) {
            wc_print_notice($notice, 'notice');
        }
        
        // Clear debug messages after displaying
        WC()->session->set('fastrac_debug_message', '');
        WC()->session->set('fastrac_shipping_info', '');
        WC()->session->set('fastrac_tariff_request', '');
        WC()->session->set('fastrac_tariff_error', '');
        WC()->session->set('fastrac_region_search', '');
        WC()->session->set('fastrac_region_found', '');
        WC()->session->set('fastrac_region_search_error', '');
        WC()->session->set('fastrac_match_type', '');
        WC()->session->set('fastrac_api_request_info', array());
        WC()->session->set('fastrac_api_response_info', array());
        WC()->session->set('fastrac_api_response_info_json', array());
        WC()->session->set('fastrac_api_response_info_form', array());
        WC()->session->set('fastrac_regions_found', array());
        WC()->session->set('fastrac_raw_response', '');
        WC()->session->set('fastrac_raw_response_json', '');
        WC()->session->set('fastrac_raw_response_form', '');
        WC()->session->set('fastrac_critical_error', '');
        WC()->session->set('fastrac_api_success_format', '');
        WC()->session->set('fastrac_first_region', array());
        WC()->session->set('fastrac_all_regions', array());
        WC()->session->set('fastrac_available_fields', '');
        WC()->session->set('fastrac_found_subdistrict', array());
        WC()->session->set('fastrac_detected_fields', array());
        
        if (empty($notices)) {
            return;
        }
        
        foreach ($notices as $type => $message) {
            wc_print_notice($message, 'error');
        }
        
        // Clear notices after displaying
        WC()->session->set('fastrac_shipping_notices', array());
    }
    
    
    /**
     * Show admin debug notice
     * 
     * @access public
     * @return void
     */
    public function show_admin_debug_notice() {
        // Only show on WooCommerce settings pages
        $screen = get_current_screen();
        if (!$screen || !strpos($screen->id, 'woocommerce')) {
            return;
        }
        
        $has_access_key = !empty($this->access_key);
        $has_secret_key = !empty($this->secret_key);
        $has_origin_id = !empty($this->origin_id);
        
        $message = '<h3>' . __('Fastrac Shipping Debug Status', 'fastrac-shipping') . '</h3>';
        $message .= '<ul>';
        $message .= '<li>' . sprintf(
            __('API Access Key: %s', 'fastrac-shipping'),
            $has_access_key ? '<span style="color:green;">✓ ' . __('Set', 'fastrac-shipping') . '</span>' : '<span style="color:red;">✗ ' . __('Not Set', 'fastrac-shipping') . '</span>'
        ) . '</li>';
        $message .= '<li>' . sprintf(
            __('API Secret Key: %s', 'fastrac-shipping'),
            $has_secret_key ? '<span style="color:green;">✓ ' . __('Set', 'fastrac-shipping') . '</span>' : '<span style="color:red;">✗ ' . __('Not Set', 'fastrac-shipping') . '</span>'
        ) . '</li>';
        $message .= '<li>' . sprintf(
            __('Origin ID: %s', 'fastrac-shipping'),
            $has_origin_id ? '<span style="color:green;">✓ ' . __('Set', 'fastrac-shipping') . ' (' . esc_html($this->origin_id) . ')</span>' : '<span style="color:red;">✗ ' . __('Not Set', 'fastrac-shipping') . '</span>'
        ) . '</li>';
        $message .= '<li>' . sprintf(
            __('Debug Mode: %s', 'fastrac-shipping'),
            $this->debug ? '<span style="color:green;">✓ ' . __('Enabled', 'fastrac-shipping') . '</span>' : '<span style="color:orange;">✗ ' . __('Disabled', 'fastrac-shipping') . '</span>'
        ) . '</li>';
        $store_postcode = get_option('woocommerce_store_postcode');
        $message .= '<li>' . sprintf(
            __('Store Postcode: %s', 'fastrac-shipping'),
            !empty($store_postcode) ? '<span style="color:green;">✓ ' . esc_html($store_postcode) . '</span>' : '<span style="color:red;">✗ ' . __('Not Set in WooCommerce Settings', 'fastrac-shipping') . '</span>'
        ) . '</li>';
        $message .= '</ul>';
        
        echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
    }
    
    /**
     * Verify saved settings
     * 
     * @access public
     * @return void
     */
    public function verify_saved_settings() {
        $message = __('Fastrac Shipping settings updated. Please check the debug status above to verify your configuration.', 'fastrac-shipping');
        WC_Admin_Settings::add_message($message);
    }

    /**
     * Validate settings fields
     *
     * @access public
     * @param array $key Field key
     * @param string $value Field value
     * @return string
     */
    public function validate_text_field($key, $value) {
        if ('access_key' === $key && empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('Access Key is required for Fastrac Shipping.', 'fastrac-shipping'));
        }
        
        return $value;
    }

    /**
     * Validate password field
     *
     * @access public
     * @param array $key Field key
     * @param string $value Field value
     * @return string
     */
    public function validate_password_field($key, $value) {
        if ('secret_key' === $key && empty($value)) {
            WC_Admin_Settings::add_error(esc_html__('Secret Key is required for Fastrac Shipping.', 'fastrac-shipping'));
        }
        
        return $value;
    }

    /**
     * Get origin ID from store postcode
     *
     * @access private
     * @return string|bool Origin ID or false on failure
     */
    private function get_store_origin_id() {
        // Check transient first
        $origin_id = get_transient('fastrac_store_origin_id');
        if ($origin_id !== false) {
            if (isset($this->logger)) {
                $this->logger->log("Using cached origin ID: " . $origin_id, 'info');
            }
            return $origin_id;
        }

        // Get store postcode from WooCommerce settings
        $store_postcode = get_option('woocommerce_store_postcode');
        if (empty($store_postcode)) {
            if (isset($this->logger)) {
                $this->logger->log_error("Store postcode not set in WooCommerce settings");
            }
            $this->logger->log_error('Store postcode not set in WooCommerce settings');
            return false;
        }

        if (isset($this->logger)) {
            $this->logger->log("Getting origin ID for store postcode: " . $store_postcode, 'info');
        }

        // Get region ID for store postcode
        $origin_id = $this->get_region_id_from_postcode($store_postcode);
        if ($origin_id) {
            // Cache the origin ID for 24 hours
            set_transient('fastrac_store_origin_id', $origin_id, DAY_IN_SECONDS);
            if (isset($this->logger)) {
                $this->logger->log("Cached origin ID: " . $origin_id, 'info');
            }
            return $origin_id;
        }

        if (isset($this->logger)) {
            $this->logger->log_error("Could not get origin ID for store postcode: " . $store_postcode);
        }
        $this->logger->log_error('Could not get origin ID for store postcode: ' . $store_postcode);
        return false;
    }
}

