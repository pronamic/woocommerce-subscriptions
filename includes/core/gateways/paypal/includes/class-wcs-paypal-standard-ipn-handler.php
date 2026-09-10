<?php
/**
 * PayPal Standard IPN Handler
 *
 * Handles IPN requests from PayPal for PayPal Standard Subscription transactions
 *
 * Example IPN payloads https://gist.github.com/thenbrent/3037967
 *
 * @link https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNandPDTVariables/#id08CTB0S055Z
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

class WCS_PayPal_Standard_IPN_Handler extends WC_Gateway_Paypal_IPN_Handler {

	/** @var Array transaction types this class can handle */
	protected $transaction_types = array(
		'subscr_signup',  // Subscription started
		'subscr_payment', // Subscription payment received
		'subscr_cancel',  // Subscription canceled
		'subscr_eot',     // Subscription expired
		'subscr_failed',  // Subscription payment failed
		'subscr_modify',  // Subscription modified

		// The PayPal docs say these are for Express Checkout recurring payments but they are also sent for PayPal Standard subscriptions
		'recurring_payment_skipped',   // Recurring payment skipped; it will be retried up to 3 times, 5 days apart
		'recurring_payment_suspended', // Recurring payment suspended. This transaction type is sent if PayPal tried to collect a recurring payment, but the related recurring payments profile has been suspended.
		'recurring_payment_suspended_due_to_max_failed_payment', // Recurring payment failed and the related recurring payment profile has been suspended
	);

	/**
	 * Constructor from WC_Gateway_Paypal_IPN_Handler
	 */
	public function __construct( $sandbox = false, $receiver_email = '' ) {
		$this->receiver_email = $receiver_email;
		$this->sandbox        = $sandbox;
	}

	/**
	 * There was a valid response
	 *
	 * Based on the IPN Variables documented here: https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNandPDTVariables/#id091EB0901HT
	 *
	 * @param array $transaction_details Post data after wp_unslash
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function valid_response( $transaction_details ) {
		global $wpdb;

		$transaction_details = stripslashes_deep( $transaction_details );

		if ( ! $this->validate_transaction_type( $transaction_details['txn_type'] ) ) {
			return;
		}

		$transaction_details['txn_type'] = strtolower( $transaction_details['txn_type'] );

		$this->process_ipn_request( $transaction_details );

	}

	/**
	 * Process a PayPal Standard Subscription IPN request
	 *
	 * @param array $transaction_details Post data after wp_unslash
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function process_ipn_request( $transaction_details ) {

		// Get the subscription ID and order_key with backward compatibility
		$subscription_id_and_key = self::get_order_id_and_key( $transaction_details, 'shop_subscription' );
		$subscription            = wcs_get_subscription( $subscription_id_and_key['order_id'] );
		$subscription_key        = $subscription_id_and_key['order_key'];

		// For the purposes of processing the IPN, we need to force the ability to update subscription statuses by unhooking the function enforcing strict PayPal support on S- prefixed subscription ids
		remove_filter( 'woocommerce_subscription_payment_gateway_supports', 'WCS_PayPal_Supports::add_feature_support_for_subscription', 10 );

		// We have an invalid $subscription, probably because invoice_prefix has changed since the subscription was first created, so get the subscription by order key
		if ( ! is_callable( array( $subscription, 'get_id' ) ) ) {
			$subscription = wcs_get_subscription( wc_get_order_id_by_order_key( $subscription_key ) );
		}

		if ( 'recurring_payment_suspended_due_to_max_failed_payment' == $transaction_details['txn_type'] && empty( $subscription ) ) {
			WC_Gateway_Paypal::log( 'Returning as "recurring_payment_suspended_due_to_max_failed_payment" transaction is for a subscription created with Express Checkout' );
			return;
		}

		if ( empty( $subscription ) ) {

			// If the IPN is for a cancellation after a failed payment on a PayPal Standard subscription created with Subscriptions < 2.0, the subscription won't be found, but that doesn't mean we should throw an exception, we should  just ignore it
			if ( in_array( $transaction_details['txn_type'], array( 'subscr_cancel', 'subscr_eot' ) ) ) {

				// Check if the reason the subscription can't be found is because it has since been changed to a new PayPal Subscription and this IPN is for the cancellation after a renewal sign-up
				$subscription_id_and_key = self::get_order_id_and_key( $transaction_details, 'shop_subscription', '_old_paypal_subscriber_id' );

				if ( ! empty( $subscription_id_and_key['order_id'] ) ) {
					WC_Gateway_Paypal::log( 'IPN subscription cancellation request ignored - new PayPal Profile ID linked to this subscription, for subscription ' . $subscription_id_and_key['order_id'] );
					return;
				}
			}

			// If the IPN is for a suspension after a switch on a PayPal Standard subscription created with Subscriptions < 2.0, the subscription won't be found, but that doesn't mean we should throw an exception, we should just ignore it
			if ( 'recurring_payment_suspended' === $transaction_details['txn_type'] ) {

				// Check if the reason the subscription can't be found is because it has since been changed after a successful subscription switch
				$subscription_id_and_key = self::get_order_id_and_key( $transaction_details, 'shop_subscription', '_switched_paypal_subscription_id' );

				if ( ! empty( $subscription_id_and_key['order_id'] ) ) {
					WC_Gateway_Paypal::log( 'IPN subscription suspension request ignored - subscription payment gateway changed via switch' . $subscription_id_and_key['order_id'] );
					return;
				}
			}

			if ( empty( $transaction_details['custom'] ) || ! $this->is_woocommerce_payload( $transaction_details['custom'] ) ) {
				WC_Gateway_Paypal::log( 'IPN request ignored - payload is not in a WooCommerce recognizable format' );
				return;
			}
		}

		if ( empty( $subscription ) ) {
			$message = 'Subscription IPN Error: Could not find matching Subscription.'; // We dont' want this to be translated, we need it in English for support
			WC_Gateway_Paypal::log( $message );
			throw new Exception( $message );
		}

		if ( ! hash_equals( $subscription->get_order_key(), $subscription_key ) ) {
			WC_Gateway_Paypal::log( 'Subscription IPN Error: Subscription Key does not match invoice.' );
			exit;
		}

		if ( isset( $transaction_details['txn_id'] ) ) {

			// Make sure the IPN request has not already been handled
			$handled_transactions = $this->get_handled_transactions( $subscription );

			// $ipn_transaction_id will be 'txn_id'_'txn_type'_'payment_status'_'ipn_track_id'
			$ipn_transaction_id = $transaction_details['txn_id'];

			if ( isset( $transaction_details['txn_type'] ) ) {
				$ipn_transaction_id .= '_' . $transaction_details['txn_type'];
			}

			// The same transaction ID is used for different payment statuses, so make sure we handle it only once. See: http://stackoverflow.com/questions/9240235/paypal-ipn-unique-identifier
			if ( isset( $transaction_details['payment_status'] ) ) {
				$ipn_transaction_id .= '_' . $transaction_details['payment_status'];
			}

			if ( isset( $transaction_details['ipn_track_id'] ) ) {
				$ipn_transaction_id .= '_' . $transaction_details['ipn_track_id'];
			}

			if ( in_array( $ipn_transaction_id, $handled_transactions ) ) {
				WC_Gateway_Paypal::log( 'Subscription IPN Error: transaction ' . $ipn_transaction_id . ' has already been correctly handled.' );
				exit;
			}

			// Make sure we're not in the process of handling this IPN request on a server under extreme load and therefore, taking more than a minute to process it (which is the amount of time PayPal allows before resending the IPN request)
			$ipn_lock_transient_name = 'wcs_pp_' . md5( $ipn_transaction_id ); // transient names need to be less than 45 characters and the $ipn_id will be long, e.g. 34292625HU746553V_subscr_payment_completed_5ab4c38e1f39d, so md5

			if ( 'in-progress' == get_transient( $ipn_lock_transient_name ) && 'recurring_payment_suspended_due_to_max_failed_payment' !== $transaction_details['txn_type'] ) {

				WC_Gateway_Paypal::log( 'Subscription IPN Error: an older IPN request with ID ' . $ipn_transaction_id . ' is still in progress.' );

				// We need to send an error code to make sure PayPal does retry the IPN after our lock expires, in case something is actually going wrong and the server isn't just taking a long time to process the request
				status_header( 503 );
				exit;
			}

			// Set a transient to block IPNs with this transaction ID for the next 4 days (An IPN message may be present in PayPal up to 4 days after the original was sent)
			set_transient( $ipn_lock_transient_name, 'in-progress', apply_filters( 'woocommerce_subscriptions_paypal_ipn_request_lock_time', 4 * DAY_IN_SECONDS ) );
		}

		$is_renewal_sign_up_after_failure = false;
		$transaction_order                = false;

		// If the invoice ID doesn't match the default invoice ID and contains the string '-wcsfrp-', the IPN is for a subscription payment to fix up a failed payment
		if ( in_array( $transaction_details['txn_type'], array( 'subscr_signup', 'subscr_payment' ) ) && false !== strpos( $transaction_details['invoice'], '-wcsfrp-' ) ) {

			$transaction_order = $this->get_failed_renewal_order( $subscription, $transaction_details['invoice'] );

			// Handle deleted orders gracefully - the referenced order may have been removed from the database, or may not
			// be one of this subscription's renewal orders at all. When this happens, treat it as a standard renewal so
			// a new order is created at line 319.
			if ( false === $transaction_order || ! is_object( $transaction_order ) ) {
				WC_Gateway_Paypal::log(
					sprintf(
						'IPN renewal: referenced order from invoice %s not found (may have been deleted) or not a renewal order of the subscription. Will create new renewal order.',
						$transaction_details['invoice']
					)
				);
			} elseif ( wcs_get_objects_property( $transaction_order, 'id' ) !== (int) $subscription->get_meta( '_paypal_failed_sign_up_recorded', true ) ) {
				// Check if the failed signup has been previously recorded.
				$is_renewal_sign_up_after_failure = true;
			}
		}

		// If the invoice ID doesn't match the default invoice ID and contains the string '-wcscpm-', the IPN is for a subscription payment method change
		if ( 'subscr_signup' === $transaction_details['txn_type'] && false !== strpos( $transaction_details['invoice'], '-wcscpm-' ) ) {
			$is_payment_change = true;
		} else {
			$is_payment_change = false;
		}

		// Ignore IPN messages when the payment method isn't PayPal
		if ( 'paypal' != $subscription->get_payment_method() ) {

			// The 'recurring_payment_suspended' transaction is actually an Express Checkout transaction type, but PayPal also send it for PayPal Standard Subscriptions suspended by admins at PayPal, so we need to handle it *if* the subscription has PayPal as the payment method, or leave it if the subscription is using a different payment method (because it might be using PayPal Express Checkout or PayPal Digital Goods)
			if ( 'recurring_payment_suspended' == $transaction_details['txn_type'] ) {

				WC_Gateway_Paypal::log( '"recurring_payment_suspended" IPN ignored: recurring payment method is not "PayPal". Returning to allow another extension to process the IPN, like PayPal Digital Goods.' );
				return;

			} elseif ( false === $is_renewal_sign_up_after_failure && false === $is_payment_change ) {

				WC_Gateway_Paypal::log( 'IPN ignored, recurring payment method has changed.' );
				exit;

			}
		}

		// Check the transaction is what the store asked PayPal for before anything is recorded against the subscription.
		$rejection_note = $this->validate_ipn_transaction( $subscription, $transaction_details, $is_renewal_sign_up_after_failure ? $transaction_order : null );

		if ( null !== $rejection_note ) {
			$this->reject_ipn_transaction( $subscription, $rejection_note, $transaction_details );

			// Release the lock so that a resend of the transaction is processed once its cause has been dealt with, instead of being answered with a 503 for the life of the lock.
			if ( isset( $ipn_lock_transient_name ) ) {
				delete_transient( $ipn_lock_transient_name );
			}

			exit;
		}

		if ( $is_renewal_sign_up_after_failure || $is_payment_change ) {

			// Store the old profile ID on the order (for the first IPN message that comes through)
			$existing_profile_id = wcs_get_paypal_id( $subscription );

			if ( empty( $existing_profile_id ) || $existing_profile_id !== $transaction_details['subscr_id'] ) {
				$subscription->update_meta_data( '_old_paypal_subscriber_id', $existing_profile_id );
				$subscription->update_meta_data( '_old_payment_method', $subscription->get_payment_method() );
				$subscription->save();
			}
		}

		// Save the profile ID if it's not a cancellation/expiration request
		if ( isset( $transaction_details['subscr_id'] ) && ! in_array( $transaction_details['txn_type'], array( 'subscr_cancel', 'subscr_eot' ) ) ) {
			wcs_set_paypal_id( $subscription, $transaction_details['subscr_id'] );

			if ( wcs_is_paypal_profile_a( $transaction_details['subscr_id'], 'out_of_date_id' ) && 'disabled' != get_option( 'wcs_paypal_invalid_profile_id' ) ) {
				update_option( 'wcs_paypal_invalid_profile_id', 'yes' );
			}
		}

		$is_first_payment = $subscription->get_payment_count() < 1;

		if ( $subscription->has_status( 'switched' ) ) {
			WC_Gateway_Paypal::log( 'IPN ignored, subscription has been switched.' );
			exit;
		}

		switch ( $transaction_details['txn_type'] ) {
			case 'subscr_signup':
				$order = self::get_parent_order_with_fallback( $subscription );

				// Store PayPal Details on Subscription and Order
				$this->save_paypal_meta_data( $subscription, $transaction_details );
				$this->save_paypal_meta_data( $order, $transaction_details );

				// Now that the profile exists, the second trial period recorded when it was requested is the live one.
				$pending_second_trial_end = $subscription->get_meta( '_paypal_pending_second_trial_end', true );

				if ( '' !== $pending_second_trial_end ) {
					$subscription->update_meta_data( '_paypal_second_trial_end', (int) $pending_second_trial_end );
					$subscription->delete_meta_data( '_paypal_pending_second_trial_end' );
					$subscription->delete_meta_data( '_paypal_second_trial_txn_id' );
					$subscription->save();
				}

				// When there is a free trial & no initial payment amount, we need to mark the order as paid and activate the subscription
				if ( ! $is_payment_change && ! $is_renewal_sign_up_after_failure && 0 == $order->get_total() ) {
					// Safe to assume the subscription has an order here because otherwise we wouldn't get a 'subscr_signup' IPN
					$order->payment_complete(); // No 'txn_id' value for 'subscr_signup' IPN messages

					$subscription = $this->reload_subscription( $subscription );
					$subscription->update_meta_data( '_paypal_first_ipn_ignored_for_pdt', 'true' );
					$subscription->save();
				}

				// Payment completed
				if ( $is_payment_change ) {

					// Set PayPal as the new payment method
					WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $subscription, 'paypal' );

					// We need to cancel the subscription now that the method has been changed successfully
					if ( 'paypal' === $subscription->get_meta( '_old_payment_method', true ) ) {
						self::cancel_subscription( $subscription, $subscription->get_meta( '_old_paypal_subscriber_id', true ) );
					}

					$this->add_order_note( _x( 'IPN subscription payment method changed to PayPal.', 'when it is a payment change, and there is a subscr_signup message, this will be a confirmation message that PayPal accepted it being the new payment method', 'woocommerce-subscriptions' ), $subscription, $transaction_details );

				} else {

					$this->add_order_note( __( 'IPN subscription sign up completed.', 'woocommerce-subscriptions' ), $subscription, $transaction_details );

				}

				if ( $is_payment_change ) {
					WC_Gateway_Paypal::log( 'IPN subscription payment method changed for subscription ' . $subscription->get_id() );
				} else {
					WC_Gateway_Paypal::log( 'IPN subscription sign up completed for subscription ' . $subscription->get_id() );
				}

				break;

			case 'subscr_payment':
				if ( 0.01 == $transaction_details['mc_gross'] ) {
					// Remember which payment this was: the profile owes only one, so validate_ipn_transaction() expects no other.
					$subscription->update_meta_data( '_paypal_second_trial_txn_id', $transaction_details['txn_id'] );
					$subscription->save();

					WC_Gateway_Paypal::log( 'IPN ignored, treating IPN as secondary trial period.' );
					exit;
				}

				$subscription_was_active = $subscription->has_status( 'active' );

				if ( ! $is_first_payment && ! $is_renewal_sign_up_after_failure ) {

					if ( $subscription->has_status( 'active' ) ) {
						remove_action( 'woocommerce_subscription_on-hold_paypal', 'WCS_PayPal_Status_Manager::suspend_subscription' );
						$subscription->update_status( 'on-hold' );
						add_action( 'woocommerce_subscription_on-hold_paypal', 'WCS_PayPal_Status_Manager::suspend_subscription' );
					}

					// Gets renewals order based on transaction id.
					$transaction_order = $this->get_renewal_order_by_transaction_id( $subscription, $transaction_details['txn_id'] );
					if ( is_null( $transaction_order ) ) {
						// if renewal order is null, search for a parent order.
						$transaction_order = $this->get_parent_order_by_transaction_id( $subscription, $transaction_details['txn_id'] );

						// If this transaction id is linked to a parent order, we need to set $is_first_payment to true.
						if ( ! is_null( $transaction_order ) ) {
							$is_first_payment = true;
						}
					}

					// If we still have a non-valid order, let's create a renewal order.
					if ( is_null( $transaction_order ) ) {
						$transaction_order = wcs_create_renewal_order( $subscription );
					}

					// Set PayPal as the payment method.
					$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
					$transaction_order->set_payment_method( $available_gateways['paypal'] );
				}

				if ( 'completed' == strtolower( $transaction_details['payment_status'] ) ) {
					// Store PayPal Details
					$this->save_paypal_meta_data( $subscription, $transaction_details );

					// Subscription Payment completed
					$this->add_order_note( __( 'IPN subscription payment completed.', 'woocommerce-subscriptions' ), $subscription, $transaction_details );

					WC_Gateway_Paypal::log( 'IPN subscription payment completed for subscription ' . $subscription->get_id() );

					// First payment on order, process payment & activate subscription
					if ( $is_first_payment ) {
						$parent_order = self::get_parent_order_with_fallback( $subscription );

						// If we don't a valid order, let's create a renewal order.
						if ( ! $parent_order ) {
							$parent_order = wcs_create_renewal_order( $subscription );
						}

						if ( ! $parent_order->is_paid() ) {
							$parent_order->payment_complete( $transaction_details['txn_id'] );
						}

						// Store PayPal Details on Order
						$this->save_paypal_meta_data( $parent_order, $transaction_details );

						// IPN got here first or PDT will never arrive. Normally PDT would have arrived, so the first IPN would not be the first payment. In case the the first payment is an IPN, we need to make sure to not ignore the second one
						$subscription = $this->reload_subscription( $subscription );
						$subscription->update_meta_data( '_paypal_first_ipn_ignored_for_pdt', 'true' );
						$subscription->save();

					// Ignore the first IPN message if the PDT should have handled it (if it didn't handle it, it will have been dealt with as first payment), but set a flag to make sure we only ignore it once
					} elseif ( $subscription->get_payment_count() === 1 && '' !== WCS_PayPal::get_option( 'identity_token' ) && 'true' !== $subscription->get_meta( '_paypal_first_ipn_ignored_for_pdt', true ) && false === $is_renewal_sign_up_after_failure ) {

						WC_Gateway_Paypal::log( 'IPN subscription payment ignored for subscription ' . $subscription->get_id() . ' due to PDT previously handling the payment.' );

						$subscription->update_meta_data( '_paypal_first_ipn_ignored_for_pdt', 'true' );
						$subscription->save();

					// Process the payment if the subscription is active
					} elseif ( ! $subscription->has_status( array( 'cancelled', 'expired', 'switched', 'trash' ) ) ) {

						if ( true === $is_renewal_sign_up_after_failure && is_object( $transaction_order ) ) {

							$subscription->update_meta_data( '_paypal_failed_sign_up_recorded', wcs_get_objects_property( $transaction_order, 'id' ) );
							$subscription->save();

							// We need to cancel the old subscription now that the method has been changed successfully
							if ( 'paypal' === $subscription->get_meta( '_old_payment_method', true ) ) {
								$profile_id = $subscription->get_meta( '_old_paypal_subscriber_id' );

								// Make sure we don't cancel the current profile
								if ( $profile_id !== $transaction_details['subscr_id'] ) {
									self::cancel_subscription( $subscription, $profile_id );
								}

								$this->add_order_note( __( 'IPN subscription failing payment method changed.', 'woocommerce-subscriptions' ), $subscription, $transaction_details );
							}
						}

						try {

							// to cover the case when PayPal drank too much coffee and sent IPNs early - needs to happen before $transaction_order->payment_complete
							$update_dates = array();

							if ( $subscription->get_time( 'trial_end' ) > gmdate( 'U' ) ) {
								$update_dates['trial_end'] = gmdate( 'Y-m-d H:i:s', gmdate( 'U' ) - 1 );
								WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment for subscription %d: trial_end is in futute (date: %s) setting to %s.', $subscription->get_id(), $subscription->get_date( 'trial_end' ), $update_dates['trial_end'] ) );
							} else {
								WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment for subscription %d: trial_end is in past (date: %s).', $subscription->get_id(), $subscription->get_date( 'trial_end' ) ) );
							}

							if ( $subscription->get_time( 'next_payment' ) > gmdate( 'U' ) ) {
								$update_dates['next_payment'] = gmdate( 'Y-m-d H:i:s', gmdate( 'U' ) - 1 );
								WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment for subscription %d: next_payment is in future (date: %s) setting to %s.', $subscription->get_id(), $subscription->get_date( 'next_payment' ), $update_dates['next_payment'] ) );
							} else {
								WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment for subscription %d: next_payment is in past (date: %s).', $subscription->get_id(), $subscription->get_date( 'next_payment' ) ) );
							}

							if ( ! empty( $update_dates ) ) {
								$subscription->update_dates( $update_dates );
							}
						} catch ( Exception $e ) {
							WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment exception subscription %d: %s.', $subscription->get_id(), $e->getMessage() ) );
						}

						// The profile only needs reactivating at PayPal where the store suspended it there, which is the case
						// when the order being paid is the failed one a rejected payment left behind - the payment being a
						// resend of the rejected transaction - and not for the hold applied above, nor for a recovery profile.
						$reactivate_profile_at_paypal = ! $subscription_was_active && ! $is_renewal_sign_up_after_failure && wc_string_to_bool( $transaction_order->get_meta( WC_Subscription::RENEWAL_FAILED_META_KEY, true ) );

						if ( ! $reactivate_profile_at_paypal ) {
							remove_action( 'woocommerce_subscription_activated_paypal', 'WCS_PayPal_Status_Manager::reactivate_subscription' );
						}

						try {
							$transaction_order->payment_complete( $transaction_details['txn_id'] );
						} catch ( Exception $e ) {
							WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment exception calling $transaction_order->payment_complete() for subscription %d: %s.', $subscription->get_id(), $e->getMessage() ) );
						}

						$this->add_order_note( __( 'IPN subscription payment completed.', 'woocommerce-subscriptions' ), $transaction_order, $transaction_details );

						if ( ! $reactivate_profile_at_paypal ) {
							add_action( 'woocommerce_subscription_activated_paypal', 'WCS_PayPal_Status_Manager::reactivate_subscription' );
						}

						wcs_set_paypal_id( $transaction_order, $transaction_details['subscr_id'] );
					}
				} elseif ( in_array( strtolower( $transaction_details['payment_status'] ), array( 'pending', 'failed' ) ) ) {

					// Subscription Payment completed
					// translators: placeholder is payment status (e.g. "completed")
					$this->add_order_note( sprintf( _x( 'IPN subscription payment %s.', 'used in order note', 'woocommerce-subscriptions' ), $transaction_details['payment_status'] ), $subscription, $transaction_details );

					if ( ! $is_first_payment ) {

						wcs_set_objects_property( $transaction_order, 'transaction_id', $transaction_details['txn_id'] );

						if ( 'failed' == strtolower( $transaction_details['payment_status'] ) ) {
							$subscription->payment_failed();
							// translators: placeholder is payment status (e.g. "completed")
							$this->add_order_note( sprintf( _x( 'IPN subscription payment %s.', 'used in order note', 'woocommerce-subscriptions' ), $transaction_details['payment_status'] ), $transaction_order, $transaction_details );
						} else {
							$transaction_order->update_status( 'on-hold' );
							// translators: 1: payment status (e.g. "completed"), 2: pending reason.
							$this->add_order_note( sprintf( _x( 'IPN subscription payment %1$s for reason: %2$s.', 'used in order note', 'woocommerce-subscriptions' ), $transaction_details['payment_status'], $transaction_details['pending_reason'] ), $transaction_order, $transaction_details );
						}
					}

					WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment %s for subscription %d ', $transaction_details['payment_status'], $subscription->get_id() ) );
				} else {

					WC_Gateway_Paypal::log( 'IPN subscription payment notification received for subscription ' . $subscription->get_id() . ' with status ' . $transaction_details['payment_status'] );

				}

				break;

			// Admins can suspend subscription at PayPal triggering this IPN
			case 'recurring_payment_suspended':

				// When a subscriber suspends a PayPal Standard subscription, PayPal will notify WooCommerce by sending an IPN that uses an Express Checkout Recurring Payment payload, instead of an IPN payload for a PayPal Standard Subscription. This means the payload uses the 'recurring_payment_id' key for the subscription ID, not the 'subscr_id' key.
				$ipn_profile_id = ( isset( $transaction_details['subscr_id'] ) ) ? $transaction_details['subscr_id'] : $transaction_details['recurring_payment_id'];

				// Make sure subscription hasn't been linked to a new payment method
				if ( wcs_get_paypal_id( $subscription ) != $ipn_profile_id ) {

					WC_Gateway_Paypal::log( sprintf( 'IPN "recurring_payment_suspended" ignored for subscription %d - PayPal profile ID has changed', $subscription->get_id() ) );

				} else if ( $subscription->has_status( 'active' ) ) {

					// We don't need to suspend the subscription at PayPal because it's already on-hold there
					remove_action( 'woocommerce_subscription_on-hold_paypal', 'WCS_PayPal_Status_Manager::suspend_subscription' );

					$subscription->update_status( 'on-hold', __( 'IPN subscription suspended.', 'woocommerce-subscriptions' ) );

					add_action( 'woocommerce_subscription_on-hold_paypal', 'WCS_PayPal_Status_Manager::suspend_subscription' );

					WC_Gateway_Paypal::log( 'IPN subscription suspended for subscription ' . $subscription->get_id() );

				} else {

					WC_Gateway_Paypal::log( sprintf( 'IPN "recurring_payment_suspended" ignored for subscription %d. Subscription already %s.', $subscription->get_id(), $subscription->get_status() ) );

				}

				break;

			case 'subscr_cancel':

				// Make sure the subscription hasn't been linked to a new payment method
				if ( wcs_get_paypal_id( $subscription ) != $transaction_details['subscr_id'] ) {

					WC_Gateway_Paypal::log( 'IPN subscription cancellation request ignored - new PayPal Profile ID linked to this subscription, for subscription ' . $subscription->get_id() );

				} else {

					$subscription->cancel_order( __( 'IPN subscription cancelled.', 'woocommerce-subscriptions' ) );

					WC_Gateway_Paypal::log( 'IPN subscription cancelled for subscription ' . $subscription->get_id() );

				}

				break;

			case 'subscr_eot': // Subscription ended, either due to failed payments or expiration

				WC_Gateway_Paypal::log( 'IPN EOT request ignored for subscription ' . $subscription->get_id() );
				break;

			case 'subscr_failed': // Subscription sign up failed
			case 'recurring_payment_suspended_due_to_max_failed_payment': // Recurring payment failed

				$ipn_failure_note = __( 'IPN subscription payment failure.', 'woocommerce-subscriptions' );

				if ( ! $is_first_payment && ! $is_renewal_sign_up_after_failure && $subscription->has_status( 'active' ) ) {
					// Generate a renewal order to record the failed payment
					$transaction_order = wcs_create_renewal_order( $subscription );

					// Set PayPal as the payment method
					$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
					$transaction_order->set_payment_method( $available_gateways['paypal'] );
					$this->add_order_note( $ipn_failure_note, $transaction_order, $transaction_details );
				}

				WC_Gateway_Paypal::log( 'IPN subscription payment failure for subscription ' . $subscription->get_id() );

				// Subscription Payment completed
				$this->add_order_note( $ipn_failure_note, $subscription, $transaction_details );

				try {
					$subscription->payment_failed();
				} catch ( Exception $e ) {
					WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment failure, unable to process payment failure. Exception: %s ', $e->getMessage() ) );
				}

				break;
		}

		// Store the transaction IDs to avoid handling requests duplicated by PayPal
		if ( isset( $transaction_details['txn_id'] ) ) {
			$subscription           = $this->reload_subscription( $subscription );
			$handled_transactions   = $this->get_handled_transactions( $subscription );
			$handled_transactions[] = $ipn_transaction_id;

			$subscription->update_meta_data( '_paypal_ipn_tracking_ids', $handled_transactions );
			$subscription->save();
		}

		// And delete the transient that's preventing other IPN's being processed
		if ( isset( $ipn_lock_transient_name ) ) {
			delete_transient( $ipn_lock_transient_name );
		}

		// Log completion
		$log_message = 'IPN subscription request processed for ' . $subscription->get_id();

		if ( isset( $ipn_id ) && ! empty( $ipn_id ) ) {
			$log_message .= sprintf( ' (%s)', $ipn_id );
		}

		WC_Gateway_Paypal::log( $log_message );

		// Prevent default IPN handling for subscription txn_types
		exit;
	}

	/**
	 * Reads the subscription again, so that meta written later in a request is not saved on top of an instance
	 * which still holds the values the subscription had earlier in it.
	 *
	 * Handling an IPN can complete a payment, and completing a payment advances the subscription's schedule on
	 * its own instance. The instance loaded at the top of process_ipn_request() is left holding the dates and
	 * status the subscription had before that, and saving it writes them back. Every change this class makes to
	 * the subscription is saved where it is made, so replacing the instance loses nothing.
	 *
	 * @param WC_Subscription $subscription The subscription to read again.
	 *
	 * @return WC_Subscription The subscription as stored, or the instance passed in where it cannot be read.
	 */
	protected function reload_subscription( $subscription ) {
		$reloaded = wcs_get_subscription( $subscription->get_id() );

		if ( ! $reloaded instanceof WC_Subscription ) {
			WC_Gateway_Paypal::log( sprintf( 'IPN subscription %d could not be read back; continuing with the subscription already in hand.', $subscription->get_id() ) );
			return $subscription;
		}

		return $reloaded;
	}

	/**
	 * Gets the IPN transactions already handled for a subscription.
	 *
	 * @param WC_Subscription $subscription The subscription to read.
	 *
	 * @return array The handled transaction IDs, empty where none have been recorded.
	 */
	protected function get_handled_transactions( $subscription ) {
		$handled_transactions = $subscription->get_meta( '_paypal_ipn_tracking_ids', true );

		return is_array( $handled_transactions ) ? $handled_transactions : array();
	}

	/**
	 * Check that a sign up or payment was made to this store's PayPal account, in the subscription's currency and,
	 * for a completed payment, for the amount PayPal was asked to bill.
	 *
	 * PayPal Standard sends the customer's browser to PayPal with the receiving account, the amounts and the currency
	 * in the request, where any of them can be changed. A genuine, PayPal-verified IPN is therefore not on its own
	 * evidence that this store was paid what it asked for: the payment it describes may have gone to someone else,
	 * or have been for some other amount.
	 *
	 * @param WC_Subscription $subscription         The subscription the IPN is for.
	 * @param array           $transaction_details  Post data after wp_unslash.
	 * @param WC_Order|null   $failed_renewal_order The renewal order a '-wcsfrp-' profile was created to pay, while that payment is still outstanding.
	 * @return string|null A note saying why the transaction was rejected, or null if it was accepted.
	 * @since 9.2.0
	 */
	protected function validate_ipn_transaction( $subscription, $transaction_details, $failed_renewal_order = null ) {
		// Sign ups and payments are the only transaction types which move money. The rest carry nothing to check.
		if ( ! in_array( $transaction_details['txn_type'], array( 'subscr_signup', 'subscr_payment' ), true ) ) {
			return null;
		}

		if ( ! $this->is_paid_to_this_store( $transaction_details ) ) {
			$receiver_email = isset( $transaction_details['receiver_email'] ) ? $transaction_details['receiver_email'] : '';

			WC_Gateway_Paypal::log( sprintf( 'Subscription IPN Error: IPN response is for another account: receiver_email %1$s, business %2$s. Your email is %3$s', $receiver_email, isset( $transaction_details['business'] ) ? $transaction_details['business'] : '', $this->receiver_email ) );

			// translators: %1$s: the PayPal account the payment was made to.
			return sprintf( __( 'Validation error: PayPal IPN response from a different email address (%1$s).', 'woocommerce-subscriptions' ), $receiver_email );
		}

		$currency = isset( $transaction_details['mc_currency'] ) ? $transaction_details['mc_currency'] : '';

		if ( $subscription->get_currency() !== $currency ) {
			WC_Gateway_Paypal::log( sprintf( 'Subscription IPN Error: Currencies do not match (sent "%1$s" | returned "%2$s")', $subscription->get_currency(), $currency ) );

			// translators: %1$s: currency code.
			return sprintf( __( 'Validation error: PayPal currencies do not match (code %1$s).', 'woocommerce-subscriptions' ), $currency );
		}

		// A sign up describes the profile rather than a payment, and only a completed payment credits the store: a
		// pending or failed one records nothing, and a refund or reversal reports an amount which was never the one
		// being billed. Each payment is checked when it completes.
		if ( 'subscr_payment' !== $transaction_details['txn_type'] || ! isset( $transaction_details['payment_status'] ) || 'completed' !== strtolower( $transaction_details['payment_status'] ) ) {
			return null;
		}

		$gross = isset( $transaction_details['mc_gross'] ) ? $transaction_details['mc_gross'] : '';

		if ( 0.01 === (float) $gross ) {
			// PayPal requires a non-zero amount for the second trial period used to hold a profile's first payment back
			// by more than 90 days, so a payment of 0.01 is expected - and ignored by process_ipn_request() - on a profile
			// the store created with one. It lands around the time that trial period was created to end: up to the whole
			// period before it and, as the trial lengths are rounded to whole weeks or calendar months, up to a couple of
			// weeks after it. Any other payment of 0.01 is for the wrong amount.
			$second_trial_end = $subscription->get_meta( '_paypal_second_trial_end', true );

			// For a profile created before WCS_PayPal_Standard_Request recorded this, the next payment date is the best that is known.
			if ( '' === $second_trial_end ) {
				$second_trial_end = $subscription->get_time( 'next_payment' );
			}

			// A profile owes exactly one such payment, which process_ipn_request() records, so once it has been taken
			// only a resend of it is expected - not a renewal of 0.01 in its place.
			$second_trial_txn_id = $subscription->get_meta( '_paypal_second_trial_txn_id', true );
			$transaction_id      = isset( $transaction_details['txn_id'] ) ? $transaction_details['txn_id'] : '';

			if ( time() < (int) $second_trial_end + 2 * WEEK_IN_SECONDS && ( '' === $second_trial_txn_id || $second_trial_txn_id === $transaction_id ) ) {
				return null;
			}
		}

		$expected_amounts = $this->get_expected_payment_amounts( $subscription, $transaction_details, $failed_renewal_order );

		foreach ( $expected_amounts as $expected_amount ) {
			if ( number_format( (float) $expected_amount, 2, '.', '' ) === number_format( (float) $gross, 2, '.', '' ) ) {
				return null;
			}
		}

		WC_Gateway_Paypal::log( sprintf( 'Subscription IPN Error: Amounts do not match (expected %1$s | gross %2$s)', implode( ' or ', $expected_amounts ), $gross ) );

		// translators: %1$s: gross amount reported by PayPal.
		return sprintf( __( 'Validation error: PayPal amounts do not match (gross %1$s).', 'woocommerce-subscriptions' ), $gross );
	}

	/**
	 * Check that a transaction was paid to this store's PayPal account.
	 *
	 * PayPal reports the receiving account twice: 'business' is the address or merchant ID the payment was addressed
	 * to, and 'receiver_email' is that account's primary address whichever of its addresses was used. The store's
	 * setting may hold either, so a match on either is accepted. Both come from PayPal rather than the customer, and
	 * a payment sent to another account matches neither.
	 *
	 * @param array $transaction_details Post data after wp_unslash.
	 * @return bool
	 * @since 9.2.0
	 */
	protected function is_paid_to_this_store( $transaction_details ) {
		foreach ( array( 'receiver_email', 'business' ) as $key ) {
			$account = isset( $transaction_details[ $key ] ) ? trim( $transaction_details[ $key ] ) : '';

			if ( '' !== $account && 0 === strcasecmp( $account, trim( $this->receiver_email ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record that a transaction was rejected and withhold service for it.
	 *
	 * A rejected payment on the profile funding an active subscription puts it on hold - which suspends that profile
	 * at PayPal, as a failed payment does, so it does not go on taking payments the store will not honour - and leaves
	 * it a failed renewal order to pay, so that it stays on hold until a payment the store can verify is made rather
	 * than until the customer reactivates it from their account. Paying that order creates a fresh PayPal Standard
	 * profile for its total, which replaces the suspended one; reactivating the subscription instead reactivates it,
	 * as does a resend of the rejected transaction once its cause has been put right.
	 *
	 * A rejected sign up moves no money, and a rejected payment on a profile the subscription has since moved on from
	 * displaces none - the subscription is funded by whatever funds it now - so those are only noted.
	 *
	 * @param WC_Subscription $subscription        The subscription the IPN is for.
	 * @param string          $note                Why the transaction was rejected.
	 * @param array           $transaction_details Post data after wp_unslash.
	 * @since 9.2.0
	 */
	protected function reject_ipn_transaction( $subscription, $note, $transaction_details ) {
		$this->add_order_note( $note, $subscription, $transaction_details );

		$profile_id                    = wcs_get_paypal_id( $subscription );
		$is_payment_on_current_profile = 'subscr_payment' === $transaction_details['txn_type'] && ( ! $profile_id || ( isset( $transaction_details['subscr_id'] ) && $profile_id === $transaction_details['subscr_id'] ) );

		if ( ! $is_payment_on_current_profile || 'paypal' !== $subscription->get_payment_method() || ! $subscription->can_be_updated_to( 'on-hold' ) ) {
			return;
		}

		// Unless there is already an order the customer can pay, leave one. It carries the rejected transaction's ID, so
		// that a resend of the transaction - once whatever caused the rejection has been put right - pays it.
		$parent_order       = $subscription->get_parent();
		$last_renewal_order = wcs_get_last_non_early_renewal_order( $subscription );

		if ( ! ( $parent_order && $parent_order->needs_payment() ) && ! ( $last_renewal_order && $last_renewal_order->needs_payment() ) ) {
			$renewal_order = wcs_create_renewal_order( $subscription );

			if ( is_wp_error( $renewal_order ) ) {
				WC_Gateway_Paypal::log( 'Subscription IPN Error: could not create a renewal order for the rejected payment: ' . $renewal_order->get_error_message() );
			} else {
				$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) );
				$renewal_order->set_transaction_id( isset( $transaction_details['txn_id'] ) ? $transaction_details['txn_id'] : '' );
				$renewal_order->update_meta_data( WC_Subscription::RENEWAL_FAILED_META_KEY, wc_bool_to_string( true ) );

				// Failed as WC_Subscription::payment_failed_for_related_order() marks an order, but without the
				// subscription's payment-failed handling: it is held below, and a PayPal Standard profile can't be retried.
				remove_filter( 'woocommerce_order_status_changed', 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment' );
				$renewal_order->update_status( 'failed', $note );
				add_filter( 'woocommerce_order_status_changed', 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment', 10, 3 );
			}
		}

		$subscription->update_status( 'on-hold' );
	}

	/**
	 * Get the amounts a completed PayPal Standard subscription payment may be for.
	 *
	 * That is the amount PayPal was asked to bill when the profile was created: the total of the order the profile
	 * was created to pay for the first payment on it, and the subscription's recurring total for every payment after
	 * that. It is not taken from the order the payment will be recorded against because, for a renewal, that order
	 * does not exist yet - process_ipn_request() creates it once the payment has been accepted.
	 *
	 * @param WC_Subscription $subscription         The subscription being paid.
	 * @param array           $transaction_details  Post data after wp_unslash.
	 * @param WC_Order|null   $failed_renewal_order The renewal order a '-wcsfrp-' profile was created to pay, while that payment is still outstanding.
	 * @return array The acceptable amounts.
	 * @since 9.2.0
	 */
	protected function get_expected_payment_amounts( $subscription, $transaction_details, $failed_renewal_order = null ) {
		$parent_order   = $subscription->get_parent();
		$transaction_id = isset( $transaction_details['txn_id'] ) ? $transaction_details['txn_id'] : '';

		if ( $failed_renewal_order ) {
			$expected_amounts = array( $failed_renewal_order->get_total() );
		} elseif ( ! $parent_order || $parent_order->get_total() <= 0 ) {
			// Without a parent order, or with nothing to pay on it, the profile bills the recurring amount from the start.
			$expected_amounts = array( $subscription->get_total() );
		} elseif ( $subscription->get_payment_count() < 1 || $parent_order->get_transaction_id() === $transaction_id ) {
			// The first payment is for the parent order, which may include a sign-up fee or other items the recurring
			// amount does not. PDT can have recorded it before its IPN arrives, in which case the transaction ID it set
			// on the order identifies the payment.
			$expected_amounts = array( $parent_order->get_total() );
		} elseif ( 'paypal' === $parent_order->get_payment_method() && '' === $parent_order->get_transaction_id() && 0 === $subscription->get_payment_count( 'completed', 'renewal' ) ) {
			// A parent order placed through PayPal was marked paid without a PayPal transaction - by the merchant, say,
			// while its IPN was delayed. Until a renewal has been recorded, this may be that first payment or the first
			// renewal.
			$expected_amounts = array( $parent_order->get_total(), $subscription->get_total() );
		} else {
			$expected_amounts = array( $subscription->get_total() );
		}

		/**
		 * Filter the amounts a PayPal Standard subscription payment may be for.
		 *
		 * The recurring amount of a PayPal Standard profile is fixed when the profile is created, and cannot be changed
		 * from the store. A subscription whose total has been edited since, or whose taxes have changed, will have its
		 * renewals rejected for not matching the total PayPal still bills.
		 *
		 * @since 9.2.0
		 *
		 * @param array           $expected_amounts    The acceptable amounts.
		 * @param WC_Subscription $subscription        The subscription being paid.
		 * @param array           $transaction_details Post data after wp_unslash.
		 */
		return apply_filters( 'woocommerce_subscriptions_paypal_standard_expected_payment_amounts', $expected_amounts, $subscription, $transaction_details );
	}

	/**
	 * Get the renewal order a '-wcsfrp-' invoice names as the one its profile was created to pay.
	 *
	 * The invoice is set by the store when the profile is created, but it passes through the customer's browser on the
	 * way to PayPal, so the order it names is only trusted when it is one of the subscription's own renewal orders.
	 * PayPal keeps sending the same invoice for every payment on the profile, and only the first of them pays that
	 * order, so once it has been paid the profile's payments are ordinary renewals.
	 *
	 * @param WC_Subscription $subscription The subscription the IPN is for.
	 * @param string          $invoice      The IPN's invoice, ending in '-wcsfrp-' and an order ID.
	 * @return WC_Order|null The renewal order, or null if it no longer exists, is not the subscription's, or is paid.
	 * @since 9.2.0
	 */
	protected function get_failed_renewal_order( $subscription, $invoice ) {
		$order             = wc_get_order( substr( $invoice, strrpos( $invoice, '-' ) + 1 ) );
		$renewal_order_ids = $subscription->get_related_orders( 'ids', 'renewal' );

		if ( ! $order || ! isset( $renewal_order_ids[ $order->get_id() ] ) || $order->is_paid() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Return valid transaction types
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_transaction_types() {
		return $this->transaction_types;
	}


	/**
	 * Checks if a string may include a WooCommerce order key.
	 *
	 * This function expects a generic payload, in any serialization format. It looks for an 'order key' code. This
	 * function uses regular expressions and looks for 'order key'. WooCommerce allows plugins to modify the order
	 * keys through filtering, unfortunately we only check for the original
	 *
	 * @param string $payload PayPal payload data
	 *
	 * @return bool
	 */
	protected function is_woocommerce_payload( $payload ) {
		return is_numeric( $payload ) ||
			(bool) preg_match( '/(wc_)?order_[A-Za-z0-9]{5,20}/', $payload );
	}

	/**
	 * Checks a set of args and derives an Order ID with backward compatibility for WC < 1.7 where 'custom' was the Order ID.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_order_id_and_key( $args, $order_type = 'shop_order', $meta_key = '_paypal_subscription_id' ) {
		$order_id  = '';
		$order_key = '';

		if ( isset( $args['subscr_id'] ) ) { // PayPal Standard IPN message
			$subscription_id = $args['subscr_id'];
		} elseif ( isset( $args['recurring_payment_id'] ) ) { // PayPal Express Checkout IPN, most likely 'recurring_payment_suspended_due_to_max_failed_payment', for a PayPal Standard Subscription
			$subscription_id = $args['recurring_payment_id'];
		} else {
			$subscription_id = '';
		}

		// First try and get the order ID by the subscription ID
		if ( ! empty( $subscription_id ) ) {
			$orders = wcs_get_orders_with_meta_query(
				[
					'limit'      => 1,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'type'       => $order_type,
					'status'     => 'any',
					'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'     => $meta_key,
							'value'   => $subscription_id,
							'compare' => '=',
						],
					],
				]
			);

			if ( ! empty( $orders ) ) {
				$order_id  = $orders[0]->get_id();
				$order_key = $orders[0]->get_order_key();
			}
		}

		// Couldn't find the order ID by subscr_id, so it's either not set on the order yet or the $args doesn't have a subscr_id (?!), either way, let's get it from the args
		if ( empty( $order_id ) && isset( $args['custom'] ) ) {
			$order_details = json_decode( $args['custom'] );

			if ( is_object( $order_details ) ) { // WC 2.3.11+ converted the custom value to JSON, if we have an object, we've got valid JSON

				if ( 'shop_order' === $order_type ) {
					$order_id  = $order_details->order_id;
					$order_key = $order_details->order_key;
				} elseif ( isset( $order_details->subscription_id ) ) {
					// Subscription created with Subscriptions 2.0+
					$order_id  = $order_details->subscription_id;
					$order_key = $order_details->subscription_key;
				} else {
					// Subscription created with Subscriptions < 2.0
					$subscriptions = wcs_get_subscriptions_for_order( absint( $order_details->order_id ), array( 'order_type' => array( 'parent' ) ) );

					if ( ! empty( $subscriptions ) ) {
						$subscription = array_pop( $subscriptions );
						$order_id     = $subscription->get_id();
						$order_key    = $subscription->get_order_key();
					}
				}
			} else { // WC < 2.3.11, we could have a variety of payloads, but something has gone wrong if we got to here as we should only be here on new purchases where the '_paypal_subscription_id' is not already set, so throw an exception
				WC_Gateway_Paypal::log( __( 'Invalid PayPal IPN Payload: unable to find matching subscription.', 'woocommerce-subscriptions' ) );
			}
		}

		return array(
			'order_id'  => (int) $order_id,
			// The key can come from the IPN's JSON payload, so it is not guaranteed to be a string.
			'order_key' => is_scalar( $order_key ) ? (string) $order_key : '',
		);
	}

	/**
	 * This function will try to get the parent order, and if not available, will get the last order related to the Subscription.
	 *
	 * @param WC_Subscription $subscription The Subscription.
	 *
	 * @return WC_Order Parent order or the last related order (renewal)
	 */
	protected static function get_parent_order_with_fallback( $subscription ) {
		$order = $subscription->get_parent();
		if ( ! $order ) {
			$order = $subscription->get_last_order( 'all' );
		}

		return $order;
	}

	/**
	 * Cancel a specific PayPal Standard Subscription Profile with PayPal.
	 *
	 * Used when switching payment methods with PayPal Standard to make sure that
	 * the old subscription's profile ID is cancelled, not the new one.
	 *
	 * @param WC_Subscription $subscription A subscription object
	 * @param string $old_paypal_subscriber_id A PayPal Subscription Profile ID
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected static function cancel_subscription( $subscription, $old_paypal_subscriber_id ) {

		// No need to cancel billing agreements
		if ( wcs_is_paypal_profile_a( $old_paypal_subscriber_id, 'billing_agreement' ) ) {
			return;
		}

		$current_profile_id = wcs_get_paypal_id( $subscription->get_id() );

		// Update the subscription using the old profile ID
		wcs_set_paypal_id( $subscription, $old_paypal_subscriber_id );

		// Call update_subscription_status() directly as we don't want the notes added by WCS_PayPal_Status_Manager::cancel_subscription()
		WCS_PayPal_Status_Manager::update_subscription_status( $subscription, 'Cancel' );

		// Restore the current profile ID
		wcs_set_paypal_id( $subscription, $current_profile_id );
	}

	/**
	 * Check for a valid transaction type
	 *
	 * @param  string $txn_type
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function validate_transaction_type( $txn_type ) {
		if ( in_array( strtolower( $txn_type ), $this->get_transaction_types() ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Add an note for the given order or subscription
	 *
	 * @param string $note The text note
	 * @param WC_Order $order An order object
	 * @param array $transaction_details The transaction details, as provided by PayPal
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.20
	 */
	protected function add_order_note( $note, $order, $transaction_details ) {
		$note = apply_filters( 'wcs_paypal_ipn_note', $note, $order, $transaction_details );
		if ( ! empty( $note ) ) {
			$order->add_order_note( $note );
		}
	}

	/**
	 * Get an order associated with a subscription that has a specified transaction id.
	 *
	 * @param WC_Subscription $subscription
	 * @param int $transaction_id Id from transaction details as provided by PayPal
	 * @param array|string $order_types Order type we want. Defaults to any.
	 *
	 * @return WC_Order|null If order with that transaction id, WC_Order object, otherwise null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.3
	 */
	protected function get_order_by_transaction_id( $subscription, $transaction_id, $order_types = 'any' ) {
		$orders        = $subscription->get_related_orders( 'all', $order_types );
		$renewal_order = null;

		foreach ( $orders as $order ) {
			if ( $order->get_transaction_id() == $transaction_id ) {
				$renewal_order = $order;
				break;
			}
		}

		return $renewal_order;
	}

	/**
	* Get a renewal order associated with a subscription that has a specified transaction id.
	*
	* @param WC_Subscription $subscription
	* @param int $transaction_id Id from transaction details as provided by PayPal
	* @return WC_Order|null If order with that transaction id, WC_Order object, otherwise null
	* @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	*/
	protected function get_renewal_order_by_transaction_id( $subscription, $transaction_id ) {
		return self::get_order_by_transaction_id( $subscription, $transaction_id, 'renewal' );
	}

	/**
	 * Get a parent order associated with a subscription that has a specified transaction id.
	 *
	 * @param WC_Subscription $subscription
	 * @param int $transaction_id Id from transaction details as provided by PayPal
	 *
	 * @return WC_Order|null If order with that transaction id, WC_Order object, otherwise null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.3
	 */
	protected function get_parent_order_by_transaction_id( $subscription, $transaction_id ) {
		return self::get_order_by_transaction_id( $subscription, $transaction_id, 'parent' );
	}
}
