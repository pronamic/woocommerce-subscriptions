<?php
/**
 * WooCommerce Subscriptions Switch Functions
 *
 * @author 		Prospress
 * @category 	Core
 * @package 	WooCommerce Subscriptions/Functions
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Check if a given order was to switch a subscription
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 2.0
 */
function wcs_order_contains_switch( $order ) {

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}

	if ( ! wcs_is_order( $order ) || wcs_order_contains_renewal( $order ) ) {

		$is_switch_order = false;

	} else {

		$switched_subscriptions = wcs_get_subscriptions_for_switch_order( $order );

		if ( ! empty( $switched_subscriptions ) ) {
			$is_switch_order = true;
		} else {
			$is_switch_order = false;
		}
	}

	return apply_filters( 'woocommerce_subscriptions_is_switch_order', $is_switch_order, $order );
}

/**
 * Get the subscriptions that had an item switch for a given order (if any).
 *
 * @param int|WC_Order $order_id The post_id of a shop_order post or an instance of a WC_Order object
 * @return array Subscription details in post_id => WC_Subscription form.
 * @since  2.0
 */
function wcs_get_subscriptions_for_switch_order( $order ) {

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}

	$subscriptions    = array();
	$subscription_ids = wcs_get_objects_property( $order, 'subscription_switch', 'multiple' );

	foreach ( $subscription_ids as $subscription_id ) {

		$subscription = wcs_get_subscription( $subscription_id );

		if ( $subscription ) {
			$subscriptions[ $subscription_id ] = $subscription;
		}
	}

	return $subscriptions;
}

/**
 * Get all the orders which have recorded a switch for a given subscription.
 *
 * @param int|WC_Subscription $subscription_id The post_id of a shop_subscription post or an instance of a WC_Subscription object
 * @return array Order details in post_id => WC_Order form.
 * @since  2.0
 */
function wcs_get_switch_orders_for_subscription( $subscription_id ) {

	$orders = array();

	// Select the orders which switched item/s from this subscription
	$order_ids = get_posts( array(
		'post_type'      => 'shop_order',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => -1,
		'meta_query' => array(
			array(
				'key'   => '_subscription_switch',
				'value' => $subscription_id,
			),
		),
	) );

	foreach ( $order_ids as $order_id ) {
		$orders[ $order_id ] = wc_get_order( $order_id );
	}

	return $orders;
}

/**
 * Checks if a given product is of a switchable type
 *
 * @param int|WC_Product $product A WC_Product object or the ID of a product to check
 * @return bool
 * @since  2.0
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
		$parent_id = wcs_get_objects_property( $product, 'parent_id' );

		if ( $product->is_type( 'subscription_variation' ) && ! empty( $parent_id ) ) {
			$variation = $product;
			$product   = wc_get_product( $parent_id );;
		}

		$allow_switching = get_option( WC_Subscriptions_Admin::$option_prefix . '_allow_switching', 'no' );

		switch ( $allow_switching ) {
			case 'variable' :
				$is_product_switchable = ( $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) ) ? true : false;
				break;
			case 'grouped' :
				$is_product_switchable = ( WC_Subscriptions_Product::get_parent_ids( $product ) ) ? true : false;
				break;
			case 'variable_grouped' :
				$is_product_switchable = ( $product->is_type( array( 'variable-subscription', 'subscription_variation' ) ) || WC_Subscriptions_Product::get_parent_ids( $product ) ) ? true : false;
				break;
			case 'no' :
			default:
				$is_product_switchable = false;
				break;
		}
	}

	return apply_filters( 'wcs_is_product_switchable', $is_product_switchable, $product, $variation );
}
