<?php
/**
 * PayPal Reference Transaction API Do Express Checkout Response Class
 *
 * Parses DoExpressCheckout response which are used to process initial payments (if any) when checking out.
 *
 * @link https://developer.paypal.com/docs/classic/api/merchant/DoExpressCheckoutPayment_API_Operation_NVP/
 *
 * Heavily inspired by the WC_Paypal_Express_API_Payment_Response class developed by the masterful SkyVerge team
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @since       2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Reference_Transaction_API_Response_Payment extends WCS_PayPal_Reference_Transaction_API_Response_Billing_Agreement {


	/** approved transaction response payment status */
	const TRANSACTION_COMPLETED = 'Completed';

	/** in progress transaction response payment status */
	const TRANSACTION_INPROGRESS = 'In-Progress';

	/** in progress transaction response payment status */
	const TRANSACTION_PROCESSED = 'Processed';

	/** pending transaction response payment status */
	const TRANSACTION_PENDING = 'Pending';

	/** @var array URL-decoded and parsed parameters */
	protected $successful_statuses = array();


	/**
	 * Parse the payment response
	 *
	 * @see WC_PayPal_Express_API_Response::__construct()
	 * @param string $response the raw URL-encoded response string
	 * @since 2.0
	 */
	public function __construct( $response ) {

		parent::__construct( $response );

		$this->successful_statuses = array(
			self::TRANSACTION_COMPLETED,
			self::TRANSACTION_PROCESSED,
			self::TRANSACTION_INPROGRESS,
		);
	}

	/**
	 * Checks if the transaction was successful
	 *
	 * @return bool true if approved, false otherwise
	 * @since 2.0
	 */
	public function transaction_approved() {

		return in_array( $this->get_payment_status(), $this->successful_statuses );
	}


	/**
	 * Returns true if the payment is pending, for instance if the payment was authorized, but not captured. There are many other
	 * possible reasons
	 *
	 * @link https://developer.paypal.com/docs/classic/api/merchant/DoExpressCheckoutPayment_API_Operation_NVP/#id105CAM003Y4__id116RI0UF0YK
	 *
	 * @return bool true if the transaction was held, false otherwise
	 * @since 2.0
	 */
	public function transaction_held() {

		return self::TRANSACTION_PENDING === $this->get_payment_status();
	}


	/**
	 * Gets the response status code, or null if there is no status code associated with this transaction.
	 *
	 * @link https://developer.paypal.com/docs/classic/api/merchant/DoExpressCheckoutPayment_API_Operation_NVP/#id105CAM003Y4__id116RI0UF0YK
	 *
	 * @return string status code
	 * @since 2.0
	 */
	public function get_status_code() {

		return $this->get_payment_status();
	}


	/**
	 * Gets the response status message, or null if there is no status message associated with this transaction.
	 *
	 * PayPal provides additional info only for Pending or Completed-Funds-Held transactions.
	 *
	 * @return string status message
	 * @since 2.0
	 */
	public function get_status_message() {

		$message = '';

		if ( $this->transaction_held() ) {

			// PayPal's "pending" is our Held
			$message = $this->get_pending_reason();

		} elseif ( 'echeck' == $this->get_payment_type() ) {

			// translators: placeholder is localised datetime
			$message = sprintf( __( 'expected clearing date %s', 'woocommerce-subscriptions' ), date_i18n( wc_date_format(), wcs_date_to_time( $this->get_payment_parameter( 'EXPECTEDECHECKCLEARDATE' ) ) ) );
		}

		// add fraud filters
		if ( $filters = $this->get_fraud_filters() ) {

			foreach ( $filters as $filter ) {
				$message .= sprintf( ' %s: %s', $filter['name'], $filter['id'] );
			}
		}

		return $message;
	}


	/**
	 * Gets the response transaction id, or null if there is no transaction id associated with this transaction.
	 *
	 * @return string transaction id
	 * @since 2.0
	 */
	public function get_transaction_id() {

		return $this->get_payment_parameter( 'TRANSACTIONID' );
	}


	/**
	 * Return true if the response has a payment type other than `none`
	 *
	 * @return bool
	 * @since 2.0
	 */
	public function has_payment_type() {

		return 'none' !== $this->get_payment_type();
	}


	/**
	 * Get the PayPal payment type, either `none`, `echeck`, or `instant`
	 *
	 * @since 2.0.9
	 * @return string
	 */
	public function get_payment_type() {

		return $this->get_payment_parameter( 'PAYMENTTYPE' );
	}


	/**
	 * Gets payment status
	 *
	 * @return string
	 * @since 2.0
	 */
	private function get_payment_status() {

		return $this->has_payment_parameter( 'PAYMENTSTATUS' ) ? $this->get_payment_parameter( 'PAYMENTSTATUS' ) : 'N/A';
	}


	/**
	 * Gets the pending reason
	 *
	 * @return string
	 * @since 2.0
	 */
	private function get_pending_reason() {

		return $this->has_payment_parameter( 'PENDINGREASON' ) ? $this->get_payment_parameter( 'PENDINGREASON' ) : 'N/A';
	}


	/** AVS/CSC Methods *******************************************************/


	/**
	 * PayPal Express does not return an authorization code
	 *
	 * @return string credit card authorization code
	 * @since 2.0
	 */
	public function get_authorization_code() {
		return false;
	}


	/**
	 * Returns the result of the AVS check
	 *
	 * @return string result of the AVS check, if any
	 * @since 2.0
	 */
	public function get_avs_result() {

		if ( $filters = $this->get_fraud_filters() ) {

			foreach ( $filters as $filter ) {

				if ( in_array( $filter['id'], range( 1, 3 ) ) ) {

					return $filter['id'];
				}
			}
		}

		return null;
	}


	/**
	 * Returns the result of the CSC check
	 *
	 * @return string result of CSC check
	 * @since 2.0
	 */
	public function get_csc_result() {

		if ( $filters = $this->get_fraud_filters() ) {

			foreach ( $filters as $filter ) {

				if ( '4' == $filter['id'] ) {

					return $filter['id'];
				}
			}
		}

		return null;
	}


	/**
	 * Returns true if the CSC check was successful
	 *
	 * @return boolean true if the CSC check was successful
	 * @since 2.0
	 */
	public function csc_match() {

		return is_null( $this->get_csc_result() );
	}


	/**
	 * Return any fraud management data available. This data is explicitly
	 * enabled in the request, but PayPal recommends checking certain error
	 * conditions prior to accessing this data.
	 *
	 * This data provides additional context for why a transaction was held for
	 * review or declined.
	 *
	 * @link https://developer.paypal.com/webapps/developer/docs/classic/fmf/integration-guide/FMFProgramming/#id091UNG0065Z
	 * @link https://developer.paypal.com/webapps/developer/docs/classic/api/merchant/DoReferenceTransaction_API_Operation_NVP/#id09BUI01L0K3__id0861GA0N07U (L_FMFfilterIDn Type Fields)
	 *
	 * @return array $filters {
	 *   @type string $id filter ID, integer from 1-17
	 *   @type string name filter name, short description for filter
	 * }
	 * @since 2.0
	 */
	private function get_fraud_filters() {

		$filters = array();

		if ( '11610' == $this->get_api_error_code() ) {

			$type = 'PENDING';

		} elseif ( '11611' == $this->get_api_error_code() ) {

			$type = 'DENY';

		} else {

			// not supporting REPORT type yet
			return $filters;
		}

		foreach ( range( 0, 9 ) as $index ) {

			if ( $this->has_parameter( "L_FMF{$type}ID{$index}" ) && $this->has_parameter( "L_FMF{$type}NAME{$index}" ) ) {
				$filters[] = array(
					'id'   => $this->get_parameter( "L_FMF{$type}ID{$index}" ),
					'name' => $this->get_parameter( "L_FMF{$type}NAME{$index}" ),
				);
			}
		}

		return $filters;
	}


	/**
	 * Check if the response has a specific payment parameter.
	 *
	 * A wrapper around @see WCS_PayPal_Reference_Transaction_API_Response::has_parameter()
	 * that prepends the @see self::get_payment_parameter_prefix().
	 *
	 * @since 2.0.9
	 * @param string $name parameter name
	 * @return bool
	 */
	protected function has_payment_parameter( $name ) {
		return $this->has_parameter( $this->get_payment_parameter_prefix() . $name );
	}


	/**
	 * Gets a given payment parameter's value, or null if parameter is not set or empty.
	 *
	 * A wrapper around @see WCS_PayPal_Reference_Transaction_API_Response::get_parameter()
	 * that prepends the @see self::get_payment_parameter_prefix().
	 *
	 * @since 2.0.9
	 * @param string $name parameter name
	 * @return string|null
	 */
	protected function get_payment_parameter( $name ) {
		return $this->get_parameter( $this->get_payment_parameter_prefix() . $name );
	}


	/**
	 * DoExpressCheckoutPayment API responses have a prefix for the payment
	 * parameters. Parallels payments are not used, so the numeric portion of
	 * the prefix is always '0'
	 *
	 * @since 2.0.9
	 * @return string
	 */
	protected function get_payment_parameter_prefix() {
		return 'PAYMENTINFO_0_';
	}

}
