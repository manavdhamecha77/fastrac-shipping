<?php
/**
 * Fastrac Shipping Logger Class
 *
 * @package Fastrac_Shipping
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Fastrac_Shipping_Logger class.
 *
 * Handles logging for Fastrac Shipping
 */
class Fastrac_Shipping_Logger {

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    private $logger;

    /**
     * Log enabled
     *
     * @var bool
     */
    private $enabled;

    /**
     * Log context
     *
     * @var array
     */
    private $context;

    /**
     * Constructor
     *
     * @param bool $enabled Whether logging is enabled
     */
    public function __construct($enabled = false) {
        $this->enabled = $enabled;
        $this->context = array('source' => 'fastrac-shipping');
        
        // Always create the logger, but only log if enabled
        $this->logger = wc_get_logger();
        
        // Log initialization if enabled
        if ($this->enabled) {
            $this->log('Fastrac Shipping logger initialized', 'info');
            
            // Create a debug log file marker
            $upload_dir = wp_upload_dir();
            $log_dir = $upload_dir['basedir'] . '/wc-logs/';
            $log_file = $log_dir . 'fastrac-shipping-debug.log';
            
            if (!file_exists($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            
            // Create or append to the log file
            $timestamp = current_time('mysql');
            $message = "=== Fastrac Shipping Debug Log Started at {$timestamp} ===\n";
            
            // Using error_log to write directly to the file as a backup
            error_log($message, 3, $log_file);
        }
    }

    /**
     * Log message
     *
     * @param string $message Message to log
     * @param string $level Log level (debug, info, notice, warning, error)
     * @return void
     */
    public function log($message, $level = 'debug') {
        // Always log critical errors, otherwise respect enabled setting
        if (!$this->enabled && $level !== 'emergency' && $level !== 'critical') {
            return;
        }

        if (!$this->logger) {
            return;
        }

        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        // Log to WC logger
        $this->logger->log($level, $message, $this->context);
        
        // Also log to a dedicated file for easier debugging
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/wc-logs/fastrac-shipping-debug.log';
        
        $timestamp = current_time('mysql');
        $formatted_message = "[{$timestamp}] [{$level}] {$message}\n";
        
        // Using error_log to write directly to the file
        error_log($formatted_message, 3, $log_file);
    }

    /**
     * Log API request
     *
     * @param string $endpoint API endpoint
     * @param array $args Request arguments
     * @return void
     */
    public function log_api_request($endpoint, $args) {
        if (!$this->enabled) {
            return;
        }

        $message = sprintf('API Request to: %s', $endpoint);
        $this->log($message, 'info');

        // Log request arguments (exclude sensitive data)
        $safe_args = $args;
        
        // Create a detailed breakdown of the request
        $details = array(
            'Endpoint' => $endpoint,
            'Method' => isset($args['method']) ? $args['method'] : 'GET',
            'Timeout' => isset($args['timeout']) ? $args['timeout'] . ' seconds' : 'default',
        );
        
        if (isset($safe_args['headers'])) {
            // Hide sensitive header values
            foreach ($safe_args['headers'] as $key => $value) {
                if (in_array(strtolower($key), array('access-key', 'secret-key'))) {
                    $safe_args['headers'][$key] = 'REDACTED';
                } else {
                    $details['Header: ' . $key] = $value;
                }
            }
        }
        
        // Log body if it exists
        if (isset($safe_args['body'])) {
            if (is_array($safe_args['body'])) {
                $details['Body'] = print_r($safe_args['body'], true);
            } elseif (is_string($safe_args['body'])) {
                // Try to decode JSON
                $json_body = json_decode($safe_args['body'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $details['Body (JSON)'] = print_r($json_body, true);
                } else {
                    $details['Body'] = $safe_args['body'];
                }
            }
        }
        
        // Log detailed request information
        $this->log('API Request Details: ' . print_r($details, true), 'debug');
        
        // Also log the raw request for troubleshooting
        $this->log('Full Request Arguments: ' . print_r($safe_args, true), 'debug');
    }

    /**
     * Log API response
     *
     * @param mixed $response API response
     * @param bool $is_error Whether the response is an error
     * @return void
     */
    public function log_api_response($response, $is_error = false) {
        if (!$this->enabled) {
            return;
        }
        
        $level = $is_error ? 'error' : 'info';
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_data = $response->get_error_data();
            $this->log('API Response Error: ' . $error_message, 'error');
            if ($error_data) {
                $this->log('API Response Error Data: ' . print_r($error_data, true), 'error');
            }
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $body = wp_remote_retrieve_body($response);
        
        // Create a detailed breakdown of the response
        $details = array(
            'Status Code' => $status_code,
            'Response Time' => current_time('mysql'),
        );
        
        // Add headers (if available)
        if (!empty($headers)) {
            $details['Headers'] = print_r($headers, true);
        }
        
        $this->log(sprintf('API Response Status: %s', $status_code), $level);
        
        // Log if response is empty
        if (empty($body)) {
            $this->log('API Response Body is empty', 'warning');
            return;
        }
        
        // Try to decode JSON response
        $json_body = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $details['Body (JSON)'] = print_r($json_body, true);
            $this->log('API Response Body (JSON): ' . print_r($json_body, true), $level);
            
            // Check for API errors in response
            if (isset($json_body['success']) && $json_body['success'] === false) {
                $error_msg = isset($json_body['message']) ? $json_body['message'] : 'Unknown API error';
                $this->log('API Error in Response: ' . $error_msg, 'error');
            }
        } else {
            $details['Body'] = $body;
            $this->log('API Response Body (not JSON): ' . $body, $level);
        }
        
        // Log detailed response information
        $this->log('API Response Details: ' . print_r($details, true), 'debug');
    }

    /**
     * Log shipping calculation
     *
     * @param array $package Shipping package
     * @param array $rate Calculated shipping rate
     * @return void
     */
    public function log_shipping_calculation($package, $rate = null) {
        if (!$this->enabled) {
            return;
        }
        
        $this->log('Shipping Package: ' . print_r($package, true), 'debug');
        
        if ($rate) {
            $this->log('Calculated Rate: ' . print_r($rate, true), 'info');
        }
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @return void
     */
    public function log_error($message) {
        if (!$this->enabled) {
            return;
        }
        
        $this->log($message, 'error');
    }
}

