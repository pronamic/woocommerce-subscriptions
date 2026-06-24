<?php
/**
 * WooCommerce Subscriptions Limit Functions
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version   1.0.0 - Migrated from WooCommerce Subscriptions v2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Get the subscription's limit type.
 *
 * @param int|WC_Product $product A WC_Product object or the ID of a product
 * @return string containing the limit type
 */
function wcs_get_product_limitation( $product ) {

	if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
		$product = wc_get_product( $product );
	}

	if ( $product->is_type( 'variation' ) ) {
		$parent_product = wc_get_product( $product->get_parent_id() );
	} else {
		$parent_product = $product;
	}

	return apply_filters( 'woocommerce_subscriptions_product_limitation', WC_Subscriptions_Product::get_meta_data( $parent_product, 'subscription_limit', 'no', 'use_default_value' ), $product );
}

/**
 * Returns true if the product's subscription limit has been reached for the given user.
 *
 * When limited to one "active" subscription, subscriptions with on-hold or
 * pending-cancel status also count because they still grant access.
 *
 * @param int|WC_Product $product A WC_Product object or the ID of a product
 * @param int $user_id (optional) The ID of the user to check. Defaults to the current user.
 * @param int[] $excluded_subscription_ids (optional) Subscription IDs to ignore when counting towards the limit. Useful for disregarding a subscription the customer is currently paying for.
 * @return boolean
 */
function wcs_is_product_limited_for_user( $product, $user_id = 0, $excluded_subscription_ids = array() ) {
	if ( ! is_object( $product ) ) {
		$product = wc_get_product( $product );
	}

	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$is_limited_for_user = false;
	$product_limitation  = wcs_get_product_limitation( $product );

	// Map 'active' limitation to include statuses that still grant access.
	$statuses_to_check = ( 'active' === $product_limitation )
		? array( 'active', 'on-hold', 'pending-cancel' )
		: $product_limitation;

	if ( 'no' !== $product_limitation ) {
		$is_limited_for_user = wcs_user_has_subscription( $user_id, $product->get_id(), $statuses_to_check, $excluded_subscription_ids );

		// If the product is limited for any status, there exists a chance that the customer has cancelled subscriptions which cannot be resubscribed to as they have no completed payments.
		if ( 'any' === $product_limitation && $is_limited_for_user ) {
			$is_limited_for_user = false;

			foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
				// Skip subscriptions the customer is currently paying for (e.g. their own pending/failed order).
				if ( in_array( $subscription->get_id(), $excluded_subscription_ids, true ) ) {
					continue;
				}

				// Skip if the subscription is not for the product we are checking.
				if ( ! $subscription->has_product( $product->get_id() ) ) {
					continue;
				}

				if ( ! $subscription->has_status( 'cancelled' ) || 0 !== $subscription->get_payment_count() ) {
					$is_limited_for_user = true;
					break;
				}
			}
		}
	}

	return apply_filters( 'woocommerce_subscriptions_product_limited_for_user', $is_limited_for_user, $product, $user_id );
}
