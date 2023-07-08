<?php
/**
 * PayPal Reference Transaction API Class
 *
 * Performs reference transaction related transactions requests via the PayPal Express Checkout API,
 * including the creation of a billing agreement and processing renewal payments using that billing
 * agremeent's ID in a reference tranasction.
 *
 * Also hijacks checkout when PayPal Standard is chosen as the payment method, but Reference Transactions
 * are enabled on the store's PayPal account, to go via Express Checkout approval flow instead of the
 * PayPal Standard checkout flow.
 *
 * Heavily inspired by the WC_Paypal_Express_API class developed by the masterful SkyVerge team
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Reference_Transaction_API extends WCS_SV_API_Base {

	/** the production endpoint */
	const PRODUCTION_ENDPOINT = 'https://api-3t.paypal.com/nvp';

	/** the sandbox endpoint */
	const SANDBOX_ENDPOINT = 'https://api-3t.sandbox.paypal.com/nvp';

	/** NVP API version */
	const VERSION = '124';

	/** @var array the request parameters */
	private $parameters = array();

	/**
	 * Constructor - setup request object and set endpoint
	 *
	 * @param string $gateway_id gateway ID for this request
	 * @param string $api_environment the API environment
	 * @param string $api_username the API username
	 * @param string $api_password the API password
	 * @param string $api_signature the API signature
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct( $gateway_id, $api_environment, $api_username, $api_password, $api_signature ) {

		// tie API to gateway
		$this->gateway_id = $gateway_id;

		// request URI does not vary per-request
		$this->request_uri = ( 'production' === $api_environment ) ? self::PRODUCTION_ENDPOINT : self::SANDBOX_ENDPOINT;

		// PayPal requires HTTP 1.1
		$this->request_http_version = '1.1';

		$this->api_username  = $api_username;
		$this->api_password  = $api_password;
		$this->api_signature = $api_signature;
	}

	/**
	 * Get PayPal URL parameters for the checkout URL
	 *
	 * @param array $paypal_args
	 * @param WC_Order $order
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_paypal_args( $paypal_args, $order ) {

		$request = $this->get_new_request();

		// First we need to request an express checkout token for setting up a billing agreement, to do that, we need to pull details of the transaction from the PayPal Standard args and massage them into the Express Checkout params
		$response = $this->set_express_checkout( array(
			'currency'   => $paypal_args['currency_code'],
			'return_url' => $this->get_callback_url( 'create_billing_agreement' ),
			'cancel_url' => $paypal_args['cancel_return'],
			'notify_url' => $paypal_args['notify_url'],
			'custom'     => $paypal_args['custom'],
			'order'      => $order,
		) );

		$paypal_args = array(
			'cmd'   => '_express-checkout',
			'token' => $response->get_token(),
		);

		return $paypal_args;
	}

	/**
	 * Check account for reference transaction support
	 *
	 * For reference transactions to be enabled, we need to be able to setup a dummy SetExpressCheckout request without receiving any APIs errors.
	 * This ensures there are no API credentials errors (e.g. error code 10008: "Security header is not valid") as well as testing the account for
	 * reference transaction support. If the account does not have reference transaction support enabled, PayPal will return the error code
	 * error code 11452: "Merchant not enabled for reference transactions".
	 *
	 * @link https://developer.paypal.com/docs/classic/api/errorcodes/#id09C3G0PJ0N9__id5e8c50e9-4f1b-462a-8586-399b63b07f1a
	 *
	 * @return bool
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function are_reference_transactions_enabled() {

		$request = $this->get_new_request();

		$request->set_express_checkout( array(
			// As we are only testing for whether billing agreements are allowed, we don't need to se any other details
			'currency'   => get_woocommerce_currency(),
			'return_url' => $this->get_callback_url( 'reference_transaction_account_check' ),
			'cancel_url' => $this->get_callback_url( 'reference_transaction_account_check' ),
			'notify_url' => $this->get_callback_url( 'reference_transaction_account_check' ),
		) );

		$this->set_response_handler( 'WCS_PayPal_Reference_Transaction_API_Response' );

		try {
			$response = $this->perform_request( $request );

			if ( ! $response->has_api_error() ) {
				$reference_transactions_enabled = true;
			} else {
				$reference_transactions_enabled = false;

				// And set a flag to display invalid credentials notice
				if ( $response->has_api_error_for_credentials() ) {
					update_option( 'wcs_paypal_credentials_error', 'yes' );
				}
			}
		} catch ( Exception $e ) {
			$reference_transactions_enabled = false;
		}

		return $reference_transactions_enabled;
	}

	/**
	 * Set Express Checkout
	 *
	 * @param array $args @see WCS_PayPal_Reference_Transaction_API_Request::set_express_checkout() for details
	 * @throws Exception network timeouts, etc
	 * @return WCS_PayPal_Reference_Transaction_API_Response_Checkout response object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function set_express_checkout( $args ) {

		$request = $this->get_new_request();

		$request->set_express_checkout( $args );

		$this->set_response_handler( 'WCS_PayPal_Reference_Transaction_API_Response_Checkout' );

		return $this->perform_request( $request );
	}

	/**
	 * Create a billing agreement, required when a subscription sign-up has no initial payment
	 *
	 * @link https://developer.paypal.com/docs/classic/express-checkout/integration-guide/ECReferenceTxns/#id094TB0Y0J5Z__id094TB4003HS
	 * @link https://developer.paypal.com/docs/classic/api/merchant/CreateBillingAgreement_API_Operation_NVP/
	 *
	 * @param string $token token from SetExpressCheckout response
	 * @return WCS_PayPal_Reference_Transaction_API_Response_Billing_Agreement response object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function create_billing_agreement( $token ) {

		$request = $this->get_new_request();

		$request->create_billing_agreement( $token );

		$this->set_response_handler( 'WCS_PayPal_Reference_Transaction_API_Response_Billing_Agreement' );

		return $this->perform_request( $request );
	}

	/**
	 * Get Express Checkout Details
	 *
	 * @param string $token Token from set_express_checkout response
	 * @return WC_PayPal_Reference_Transaction_API_Checkout_Response response object
	 * @throws Exception network timeouts, etc
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_express_checkout_details( $token ) {

		$request = $this->get_new_request();

		$request->get_express_checkout_details( $token );

		$this->set_response_handler( 'WCS_PayPal_Reference_Transaction_API_Response_Checkout' );

		return $this->perform_request( $request );
	}

	/**
	 * Process an express checkout payment and billing agreement creation
	 *
	 * @param string $token PayPal Express Checkout token returned by SetExpressCheckout operation
	 * @param WC_Order $order order object
	 * @param array $args
	 * @return WCS_PayPal_Reference_Transaction_API_Response_Payment refund response
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.9
	 */
	public function do_express_checkout( $token, $order, $args ) {

		$this->order = $order;

		$request = $this->get_new_request();

		$request->do_express_checkout( $token, $order, $args );

		$this->set_response_handler( 'WCS_PayPal_Reference_Transaction_API_Response_Payment' );

		return $this->perform_request( $request );
	}

	/**
	 * Perform a reference transaction for the given order
	 *
	 * @see SV_WC_Payment_Gateway_API::refund()
	 * @param WC_Order $order order object
	 * @return SV_WC_Payment_Gateway_API_Response refund response
	 * @throws SV_WC_Payment_Gateway_Exception network timeouts, etc
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function do_reference_transaction( $reference_id, $order, $args ) {

		$this->order = $order;

		$request = $this->get_new_request();

		$request->do_reference_transaction( $reference_id, $order, $args );

		$this->set_response_handler( 'WCS_PayPal_Reference_Transaction_API_Response_Recurring_Payment' );

		return $this->perform_request( $request );
	}

	/**
	 * Change the status of a subscription for a given order/profile ID
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.0
	 * @see SV_WC_Payment_Gateway_API::refund()
	 * @param WC_Order $order order object
	 * @return SV_WC_Payment_Gateway_API_Response refund response
	 * @throws SV_WC_Payment_Gateway_Exception network timeouts, etc
	 */
	public function manage_recurring_payments_profile_status( $profile_id, $new_status, $order ) {

		$this->order = $order;

		$request = $this->get_new_request();

		$request->manage_recurring_payments_profile_status( $profile_id, $new_status, $order );

		$this->set_response_handler( 'WCS_PayPal_Reference_Transaction_API_Response_Checkout' );

		return $this->perform_request( $request );
	}

	/** Helper methods ******************************************************/

	/**
	 * Get the wc-api URL to redirect to.
	 *
	 * @param string $action checkout action, either `set_express_checkout or `get_express_checkout_details`.
	 *
	 * @return string URL The URL. Note: this URL is escaped.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_callback_url( $action ) {
		return esc_url(
			add_query_arg(
				'action',
				$action,
				WC()->api_request_url( 'wcs_paypal' )
			),
			null,
			'db'
		);
	}

	/**
	 * Builds and returns a new API request object
	 *
	 * @see \WCS_SV_API_Base::get_new_request()
	 * @param array $args
	 * @return WC_PayPal_Reference_Transaction_API_Request API request object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function get_new_request( $args = array() ) {
		return new WCS_PayPal_Reference_Transaction_API_Request( $this->api_username, $this->api_password, $this->api_signature, self::VERSION );
	}

	/**
	 * Supposed to return the main gatewya plugin class, but we don't have one of those
	 *
	 * @see \WCS_SV_API_Base::get_plugin()
	 * @return object
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function get_plugin() {
		return WCS_PayPal::instance();
	}
}
