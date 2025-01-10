<?php
/**
 * WooCommerce Subscriptions Early Renewal functions.
 *
 * @author   Prospress
 * @category Core
 * @package  WooCommerce Subscriptions/Functions
 * @since    2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Checks the cart to see if it contains an early subscription renewal.
 *
 * @return bool|array The cart item containing the early renewal, else false.
 * @since  2.3.0
 */
function wcs_cart_contains_early_renewal() {

	$cart_item = wcs_cart_contains_renewal();

	if ( $cart_item && ! empty( $cart_item['subscription_renewal']['subscription_renewal_early'] ) ) {
		return $cart_item;
	}

	return false;
}

/**
 * Checks if a user can renew an active subscription early.
 *
 * @param int|WC_Subscription $subscription Post ID of a 'shop_subscription' post, or instance of a WC_Subscription object.
 * @param int $user_id The ID of a user.
 * @since 2.3.0
 * @return bool Whether the user can renew a subscription early.
 */
function wcs_can_user_renew_early( $subscription, $user_id = 0 ) {
	$subscription = wcs_get_subscription( $subscription );
	$user_id      = ! empty( $user_id ) ? $user_id : get_current_user_id();
	$reason       = '';

	// Check for all the normal reasons a subscription can't be renewed early.
	if ( ! $subscription ) {
		$reason = 'not_a_subscription';
	} elseif ( ! $subscription->has_status( array( 'active' ) ) ) {
		$reason = 'subscription_not_active';
	} elseif ( 0.0 === floatval( $subscription->get_total() ) ) {
		$reason = 'subscription_zero_total';
	} elseif ( $subscription->get_time( 'trial_end' ) > gmdate( 'U' ) ) {
		$reason = 'subscription_still_in_free_trial';
	} elseif ( ! $subscription->get_time( 'next_payment' ) ) {
		$reason = 'subscription_no_next_payment';
	} elseif ( ! $subscription->payment_method_supports( 'subscription_date_changes' ) ) {
		$reason = 'payment_method_not_supported';
	} elseif (
		WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $subscription ) &&
		/**
		 * Determine whether a subscription with Synchronized products can be renewed early.
		 *
		 * @param bool            $can_renew_early Whether the subscription can be renewed early.
		 * @param WC_Subscription $subscription    The subscription to be renewed early.
		 */
		! boolval( apply_filters( 'wcs_allow_synced_product_early_renewal', false, $subscription ) )
	) {
		$reason = 'subscription_contains_synced_product';
	} else {
		// Make sure all line items still exist.
		foreach ( $subscription->get_items() as $line_item ) {
			$product = wc_get_product( wcs_get_canonical_product_id( $line_item ) );

			if ( false === $product ) {
				$reason = 'line_item_no_longer_exists';
				break;
			}
		}
	}

	// Non-empty $reason means we can't renew early.
	$can_renew_early = empty( $reason );

	/**
	 * Allow third-parties to filter whether the customer can renew a subscription early.
	 *
	 * @since 2.3.0
	 *
	 * @param bool            $can_renew_early Whether early renewal is permitted.
	 * @param WC_Subscription $subscription    The subscription being renewed early.
	 * @param int             $user_id         The user's ID.
	 * @param string          $reason          The reason why the subscription cannot be renewed early. Empty
	 *                                         string if the subscription can be renewed early.
	 */
	return apply_filters( 'woocommerce_subscriptions_can_user_renew_early', $can_renew_early, $subscription, $user_id, $reason );
}

/**
 * Returns a URL for early renewal of a subscription.
 *
 * @param  int|WC_Subscription $subscription WC_Subscription ID, or instance of a WC_Subscription object.
 * @return string The early renewal URL.
 * @since  2.3.0
 */
function wcs_get_early_renewal_url( $subscription ) {
	$subscription_id = is_a( $subscription, 'WC_Subscription' ) ? $subscription->get_id() : absint( $subscription );

	$url = add_query_arg( array(
		'subscription_renewal_early' => $subscription_id,
		'subscription_renewal'       => 'true',
	), get_permalink( wc_get_page_id( 'myaccount' ) ) );

	/**
	 * Allow third-parties to filter the early renewal URL.
	 *
	 * @since 2.3.0
	 * @param string $url The early renewal URL.
	 * @param int    $subscription_id The ID of the subscription to renew to.
	 */
	return apply_filters( 'woocommerce_subscriptions_get_early_renewal_url', $url, $subscription_id ); // nosemgrep: audit.php.wp.security.xss.query-arg -- False positive. $url is escaped in the template and escaping URLs should be done at the point of output or usage.
}

/**
 * Update the subscription dates after processing an early renewal.
 *
 * @since 2.6.0
 *
 * @param WC_Subscription $subscription The subscription to update.
 * @param WC_Order $early_renewal       The early renewal.
 */
function wcs_update_dates_after_early_renewal( $subscription, $early_renewal ) {
	$dates_to_update = WCS_Early_Renewal_Manager::get_dates_to_update( $subscription );

	if ( ! empty( $dates_to_update ) ) {
		// translators: %s: order ID.
		$order_number = sprintf( _x( '#%s', 'hash before order number', 'woocommerce-subscriptions' ), $early_renewal->get_order_number() );
		$order_link   = sprintf( '<a href="%s">%s</a>', esc_url( wcs_get_edit_post_link( $early_renewal->get_id() ) ), $order_number );

		try {
			$subscription->update_dates( $dates_to_update );

			// translators: placeholder contains a link to the order's edit screen.
			$subscription->add_order_note( sprintf( __( 'Customer successfully renewed early with order %s.', 'woocommerce-subscriptions' ), $order_link ) );
		} catch ( Exception $e ) {
			// translators: placeholder contains a link to the order's edit screen.
			$subscription->add_order_note( sprintf( __( 'Failed to update subscription dates after customer renewed early with order %s.', 'woocommerce-subscriptions' ), $order_link ) );
		}
	}
}
