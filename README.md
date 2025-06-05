# Fastrac Shipping for WooCommerce

## Implementation Status

The implementation of the Fastrac Shipping integration is complete. The code will:

1. Automatically detect when a postcode is entered in the checkout page
2. Query the Fastrac API to find the destination ID for that postcode
3. Calculate shipping rates based on the cart contents and display them to the customer

## Configuration Required

For the shipping rates to display correctly, you must configure the following in WooCommerce:

1. Go to WooCommerce > Settings > Shipping > Shipping Zones
2. Add a new shipping zone or edit an existing one
3. Add "Fastrac Shipping" as a shipping method
4. Configure it with your API credentials:
   - **Access Key** - Your Fastrac API access key
   - **Secret Key** - Your Fastrac API secret key
   - **Origin ID** - The subdistrict ID of your shipping origin

## Testing

Once configured, test the integration by:

1. Adding a product to your cart
2. Going to the checkout page
3. Entering a valid postcode in the shipping address
4. Verifying that shipping rates appear automatically

## Troubleshooting

If shipping rates do not appear:

1. Verify your API credentials are correct
2. Check that your Origin ID is valid
3. Make sure the postcode entered is valid for Fastrac's system
4. Enable debug mode in the shipping method settings for detailed logging

## API Requirements

The Fastrac API requires:
- Valid API credentials (access_key and secret_key)
- A valid origin_id (your warehouse/store location's subdistrict ID)
- A valid destination_id (obtained from customer's postcode)
- Product dimensions and weight

Without these properly configured, shipping rates cannot be calculated or displayed.

