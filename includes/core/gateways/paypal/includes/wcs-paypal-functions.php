<?php
/**
 * WooCommerce Subscriptions PayPal Functions
 *
 * Helper functions to check for data types etc.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @author      Brent Shepherd
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Returns a PayPal Subscription ID or Billing Agreement ID use to process payment for a given subscription or order.
 *
 * @param int The ID of a WC_Order or WC_Subscription object
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_paypal_id( $order ) {

	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	return wcs_get_objects_property( $order, '_paypal_subscription_id' );
}

/**
 * Stores a PayPal Standard Subscription ID or Billing Agreement ID in the post meta of a given order and the user meta of the order's user.
 *
 * @param int|object A WC_Order or WC_Subscription object or the ID of a WC_Order or WC_Subscription object
 * @param string A PayPal Standard Subscription ID or Express Checkout Billing Agreement ID
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_set_paypal_id( $order, $paypal_subscription_id ) {

	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	if ( wcs_is_paypal_profile_a( $paypal_subscription_id, 'billing_agreement' ) ) {
		if ( ! in_array( $paypal_subscription_id, get_user_meta( $order->get_user_id(), '_paypal_subscription_id', false ) ) ) {
			add_user_meta( $order->get_user_id(), '_paypal_subscription_id', $paypal_subscription_id );
		}
	}

	wcs_set_objects_property( $order, 'paypal_subscription_id', $paypal_subscription_id );
}

/**
 * Checks if a given profile ID is of a certain type.
 *
 * PayPal offers many different profile IDs that can be used for recurring payments, including:
 * - Express Checkout Billing Agreement IDs for Reference Transactios
 * - Express Checkout Recurring Payment profile IDs
 * - PayPal Standard Subscription IDs
 * - outdated PayPal Standard Subscription IDs (for accounts prior to 2009 that have not been upgraded).
 *
 * @param string $profile_id A PayPal Standard Subscription ID or Express Checkout Billing Agreement ID
 * @param string $profile_type A type of profile ID, can be 'billing_agreement' or 'old_id'.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_is_paypal_profile_a( $profile_id, $profile_type ) {

	if ( 'billing_agreement' === $profile_type && 'B-' == substr( $profile_id, 0, 2 ) ) {
		$is_a = true;
	} elseif ( 'out_of_date_id' === $profile_type && 'S-' == substr( $profile_id, 0, 2 ) ) {
		$is_a = true;
	} else {
		$is_a = false;
	}

	return apply_filters( 'woocommerce_subscriptions_is_paypal_profile_a_' . $profile_type, $is_a, $profile_id );
}

/**
 * Limit the length of item names to be within the allowed 127 character range.
 *
 * @param  string $item_name
 * @return string
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_paypal_item_name( $item_name ) {

	if ( strlen( $item_name ) > 127 ) {
		$item_name = substr( $item_name, 0, 124 ) . '...';
	}
	return html_entity_decode( $item_name, ENT_NOQUOTES, 'UTF-8' );
}

/**
 * Takes a timestamp for a date in the future and calculates the number of days between now and then
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_calculate_paypal_trial_periods_until( $future_timestamp ) {

	$seconds_until_next_payment = $future_timestamp - gmdate( 'U' );
	$days_until_next_payment    = ceil( $seconds_until_next_payment / ( 60 * 60 * 24 ) );

	if ( $days_until_next_payment <= 90 ) { // Can't be more than 90 days free trial

		$first_trial_length = $days_until_next_payment;
		$first_trial_period = 'D';

		$second_trial_length = 0;
		$second_trial_period = 'D';

	} else { // We need to use a second trial period

		if ( $days_until_next_payment > 365 * 2 ) { // We need to use years because PayPal has a maximum of 24 months

			$first_trial_length = floor( $days_until_next_payment / 365 );
			$first_trial_period = 'Y';

			$second_trial_length = $days_until_next_payment % 365;
			$second_trial_period = 'D';

		} elseif ( $days_until_next_payment > 365 ) { // Less than two years but more than one, use months

			$first_trial_length = floor( $days_until_next_payment / 30 );
			$first_trial_period = 'M';

			$days_remaining = $days_until_next_payment % 30;

			if ( $days_remaining <= 90 ) { // We can use days
				$second_trial_length = $days_remaining;
				$second_trial_period = 'D';
			} else { // We need to use weeks
				$second_trial_length = floor( $days_remaining / 7 );
				$second_trial_period = 'W';
			}
		} else {  // We need to use weeks

			$first_trial_length = floor( $days_until_next_payment / 7 );
			$first_trial_period = 'W';

			$second_trial_length = $days_until_next_payment % 7;
			$second_trial_period = 'D';

		}
	}

	return array(
		'first_trial_length'  => $first_trial_length,
		'first_trial_period'  => $first_trial_period,
		'second_trial_length' => $second_trial_length,
		'second_trial_period' => $second_trial_period,
	);
}

/**
 * Check if the $_SERVER global has PayPal WC-API endpoint URL slug in its 'REQUEST_URI' value
 *
 * In some cases, we need tdo be able to check if we're on the PayPal API page before $wp's query vars are setup,
 * like from WC_Subscriptions_Product::is_purchasable() and WC_Product_Subscription_Variation::is_purchasable(),
 * both of which are called within WC_Cart::get_cart_from_session(), which is run before query vars are setup.
 *
 * @return 2.0.13
 * @return bool
 **/
function wcs_is_paypal_api_page() {
	return ( false !== strpos( $_SERVER['REQUEST_URI'], 'wc-api/wcs_paypal' ) );
}
