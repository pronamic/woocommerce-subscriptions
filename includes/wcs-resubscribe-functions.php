<?php
/**
 * WooCommerce Subscriptions Resubscribe Functions
 *
 * Functions for managing resubscribing to expired or cancelled subscriptions.
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
 * Check if a given order was created to resubscribe to a cancelled or expired subscription.
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 2.0
 */
function wcs_order_contains_resubscribe( $order ) {

	if ( ! is_object( $order ) ) {
		$order = new WC_Order( $order );
	}

	if ( '' !== get_post_meta( $order->id, '_subscription_resubscribe', true ) ) {
		$is_resubscribe_order = true;
	} else {
		$is_resubscribe_order = false;
	}

	return apply_filters( 'woocommerce_subscriptions_is_resubscribe_order', $is_resubscribe_order, $order );
}

/**
 * Create a resubscribe order to record a customer resubscribing to an expired or cancelled subscription.
 *
 * This method is a wrapper for @see wcs_create_order() which creates an order with the same post meta, order
 * items and order item meta as the subscription passed to it. No trial periods or sign up fees are applied
 * to resubscribe orders.
 *
 * @param  int | WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object
 * @return WC_Subscription
 * @since  2.0
 */
function wcs_create_resubscribe_order( $subscription ) {

	$resubscribe_order = wcs_create_order_from_subscription( $subscription, 'resubscribe_order' );

	if ( is_wp_error( $resubscribe_order ) ) {
		return new WP_Error( 'resubscribe-order-error', $renewal_order->get_error_message() );
	}

	// Keep a record of the original subscription's ID on the new order
	update_post_meta( $resubscribe_order->id, '_subscription_resubscribe', $subscription->id, true );

	do_action( 'wcs_resubscribe_order_created', $resubscribe_order, $subscription );

	return $resubscribe_order;
}

/**
 * Returns a URL including required parameters for an authenticated user to renew a subscription
 *
 * @param  int | WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object
 * @return string
 * @since  2.0
 */
function wcs_get_users_resubscribe_link( $subscription ) {

	$subscription_id  = ( is_object( $subscription ) ) ? $subscription->id : $subscription;

	$resubscribe_link = add_query_arg( array( 'resubscribe' => $subscription_id ), get_permalink( wc_get_page_id( 'myaccount' ) ) );
	$resubscribe_link = wp_nonce_url( $resubscribe_link, $subscription_id );

	return apply_filters( 'wcs_users_resubscribe_link', $resubscribe_link, $subscription_id );
}

/**
 * Returns a URL including required parameters for an authenticated user to renew a subscription by product ID.
 *
 * @param int $product_id The ID of a product post type.
 * @since 1.2
 */
function wcs_get_users_resubscribe_link_for_product( $product_id ) {

	$renewal_url = '';

	if ( is_user_logged_in() ) {
		foreach ( wcs_get_users_subscriptions() as $subscription ) {
			foreach ( $subscription->get_items() as $line_item ) {
				if ( ( $line_item['product_id'] == $product_id || $line_item['variation_id'] == $product_id ) && wcs_can_user_resubscribe_to( $subscription ) ) {
					$renewal_url = wcs_get_users_resubscribe_link( $subscription );
					break;
				}
			}
		}
	}

	return apply_filters( 'wcs_users_resubscribe_link_for_product', $renewal_url, $product_id );
}

/**
 * Checks the cart to see if it contains a subscription product renewal.
 *
 * @param  bool | Array The cart item containing the renewal, else false.
 * @return string
 * @since  2.0
 */
function wcs_cart_contains_resubscribe( $cart = '' ) {

	$contains_resubscribe = false;

	if ( empty( $cart ) ) {
		$cart = WC()->cart;
	}

	if ( ! empty( $cart->cart_contents ) ) {
		foreach ( $cart->cart_contents as $cart_item ) {
			if ( isset( $cart_item['subscription_resubscribe'] ) ) {
				$contains_resubscribe = $cart_item;
				break;
			}
		}
	}

	return apply_filters( 'wcs_cart_contains_resubscribe', $contains_resubscribe, $cart );
}

/**
 * Get the subscription to which a renewal order relates.
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 2.0
 */
function wcs_get_subscriptions_for_resubscribe_order( $order ) {

	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	$subscriptions    = array();
	$subscription_ids = get_post_meta( $order->id, '_subscription_resubscribe', false );

	foreach ( $subscription_ids as $subscription_id ) {
		if ( wcs_is_subscription( $subscription_id ) ) {
			$subscriptions[ $subscription_id ] = wcs_get_subscription( $subscription_id );
		}
	}

	return apply_filters( 'wcs_subscriptions_for_resubscribe_order', $subscriptions, $order );
}

/**
 * Check if a user can resubscribe to an expired or cancelled subscription by creating a
 * new subscription with the same terms.
 *
 * For it to be possible to resubscribe to a subscription, the user specified with $user_id must
 * and the subscription must:
 * 1. be be inactive (expired or cancelled)
 * 2. had at least one payment, to avoid circumventing sign-up fees
 * 3. its parent order must not have already been superseded by a new order (to prevent
 *    displaying "Resubscribe" links on subscriptions that have already been renewed)
 * 4. the products to which the subscription relates must not have been deleted
 *
 * @param  int | WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object
 * @param  int The ID of a user
 * @return bool
 * @since  2.0
 */
function wcs_can_user_resubscribe_to( $subscription, $user_id = '' ) {

	if ( ! is_object( $subscription ) ) {
		$subscription = wcs_get_subscription( $subscription );
	}

	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	if ( empty( $subscription ) ) {

		$can_user_resubscribe = false;

	} elseif ( ! user_can( $user_id, 'subscribe_again', $subscription->id ) ) {

		$can_user_resubscribe = false;

	} else {

		$resubscribe_orders = get_posts( array(
			'meta_query'  => array(
				array(
					'key'     => '_subscription_resubscribe',
					'compare' => '=',
					'value'   => $subscription->id,
					'type'    => 'numeric',
				),
			),
			'post_type'   => 'shop_order',
			'post_status' => 'any',
		) );

		// Make sure all line items still exist
		$all_line_items_exist = true;

		foreach ( $subscription->get_items() as $line_item ) {

			$product = ( ! empty( $line_item['variation_id'] ) ) ? wc_get_product( $line_item['variation_id'] ) : wc_get_product( $line_item['product_id'] );

			if ( false === $product ) {
				$all_line_items_exist = false;
				break;
			}
		}

		if ( empty( $resubscribe_orders ) && $subscription->get_completed_payment_count() > 0 && $subscription->get_total() > 0 && true === $all_line_items_exist && $subscription->has_status( array( 'cancelled', 'expired', 'trash' ) ) ) {
			$can_user_resubscribe = true;
		} else {
			$can_user_resubscribe = false;
		}
	}

	return apply_filters( 'wcs_can_user_resubscribe_to_subscription', $can_user_resubscribe, $subscription, $user_id );
}
