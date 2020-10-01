<?php
/**
 * PayPal Reference Transaction API Response Class for Express Checkout API calls
 *
 * Parses response string received from PayPal Express Checkout API, which is simply a URL-encoded string of parameters
 *
 * @link https://developer.paypal.com/webapps/developer/docs/classic/api/merchant/SetExpressCheckout_API_Operation_NVP/
 * @link https://developer.paypal.com/docs/classic/api/merchant/ManageRecurringPaymentsProfileStatus_API_Operation_NVP/
 * @link https://developer.paypal.com/webapps/developer/docs/classic/api/merchant/GetExpressCheckoutDetails_API_Operation_NVP/
 *
 * Heavily inspired by the WC_Paypal_Express_API_Checkout_Response class developed by the masterful SkyVerge team
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @since       2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Reference_Transaction_API_Response_Checkout extends WCS_PayPal_Reference_Transaction_API_Response {

	/**
	 * Get the token which is returned after a successful SetExpressCheckout
	 * API call
	 *
	 * @return string|null
	 * @since 2.0
	 */
	public function get_token() {
		return $this->get_parameter( 'TOKEN' );
	}

	/**
	 * Get the billing agreement status for a successful SetExpressCheckout
	 *
	 * @since 3.0.0
	 * @return string|null
	 * @since 2.0
	 */
	public function get_billing_agreement_status() {
		return $this->get_parameter( 'BILLINGAGREEMENTACCEPTEDSTATUS' );
	}

	/**
	 * Get the shipping details from GetExpressCheckoutDetails response mapped to the WC shipping address format
	 *
	 * @since 3.0.0
	 * @return array
	 * @since 2.0
	 */
	public function get_shipping_details() {

		$details = array();

		if ( $this->has_parameter( 'FIRSTNAME' ) ) {

			$details = array(
				'first_name' => $this->get_parameter( 'FIRSTNAME' ),
				'last_name'  => $this->get_parameter( 'LASTNAME' ),
				'company'    => $this->get_parameter( 'BUSINESS' ),
				'email'      => $this->get_parameter( 'EMAIL' ),
				'phone'      => $this->get_parameter( 'PHONENUM' ),
				'address_1'  => $this->get_parameter( 'SHIPTOSTREET' ),
				'address_2'  => $this->get_parameter( 'SHIPTOSTREET2' ),
				'city'       => $this->get_parameter( 'SHIPTOCITY' ),
				'postcode'   => $this->get_parameter( 'SHIPTOZIP' ),
				'country'    => $this->get_parameter( 'SHIPTOCOUNTRYCODE' ),
				'state'      => $this->get_state_code( $this->get_parameter( 'SHIPTOCOUNTRYCODE' ), $this->get_parameter( 'SHIPTOSTATE' ) ),
			);
		}

		return $details;
	}

	/**
	 * Get the note text from checkout details
	 *
	 * @return string
	 * @since 2.0
	 */
	public function get_note_text() {
		return $this->get_parameter( 'PAYMENTREQUEST_0_NOTETEXT' );
	}

	/**
	 * Gets the payer ID from checkout details, a payer ID is a Unique PayPal Customer Account identification number
	 *
	 * @return string
	 * @since 2.0
	 */
	public function get_payer_id() {
		return $this->get_parameter( 'PAYERID' );
	}

	/**
	 * Get state code given a full state name and country code
	 *
	 * @param string $country_code country code sent by PayPal
	 * @param string $state state name or code sent by PayPal
	 * @return string state code
	 * @since 2.0
	 */
	private function get_state_code( $country_code, $state ) {

		// if not a US address, then convert state to abbreviation
		if ( 'US' !== $country_code && isset( WC()->countries->states[ $country_code ] ) ) {

			$local_states = WC()->countries->states[ $country_code ];

			if ( ! empty( $local_states ) && in_array( $state, $local_states ) ) {

				foreach ( $local_states as $key => $val ) {

					if ( $val === $state ) {
						return $key;
					}
				}
			}
		}

		return $state;
	}
}
