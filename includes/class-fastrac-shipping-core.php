<?php
/**
 * Fastrac Shipping Core Functionality
 * 
 * This file contains the core functions for Fastrac Shipping rate calculations.
 * 
 * @package Fastrac_Shipping
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Fastrac_Shipping_Core class.
 * 
 * Contains the core functions for getting shipping rates from Fastrac API.
 */
class Fastrac_Shipping_Core {
    /**
     * API access key
     *
     * @var string
     */
    private $access_key;
    
    /**
     * API secret key
     *
     * @var string
     */
    private $secret_key;
    
    /**
     * Origin ID (subdistrict ID)
     *
     * @var string
     */
    private $origin_id;
    
    /**
     * Debug mode
     *
     * @var bool
     */
    private $debug;
    
    /**
     * API Endpoint for searching regions
     *
     * @var string
     */
    private $api_search_region_endpoint = 'https://b2b-api-stg.fastrac.id/apiRegion/search-region';
    
    /**
     * API Endpoint for tariff calculation
     *
     * @var string
     */
    private $api_tariff_endpoint = 'https://b2b-api-stg.fastrac.id/apiTariff/tariffExpress';
    
    /**
     * Constructor
     *
     * @param string $access_key API access key
     * @param string $secret_key API secret key
     * @param string $origin_id Origin subdistrict ID
     * @param bool $debug Debug mode
     */
    public function __construct($access_key, $secret_key, $origin_id, $debug = false) {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->origin_id = $origin_id;
        $this->debug = $debug;
    }
    
    /**
     * Get destination ID from postcode
     * 
     * @param string $postcode Destination postcode
     * @return string|bool Destination ID or false on failure
     */
    public function get_destination_id($postcode) {
        // Basic validation
        $postcode = trim($postcode);
        if (empty($postcode)) {
            $this->log_debug("Empty postcode provided");
            return false;
        }
        
        // Make API call to search region by postcode
        $url = $this->api_search_region_endpoint;
        
        $args = array(
            'method'  => 'POST',
            'timeout' => 30,
            'headers' => array(
                'access-key' => $this->access_key,
                'secret-key' => $this->secret_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'search' => $postcode,
                'limit'  => 10,
                'offset' => 0
            )),
        );
        
        $this->log_debug("Searching region for postcode: " . $postcode);
        $response = wp_remote_post($url, $args);
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->log_debug("API request error: " . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Check response validity
        if (empty($data) || !isset($data['success']) || !$data['success']) {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->log_debug("API error: " . $error_message);
            return false;
        }
        
        // Check if regions were found
        if (empty($data['data'])) {
            $this->log_debug("No regions found for postcode: " . $postcode);
            return false;
        }
        
        // Look for matching region
        foreach ($data['data'] as $region) {
            // Check if required field exists
            if (isset($region['subdistrict_id'])) {
                $this->log_debug("Found subdistrict ID: " . $region['subdistrict_id']);
                return (string) $region['subdistrict_id'];
            }
        }
        
        $this->log_debug("No usable subdistrict found for postcode: " . $postcode);
        return false;
    }
    
    /**
     * Calculate shipping rates
     * 
     * @param float $weight Weight in kg
     * @param float $length Length in cm
     * @param float $width Width in cm
     * @param float $height Height in cm
     * @param string $destination_id Destination subdistrict ID
     * @return array|bool Array of shipping rates or false on failure
     */
    public function calculate_shipping_rates($weight, $length, $width, $height, $destination_id) {
        // Validate inputs
        if (empty($this->origin_id) || empty($destination_id)) {
            $this->log_debug("Missing origin or destination ID");
            return false;
        }
        
        if ($weight <= 0 || $length <= 0 || $width <= 0 || $height <= 0) {
            $this->log_debug("Invalid dimensions or weight");
            return false;
        }
        
        // Convert weight from kg to grams (API expects weight in grams)
        $weight_in_grams = $weight * 1000;
        
        // Prepare API request
        $url = $this->api_tariff_endpoint;
        
        $request_body = array(
            'origin' => (int) $this->origin_id,
            'destination' => (int) $destination_id,
            'weight' => (int) $weight_in_grams,
            'length' => (int) $length,
            'width' => (int) $width,
            'height' => (int) $height,
        );
        
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
        
        $this->log_debug("Calculating tariff with data: " . print_r($request_body, true));
        
        // Add timeout handling
        $start_time = microtime(true);
        $response = wp_remote_post($url, $args);
        $request_time = microtime(true) - $start_time;
        $this->log_debug(sprintf("API request completed in %.2f seconds", $request_time));
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->log_debug("API request error: " . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Check response validity
        if (empty($data) || !isset($data['success']) || !$data['success']) {
            $error_message = isset($data['message']) ? $data['message'] : 'Unknown error';
            $this->log_debug("API error: " . $error_message);
            return false;
        }
        
        // Check if rates were found
        if (empty($data['data'])) {
            $this->log_debug("No shipping rates found in API response");
            return false;
        }
        
        // Process shipping rates
        $shipping_rates = array();
        $shipping_categories = array(
            'nextday' => 'Next Day',
            'regular' => 'Regular',
            'sameday' => 'Same Day',
            'cargo' => 'Cargo',
            // Add support for other potential service types returned by the API
            'economy' => 'Economy',
            'express' => 'Express'
        );
        
        // Process each shipping type
        foreach ($shipping_categories as $category_key => $category_label) {
            if (!isset($data['data'][$category_key]) || empty($data['data'][$category_key])) {
                continue;
            }
            
            $rates = $data['data'][$category_key];
            
            foreach ($rates as $rate_data) {
                // Skip if required data is missing
                if (!isset($rate_data['total']) || !isset($rate_data['courier_name']) || 
                    !isset($rate_data['service']) || !isset($rate_data['etd'])) {
                    continue;
                }
                
                // Create rate entry
                $shipping_rates[] = array(
                    'id' => sanitize_title($category_key . '_' . $rate_data['courier_name'] . '_' . $rate_data['service']),
                    'label' => sprintf('%s - %s (%s)', $rate_data['courier_name'], $rate_data['service'], $rate_data['etd']),
                    'cost' => (float) $rate_data['total'],
                    'meta_data' => array(
                        'courier' => $rate_data['courier_name'],
                        'service' => $rate_data['service'],
                        'etd' => $rate_data['etd'],
                        'category' => $category_label,
                    ),
                );
            }
        }
        
        $this->log_debug("Found " . count($shipping_rates) . " shipping rates");
        return $shipping_rates;
    }
    
    /**
     * Simple debug logging
     * 
     * @param string $message Message to log
     * @return void
     */
    private function log_debug($message) {
        if ($this->debug) {
            error_log('Fastrac Shipping: ' . $message);
        }
    }
}

