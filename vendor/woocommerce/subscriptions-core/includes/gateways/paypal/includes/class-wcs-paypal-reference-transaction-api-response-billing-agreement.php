<?php
/**
 * PayPal Reference Transaction API Response Class for Express Checkout API calls to create a billing agreement
 *
 * @link https://developer.paypal.com/docs/classic/api/merchant/CreateBillingAgreement_API_Operation_NVP/
 *
 * Heavily inspired by the WC_Paypal_Express_API_Checkout_Response class developed by the masterful SkyVerge team
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Reference_Transaction_API_Response_Billing_Agreement extends WCS_PayPal_Reference_Transaction_API_Response {


	/**
	 * Get the billing agreement ID which is returned after a successful CreateBillingAgreement API call
	 *
	 * @return string|null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.0
	 */
	public function get_billing_agreement_id() {
		return $this->get_parameter( 'BILLINGAGREEMENTID' );
	}

}
