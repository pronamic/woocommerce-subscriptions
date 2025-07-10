<?php
/**
 * WooCommerce Subscriptions Conditional Functions
 *
 * Functions for determining the current state of the query/page/session.
 *
 * @author      Prospress
 * @category    Core
 * @package     WooCommerce Subscriptions/Functions
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the $_SERVER global has order received URL slug in its 'REQUEST_URI' value
 *
 * Similar to WooCommerce's is_order_received_page(), but can be used before the $wp's query vars are setup, which is essential
 * in some cases, like WC_Subscriptions_Product::is_purchasable() and WC_Product_Subscription_Variation::is_purchasable(), both
 * called within WC_Cart::get_cart_from_session(), which is run before query vars are setup.
 *
 * @return bool
 **/
function wcs_is_order_received_page() {
	// @phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( $_SERVER['REQUEST_URI'] ) : '';

	return ( false !== strpos( $request_uri, 'order-received' ) );
}
