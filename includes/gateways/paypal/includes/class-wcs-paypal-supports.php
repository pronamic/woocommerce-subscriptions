<?php
/**
 * PayPal Subscription Support Class.
 *
 * Hook into WooCommerce and Subscriptions to declare support for different subscription features depending
 * on the PayPal flavour in use. Site wide, the feature support is based on whether the PayPal account has
 * reference transactions enabled or not. However, because we use two flavours of PayPal, both identified with
 * the same gateway ID, we also need to hook in to check for feature support on a subscription specific basis.
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

class WCS_PayPal_Supports {

	protected static $standard_supported_features = array(
		'subscriptions',
		'gateway_scheduled_payments',
		'subscription_payment_method_change_customer',
		'subscription_cancellation',
		'subscription_suspension',
		'subscription_reactivation',
	);

	protected static $reference_transaction_supported_features = array(
		'subscription_payment_method_change_customer',
		'subscription_payment_method_change_admin',
		'subscription_amount_changes',
		'subscription_date_changes',
		'multiple_subscriptions',
	);

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public static function init() {

		// Set the PayPal Standard gateway to support subscriptions after it is added to the woocommerce_payment_gateways array
		add_filter( 'woocommerce_payment_gateway_supports', __CLASS__ . '::add_feature_support_for_gateway', 10, 3 );

		// Check for specific subscription support based on whether the subscription is using a billing agreement or subscription for recurring payments with PayPal
		add_filter( 'woocommerce_subscription_payment_gateway_supports', __CLASS__ . '::add_feature_support_for_subscription', 10, 3 );
	}

	/**
	 * Add subscription support to the PayPal Standard gateway only when credentials are set
	 *
	 * @since 2.0
	 */
	public static function add_feature_support_for_gateway( $is_supported, $feature, $gateway ) {

		if ( 'paypal' === $gateway->id && WCS_PayPal::are_credentials_set() ) {

			if ( in_array( $feature, self::$standard_supported_features ) ) {
				$is_supported = true;
			} elseif ( in_array( $feature, self::$reference_transaction_supported_features ) && WCS_PayPal::are_reference_transactions_enabled() ) {
				$is_supported = true;
			}
		}

		return $is_supported;
	}

	/**
	 * Add additional feature support at the subscription level instead of just the gateway level because some subscriptions may have been
	 * setup with PayPal Standard while others may have been setup with Billing Agreements to use with Reference Transactions.
	 *
	 * @since 2.0
	 */
	public static function add_feature_support_for_subscription( $is_supported, $feature, $subscription ) {

		if ( 'paypal' === $subscription->payment_method && WCS_PayPal::are_credentials_set() ) {

			$paypal_profile_id    = wcs_get_paypal_id( $subscription->id );
			$is_billing_agreement = wcs_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' );

			if ( 'gateway_scheduled_payments' === $feature && $is_billing_agreement ) {

				$is_supported = false;

			} elseif ( in_array( $feature, self::$standard_supported_features ) ) {

				if ( wcs_is_paypal_profile_a( $paypal_profile_id, 'out_of_date_id' )  ) {
					$is_supported = false;
				} else {
					$is_supported = true;
				}
			} elseif ( in_array( $feature, self::$reference_transaction_supported_features ) ) {

				if ( $is_billing_agreement ) {
					$is_supported = true;
				} else {
					$is_supported = false;
				}
			}
		}

		return $is_supported;
	}

}
