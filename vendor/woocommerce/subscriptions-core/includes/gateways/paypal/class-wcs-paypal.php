<?php
/**
 * PayPal Subscription Class.
 *
 * Filters necessary functions in the WC_Paypal class to allow for subscriptions, either via PayPal Standard (default)
 * or PayPal Express Checkout using Reference Transactions (preferred)
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @author      Prospress
 * @since       2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal {

	/** @var WCS_PayPal_Express_API for communicating with PayPal */
	protected static $api;

	/** @var WCS_PayPal single instance of this class */
	protected static $instance;

	/** @var Array cache of PayPal IPN Handler */
	protected static $ipn_handlers;

	/** @var Array cache of PayPal Standard settings in WooCommerce */
	protected static $paypal_settings;

	/**
	 * An internal cache of subscription IDs with a specific PayPal Standard Profile ID or Reference Transaction Billing Agreement.
	 *
	 * @var int[][]
	 */
	protected static $subscriptions_by_paypal_id = array();

	/**
	 * Main PayPal Instance, ensures only one instance is/can be loaded
	 *
	 * @see wc_paypal_express()
	 * @return WCS_PayPal
	 * @since 2.0
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public static function init() {

		self::$paypal_settings = self::get_options();

		// wc-api handler for express checkout transactions
		if ( ! has_action( 'woocommerce_api_wcs_paypal' ) ) {
			add_action( 'woocommerce_api_wcs_paypal', __CLASS__ . '::handle_wc_api' );
		}

		// When necessary, set the PayPal args to be for a subscription instead of shopping cart
		add_action( 'woocommerce_update_options_payment_gateways_paypal', __CLASS__ . '::reload_options', 100 );

		// When necessary, set the PayPal args to be for a subscription instead of shopping cart
		add_action( 'woocommerce_update_options_payment_gateways_paypal', __CLASS__ . '::are_reference_transactions_enabled', 100 );

		// When necessary, set the PayPal args to be for a subscription instead of shopping cart
		add_filter( 'woocommerce_paypal_args', __CLASS__ . '::get_paypal_args', 10, 2 );

		// Check a valid PayPal IPN request to see if it's a subscription *before* WCS_Gateway_Paypal::successful_request()
		add_action( 'valid-paypal-standard-ipn-request', __CLASS__ . '::process_ipn_request', 0 );

		add_action( 'woocommerce_scheduled_subscription_payment_paypal', __CLASS__ . '::process_subscription_payment', 10, 2 );

		// Don't copy over PayPal details to Resubscribe Orders
		add_filter( 'wcs_resubscribe_order_created', __CLASS__ . '::remove_resubscribe_order_meta', 10, 2 );

		// Triggered by WCS_SV_API_Base::broadcast_request() whenever an API request is made
		add_action( 'wc_paypal_api_request_performed', __CLASS__ . '::log_api_requests', 10, 2 );

		add_filter( 'woocommerce_subscriptions_admin_meta_boxes_script_parameters', __CLASS__ . '::maybe_add_change_payment_method_warning' );

		// Maybe order don't need payment because lock.
		add_filter( 'woocommerce_order_needs_payment', __CLASS__ . '::maybe_override_needs_payment', 10, 2 );

		// Remove payment lock when order is completely paid or order is cancelled.
		add_action( 'woocommerce_order_status_cancelled', __CLASS__ . '::maybe_remove_payment_lock' );
		add_action( 'woocommerce_payment_complete', __CLASS__ . '::maybe_remove_payment_lock' );

		// Adds payment lock on order received.
		add_action( 'get_header', __CLASS__ . '::maybe_add_payment_lock' );

		// Run the IPN failure handler attach and detach functions before and after processing to catch and log any unexpected shutdowns
		add_action( 'valid-paypal-standard-ipn-request', 'WCS_PayPal_Standard_IPN_Failure_Handler::attach', -1, 1 );
		add_action( 'valid-paypal-standard-ipn-request', 'WCS_PayPal_Standard_IPN_Failure_Handler::detach', 1, 1 );

		// Remove PayPal from the available payment methods if it's disabled for subscription purchases.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'maybe_remove_paypal_standard' ) );

		WCS_PayPal_Supports::init();
		WCS_PayPal_Status_Manager::init();
		WCS_PayPal_Standard_Switcher::init();

		if ( is_admin() ) {
			WCS_PayPal_Admin::init();
		}

		WCS_PayPal_Change_Payment_Method_Admin::init();
	}

	/**
	 * Get a WooCommerce setting value for the PayPal Standard Gateway
	 *
	 * @since 2.0
	 */
	public static function get_option( $setting_key ) {

		// Post WC 3.3 PayPal's sandbox and live API credentials are stored separately. When requesting the API keys make sure we return the active keys - live or sandbox depending on the mode.
		if ( ! wcs_is_woocommerce_pre( '3.3' ) && in_array( $setting_key, array( 'api_username', 'api_password', 'api_signature' ) ) && 'yes' === self::get_option( 'testmode' ) ) {
			$setting_key = 'sandbox_' . $setting_key;
		}

		return ( isset( self::$paypal_settings[ $setting_key ] ) ) ? self::$paypal_settings[ $setting_key ] : '';
	}

	/**
	 * Checks if the PayPal API credentials are set.
	 *
	 * @since 2.0
	 */
	public static function are_credentials_set() {

		$credentials_are_set = false;

		if ( '' !== self::get_option( 'api_username' ) && '' !== self::get_option( 'api_password' ) && '' !== self::get_option( 'api_signature' ) ) {
			$credentials_are_set = true;
		}

		return apply_filters( 'wooocommerce_paypal_credentials_are_set', $credentials_are_set );
	}

	/**
	 * Checks if the PayPal account has reference transactions setup
	 *
	 * Subscriptions keeps a record of all accounts where reference transactions were found to be enabled just in case the
	 * store manager switches to and from accounts. This record is stored as a JSON encoded array in the options table.
	 *
	 * @since 2.0
	 */
	public static function are_reference_transactions_enabled( $bypass_cache = '' ) {

		$api_username                   = self::get_option( 'api_username' );
		$transient_key                  = 'wcs_paypal_rt_enabled';
		$reference_transactions_enabled = false;

		if ( self::are_credentials_set() ) {

			$accounts_with_reference_transactions_enabled = json_decode( get_option( 'wcs_paypal_rt_enabled_accounts', wcs_json_encode( array() ) ) );

			if ( in_array( $api_username, $accounts_with_reference_transactions_enabled ) ) {

				$reference_transactions_enabled = true;

			} elseif ( 'bypass_cache' === $bypass_cache || get_transient( $transient_key ) !== $api_username ) {

				if ( self::get_api()->are_reference_transactions_enabled() ) {
					$accounts_with_reference_transactions_enabled[] = $api_username;
					update_option( 'wcs_paypal_rt_enabled_accounts', wcs_json_encode( $accounts_with_reference_transactions_enabled ) );
					$reference_transactions_enabled = true;
				} else {
					set_transient( $transient_key, $api_username, WEEK_IN_SECONDS );
				}
			}
		}

		return apply_filters( 'wooocommerce_subscriptions_paypal_reference_transactions_enabled', $reference_transactions_enabled );
	}

	/**
	 * Handle WC API requests where we need to run a reference transaction API operation
	 *
	 * @since 2.0
	 */
	public static function handle_wc_api() {

		if ( ! isset( $_GET['action'] ) ) {
			return;
		}

		switch ( $_GET['action'] ) {

			// called when the customer is returned from PayPal after authorizing their payment, used for retrieving the customer's checkout details
			case 'create_billing_agreement':

				// bail if no token
				if ( ! isset( $_GET['token'] ) ) {
					return;
				}

				// get token to retrieve checkout details with
				$token = esc_attr( $_GET['token'] );

				try {

					$express_checkout_details_response = self::get_api()->get_express_checkout_details( $token );

					// Make sure the billing agreement was accepted
					if ( 1 == $express_checkout_details_response->get_billing_agreement_status() ) {

						$order = $express_checkout_details_response->get_order();

						if ( is_null( $order ) ) {
							throw new Exception( __( 'Unable to find order for PayPal billing agreement.', 'woocommerce-subscriptions' ) );
						}

						// we need to process an initial payment
						if ( $order->get_total() > 0 && ! wcs_is_subscription( $order ) ) {
							$billing_agreement_response = self::get_api()->do_express_checkout( $token, $order, array(
								'payment_action' => 'Sale',
								'payer_id'       => $express_checkout_details_response->get_payer_id(),
							) );
						} else {
							$billing_agreement_response = self::get_api()->create_billing_agreement( $token );
						}

						if ( $billing_agreement_response->has_api_error() ) {
							throw new Exception( $billing_agreement_response->get_api_error_message(), $billing_agreement_response->get_api_error_code() );
						}

						// We're changing the payment method for a subscription, make sure we update it before updating the billing agreement ID so that an old PayPal subscription can be cancelled if the existing payment method is also PayPal
						if ( wcs_is_subscription( $order ) ) {
							WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $order, 'paypal' );
							$redirect_url = add_query_arg( 'utm_nooverride', '1', $order->get_view_order_url() );
						}

						// Make sure PayPal is set as the payment method on the order and subscription
						$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
						$payment_method     = isset( $available_gateways[ self::instance()->get_id() ] ) ? $available_gateways[ self::instance()->get_id() ] : false;
						$order->set_payment_method( $payment_method );

						// Store the billing agreement ID on the order and subscriptions
						wcs_set_paypal_id( $order, $billing_agreement_response->get_billing_agreement_id() );

						// Update payment method on all active subscriptions?
						if ( wcs_is_subscription( $order ) && WC_Subscriptions_Change_Payment_Gateway::will_subscription_update_all_payment_methods( $order ) ) {
							WC_Subscriptions_Change_Payment_Gateway::update_all_payment_methods_from_subscription( $order, $payment_method->id );
						}

						foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) ) as $subscription ) {
							$subscription->set_payment_method( $payment_method );
							wcs_set_paypal_id( $subscription, $billing_agreement_response->get_billing_agreement_id() ); // Also saves the subscription
						}

						if ( ! wcs_is_subscription( $order ) ) {

							if ( 0 == $order->get_total() ) {
								$order->payment_complete();
							} else {
								self::process_subscription_payment_response( $order, $billing_agreement_response );
							}

							$redirect_url = add_query_arg( 'utm_nooverride', '1', $order->get_checkout_order_received_url() );
						}

						// redirect customer to order received page
						wp_safe_redirect( esc_url_raw( $redirect_url ) );

					} else {

						wp_safe_redirect( wc_get_cart_url() );

					}
				} catch ( Exception $e ) {

					wc_add_notice( __( 'An error occurred, please try again or try an alternate form of payment.', 'woocommerce-subscriptions' ), 'error' );

					wp_redirect( wc_get_cart_url() );
				}

				exit;

			case 'reference_transaction_account_check':
				exit;
		}
	}

	/**
	 * Override the default PayPal standard args in WooCommerce for subscription purchases when
	 * automatic payments are enabled and when the recurring order totals is over $0.00 (because
	 * PayPal doesn't support subscriptions with a $0 recurring total, we need to circumvent it and
	 * manage it entirely ourselves.)
	 *
	 * @since 2.0
	 */
	public static function get_paypal_args( $paypal_args, $order ) {

		if ( wcs_order_contains_subscription( $order, array( 'parent', 'renewal', 'resubscribe', 'switch' ) ) || wcs_is_subscription( $order ) ) {
			if ( self::are_reference_transactions_enabled() ) {
				$paypal_args = self::get_api()->get_paypal_args( $paypal_args, $order );
			} else {
				$paypal_args = WCS_PayPal_Standard_Request::get_paypal_args( $paypal_args, $order );
			}
		}

		return $paypal_args;
	}

	/**
	 * When a PayPal IPN messaged is received for a subscription transaction,
	 * check the transaction details and
	 *
	 * @link https://developer.paypal.com/docs/classic/ipn/integration-guide/IPNandPDTVariables/
	 *
	 * @since 2.0
	 */
	public static function process_ipn_request( $transaction_details ) {

		try {
			if ( ! isset( $transaction_details['txn_type'] ) || ! in_array( $transaction_details['txn_type'], array_merge( self::get_ipn_handler( 'standard' )->get_transaction_types(), self::get_ipn_handler( 'reference' )->get_transaction_types() ) ) ) {
				return;
			}

			WC_Gateway_Paypal::log( 'Subscription Transaction Type: ' . $transaction_details['txn_type'] );
			WC_Gateway_Paypal::log( 'Subscription Transaction Details: ' . print_r( $transaction_details, true ) );

			if ( in_array( $transaction_details['txn_type'], self::get_ipn_handler( 'standard' )->get_transaction_types() ) ) {
				self::get_ipn_handler( 'standard' )->valid_response( $transaction_details );
			} elseif ( in_array( $transaction_details['txn_type'], self::get_ipn_handler( 'reference' )->get_transaction_types() ) ) {
				self::get_ipn_handler( 'reference' )->valid_response( $transaction_details );
			}
		} catch ( Exception $e ) {
			WCS_PayPal_Standard_IPN_Failure_Handler::log_unexpected_exception( $e );
		}
	}

	/**
	 * Check whether a given subscription is using reference transactions and if so process the payment.
	 *
	 * @since 2.0
	 */
	public static function process_subscription_payment( $amount, $order ) {

		// If the subscription is using reference transactions, we can process the payment ourselves
		$paypal_profile_id = wcs_get_paypal_id( wcs_get_objects_property( $order, 'id' ) );

		if ( wcs_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' ) ) {

			if ( 0 == $amount ) {
				$order->payment_complete();
				return;
			}

			$response = self::get_api()->do_reference_transaction( $paypal_profile_id, $order, array(
				'amount'         => $amount,
				'invoice_number' => self::get_option( 'invoice_prefix' ) . wcs_str_to_ascii( ltrim( $order->get_order_number(), _x( '#', 'hash before the order number. Used as a character to remove from the actual order number', 'woocommerce-subscriptions' ) ) ),
			) );

			self::process_subscription_payment_response( $order, $response );
		}
	}

	/**
	 * Process a payment based on a response
	 *
	 * @since 2.0.9
	 */
	public static function process_subscription_payment_response( $order, $response ) {

		if ( $response->has_api_error() ) {

			$error_message = $response->get_api_error_message();

			// Some PayPal error messages end with a fullstop, others do not, we prefer our punctuation consistent, so add one if we don't already have one.
			if ( '.' !== substr( $error_message, -1 ) ) {
				$error_message .= '.';
			}

			// translators: placeholders are PayPal API error code and PayPal API error message
			$order->update_status( 'failed', sprintf( __( 'PayPal API error: (%1$d) %2$s', 'woocommerce-subscriptions' ), $response->get_api_error_code(), $error_message ) );

		} elseif ( $response->transaction_held() ) {

			// translators: placeholder is PayPal transaction status message
			$order_note   = sprintf( __( 'PayPal Transaction Held: %s', 'woocommerce-subscriptions' ), $response->get_status_message() );
			$order_status = apply_filters( 'wcs_paypal_held_payment_order_status', 'on-hold', $order, $response );

			// mark order as held
			if ( ! $order->has_status( $order_status ) ) {
				$order->update_status( $order_status, $order_note );
			} else {
				$order->add_order_note( $order_note );
			}
		} elseif ( ! $response->transaction_approved() ) {

			// translators: placeholder is PayPal transaction status message
			$order->update_status( 'failed', sprintf( __( 'PayPal payment declined: %s', 'woocommerce-subscriptions' ), $response->get_status_message() ) );

		} elseif ( $response->transaction_approved() ) {
			// translators: placeholder is a transaction ID.
			$order->add_order_note( sprintf( __( 'PayPal payment approved (ID: %s)', 'woocommerce-subscriptions' ), $response->get_transaction_id() ) );

			$order->payment_complete( $response->get_transaction_id() );
		}
	}

	/**
	 * Don't transfer PayPal meta to resubscribe orders.
	 *
	 * @param object $resubscribe_order The order created for resubscribing the subscription
	 * @param object $subscription The subscription to which the resubscribe order relates
	 * @return object
	 * @since 2.0
	 */
	public static function remove_resubscribe_order_meta( $resubscribe_order, $subscription ) {

		$post_meta_keys = array(
			'Transaction ID',
			'Payer first name',
			'Payer last name',
			'Payer PayPal address',
			'Payer PayPal first name',
			'Payer PayPal last name',
			'PayPal Subscriber ID',
			'Payment type',
		);

		foreach ( $post_meta_keys as $post_meta_key ) {
			delete_post_meta( wcs_get_objects_property( $resubscribe_order, 'id' ), $post_meta_key );
		}

		return $resubscribe_order;
	}

	/**
	 * Maybe adds a warning message to subscription script parameters which is used in a Javascript dialog if the
	 * payment method of the subscription is set to be changed. The warning message is only added if the subscriptions
	 * payment gateway is PayPal Standard.
	 *
	 * @param array $script_parameters The script parameters used in subscription meta boxes.
	 * @return array $script_parameters
	 * @since 2.0
	 */
	public static function maybe_add_change_payment_method_warning( $script_parameters ) {
		global $post;
		$subscription = wcs_get_subscription( $post );

		if ( 'paypal' === $subscription->get_payment_method() ) {

			$paypal_profile_id  = wcs_get_paypal_id( $subscription->get_id() );
			$is_paypal_standard = ! wcs_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' );

			if ( $is_paypal_standard ) {
				$script_parameters['change_payment_method_warning'] = __( "Are you sure you want to change the payment method from PayPal standard?\n\nThis will suspend the subscription at PayPal.", 'woocommerce-subscriptions' );
			}
		}

		return $script_parameters;
	}

	/**
	 * This validates against payment lock for PP and returns false if we meet the criteria:
	 *  - is a parent order.
	 *  - payment method is paypal.
	 *  - PayPal Reference Transactions is disabled.
	 *  - order has lock.
	 *  - lock hasn't timeout.
	 *
	 * @param bool     $needs_payment Does this order needs to process payment?
	 * @param WC_Order $order         The actual order.
	 *
	 * @return bool
	 * @since 2.5.3
	 */
	public static function maybe_override_needs_payment( $needs_payment, $order ) {
		if ( $needs_payment && self::instance()->get_id() === $order->get_payment_method() && ! self::are_reference_transactions_enabled() && wcs_order_contains_subscription( $order, array( 'parent' ) ) ) {
			$has_lock            = $order->get_meta( '_wcs_lock_order_payment' );
			$seconds_since_order = wcs_seconds_since_order_created( $order );

			// We have lock and order hasn't meet the lock time.
			if ( $has_lock && $seconds_since_order < apply_filters( 'wcs_lock_order_payment_seconds', 180 ) ) {
				$needs_payment = false;
			}
		}

		return $needs_payment;
	}

	/**
	 * Adds payment lock meta when order is received and...
	 * - order is valid.
	 * - payment method is paypal.
	 * - order needs payment.
	 * - PayPal Reference Transactions is disabled.
	 * - order is parent order of a subscription.
	 *
	 * @since 2.5.3
	 */
	public static function maybe_add_payment_lock() {
		if ( ! wcs_is_order_received_page() ) {
			return;
		}

		global $wp;
		$order = wc_get_order( absint( $wp->query_vars['order-received'] ) );

		if ( $order && self::instance()->get_id() === $order->get_payment_method() && $order->needs_payment() && ! self::are_reference_transactions_enabled() && wcs_order_contains_subscription( $order, array( 'parent' ) ) ) {
			$order->update_meta_data( '_wcs_lock_order_payment', 'true' );
			$order->save();
		}
	}

	/**
	 * Removes payment lock when order is parent and has paypal method.
	 *
	 * @param int $order_id Order cancelled/paid.
	 *
	 * @since 2.5.3
	 */
	public static function maybe_remove_payment_lock( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( self::instance()->get_id() === $order->get_payment_method() && wcs_order_contains_subscription( $order, array( 'parent' ) ) ) {
			$order->delete_meta_data( 'wcs_lock_order_payment' );
			$order->save();
		}
	}

	/** Getters ******************************************************/

	/**
	 * Get the API object
	 *
	 * @see SV_WC_Payment_Gateway::get_api()
	 * @return WC_PayPal_Express_API API instance
	 * @since 2.0
	 */
	protected static function get_ipn_handler( $ipn_type = 'standard' ) {

		$use_sandbox = ( 'yes' === self::get_option( 'testmode' ) );

		if ( 'reference' === $ipn_type ) {

			if ( ! isset( self::$ipn_handlers['reference'] ) ) {
				self::$ipn_handlers['reference'] = new WCS_Paypal_Reference_Transaction_IPN_Handler( $use_sandbox, self::get_option( 'receiver_email' ) );
			}

			$ipn_handler = self::$ipn_handlers['reference'];

		} else {

			if ( ! isset( self::$ipn_handlers['standard'] ) ) {
				self::$ipn_handlers['standard'] = new WCS_Paypal_Standard_IPN_Handler( $use_sandbox, self::get_option( 'receiver_email' ) );
			}

			$ipn_handler = self::$ipn_handlers['standard'];

		}

		return $ipn_handler;
	}

	/**
	 * Get the API object
	 *
	 * @return WCS_PayPal_Express_API API instance
	 * @since 2.0
	 */
	public static function get_api() {

		if ( is_object( self::$api ) ) {
			return self::$api;
		}

		if ( ! class_exists( 'WC_Gateway_Paypal_Response' ) ) {
			require_once( WC()->plugin_path() . '/includes/gateways/paypal/includes/class-wc-gateway-paypal-response.php' );
		}

		$environment = ( 'yes' === self::get_option( 'testmode' ) ) ? 'sandbox' : 'production';

		return self::$api = new WCS_PayPal_Reference_Transaction_API( 'paypal', $environment, self::get_option( 'api_username' ), self::get_option( 'api_password' ), self::get_option( 'api_signature' ) );
	}

	/**
	 * Return the default WC PayPal gateway's settings.
	 *
	 * @since 2.0
	 */
	public static function reload_options() {
		self::get_options();
	}

	/**
	 * Return the default WC PayPal gateway's settings.
	 *
	 * @since 2.0
	 */
	protected static function get_options() {

		self::$paypal_settings = get_option( 'woocommerce_paypal_settings' );

		return self::$paypal_settings;
	}

	/** Logging **/

	/**
	 * Log API request/response data
	 *
	 * @since 2.0
	 */
	public static function log_api_requests( $request_data, $response_data ) {
		WC_Gateway_Paypal::log( 'Subscription Request Parameters: ' . print_r( $request_data, true ) );
		WC_Gateway_Paypal::log( 'Subscription Request Response: ' . print_r( $response_data, true ) );
	}

	/** Method required by WCS_SV_API_Base, which normally requires an instance of SV_WC_Plugin **/

	public function get_plugin_name() {
		return _x( 'WooCommerce Subscriptions PayPal', 'used in User Agent data sent to PayPal to help identify where a payment came from', 'woocommerce-subscriptions' );
	}

	public function get_version() {
		return WC_Subscriptions_Core_Plugin::instance()->get_plugin_version();
	}

	public function get_id() {
		return 'paypal';
	}

	/**
	 * Set the default value for whether PayPal Standard is enabled or disabled for subscriptions purchases.
	 *
	 * PayPal Standard will be enabled for subscriptions when:
	 * - PayPal is enabled.
	 * - The store has existing subscriptions.
	 *
	 * In any other case, it will be disabled by default.
	 * This function is called when 2.5.0 is active for the first time. @see WC_Subscriptions_Upgrader::upgrade()
	 *
	 * @since 2.5.0
	 */
	public static function set_enabled_for_subscriptions_default() {

		// Exit early if it has already been set.
		if ( self::get_option( 'enabled_for_subscriptions' ) ) {
			return;
		}

		// For existing stores with PayPal enabled, PayPal is automatically enabled for subscriptions.
		if ( 'yes' === WCS_PayPal::get_option( 'enabled' ) && wcs_do_subscriptions_exist() ) {
			$default = 'yes';
		} else {
			$default = 'no';
		}

		// Find the PayPal Standard gateway instance to set the setting.
		foreach ( WC()->payment_gateways->payment_gateways as $gateway ) {
			if ( $gateway->id === 'paypal' ) {
				wcs_update_settings_option( $gateway, 'enabled_for_subscriptions', $default );
				break;
			}
		}
	}

	/**
	 * Remove PayPal Standard as an available payment method if it is disabled for subscriptions.
	 *
	 * @param array $available_gateways A list of available payment methods displayed on the checkout.
	 * @return array
	 * @since 2.5.0
	 */
	public static function maybe_remove_paypal_standard( $available_gateways ) {

		if ( ! isset( $available_gateways['paypal'] ) || 'yes' === self::get_option( 'enabled_for_subscriptions' ) || WCS_PayPal::are_reference_transactions_enabled() ) {
			return $available_gateways;
		}

		$paying_for_order = absint( get_query_var( 'order-pay' ) );

		if (
			WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment ||
			WC_Subscriptions_Cart::cart_contains_subscription() ||
			wcs_cart_contains_renewal() ||
			( $paying_for_order && wcs_order_contains_subscription( $paying_for_order ) )
		) {
			unset( $available_gateways['paypal'] );
		}

		return $available_gateways;
	}

	/**
	 * Gets subscriptions with a given paypal subscription id.
	 *
	 * @since 2.5.4
	 * @param string $paypal_id The PayPal Standard Profile ID or PayPal Reference Transactions Billing Agreement.
	 * @param string $return    Optional. The type to return. Can be 'ids' to return subscription IDs or 'objects' to return WC_Subscription objects. Default 'ids'.
	 * @return WC_Subscription[]|int[] Subscriptions (objects or IDs) with the PayPal Profile ID or Billing Agreement stored in meta.
	 */
	public static function get_subscriptions_by_paypal_id( $paypal_id, $return = 'ids' ) {

		if ( ! isset( self::$subscriptions_by_paypal_id[ $paypal_id ] ) ) {
			$subscription_ids = get_posts( array(
				'posts_per_page' => -1,
				'post_type'      => 'shop_subscription',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_paypal_subscription_id',
						'compare' => '=',
						'value'   => $paypal_id,
					),
				),
			) );

			self::$subscriptions_by_paypal_id[ $paypal_id ] = array_combine( $subscription_ids, $subscription_ids );
		}

		if ( 'objects' === $return ) {
			$subscriptions = array_filter( array_map( 'wcs_get_subscription', self::$subscriptions_by_paypal_id[ $paypal_id ] ) );
		} else {
			$subscriptions = self::$subscriptions_by_paypal_id[ $paypal_id ];
		}

		return $subscriptions;
	}
}
