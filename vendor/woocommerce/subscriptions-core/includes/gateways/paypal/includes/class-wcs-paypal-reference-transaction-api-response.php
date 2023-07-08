<?php
/**
 * PayPal Reference Transaction API Response Class
 *
 * Parses response string received from the PayPal Express Checkout API for Reference Transaction related requests, which is simply a URL-encoded string of parameters
 *
 * @link https://developer.paypal.com/docs/classic/api/NVPAPIOverview/#id084DN080HY4
 *
 * Heavily inspired by the WC_Paypal_Express_API_Payment_Response class developed by the masterful SkyVerge team
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Reference_Transaction_API_Response extends WC_Gateway_Paypal_Response {

	/** @var array URL-decoded and parsed parameters */
	protected $parameters = array();

	/**
	 * Parse the response parameters from the raw URL-encoded response string
	 *
	 * @link https://developer.paypal.com/docs/classic/api/NVPAPIOverview/#id084FBM0M0HS
	 *
	 * @param string $response the raw URL-encoded response string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct( $response ) {

		// URL decode the response string and parse it
		wp_parse_str( urldecode( $response ), $this->parameters );
	}

	/**
	 * Checks if response contains an API error code
	 *
	 * @link https://developer.paypal.com/docs/classic/api/errorcodes/
	 *
	 * @return bool true if has API error, false otherwise
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function has_api_error() {

		// assume something went wrong if ACK is missing
		if ( ! $this->has_parameter( 'ACK' ) ) {
			return true;
		}

		// any non-success ACK is considered an error, see
		// https://developer.paypal.com/docs/classic/api/NVPAPIOverview/#id09C2F0K30L7
		return ( 'Success' !== $this->get_parameter( 'ACK' ) && 'SuccessWithWarning' !== $this->get_parameter( 'ACK' ) );
	}

	/**
	 * Checks if response contains an API error code or message relating to invalid credentails
	 *
	 * @link https://developer.paypal.com/docs/classic/api/errorcodes/
	 *
	 * @return bool true if has API error relating to incorrect credentials, false otherwise
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	 */
	public function has_api_error_for_credentials() {

		$has_api_error_for_credentials = false;

		// assume something went wrong if ACK is missing
		if ( $this->has_api_error() ) {

			foreach ( range( 0, 9 ) as $index ) {

				// Error codes refer to multiple errors, go figure, so we need to compare both error codes and error messages
				$has_credentials_error_code    = $this->has_parameter( "L_ERRORCODE{$index}" ) && in_array( $this->get_parameter( "L_ERRORCODE{$index}" ), array( 10002, 10008 ) );
				$has_credentials_error_message = $this->has_parameter( "L_LONGMESSAGE{$index}" ) && in_array( $this->get_parameter( "L_LONGMESSAGE{$index}" ), array( 'Username/Password is incorrect', 'Security header is not valid' ) );

				if ( $has_credentials_error_code && $has_credentials_error_message ) {
					$has_api_error_for_credentials = true;
					break;
				}
			}
		}

		return $has_api_error_for_credentials;
	}

	/**
	 * Gets the API error code
	 *
	 * Note that PayPal can return multiple error codes, which are merged here
	 * for convenience
	 *
	 * @link https://developer.paypal.com/docs/classic/api/errorcodes/
	 *
	 * @return string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_api_error_code() {

		$error_codes = array();

		foreach ( range( 0, 9 ) as $index ) {

			if ( $this->has_parameter( "L_ERRORCODE{$index}" ) ) {
				$error_codes[] = $this->get_parameter( "L_ERRORCODE{$index}" );
			}
		}

		return empty( $error_codes ) ? 'N/A' : trim( implode( ', ', $error_codes ) );
	}

	/**
	 * Gets the API error message
	 *
	 * Note that PayPal can return multiple error messages, which are merged here
	 * for convenience
	 *
	 * @link https://developer.paypal.com/docs/classic/api/errorcodes/
	 *
	 * @return string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_api_error_message() {

		$error_messages = array();

		foreach ( range( 0, 9 ) as $index ) {

			if ( $this->has_parameter( "L_SHORTMESSAGE{$index}" ) ) {

				$error_message = sprintf( '%s: %s - %s',
					$this->has_parameter( "L_SEVERITYCODE{$index}" ) ? $this->get_parameter( "L_SEVERITYCODE{$index}" ) : _x( 'Error', 'used in api error message if there is no severity code from PayPal', 'woocommerce-subscriptions' ),
					$this->get_parameter( "L_SHORTMESSAGE{$index}" ),
					$this->has_parameter( "L_LONGMESSAGE{$index}" ) ? $this->get_parameter( "L_LONGMESSAGE{$index}" ) : _x( 'Unknown error', 'used in api error message if there is no long message', 'woocommerce-subscriptions' )
				);

				// append additional info if available
				if ( $this->has_parameter( "L_ERRORPARAMID{$index}" ) && $this->has_parameter( "L_ERRORPARAMVALUE{$index}" ) ) {
					$error_message .= sprintf( ' (%s - %s)', $this->get_parameter( "L_ERRORPARAMID{$index}" ), $this->get_parameter( "L_ERRORPARAMVALUE{$index}" ) );
				}

				$error_messages[] = $error_message;
			}
		}

		return empty( $error_messages ) ? _x( 'N/A', 'no information about something', 'woocommerce-subscriptions' ) : trim( implode( ', ', $error_messages ) );
	}

	/**
	 * Returns true if the parameter is not empty
	 *
	 * @param string $name parameter name
	 * @return bool
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function has_parameter( $name ) {
		return ! empty( $this->parameters[ $name ] );
	}

	/**
	 * Gets the parameter value, or null if parameter is not set or empty
	 *
	 * @param string $name parameter name
	 * @return string|null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function get_parameter( $name ) {
		return $this->has_parameter( $name ) ? $this->parameters[ $name ] : null;
	}

	/**
	 * Returns a message appropriate for a frontend user.  This should be used
	 * to provide enough information to a user to allow them to resolve an
	 * issue on their own, but not enough to help nefarious folks fishing for
	 * info.
	 *
	 * @link https://developer.paypal.com/docs/classic/api/errorcodes/
	 *
	 * @return string user message, if there is one
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_user_message() {

		$allowed_user_error_message_codes = array(
			'10445',
			'10474',
			'12126',
			'13113',
			'13122',
			'13112',
		);

		return in_array( $this->get_api_error_code(), $allowed_user_error_message_codes ) ? $this->get_api_error_message() : null;
	}

	/**
	 * Returns the string representation of this response
	 *
	 * @return string response
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function to_string() {

		return print_r( $this->parameters, true );
	}

	/**
	 * Returns the string representation of this response with any and all
	 * sensitive elements masked or removed
	 *
	 * @return string response safe for logging/displaying
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function to_string_safe() {

		// no sensitive data to mask
		return $this->to_string();
	}

	/**
	 * Get the order for a request based on the 'custom' response field
	 *
	 * @see WC_Gateway_Paypal_Response::get_paypal_order()
	 * @param string $response the raw URL-encoded response string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_order() {

		// assume something went wrong if ACK is missing
		if ( $this->has_parameter( 'CUSTOM' ) ) {
			return $this->get_paypal_order( $this->get_parameter( 'CUSTOM' ) );
		}
	}
}
