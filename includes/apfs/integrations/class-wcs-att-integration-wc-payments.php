<?php
/**
 * WCS_ATT_Intgeration_WC_Payments class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 3.2.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooPayments Integration.
 *
 * @version  5.0.5
 */
class WCS_ATT_Intgeration_WC_Payments {

	// Hide quick-pay buttons in product pages with Subscription plans.
	public static function init() {
		add_filter( 'wcpay_payment_request_is_product_supported', array( __CLASS__, 'handle_quick_pay_buttons' ), 10, 2 );
		add_filter( 'wcpay_woopay_button_is_product_supported', array( __CLASS__, 'handle_quick_pay_buttons' ), 10, 2 );
	}

	/**
	 * Hide quick-pay buttons in product pages with Subscription plans.
	 *
	 * @param  bool       $is_supported
	 * @param  WC_Product $product
	 * @return bool
	 */
	public static function handle_quick_pay_buttons( $is_supported, $product ) {

		// If the express checkout button is not supported by some other plugin, respect that.
		if ( ! $is_supported ) {
			return $is_supported;
		}

		if ( ! $product ) {
			return $is_supported;
		}

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			$is_supported = false;
		}

		return $is_supported;
	}
}
