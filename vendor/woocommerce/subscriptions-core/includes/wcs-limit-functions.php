<?php
/**
 * WooCommerce Subscriptions Limit Functions
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version   2.1
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
 * Returns true if product is limited to one active subscription and user currently has this product on-hold.
 *
 * @param int|WC_Product $product A WC_Product object or the ID of a product
 * @return boolean
 */
function wcs_is_product_limited_for_user( $product, $user_id = 0 ) {
	if ( ! is_object( $product ) ) {
		$product = wc_get_product( $product );
	}

	if ( empty( $user_id ) ) {
		$user_id = get_current_user_id();
	}

	$is_limited_for_user = false;
	$product_limitation  = wcs_get_product_limitation( $product );

	// If the subscription is limited to 1 active and the customer has a subscription to this product on-hold.
	if ( 'active' == $product_limitation && wcs_user_has_subscription( $user_id, $product->get_id(), 'on-hold' ) ) {
		$is_limited_for_user = true;
	} elseif ( 'no' !== $product_limitation ) {
		$is_limited_for_user = wcs_user_has_subscription( $user_id, $product->get_id(), $product_limitation );

		// If the product is limited for any status, there exists a chance that the customer has cancelled subscriptions which cannot be resubscribed to as they have no completed payments.
		if ( 'any' === $product_limitation && $is_limited_for_user ) {
			$is_limited_for_user = false;
			$user_subscriptions  = wcs_get_subscriptions( array(
				'subscriptions_per_page' => -1,
				'customer_id'            => $user_id,
				'product_id'             => $product->get_id(),
			) );

			foreach ( $user_subscriptions as $subscription ) {
				if ( ! $subscription->has_status( 'cancelled' ) || 0 !== $subscription->get_payment_count() ) {
					$is_limited_for_user = true;
					break;
				}
			}
		}
	}

	return apply_filters( 'woocommerce_subscriptions_product_limited_for_user', $is_limited_for_user, $product, $user_id );
}
