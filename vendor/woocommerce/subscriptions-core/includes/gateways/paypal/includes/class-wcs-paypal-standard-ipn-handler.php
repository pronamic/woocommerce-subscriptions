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
 * @since       2.0
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
	 * @since 2.0
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
	 * @since 2.0
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

		if ( $subscription->get_order_key() != $subscription_key ) {
			WC_Gateway_Paypal::log( 'Subscription IPN Error: Subscription Key does not match invoice.' );
			exit;
		}

		if ( isset( $transaction_details['txn_id'] ) ) {

			// Make sure the IPN request has not already been handled
			$handled_transactions = get_post_meta( $subscription->get_id(), '_paypal_ipn_tracking_ids', true );

			if ( empty( $handled_transactions ) ) {
				$handled_transactions = array();
			}

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

		// If the invoice ID doesn't match the default invoice ID and contains the string '-wcsfrp-', the IPN is for a subscription payment to fix up a failed payment
		if ( in_array( $transaction_details['txn_type'], array( 'subscr_signup', 'subscr_payment' ) ) && false !== strpos( $transaction_details['invoice'], '-wcsfrp-' ) ) {

			$transaction_order = wc_get_order( substr( $transaction_details['invoice'], strrpos( $transaction_details['invoice'], '-' ) + 1 ) );

			// check if the failed signup has been previously recorded
			if ( wcs_get_objects_property( $transaction_order, 'id' ) != get_post_meta( $subscription->get_id(), '_paypal_failed_sign_up_recorded', true ) ) {
				$is_renewal_sign_up_after_failure = true;
			}
		}

		// If the invoice ID doesn't match the default invoice ID and contains the string '-wcscpm-', the IPN is for a subscription payment method change
		if ( 'subscr_signup' == $transaction_details['txn_type'] && false !== strpos( $transaction_details['invoice'], '-wcscpm-' ) ) {
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

		if ( $is_renewal_sign_up_after_failure || $is_payment_change ) {

			// Store the old profile ID on the order (for the first IPN message that comes through)
			$existing_profile_id = wcs_get_paypal_id( $subscription );

			if ( empty( $existing_profile_id ) || $existing_profile_id !== $transaction_details['subscr_id'] ) {
				update_post_meta( $subscription->get_id(), '_old_paypal_subscriber_id', $existing_profile_id );
				update_post_meta( $subscription->get_id(), '_old_payment_method', $subscription->get_payment_method() );
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

				// When there is a free trial & no initial payment amount, we need to mark the order as paid and activate the subscription
				if ( ! $is_payment_change && ! $is_renewal_sign_up_after_failure && 0 == $order->get_total() ) {
					// Safe to assume the subscription has an order here because otherwise we wouldn't get a 'subscr_signup' IPN
					$order->payment_complete(); // No 'txn_id' value for 'subscr_signup' IPN messages
					update_post_meta( $subscription->get_id(), '_paypal_first_ipn_ignored_for_pdt', 'true' );
				}

				// Payment completed
				if ( $is_payment_change ) {

					// Set PayPal as the new payment method
					WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $subscription, 'paypal' );

					// We need to cancel the subscription now that the method has been changed successfully
					if ( 'paypal' == get_post_meta( $subscription->get_id(), '_old_payment_method', true ) ) {
						self::cancel_subscription( $subscription, get_post_meta( $subscription->get_id(), '_old_paypal_subscriber_id', true ) );
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
					WC_Gateway_Paypal::log( 'IPN ignored, treating IPN as secondary trial period.' );
					exit;
				}

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
						update_post_meta( $subscription->get_id(), '_paypal_first_ipn_ignored_for_pdt', 'true' );

					// Ignore the first IPN message if the PDT should have handled it (if it didn't handle it, it will have been dealt with as first payment), but set a flag to make sure we only ignore it once
					} elseif ( $subscription->get_payment_count() == 1 && '' !== WCS_PayPal::get_option( 'identity_token' ) && 'true' != get_post_meta( $subscription->get_id(), '_paypal_first_ipn_ignored_for_pdt', true ) && false === $is_renewal_sign_up_after_failure ) {

						WC_Gateway_Paypal::log( 'IPN subscription payment ignored for subscription ' . $subscription->get_id() . ' due to PDT previously handling the payment.' );

						update_post_meta( $subscription->get_id(), '_paypal_first_ipn_ignored_for_pdt', 'true' );

					// Process the payment if the subscription is active
					} elseif ( ! $subscription->has_status( array( 'cancelled', 'expired', 'switched', 'trash' ) ) ) {

						if ( true === $is_renewal_sign_up_after_failure && is_object( $transaction_order ) ) {

							update_post_meta( $subscription->get_id(), '_paypal_failed_sign_up_recorded', wcs_get_objects_property( $transaction_order, 'id' ) );

							// We need to cancel the old subscription now that the method has been changed successfully
							if ( 'paypal' == get_post_meta( $subscription->get_id(), '_old_payment_method', true ) ) {

								$profile_id = get_post_meta( $subscription->get_id(), '_old_paypal_subscriber_id', true );

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

						remove_action( 'woocommerce_subscription_activated_paypal', 'WCS_PayPal_Status_Manager::reactivate_subscription' );

						try {
							$transaction_order->payment_complete( $transaction_details['txn_id'] );
						} catch ( Exception $e ) {
							WC_Gateway_Paypal::log( sprintf( 'IPN subscription payment exception calling $transaction_order->payment_complete() for subscription %d: %s.', $subscription->get_id(), $e->getMessage() ) );
						}

						$this->add_order_note( __( 'IPN subscription payment completed.', 'woocommerce-subscriptions' ), $transaction_order, $transaction_details );

						add_action( 'woocommerce_subscription_activated_paypal', 'WCS_PayPal_Status_Manager::reactivate_subscription' );

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
			$handled_transactions[] = $ipn_transaction_id;
			update_post_meta( $subscription->get_id(), '_paypal_ipn_tracking_ids', $handled_transactions );
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
	 * Return valid transaction types
	 *
	 * @since 2.0
	 */
	public function get_transaction_types() {
		return $this->transaction_types;
	}


	/**
	 * Checks if a string may include a WooCommerce order key.
	 *
	 * This function expects a generic payload, in any serialization format. It looks for an 'order key' code. This
	 * function uses regular expressions and looks for 'order key'. WooCommerce allows plugins to modify the order
	 * keys through filtering, unfortunatelly we only check for the original
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
	 * @since 2.0
	 */
	public static function get_order_id_and_key( $args, $order_type = 'shop_order', $meta_key = '_paypal_subscription_id' ) {

		$order_id = $order_key = '';

		if ( isset( $args['subscr_id'] ) ) { // PayPal Standard IPN message
			$subscription_id = $args['subscr_id'];
		} elseif ( isset( $args['recurring_payment_id'] ) ) { // PayPal Express Checkout IPN, most likely 'recurring_payment_suspended_due_to_max_failed_payment', for a PayPal Standard Subscription
			$subscription_id = $args['recurring_payment_id'];
		} else {
			$subscription_id = '';
		}

		// First try and get the order ID by the subscription ID
		if ( ! empty( $subscription_id ) ) {

			$posts = get_posts( array(
				'numberposts'      => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => $meta_key,
				'meta_value'       => $subscription_id,
				'post_type'        => $order_type,
				'post_status'      => 'any',
				'suppress_filters' => true,
			) );

			if ( ! empty( $posts ) ) {
				$order_id  = $posts[0]->ID;
				$order_key = get_post_meta( $order_id, '_order_key', true );
			}
		}

		// Couldn't find the order ID by subscr_id, so it's either not set on the order yet or the $args doesn't have a subscr_id (?!), either way, let's get it from the args
		if ( empty( $order_id ) && isset( $args['custom'] ) ) {

			$order_details = json_decode( $args['custom'] );

			if ( is_object( $order_details ) ) { // WC 2.3.11+ converted the custom value to JSON, if we have an object, we've got valid JSON

				if ( 'shop_order' == $order_type ) {
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
						$order_id  = $subscription->get_id();
						$order_key = $subscription->get_order_key();
					}
				}
			} else { // WC < 2.3.11, we could have a variety of payloads, but something has gone wrong if we got to here as we should only be here on new purchases where the '_paypal_subscription_id' is not already set, so throw an exception
				WC_Gateway_Paypal::log( __( 'Invalid PayPal IPN Payload: unable to find matching subscription.', 'woocommerce-subscriptions' ) );
			}
		}

		return array(
			'order_id'  => (int) $order_id,
			'order_key' => $order_key,
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
	 * @param WC_Subscription A subscription object
	 * @param string A PayPal Subscription Profile ID
	 * @since 2.0
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
	 * @since 2.0
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
	 * @since 2.0.20
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
	 * @param WC_Subscription object $subscription
	 * @param int $transaction_id Id from transaction details as provided by PayPal
	 * @param array|string Order type we want. Defaults to any.
	 *
	 * @return WC_Order|null If order with that transaction id, WC_Order object, otherwise null
	 * @since 2.4.3
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
	* @param WC_Subscription object $subscription
	* @param int $transaction_id Id from transaction details as provided by PayPal
	* @return WC_Order|null If order with that transaction id, WC_Order object, otherwise null
	* @since 2.1
	*/
	protected function get_renewal_order_by_transaction_id( $subscription, $transaction_id ) {
		return self::get_order_by_transaction_id( $subscription, $transaction_id, 'renewal' );
	}

	/**
	 * Get a parent order associated with a subscription that has a specified transaction id.
	 *
	 * @param WC_Subscription object $subscription
	 * @param int $transaction_id Id from transaction details as provided by PayPal
	 *
	 * @return WC_Order|null If order with that transaction id, WC_Order object, otherwise null
	 * @since 2.4.3
	 */
	protected function get_parent_order_by_transaction_id( $subscription, $transaction_id ) {
		return self::get_order_by_transaction_id( $subscription, $transaction_id, 'parent' );
	}
}
