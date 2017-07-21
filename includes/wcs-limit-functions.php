<?php
/**
 * WooCommerce Subscriptions Limit Functions
 *
 * @author 		Prospress
 * @category 	Core
 * @package 	WooCommerce Subscriptions/Functions
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

	return apply_filters( 'woocommerce_subscriptions_product_limitation', WC_Subscriptions_Product::get_meta_data( $product, 'subscription_limit', 'no', 'use_default_value' ), $product );
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

	return ( ( 'active' == wcs_get_product_limitation( $product ) && wcs_user_has_subscription( $user_id, $product->get_id(), 'on-hold' ) ) || ( 'no' !== wcs_get_product_limitation( $product ) && wcs_user_has_subscription( $user_id, $product->get_id(), wcs_get_product_limitation( $product ) ) ) ) ? true : false;
}
