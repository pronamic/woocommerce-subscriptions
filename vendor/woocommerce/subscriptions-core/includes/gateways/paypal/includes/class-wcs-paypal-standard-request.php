<?php
/**
 * WooCommerce Subscriptions PayPal Standard Request Class.
 *
 * Generates URL parameters to send to PayPal to create a subscription with PayPal Standard
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @author      Prospress
 * @since       2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Standard_Request {

	/**
	 * Get PayPal Args for passing to PP
	 *
	 * Based on the HTML Variables documented here: https://developer.paypal.com/webapps/developer/docs/classic/paypal-payments-standard/integration-guide/Appx_websitestandard_htmlvariables/#id08A6HI00JQU
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	public static function get_paypal_args( $paypal_args, $order ) {

		$is_payment_change = WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
		$order_contains_failed_renewal = false;

		// Payment method changes act on the subscription not the original order
		if ( $is_payment_change ) {

			$subscription = wcs_get_subscription( wcs_get_objects_property( $order, 'id' ) );
			$order        = $subscription->get_parent();

			// We need the subscription's total
			if ( wcs_is_woocommerce_pre( '3.0' ) ) {
				remove_filter( 'woocommerce_order_amount_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11 );
			} else {
				remove_filter( 'woocommerce_subscription_get_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11 );
			}
		} else {

			// Otherwise the order is the $order
			if ( $cart_item = wcs_cart_contains_failed_renewal_order_payment() || false !== WC_Subscriptions_Renewal_Order::get_failed_order_replaced_by( wcs_get_objects_property( $order, 'id' ) ) ) {
				$subscriptions                 = wcs_get_subscriptions_for_renewal_order( $order );
				$order_contains_failed_renewal = true;
			} else {
				$subscriptions                 = wcs_get_subscriptions_for_order( $order );
			}

			// Only one subscription allowed per order with PayPal
			$subscription = array_pop( $subscriptions );
		}

		if ( $order_contains_failed_renewal || ( ! empty( $subscription ) && $subscription->get_total() > 0 && ! wcs_is_manual_renewal_required() ) ) {

			// It's a subscription
			$paypal_args['cmd'] = '_xclick-subscriptions';

			foreach ( $subscription->get_items() as $item ) {
				if ( $item['qty'] > 1 ) {
					$item_names[] = $item['qty'] . ' x ' . wcs_get_paypal_item_name( $item['name'] );
				} elseif ( $item['qty'] > 0 ) {
					$item_names[] = wcs_get_paypal_item_name( $item['name'] );
				}
			}

			// Subscription imported or manually added via admin so doesn't have a parent order
			if ( empty( $order ) ) {
				// translators: 1$: subscription ID, 2$: names of items, comma separated
				$paypal_args['item_name'] = wcs_get_paypal_item_name( sprintf( _x( 'Subscription %1$s - %2$s', 'item name sent to paypal', 'woocommerce-subscriptions' ), $subscription->get_order_number(), implode( ', ', $item_names ) ) );
			} else {
				// translators: 1$: subscription ID, 2$: order ID, 3$: names of items, comma separated
				$paypal_args['item_name'] = wcs_get_paypal_item_name( sprintf( _x( 'Subscription %1$s (Order %2$s) - %3$s', 'item name sent to paypal', 'woocommerce-subscriptions' ), $subscription->get_order_number(), $order->get_order_number(), implode( ', ', $item_names ) ) );
			}

			$unconverted_periods = array(
				'billing_period' => $subscription->get_billing_period(),
				'trial_period'   => $subscription->get_trial_period(),
			);

			$converted_periods = array();

			// Convert period strings into PayPay's format
			foreach ( $unconverted_periods as $key => $period ) {
				switch ( strtolower( $period ) ) {
					case 'day':
						$converted_periods[ $key ] = 'D';
						break;
					case 'week':
						$converted_periods[ $key ] = 'W';
						break;
					case 'year':
						$converted_periods[ $key ] = 'Y';
						break;
					case 'month':
					default:
						$converted_periods[ $key ] = 'M';
						break;
				}
			}

			$price_per_period       = $subscription->get_total();
			$subscription_interval  = $subscription->get_billing_interval();
			$start_timestamp        = $subscription->get_time( 'date_created' );
			$trial_end_timestamp    = $subscription->get_time( 'trial_end' );
			$next_payment_timestamp = $subscription->get_time( 'next_payment' );

			$is_synced_subscription = WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $subscription->get_id() );
			$is_early_resubscribe   = false;

			if ( $resubscribe_cart_item = wcs_cart_contains_resubscribe() ) {
				$resubscribed_subscription = wcs_get_subscription( $resubscribe_cart_item['subscription_resubscribe']['subscription_id'] );
				$is_early_resubscribe      = wcs_is_subscription( $resubscribed_subscription ) && $resubscribed_subscription->has_status( 'pending-cancel' );
			}

			if ( $is_synced_subscription ) {
				$length_from_timestamp = $next_payment_timestamp;
			} elseif ( $trial_end_timestamp > 0 ) {
				$length_from_timestamp = $trial_end_timestamp;
			} else {
				$length_from_timestamp = $start_timestamp;
			}

			$subscription_length = wcs_estimate_periods_between( $length_from_timestamp, $subscription->get_time( 'end' ), $subscription->get_billing_period() );

			$subscription_installments = $subscription_length / $subscription_interval;

			$initial_payment = ( $is_payment_change ) ? 0 : $order->get_total();

			if ( $order_contains_failed_renewal || $is_payment_change ) {

				if ( $is_payment_change ) {
					// Add a nonce to the order ID to avoid "This invoice has already been paid" error when changing payment method to PayPal when it was previously PayPal
					$suffix = '-wcscpm-' . wp_create_nonce();
				} else {
					// Failed renewal order, append a descriptor and renewal order's ID
					$suffix = '-wcsfrp-' . wcs_get_objects_property( $order, 'id' );
				}

				$parent_order = $subscription->get_parent();

				// Change the 'invoice' and the 'custom' values to be for the original order (if there is one)
				if ( false === $parent_order ) {
					// No original order so we need to use the subscriptions values instead
					$order_number = ltrim( $subscription->get_order_number(), _x( '#', 'hash before the order number. Used as a character to remove from the actual order number', 'woocommerce-subscriptions' ) ) . '-subscription';
					$order_id_key = array(
						'order_id'  => $subscription->get_id(),
						'order_key' => $subscription->get_order_key(),
					);
				} else {
					$order_number = ltrim( $parent_order->get_order_number(), _x( '#', 'hash before the order number. Used as a character to remove from the actual order number', 'woocommerce-subscriptions' ) );
					$order_id_key = array(
						'order_id'  => wcs_get_objects_property( $parent_order, 'id' ),
						'order_key' => wcs_get_objects_property( $parent_order, 'order_key' ),
					);
				}

				// Set the invoice details to the original order's invoice but also append a special string and this renewal orders ID so that we can match it up as a failed renewal order payment later
				$paypal_args['invoice'] = WCS_PayPal::get_option( 'invoice_prefix' ) . $order_number . $suffix;
				$paypal_args['custom']  = wcs_json_encode(
					array_merge(
						$order_id_key,
						array(
							'subscription_id'  => $subscription->get_id(),
							'subscription_key' => $subscription->get_order_key(),
						)
					)
				);

			} else {

				// Store the subscription ID in the args sent to PayPal so we can access them later
				$paypal_args['custom'] = wcs_json_encode(
					array(
						'order_id'         => wcs_get_objects_property( $order, 'id' ),
						'order_key'        => wcs_get_objects_property( $order, 'order_key' ),
						'subscription_id'  => $subscription->get_id(),
						'subscription_key' => $subscription->get_order_key(),
					)
				);
			}

			if ( $order_contains_failed_renewal ) {

				$subscription_trial_length = 0;
				$subscription_installments = max( $subscription_installments - $subscription->get_payment_count(), 0 );

			// If we're changing the payment date or switching subs, we need to set the trial period to the next payment date & installments to be the number of installments left
			} elseif ( $is_payment_change || $is_synced_subscription || $is_early_resubscribe ) {

				$next_payment_timestamp = $subscription->get_time( 'next_payment' );

				// When the subscription is on hold
				if ( false != $next_payment_timestamp && ! empty( $next_payment_timestamp ) ) {

					$trial_until = wcs_calculate_paypal_trial_periods_until( $next_payment_timestamp );

					$subscription_trial_length = $trial_until['first_trial_length'];
					$converted_periods['trial_period'] = $trial_until['first_trial_period'];

					$second_trial_length = $trial_until['second_trial_length'];
					$second_trial_period = $trial_until['second_trial_period'];

				} else {

					$subscription_trial_length = 0;

				}

				// If this is a payment change, we need to account for completed payments on the number of installments owing
				if ( $is_payment_change && $subscription_length > 0 ) {
					$subscription_installments = max( $subscription_installments - $subscription->get_payment_count(), 0 );
				}
			} else {

				$subscription_trial_length = wcs_estimate_periods_between( $start_timestamp, $trial_end_timestamp, $subscription->get_trial_period() );

			}

			if ( $subscription_trial_length > 0 ) { // Specify a free trial period

				$paypal_args['a1'] = ( $initial_payment > 0 ) ? $initial_payment : 0;

				// Trial period length
				$paypal_args['p1'] = $subscription_trial_length;

				// Trial period
				$paypal_args['t1'] = $converted_periods['trial_period'];

				// We need to use a second trial period before we have more than 90 days until the next payment
				if ( isset( $second_trial_length ) && $second_trial_length > 0 ) {
					$paypal_args['a2'] = 0.01; // Alas, although it's undocumented, PayPal appears to require a non-zero value in order to allow a second trial period
					$paypal_args['p2'] = $second_trial_length;
					$paypal_args['t2'] = $second_trial_period;
				}
			} elseif ( $initial_payment != $price_per_period ) { // No trial period, but initial amount includes a sign-up fee and/or other items, so charge it as a separate period

				if ( 1 == $subscription_installments ) {
					$param_number = 3;
				} else {
					$param_number = 1;
				}

				$paypal_args[ 'a' . $param_number ] = $initial_payment;

				// Sign Up interval
				$paypal_args[ 'p' . $param_number ] = $subscription_interval;

				// Sign Up unit of duration
				$paypal_args[ 't' . $param_number ] = $converted_periods['billing_period'];

			}

			// We have a recurring payment
			if ( ! isset( $param_number ) || 1 == $param_number ) {

				// Subscription price
				$paypal_args['a3'] = $price_per_period;

				// Subscription duration
				$paypal_args['p3'] = $subscription_interval;

				// Subscription period
				$paypal_args['t3'] = $converted_periods['billing_period'];

			}

			// Recurring payments
			if ( 1 == $subscription_installments || ( $initial_payment != $price_per_period && 0 == $subscription_trial_length && 2 == $subscription_installments ) ) {

				// Non-recurring payments
				$paypal_args['src'] = 0;

			} else {

				$paypal_args['src'] = 1;

				if ( $subscription_installments > 0 ) {

					// An initial period is being used to charge a sign-up fee
					if ( $initial_payment != $price_per_period && 0 == $subscription_trial_length ) {
						$subscription_installments--;
					}

					$paypal_args['srt'] = $subscription_installments;

				}
			}

			// Don't reattempt failed payments, instead let Subscriptions handle the failed payment
			$paypal_args['sra'] = 0;

			// Force return URL so that order description & instructions display
			$paypal_args['rm'] = 2;

			// Reattach the filter we removed earlier
			if ( $is_payment_change ) {
				if ( wcs_is_woocommerce_pre( '3.0' ) ) {
					add_filter( 'woocommerce_order_amount_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11, 2 );
				} else {
					add_filter( 'woocommerce_subscription_get_total', 'WC_Subscriptions_Change_Payment_Gateway::maybe_zero_total', 11, 2 );
				}
			}
		}

		return $paypal_args;
	}

}
