<?php
/**
 * PayPal Subscription Switcher Class.
 *
 * Because PayPal Standard does not support recurring amount or date changes, items can not be switched when the subscription is using a
 * profile ID for PayPal Standard. However, PayPal Reference Transactions do allow these to be updated and because switching uses the checkout
 * process, we can migrate a subscription from PayPal Standard to Reference Transactions when the customer switches.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @author      Prospress
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Standard_Switcher {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function init() {

		// Allow items on PayPal Standard Subscriptions to be switch when the PayPal account supports Reference Transactions
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', __CLASS__ . '::can_item_be_switched', 10, 3 );

		// Sometimes, even if the order total is $0, the cart still needs payment
		add_filter( 'woocommerce_cart_needs_payment', __CLASS__ . '::cart_needs_payment', 100, 2 );

		// Update the new payment method if switching from PayPal Standard and not creating a new subscription
		add_filter( 'woocommerce_payment_successful_result', __CLASS__ . '::maybe_set_payment_method', 10, 2 );

		// Save old PP standand id on switched orders so that PP recurring payments can be cancelled after successful switch
		add_action( 'woocommerce_checkout_update_order_meta', __CLASS__ . '::save_old_paypal_meta', 15, 2 );

		// Try to cancel a paypal once the switch has been successfully completed
		add_action( 'woocommerce_subscriptions_switch_completed', __CLASS__ . '::cancel_paypal_standard_after_switch', 10, 1 );

		// Do not allow subscriptions to be switched using PayPal Standard as the payment method
		add_filter( 'woocommerce_available_payment_gateways', __CLASS__ . '::get_available_payment_gateways', 12, 1 );
	}

	/**
	 * Allow items on PayPal Standard Subscriptions to be switch when the PayPal account supports Reference Transactions
	 *
	 * Because PayPal Standard does not support recurring amount or date changes, items can not be switched when the subscription is using a
	 * profile ID for PayPal Standard. However, PayPal Reference Transactions do allow these to be updated and because switching uses the checkout
	 * process, we can migrate a subscription from PayPal Standard to Reference Transactions when the customer switches, so we will allow that.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function can_item_be_switched( $item_can_be_switch, $item, $subscription ) {

		if ( false === $item_can_be_switch && 'paypal' === $subscription->get_payment_method() && WCS_PayPal::are_reference_transactions_enabled() ) {

			$is_billing_agreement = wcs_is_paypal_profile_a( wcs_get_paypal_id( $subscription->get_id() ), 'billing_agreement' );

			if ( 'line_item' == $item['type'] && wcs_is_product_switchable_type( $item['product_id'] ) ) {
				$is_product_switchable = true;
			} else {
				$is_product_switchable = false;
			}

			if ( $subscription->has_status( 'active' ) && 0 !== $subscription->get_date( 'last_order_date_created' ) ) {
				$is_subscription_switchable = true;
			} else {
				$is_subscription_switchable = false;
			}

			// If the only reason the subscription isn't switchable is because the PayPal profile ID is not a billing agreement, allow it to be switched
			if ( false === $is_billing_agreement && $is_product_switchable && $is_subscription_switchable ) {
				$item_can_be_switch = true;
			}
		}

		return $item_can_be_switch;
	}

	/**
	 * Check whether the cart needs payment even if the order total is $0 because it's a subscription switch request for a subscription using
	 * PayPal Standard as the subscription.
	 *
	 * @param bool $needs_payment The existing flag for whether the cart needs payment or not.
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {

		$cart_switch_items = wcs_cart_contains_switches();

		if ( false === $needs_payment && 0 == $cart->total && false !== $cart_switch_items && ! wcs_is_manual_renewal_required() ) {

			foreach ( $cart_switch_items as $cart_switch_details ) {

				$subscription = wcs_get_subscription( $cart_switch_details['subscription_id'] );

				if ( 'paypal' === $subscription->get_payment_method() && ! wcs_is_paypal_profile_a( wcs_get_paypal_id( $subscription->get_id() ), 'billing_agreement' ) ) {
					$needs_payment = true;
					break;
				}
			}
		}

		return $needs_payment;
	}

	/**
	 * If switching a subscription using PayPal Standard as the payment method and the customer has entered
	 * in a payment method other than PayPal (which would be using Reference Transactions), make sure to update
	 * the payment method on the subscription (this is hooked to 'woocommerce_payment_successful_result' to make
	 * sure it happens after the payment succeeds).
	 *
	 * @param array $payment_processing_result The result of the process payment gateway extension request.
	 * @param int $order_id The ID of an order potentially recording a switch.
	 * @return array
	 */
	public static function maybe_set_payment_method( $payment_processing_result, $order_id ) {

		if ( wcs_order_contains_switch( $order_id ) ) {

			$order = wc_get_order( $order_id );

			foreach ( wcs_get_subscriptions_for_switch_order( $order_id ) as $subscription ) {

				$order_payment_method = wcs_get_objects_property( $order, 'payment_method' );

				if ( 'paypal' === $subscription->get_payment_method() && $subscription->get_payment_method() !== $order_payment_method && false === wcs_is_paypal_profile_a( wcs_get_paypal_id( $subscription->get_id() ), 'billing_agreement' ) ) {

					// Set the new payment method on the subscription
					$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

					if ( isset( $available_gateways[ $order_payment_method ] ) ) {
						$subscription->set_payment_method( $available_gateways[ $order_payment_method ] );
						$subscription->save();
					}
				}
			}
		}

		return $payment_processing_result;
	}

	/**
	 * Stores the old paypal standard subscription id on the switch order so that it can be used later to cancel the recurring payment.
	 *
	 * Strictly hooked on after WC_Subscriptions_Switcher::add_order_meta()
	 *
	 * @param int $order_id
	 * @param array $posted
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.15
	 */
	public static function save_old_paypal_meta( $order_id, $posted ) {
		$order = wc_get_order( $order_id );

		if ( ! wcs_is_order( $order ) || ! wcs_order_contains_switch( $order ) ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'switch' ) );

		foreach ( $subscriptions as $subscription ) {

			if ( 'paypal' === $subscription->get_payment_method() ) {

				$paypal_id = wcs_get_paypal_id( $subscription->get_id() );

				if ( ! wcs_is_paypal_profile_a( $paypal_id, 'billing_agreement' ) ) {
					$order->update_meta_data( '_old_payment_method', 'paypal_standard' );
					$order->update_meta_data( '_old_paypal_subscription_id', $paypal_id );
					$order->save();

					update_post_meta( $subscription->get_id(), '_switched_paypal_subscription_id', $paypal_id );
				}
			}
		}
	}

	/**
	 * Cancel subscriptions with PayPal Standard after the order has been successfully switched.
	 *
	 * @param WC_Order $order
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	 */
	public static function cancel_paypal_standard_after_switch( $order ) {

		if ( 'paypal_standard' === $order->get_meta( '_old_payment_method' )  ) {

			$old_profile_id = $order->get_meta( '_old_paypal_subscription_id' );

			if ( ! empty( $old_profile_id ) ) {

				$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'switch' ) );

				foreach ( $subscriptions as $subscription ) {

					if ( ! wcs_is_paypal_profile_a( $old_profile_id, 'billing_agreement' ) ) {
						$new_payment_method = $subscription->get_payment_method();
						$new_profile_id     = $subscription->get_meta( '_paypal_subscription_id' ); // grab the current paypal subscription id in case it's a billing agreement

						$subscription->update_meta_data( '_payment_method', 'paypal' );
						$subscription->update_meta_data( '_paypal_subscription_id', $old_profile_id );

						add_filter( 'wc_subscriptions_paypal_standard_suspension_note', array( __CLASS__, 'filter_suspended_switch_note' ) );
						WCS_PayPal_Status_Manager::suspend_subscription( $subscription );
						remove_filter( 'wc_subscriptions_paypal_standard_suspension_note', array( __CLASS__, 'filter_suspended_switch_note' ) );

						// restore payment meta to the new data
						$subscription->update_meta_data( '_payment_method', $new_payment_method );
						$subscription->update_meta_data( '_paypal_subscription_id', $new_profile_id );
						$subscription->save();
					}
				}
			}
		}
	}

	/**
	 * Do not allow subscriptions to be switched using PayPal Standard as the payment method
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.16
	 */
	public static function get_available_payment_gateways( $available_gateways ) {

		if ( ! is_wc_endpoint_url( 'order-pay' ) && ( wcs_cart_contains_switches() || ( isset( $_GET['order_id'] ) && wcs_order_contains_switch( $_GET['order_id'] ) ) ) ) {
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( 'paypal' == $gateway_id && false == WCS_PayPal::are_reference_transactions_enabled() ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		}

		return $available_gateways;
	}

	/** Deprecated Methods **/

	/**
	 * Cancel subscriptions with PayPal Standard after the order has been successfully switched.
	 *
	 * @param int $order_id
	 * @param string $old_status
	 * @param string $new_status
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.15
	 */
	public static function maybe_cancel_paypal_after_switch( $order_id, $old_status, $new_status ) {

		_deprecated_function( __METHOD__, '2.1', __CLASS__ . 'cancel_paypal_standard_after_switch( $order )' );

		$order = wc_get_order( $order_id );
		$order_completed = in_array( $new_status, array( apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order_id, $order ), 'processing', 'completed' ) ) && in_array( $old_status, apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'on-hold', 'failed' ), $order ) );

		if ( $order_completed && wcs_order_contains_switch( $order_id ) ) {
			self::cancel_paypal_standard_after_switch( $order );
		}
	}

	/**
	 * Filters the note added to a subscription when the payment method is changed from PayPal Standard to PayPal Reference Transactions after a switch.
	 *
	 * Hooked onto 'wc_subscriptions_paypal_standard_suspension_note'. @see WCS_PayPal_Standard_Switcher::cancel_paypal_standard_after_switch()
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 * @return string The note added to a subscription when the payment method changes from PayPal Standard to PayPal RT.
	 */
	public static function filter_suspended_switch_note() {
		return __( 'Subscription changed from PayPal Standard to PayPal Reference Transactions via customer initiated switch. The PayPal Standard subscription has been suspended.', 'woocommerce-subscriptions' );
	}
}
