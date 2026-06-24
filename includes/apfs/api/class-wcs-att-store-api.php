<?php
/**
 * WCS_ATT_Store_API class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 3.3.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * Extends the store public API with bundle related data for each bundle parent and child item.
 *
 * @version 3.3.2
 */
class WCS_ATT_Store_API {

	/**
	 * Bootstraps the class and hooks required data.
	 */
	public static function init() {

		// Validate items in the Store API and add cart errors.
		add_action( 'woocommerce_store_api_validate_cart_item', array( __CLASS__, 'validate_cart_item' ), 10, 2 );

		// Prevent access to the checkout block.
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'validate_draft_order' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Callbacks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Validate cart item in Store API context.
	 *
	 * @throws RouteException
	 *
	 * @param  WC_Product $product
	 * @param  array      $cart_item
	 * @return void
	 */
	public static function validate_cart_item( $product, $cart_item ) {

		$result = WCS_ATT_Cart::validate_applied_subscription_scheme( $cart_item );

		if ( is_wp_error( $result ) ) {
			throw new RouteException( 'woocommerce_store_api_subscription_plan_invalid', $result->get_error_message() );
		}
	}

	/**
	 * Prevents access to the checkout block if a cart item is misconfigured.
	 *
	 * @throws RouteException
	 *
	 * @param  WC_Order $order
	 * @return void
	 */
	public static function validate_draft_order( $order ) {

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
			self::validate_cart_item( $cart_item['data'], $cart_item );
		}
	}
}
