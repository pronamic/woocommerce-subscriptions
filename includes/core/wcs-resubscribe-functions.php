<?php
/**
 * WooCommerce Subscriptions Resubscribe Functions
 *
 * Functions for managing resubscribing to expired or cancelled subscriptions.
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
 * Check if a given order was created to resubscribe to a cancelled or expired subscription.
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_order_contains_resubscribe( $order ) {
	$is_resubscribe_order = false;

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}

	if ( $order ) {
		$related_subscription_ids = wcs_get_subscription_ids_for_order( $order, 'resubscribe' );
		$is_resubscribe_order     = ! empty( $related_subscription_ids );
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
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_create_resubscribe_order( $subscription ) {

	$resubscribe_order = wcs_create_order_from_subscription( $subscription, 'resubscribe_order' );

	if ( is_wp_error( $resubscribe_order ) ) {
		return new WP_Error( 'resubscribe-order-error', $resubscribe_order->get_error_message() );
	}

	WCS_Related_Order_Store::instance()->add_relation( $resubscribe_order, $subscription, 'resubscribe' );

	do_action( 'wcs_resubscribe_order_created', $resubscribe_order, $subscription );

	return $resubscribe_order;
}

/**
 * Returns a URL including required parameters for an authenticated user to renew a subscription
 *
 * @param  int | WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object
 * @return string
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_users_resubscribe_link( $subscription ) {

	$subscription_id  = ( is_object( $subscription ) ) ? $subscription->get_id() : $subscription;

	$resubscribe_link = add_query_arg( array( 'resubscribe' => $subscription_id ), get_permalink( wc_get_page_id( 'myaccount' ) ) );
	$resubscribe_link = wp_nonce_url( $resubscribe_link, $subscription_id );

	return apply_filters( 'wcs_users_resubscribe_link', $resubscribe_link, $subscription_id );
}

/**
 * Returns a URL including required parameters for an authenticated user to renew a subscription by product ID.
 *
 * @param int $product_id The ID of a product post type.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
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
 * @param bool|array $cart The cart item containing the renewal, else false.
 * @return string
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_cart_contains_resubscribe( $cart = null ) {

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
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscriptions_for_resubscribe_order( $order ) {
	return wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'resubscribe' ) );
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
 * 5. have a recurring amount greater than $0, to avoid allowing resubscribes to subscriptions
 *    where the entire cost is charged in a sign-up fee
 *
 * @param int|WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object
 * @param int $user_id The ID of a user
 * @return bool
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_can_user_resubscribe_to( $subscription, $user_id = 0 ) {

	if ( ! is_object( $subscription ) ) {
		$subscription = wcs_get_subscription( $subscription );
	}

	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	if ( empty( $subscription ) ) {

		$can_user_resubscribe = false;

	} elseif ( ! user_can( $user_id, 'subscribe_again', $subscription->get_id() ) ) {

		$can_user_resubscribe = false;

	} elseif ( ! $subscription->has_status( array( 'pending-cancel', 'cancelled', 'expired', 'trash' ) ) ) {

		$can_user_resubscribe = false;

	} elseif ( $subscription->get_total() <= 0 ) {

		$can_user_resubscribe = false;

	} else {

		$resubscribe_order_ids = $subscription->get_related_orders( 'ids', 'resubscribe' );

		// Make sure all line items still exist
		$all_line_items_exist = true;

		// Check if product in subscription is limited
		$has_active_limited_subscription = false;

		foreach ( $subscription->get_items() as $line_item ) {

			$product = ( ! empty( $line_item['variation_id'] ) ) ? wc_get_product( $line_item['variation_id'] ) : wc_get_product( $line_item['product_id'] );

			if ( false === $product ) {
				$all_line_items_exist = false;
				break;
			}

			if ( 'active' === wcs_get_product_limitation( $product ) ) {
				if ( $product->is_type( 'variation' ) ) {
					$limited_product_id = $product->get_parent_id();
				} else {
					$limited_product_id = $product->get_id();
				}

				if ( wcs_user_has_subscription( $user_id, $limited_product_id, 'on-hold' ) || wcs_user_has_subscription( $user_id, $limited_product_id, 'active' ) ) {
					$has_active_limited_subscription = true;
					break;
				}
			}
		}

		if ( empty( $resubscribe_order_ids ) && $subscription->get_payment_count() > 0 && true === $all_line_items_exist && false === $has_active_limited_subscription ) {
			$can_user_resubscribe = true;
		} else {
			$can_user_resubscribe = false;
		}
	}

	return apply_filters( 'wcs_can_user_resubscribe_to_subscription', $can_user_resubscribe, $subscription, $user_id );
}
