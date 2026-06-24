<?php
/**
 * WCS_ATT_PayPal_Compatibility class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 5.0.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PayPal Compatibility.
 *
 * @version 5.0.5
 */
class WCS_ATT_PayPal_Compatibility {

	// Hide smart buttons in product pages when products have Subscription plans.
	public static function init() {
		add_filter( 'woocommerce_paypal_payments_product_supports_payment_request_button', array( __CLASS__, 'handle_smart_buttons' ), 10, 2 );
	}

	/**
	 * Hide smart buttons in product pages when the product has any Subscription plans.
	 *
	 * @param  bool       $is_supported
	 * @param  WC_Product $product
	 *
	 * @return bool
	 */
	public static function handle_smart_buttons( $is_supported, $product ) {
		// If the smart button is not supported by some other plugin, respect that.
		if ( ! $is_supported ) {
			return $is_supported;
		}

		if ( ! $product ) {
			return $is_supported;
		}

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			return false;
		}

		return $is_supported;
	}
}
