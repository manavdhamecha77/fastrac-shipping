/**
 * Fastrac Shipping Checkout JavaScript
 * 
 * Handles updating shipping rates when postcode changes in checkout
 */
jQuery(function($) {
    'use strict';

    // Debug logging helper
    // Always log to console for debugging
    const debugLog = function(message) {
        console.log('Fastrac Shipping: ' + message);
    };
    
    // Log script initialization immediately
    console.log('Fastrac Shipping: Checkout JS file loaded');
    
    // Check if we have access to the fastracShipping object
    if (typeof fastracShipping !== 'undefined') {
        console.log('Fastrac Shipping: Settings available', fastracShipping);
    } else {
        console.error('Fastrac Shipping: ERROR - Settings not available');
    }

    // Initialize
    const initFastracShipping = function() {
        debugLog('Initializing Fastrac Shipping checkout JS');

        // Selectors for postcode fields
        const shippingPostcodeField = '#shipping_postcode';
        const billingPostcodeField = '#billing_postcode';
        const shipToDifferentAddressCheckbox = '#ship-to-different-address-checkbox';

        // Debounce function to prevent multiple rapid requests
        let postcodeUpdateTimeout = null;
        const debouncePostcodeUpdate = function(callback, delay = 500) {
            if (postcodeUpdateTimeout) {
                clearTimeout(postcodeUpdateTimeout);
            }
            postcodeUpdateTimeout = setTimeout(callback, delay);
        };

        // Function to trigger shipping calculation
        const updateShipping = function() {
            debugLog('Triggering shipping update...');
            
            // Add a loading indicator
            $('form.checkout').block({
                message: null,
                overlayCSS: {
                    background: '#fff',
                    opacity: 0.6
                }
            });
            
            // Log checkout form data for debugging
            debugLog('Current checkout form data:');
            let formData = {};
            $.each($('form.checkout').serializeArray(), function(i, field) {
                formData[field.name] = field.value;
            });
            console.log('Checkout form data:', formData);
            
            // Check if postcode is in form data
            const billingPostcode = formData['billing_postcode'] || '';
            const shippingPostcode = formData['shipping_postcode'] || '';
            console.log(`Billing postcode: "${billingPostcode}", Shipping postcode: "${shippingPostcode}"`);
            
            // Trigger update of checkout
            $(document.body).trigger('update_checkout');
            
            debugLog('Shipping update triggered');
        };

        // Function to handle postcode changes
        const handlePostcodeChange = function(e) {
            const field = $(this);
            const postcode = field.val().trim();
            
            debugLog('Postcode changed: ' + postcode);
            
            // Only process if postcode is not empty
            if (postcode.length > 0) {
                debugLog('Valid postcode entered, updating shipping in 500ms...');
                
                // Debounce to prevent multiple rapid requests
                debouncePostcodeUpdate(function() {
                    updateShipping();
                }, 500);
            }
        };

        // Determine which postcode field to use based on shipping address choice
        const getActivePostcodeField = function() {
            // If shipping to different address is checked, use shipping postcode
            if ($(shipToDifferentAddressCheckbox).is(':checked')) {
                return shippingPostcodeField;
            }
            // Otherwise use billing postcode
            return billingPostcodeField;
        };

        // Attach event listeners to both postcode fields
        $(document).on('change', billingPostcodeField, handlePostcodeChange);
        $(document).on('change', shippingPostcodeField, handlePostcodeChange);
        
        // Also listen for keyup events with delay
        $(document).on('keyup', billingPostcodeField, function() {
            debouncePostcodeUpdate(function() {
                $(billingPostcodeField).trigger('change');
            }, 800);
        });
        
        $(document).on('keyup', shippingPostcodeField, function() {
            debouncePostcodeUpdate(function() {
                $(shippingPostcodeField).trigger('change');
            }, 800);
        });

        // Listen for shipping address toggle changes
        $(document).on('change', shipToDifferentAddressCheckbox, function() {
            debugLog('Shipping address option changed, updating shipping...');
            updateShipping();
        });

        // Force update when country or state changes
        $(document).on('change', '#billing_country, #shipping_country, #billing_state, #shipping_state', function() {
            debugLog('Country or state changed, updating shipping...');
            // Small delay to ensure other fields are properly updated
            setTimeout(updateShipping, 100);
        });

        // Also update on page load if postcode is already filled
        $(window).on('load', function() {
            const activeField = getActivePostcodeField();
            const postcodeValue = $(activeField).val();
            
            if (postcodeValue && postcodeValue.trim().length > 0) {
                debugLog('Postcode found on page load: ' + postcodeValue);
                updateShipping();
            }
        });

        // Add helper text below postcode fields
        const helperText = '<small class="fastrac-helper-text">Enter postcode to see shipping rates</small>';
        $(billingPostcodeField).after(helperText);
        $(shippingPostcodeField).after(helperText);

        // Update when checkout is updated (to show any errors)
        $(document.body).on('updated_checkout', function() {
            debugLog('Checkout updated, checking for shipping errors...');
            
            // Display any errors in a more visible way
            if ($('.woocommerce-error').length) {
                debugLog('Errors found in checkout');
            }
        });

        debugLog('Fastrac Shipping checkout JS initialized');
    };

    // Initialize on document ready
    initFastracShipping();

    // Re-initialize when checkout is updated
    $(document.body).on('updated_checkout', function() {
        debugLog('Checkout updated, reinitializing event handlers');
        initFastracShipping();
    });
});
