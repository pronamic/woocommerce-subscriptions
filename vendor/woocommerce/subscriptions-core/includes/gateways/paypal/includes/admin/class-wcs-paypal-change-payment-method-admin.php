<?php
/**
 * PayPal Subscription Change Payment Method Admin Class
 *
 * Allow store managers to manually set PayPal as the payment method on a subscription if reference transactions are enabled
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

class WCS_PayPal_Change_Payment_Method_Admin {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function init() {

		// Include the PayPal billing agreement ID meta key in the required meta data for setting PayPal as the payment method
		add_filter( 'woocommerce_subscription_payment_meta', __CLASS__ . '::add_payment_meta_details', 10, 2 );

		// Validate the PayPal billing agreement ID meta value when attempting to set PayPal as the payment method
		if ( is_admin() ) {
			add_filter( 'woocommerce_subscription_validate_payment_meta_paypal', __CLASS__ . '::validate_payment_meta', 10, 2 );
		}
	}

	/**
	 * Include the PayPal payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen.
	 *
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @param WC_Subscription $subscription An instance of a subscription object
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function add_payment_meta_details( $payment_meta, $subscription ) {
		$subscription_id = get_post_meta( $subscription->get_id(), '_paypal_subscription_id', true );

		if ( wcs_is_paypal_profile_a( $subscription_id, 'billing_agreement' ) || empty( $subscription_id ) ) {
			$label = 'PayPal Billing Agreement ID';
			$disabled = false;
		} else {
			$label = 'PayPal Standard Subscription ID';
			$disabled = true;
		}

		$payment_meta['paypal'] = array(
			'post_meta' => array(
				'_paypal_subscription_id' => array(
					'value'    => $subscription_id,
					'label'    => $label,
					'disabled' => $disabled,
				),
			),
		);

		return $payment_meta;
	}

	/**
	 * Validate the payment meta data required to process automatic recurring payments so that store managers can
	 * manually set up automatic recurring payments for a customer via the Edit Subscription screen.
	 *
	 * @param string $payment_method_id The ID of the payment method to validate
	 * @param array $payment_meta associative array of meta data required for automatic payments
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function validate_payment_meta( $payment_meta, $subscription ) {
		if ( empty( $payment_meta['post_meta']['_paypal_subscription_id']['value'] ) ) {
			throw new Exception( 'A valid PayPal Billing Agreement ID value is required.' );
		} elseif ( 0 !== strpos( $payment_meta['post_meta']['_paypal_subscription_id']['value'], 'B-' ) && wcs_get_paypal_id( $subscription ) !== $payment_meta['post_meta']['_paypal_subscription_id']['value'] ) {
			throw new Exception( 'Invalid Billing Agreement ID. A valid PayPal Billing Agreement ID must begin with "B-".' );
		}
	}

}
