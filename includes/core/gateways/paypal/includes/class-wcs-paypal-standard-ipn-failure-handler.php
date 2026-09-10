<?php
/**
 * PayPal Standard IPN Failure Handler
 *
 * Introduces a new handler to take care of failing IPN requests
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @author      Prospress
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Automattic\WooCommerce_Subscriptions\Internal\PayPal\Log_Sanitizer;

class WCS_PayPal_Standard_IPN_Failure_Handler {

	private static $transaction_details = null;

	/**
	 * @var WC_Logger_Interface|null
	 */
	public static $log = null;

	/**
	 * The gateway's logging preference, held while logging is force-enabled so that it can be put back.
	 *
	 * @var bool|null
	 */
	private static $merchant_log_preference = null;

	/**
	 * Whether logging was force-enabled by @see self::attach() and is waiting to be restored.
	 *
	 * @var bool
	 */
	private static $logging_forced = false;

	/**
	 * Attaches all IPN failure handler related hooks and filters and also sets logging to enabled.
	 *
	 * A fatal error while processing an IPN message is exactly when the log is worth having, so logging is turned
	 * on even where the merchant has it switched off — but only for the messages this plugin's own handlers
	 * process, and the log says that it happened. For every other message the store's own preference stands,
	 * with @see self::detach() putting it back if it was overridden.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.6
	 * @param array $transaction_details
	 */
	public static function attach( $transaction_details ) {
		self::$transaction_details = $transaction_details;

		if ( self::is_handled_transaction( $transaction_details ) && true !== WC_Gateway_Paypal::$log_enabled ) {
			self::$merchant_log_preference  = WC_Gateway_Paypal::$log_enabled;
			self::$logging_forced           = true;
			WC_Gateway_Paypal::$log_enabled = true;

			WC_Gateway_Paypal::log( 'Logging has been enabled for the duration of this IPN message so that an unexpected failure can be diagnosed.' );
		}

		add_action( 'wcs_paypal_ipn_process_failure', __CLASS__ . '::log_ipn_errors', 10, 2 );
		add_action( 'shutdown', __CLASS__ . '::catch_unexpected_shutdown' );
	}

	/**
	 * Whether the IPN message is one this plugin's own handlers will process.
	 *
	 * The logging override exists so that a failure inside those handlers can be diagnosed; a message with any
	 * other transaction type is WooCommerce's to handle, under the store's own logging preference.
	 *
	 * @since 9.2.0
	 *
	 * @param mixed $transaction_details The IPN message as received from PayPal.
	 * @return bool
	 */
	private static function is_handled_transaction( $transaction_details ) {
		if ( ! is_array( $transaction_details ) || ! isset( $transaction_details['txn_type'] ) ) {
			return false;
		}

		return in_array( $transaction_details['txn_type'], WCS_PayPal::get_handled_ipn_transaction_types(), true );
	}

	/**
	 * Close up loose ends
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.6
	 * @param $transaction_details
	 */
	public static function detach( $transaction_details ) {
		remove_action( 'wcs_paypal_ipn_process_failure', __CLASS__ . '::log_ipn_errors' );
		remove_action( 'shutdown', __CLASS__ . '::catch_unexpected_shutdown' );

		if ( self::$logging_forced ) {
			WC_Gateway_Paypal::$log_enabled = self::$merchant_log_preference;
			self::$logging_forced           = false;
			self::$merchant_log_preference  = null;
		}

		self::$transaction_details = null;
	}

	/**
	 * On PHP shutdown log any unexpected failures from PayPal IPN processing
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.6
	 */
	public static function catch_unexpected_shutdown() {

		if ( ! empty( self::$transaction_details ) && $error = error_get_last() ) {
			if ( in_array( $error['type'], array( E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ) ) ) {
				do_action( 'wcs_paypal_ipn_process_failure', self::$transaction_details, $error );
			}
		}

		self::$transaction_details = null;
	}

	/**
	 * Log any fatal errors occurred while Subscriptions is trying to process IPN messages
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.6
	 * @param array $transaction_details the current IPN message being processed when the fatal error occurred
	 * @param array $error
	 */
	public static function log_ipn_errors( $transaction_details, $error = '' ) {
		// we want to make sure the ipn error admin notice is always displayed when a new error occurs
		delete_option( 'wcs_fatal_error_handling_ipn_ignored' );

		self::log_to_failure( sprintf( 'Subscription transaction details: %1$s', Log_Sanitizer::to_json( Log_Sanitizer::sanitize_ipn_message( $transaction_details ) ) ) );

		if ( ! empty( $error ) ) {
			update_option( 'wcs_fatal_error_handling_ipn', $error['message'] );
			self::log_to_failure( sprintf( 'Error processing PayPal IPN message: %s in %s on line %s.', $error['message'], $error['file'], $error['line'] ) );

			if ( ! empty( $error['trace'] ) ) {
				self::log_to_failure( sprintf( 'Stack trace: %s', PHP_EOL . $error['trace'] ) );
			}
		}

		set_transient( 'wcs_paypal_ipn_error_occurred', WCS_PayPal::get_option( 'api_username' ), WEEK_IN_SECONDS );
	}

	/**
	 * Log any unexpected fatal errors to wcs-ipn-failures log file
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.6
	 * @param string $message
	 */
	public static function log_to_failure( $message ) {

		if ( empty( self::$log ) ) {
			self::$log = new WC_Logger();
		}

		self::$log->add( 'wcs-ipn-failures', $message );
	}

	/**
	 * Builds an error array from exception and call @see self::log_ipn_errors() to log unhandled
	 * exceptions in a separate paypal log.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.6
	 * @param Exception $exception
	 */
	public static function log_unexpected_exception( $exception ) {
		$error = array(
			'message' => $exception->getMessage(),
			'file'    => $exception->getFile(),
			'line'    => $exception->getLine(),
			'trace'   => $exception->getTraceAsString(),
		);

		if ( empty( $error['message'] ) ) {
			$error['message'] = 'Unhandled Exception: no message';
		}

		self::log_ipn_errors( self::$transaction_details, $error );
	}
}
