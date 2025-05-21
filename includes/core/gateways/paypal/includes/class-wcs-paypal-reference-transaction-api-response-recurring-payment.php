<?php
/**
 * PayPal Reference Transaction API Do Reference Transaction Response Class
 *
 * Parses DoReferenceTransaction responses
 *
 * The response parameters returned by payments initiated by DoExpressCheckout requests differ to the response parameters
 * returned by DoReferenceTransaction requests in that the former have a payment prefix 'PAYMENTINFO_n_' (for our purposes
 * that is always 'PAYMENTINFO_0_'). Because of this, we need a special class to handle the DoReferenceTransaction request
 * response. However, the logic is identical so we can extend @see WCS_PayPal_Reference_Transaction_API_Response_Payment
 * and only change the few payment prefix to be ''.
 *
 * @link https://developer.paypal.com/webapps/developer/docs/classic/api/merchant/DoReferenceTransaction_API_Operation_NVP/
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Reference_Transaction_API_Response_Recurring_Payment extends WCS_PayPal_Reference_Transaction_API_Response_Payment {

	/**
	 * Parse the payment response
	 *
	 * @see WC_PayPal_Express_API_Response::__construct()
	 * @param string $response the raw URL-encoded response string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct( $response ) {
		parent::__construct( $response );
	}

	/**
	 * DoExpressCheckoutPayment API responses have a prefix for the payment
	 * parameters. Parallels payments are not used, so the numeric portion of
	 * the prefix is always '0'
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.9
	 * @return string
	 */
	protected function get_payment_parameter_prefix() {
		return '';
	}

}
