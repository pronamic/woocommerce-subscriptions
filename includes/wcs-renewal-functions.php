<?php
/**
 * WooCommerce Subscriptions Renewal Functions
 *
 * Functions for managing renewal of a subscription.
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
 * Create a renewal order to record a scheduled subscription payment.
 *
 * This method simply creates an order with the same post meta, order items and order item meta as the subscription
 * passed to it.
 *
 * @param  int | WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object
 * @return WC_Subscription
 * @since  2.0
 */
function wcs_create_renewal_order( $subscription ) {

	$renewal_order = wcs_create_order_from_subscription( $subscription, 'renewal_order' );

	if ( is_wp_error( $renewal_order ) ) {
		return new WP_Error( 'renewal-order-error', $renewal_order->get_error_message() );
	}

	update_post_meta( $renewal_order->id, '_subscription_renewal', $subscription->id );

	return apply_filters( 'wcs_renewal_order_created', $renewal_order, $subscription );
}

/**
 * Check if a given order is a subscription renewal order.
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 2.0
 */
function wcs_order_contains_renewal( $order ) {

	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	if ( 'simple' == $order->order_type && isset( $order->subscription_renewal ) && $order->subscription_renewal > 0 ) { // It's a parent order or original order
		$is_renewal = true;
	} else {
		$is_renewal = false;
	}

	return apply_filters( 'woocommerce_subscriptions_is_renewal_order', $is_renewal, $order );
}

/**
 * Checks the cart to see if it contains a subscription product renewal.
 *
 * @param  bool | Array The cart item containing the renewal, else false.
 * @return string
 * @since  2.0
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
 * @param  bool | Array The cart item containing the renewal, else false.
 * @return string
 * @since  2.0
 */
function wcs_cart_contains_failed_renewal_order_payment() {

	$contains_renewal = false;
	$cart_item        = wcs_cart_contains_renewal();

	if ( false !== $cart_item && isset( $cart_item['subscription_renewal']['renewal_order_id'] ) ) {
		$renewal_order = wc_get_order( $cart_item['subscription_renewal']['renewal_order_id'] );
		if ( $renewal_order->has_status( 'failed' ) ) {
			$contains_renewal = $cart_item;
		}
	}

	return apply_filters( 'wcs_cart_contains_failed_renewal_order_payment', $contains_renewal );
}

/**
 * Get the subscription to which a renewal order relates.
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 2.0
 */
function wcs_get_subscriptions_for_renewal_order( $order ) {

	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	$subscriptions    = array();
	$subscription_ids = get_post_meta( $order->id, '_subscription_renewal', false );

	foreach ( $subscription_ids as $subscription_id ) {
		if ( wcs_is_subscription( $subscription_id ) ) {
			$subscriptions[ $subscription_id ] = wcs_get_subscription( $subscription_id );
		}
	}

	return apply_filters( 'wcs_subscriptions_for_renewal_order', $subscriptions, $order );
}
