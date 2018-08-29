<?php
/**
 * Handles responses from PayPal IPN for Reference Transactions
 *
 * Example IPN payloads: https://gist.github.com/thenbrent/95b6b0c0aaa3ab787b71
 *
 * @link https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNandPDTVariables/#id08CTB0S055Z
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	Gateways/PayPal
 * @category	Class
 * @author		Prospress
 * @since		2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Reference_Transaction_IPN_Handler extends WCS_PayPal_Standard_IPN_Handler {

	/** @var Array transaction types this class can handle */
	protected $transaction_types = array(
		'mp_signup', // Created a billing agreement
		'mp_cancel', // Billing agreement cancelled
		'merch_pmt', // Reference transaction payment
	);

	/**
	 * Constructor
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
	 * @param  array $posted Post data after wp_unslash
	 */
	public function valid_response( $transaction_details ) {

		if ( ! $this->validate_transaction_type( $transaction_details['txn_type'] ) ) {
			return;
		}

		switch ( $transaction_details['txn_type'] ) {

			case 'mp_cancel':
				$this->cancel_subscriptions( $transaction_details['mp_id'] );
				break;

			case 'merch_pmt' :

				if ( ! empty( $transaction_details['custom'] ) && ( $order = $this->get_paypal_order( $transaction_details['custom'] ) ) ) {

					$transaction_details['payment_status'] = strtolower( $transaction_details['payment_status'] );

					// Sandbox fix
					if ( isset( $transaction_details['test_ipn'] ) && 1 == $transaction_details['test_ipn'] && 'pending' == $transaction_details['payment_status'] ) {
						$transaction_details['payment_status'] = 'completed';
					}

					WC_Gateway_Paypal::log( 'Found order #' . wcs_get_objects_property( $order, 'id' ) );
					WC_Gateway_Paypal::log( 'Payment status: ' . $transaction_details['payment_status'] );

					if ( method_exists( $this, 'payment_status_' . $transaction_details['payment_status'] ) ) {
						call_user_func( array( $this, 'payment_status_' . $transaction_details['payment_status'] ), $order, $transaction_details );
					} else {
						WC_Gateway_Paypal::log( 'Unknown payment status: ' . $transaction_details['payment_status'] );
					}
				}
				break;

			case 'mp_signup' :
				// Silence is Golden
				break;

		}
		exit;
	}

	/**
	 * Find all subscription with a given billing agreement ID and cancel them becasue that billing agreement has been
	 * cancelled at PayPal, and therefore, no future payments can be charged.
	 *
	 * @since 2.0
	 */
	protected function cancel_subscriptions( $billing_agreement_id ) {

		$subscription_ids = get_posts( array(
			'posts_per_page' => -1,
			'post_type'      => 'shop_subscription',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => '_paypal_subscription_id',
					'compare' => '=',
					'value'   => $billing_agreement_id,
				),
			),
		) );

		if ( empty( $subscription_ids ) ) {
			return;
		}

		$note = esc_html__( 'Billing agreement cancelled at PayPal.', 'woocommerce-subscriptions' );

		foreach ( $subscription_ids as $subscription_id ) {

			$subscription = wcs_get_subscription( $subscription_id );

			// Only cancel valid subscriptions using PayPal as the payment method that have not yet been ended
			if ( false == $subscription || $subscription->is_manual() || 'paypal' != $subscription->get_payment_method() || $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) {
				continue;
			}

			try {
				$subscription->cancel_order( $note );
				WC_Gateway_Paypal::log( sprintf( 'Subscription %s Cancelled: %s', $subscription_id, $note ) );
			} catch ( Exception $e ) {
				WC_Gateway_Paypal::log( sprintf( 'Unable to cancel subscription %s: %s', $subscription_id, $e->getMessage() ) );
			}
		}
	}
}
