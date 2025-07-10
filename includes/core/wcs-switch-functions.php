<?php
/**
 * WooCommerce Subscriptions Switch Functions
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Check if a given order was to switch a subscription
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_order_contains_switch( $order ) {
	$is_switch_order = false;

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}

	if ( $order ) {
		$related_subscription_ids = wcs_get_subscription_ids_for_order( $order, 'switch' );
		$is_switch_order          = ! empty( $related_subscription_ids );
	}

	return apply_filters( 'woocommerce_subscriptions_is_switch_order', $is_switch_order, $order );
}

/**
 * Get the subscriptions that had an item switch for a given order (if any).
 *
 * @param int|WC_Order $order The post_id of a shop_order post or an instance of a WC_Order object
 * @return array Subscription details in post_id => WC_Subscription form.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscriptions_for_switch_order( $order ) {
	return wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'switch' ) );
}

/**
 * Get all the orders which have recorded a switch for a given subscription.
 *
 * @param int|WC_Subscription $subscription_id The post_id of a shop_subscription post or an instance of a WC_Subscription object
 * @return array Order details in post_id => WC_Order form.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_switch_orders_for_subscription( $subscription_id ) {
	$subscription = wcs_get_subscription( $subscription_id );
	return $subscription->get_related_orders( 'all', 'switch' );
}

/**
 * Checks if a given product is of a switchable type
 *
 * @param int|WC_Product $product A WC_Product object or the ID of a product to check
 * @return bool
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_is_product_switchable_type( $product ) {

	if ( ! is_object( $product ) ) {
		$product = wc_get_product( $product );
	}

	$variation = null;

	if ( empty( $product ) ) {

		$is_product_switchable = false;

	} else {

		// back compat for parent products
		if ( $product->is_type( 'subscription_variation' ) && $product->get_parent_id() ) {
			$variation = $product;
			$product   = wc_get_product( $product->get_parent_id() );
		}

		$allow_switching = get_option( WC_Subscriptions_Admin::$option_prefix . '_allow_switching', 'no' );

		switch ( $allow_switching ) {
			case 'variable':
				$is_product_switchable = $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) && 'publish' === wcs_get_objects_property( $product, 'post_status' );
				break;
			case 'grouped':
				$is_product_switchable = (bool) WC_Subscriptions_Product::get_visible_grouped_parent_product_ids( $product );
				break;
			case 'variable_grouped':
				$is_product_switchable = ( $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) && 'publish' === wcs_get_objects_property( $product, 'post_status' ) ) || WC_Subscriptions_Product::get_visible_grouped_parent_product_ids( $product );
				break;
			case 'no':
			default:
				$is_product_switchable = false;
				break;
		}
	}

	return apply_filters( 'wcs_is_product_switchable', $is_product_switchable, $product, $variation );
}

/**
 * Check if the cart includes any items which are to switch an existing subscription's contents.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 * @param string $item_action Types of items to include ("any", "switch", or "add").
 * @return bool|array Returns cart items that modify subscription contents, or false if no such items exist.
 */
function wcs_cart_contains_switches( $item_action = 'any' ) {
	return class_exists( 'WC_Subscriptions_Switcher' ) ? WC_Subscriptions_Switcher::cart_contains_switches( $item_action ) : false;
}

/**
 * Gets the switch direction of a cart item.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 * @param array $cart_item Cart item object.
 * @return string|null Cart item subscription switch direction or null.
 */
function wcs_get_cart_item_switch_type( $cart_item ) {
	return class_exists( 'WC_Subscriptions_Switcher' ) ? WC_Subscriptions_Switcher::get_cart_item_switch_type( $cart_item ) : null;
}
