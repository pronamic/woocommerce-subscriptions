<?php
/**
 * WCS_ATT_Integration_Square class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 3.1.27
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Square integration.
 *
 * @version  3.1.27
 */
class WCS_ATT_Integration_Square {

	public static function init() {
		// Hide Square Digital Wallet buttons from product, cart and checkout for products with Subscription plans.
		add_filter( 'wc_square_display_digital_wallet_on_pages', array( __CLASS__, 'hide_square_digital_wallet_buttons' ), 10, 2 );
	}

	/**
	 * Hide Square Digital Wallet buttons from product, cart and checkout for products with Subscription plans.
	 *
	 * @param  array                                     $available_pages
	 * @param  WooCommerce\Square\Gateway\Digital_Wallet $wallet
	 * @return array
	 */
	public static function hide_square_digital_wallet_buttons( $available_pages, $wallet ) {

		if ( is_product() ) {
			// Get the currently viewed product. Used "wc_get_product" as global $product" returns only the product title here.
			$product_id = get_the_id();
			$product    = wc_get_product( $product_id );

			if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
				$available_pages = array();
			}
		} elseif ( is_cart() || is_checkout() ) {

			$cart_contents = WC()->cart->cart_contents;

			foreach ( $cart_contents as $cart_item ) {

				if ( ! empty( $cart_item['wcsatt_data']['active_subscription_scheme'] ) ) {
					$available_pages = array();
					break;
				}
			}
		}
		return $available_pages;
	}
}
