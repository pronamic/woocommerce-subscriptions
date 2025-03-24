<?php
/**
 * WooCommerce Subscriptions Renewal Functions
 *
 * Functions for managing renewal of a subscription.
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Create a renewal order to record a scheduled subscription payment.
 *
 * This method simply creates an order with the same post meta, order items and order item meta as the subscription
 * passed to it.
 *
 * @param  int|WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object
 * @return WC_Order|WP_Error
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_create_renewal_order( $subscription ) {

	$renewal_order = wcs_create_order_from_subscription( $subscription, 'renewal_order' );

	if ( is_wp_error( $renewal_order ) ) {
		do_action( 'wcs_failed_to_create_renewal_order', $renewal_order, $subscription );
		return new WP_Error( 'renewal-order-error', $renewal_order->get_error_message() );
	}

	WCS_Related_Order_Store::instance()->add_relation( $renewal_order, $subscription, 'renewal' );

	/**
	 * Provides an opportunity to monitor, interact with and replace renewal orders when they
	 * are first created.
	 *
	 * @param WC_Order        $renewal_order The renewal order.
	 * @param WC_Subscription $subscription  The subscription the renewal is related to.
	 */
	$filtered_renewal_order = apply_filters( 'wcs_renewal_order_created', $renewal_order, $subscription );

	// It is possible that a filter function will replace the renewal order with something else entirely.
	return $filtered_renewal_order instanceof WC_Order ? $filtered_renewal_order : $renewal_order;
}

/**
 * Check if a given order is a subscription renewal order.
 *
 * @param  WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @return bool Whether the order contains renewal.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_order_contains_renewal( $order ) {
	$is_renewal_order = false;

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}

	if ( $order ) {
		$related_subscription_ids = wcs_get_subscription_ids_for_order( $order, 'renewal' );
		$is_renewal_order         = ! empty( $related_subscription_ids );
	}

	return apply_filters( 'woocommerce_subscriptions_is_renewal_order', $is_renewal_order, $order );
}

/**
 * Determines if a given order is the subscription's latest renewal order.
 *
 * @param $order WC_Order The order object.
 * @param $subscription WC_Subscription The subscription object.
 * @return bool Whether the order is the latest renewal order of the provided subscription.
 */
function wcs_is_order_last_renewal_of_subscription( $order, $subscription ) {
	$last_renewal_order = wcs_get_last_renewal_order( $subscription );
	return $last_renewal_order && $last_renewal_order->get_id() === $order->get_id();
}

/**
 * Checks the cart to see if it contains a subscription product renewal.
 *
 * @return bool|array The cart item containing the renewal, else false.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_cart_contains_renewal() {

	$contains_renewal = false;

	if ( ! empty( WC()->cart->cart_contents ) ) {
		foreach ( WC()->cart->cart_contents as $cart_item ) {
			if ( isset( $cart_item['subscription_renewal'] ) ) {
				$contains_renewal = $cart_item;
				break;
			}
		}
	}

	return apply_filters( 'wcs_cart_contains_renewal', $contains_renewal );
}

/**
 * Checks the cart to see if it contains a subscription product renewal for a failed renewal payment.
 *
 * @return bool|array The cart item containing the renewal, else false.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_cart_contains_failed_renewal_order_payment() {

	$contains_renewal = false;
	$cart_item        = wcs_cart_contains_renewal();

	if ( false !== $cart_item && isset( $cart_item['subscription_renewal']['renewal_order_id'] ) ) {
		$renewal_order = wc_get_order( $cart_item['subscription_renewal']['renewal_order_id'] );

		if ( $renewal_order ) {
			$is_failed_renewal_order = apply_filters( 'woocommerce_subscriptions_is_failed_renewal_order', $renewal_order->has_status( 'failed' ), $cart_item['subscription_renewal']['renewal_order_id'], $renewal_order->get_status() );

			if ( $is_failed_renewal_order ) {
				$contains_renewal = $cart_item;
			}
		}
	}

	return apply_filters( 'wcs_cart_contains_failed_renewal_order_payment', $contains_renewal );
}

/**
 * Get the subscription/s to which a renewal order relates.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @return WC_Subscription[] Subscription details in post_id => WC_Subscription form.
 */
function wcs_get_subscriptions_for_renewal_order( $order ) {
	return wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'renewal' ) );
}

/**
 * Get the last renewal order which isn't an early renewal order.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 *
 * @param WC_Subscription $subscription The subscription object.
 * @return WC_Order|bool The last non-early renewal order, otherwise false.
 */
function wcs_get_last_non_early_renewal_order( $subscription ) {
	$last_non_early_renewal = false;
	$renewal_orders         = wcs_get_renewal_orders_sorted_by( $subscription, 'date_created' );

	foreach ( $renewal_orders as $renewal_order ) {
		if ( ! wcs_order_contains_early_renewal( $renewal_order ) ) {
			$last_non_early_renewal = $renewal_order;
			break;
		}
	}

	return $last_non_early_renewal;
}

/**
 * Get the last renewal order (early renewals included).
 *
 * @param WC_Subscription $subscription The subscription object.
 * @return WC_Order|bool The last non-early renewal order, otherwise false.
 */
function wcs_get_last_renewal_order( $subscription ) {
	$renewal_orders = wcs_get_renewal_orders_sorted_by( $subscription, 'date_created' );
	return $renewal_orders ? reset( $renewal_orders ) : false;
}

/**
 * Gets the renewal orders for a subscription, sorted by the specified property.
 *
 * @param WC_Subscription $subscription The subscription object.
 * @param string          $sort_by      The subscription property to sort by.
 * @param string          $order        Optional. The sort order to sort by. Default is 'descending'.
 *
 * @return WC_Order[] The subscriptions renewal orders sorted.
 */
function wcs_get_renewal_orders_sorted_by( $subscription, $sort_by, $order = 'descending' ) {
	$renewal_orders = $subscription->get_related_orders( 'all', 'renewal' );

	wcs_sort_objects( $renewal_orders, $sort_by, $order );

	return $renewal_orders;
}

/**
 * Checks if manual renewals are required - automatic renewals are disabled.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 * @return bool Whether manual renewal is required.
 */
function wcs_is_manual_renewal_required() {
	return class_exists( 'WCS_Manual_Renewal_Manager' ) ? WCS_Manual_Renewal_Manager::is_manual_renewal_required() : false;
}

/**
 * Checks if manual renewals are enabled.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 * @return bool Whether manual renewal is enabled.
 */
function wcs_is_manual_renewal_enabled() {
	return class_exists( 'WCS_Manual_Renewal_Manager' ) ? WCS_Manual_Renewal_Manager::is_manual_renewal_enabled() : false;
}
