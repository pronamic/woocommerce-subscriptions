<?php
/**
 * WooCommerce Subscriptions PayPal Administration Class.
 *
 * Hooks into WooCommerce's core PayPal class to display fields and notices relating to subscriptions.
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

class WCS_PayPal_Admin {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public static function init() {

		// Add PayPal API fields to PayPal form fields as required
		add_action( 'woocommerce_settings_start', __CLASS__ . '::add_form_fields', 100 );
		add_action( 'woocommerce_api_wc_gateway_paypal', __CLASS__ . '::add_form_fields', 100 );

		// Handle requests to check whether a PayPal account has Reference Transactions enabled
		add_action( 'admin_init', __CLASS__ . '::maybe_check_account' );

		// Maybe show notice to enter PayPal API credentials
		add_action( 'admin_notices', __CLASS__ . '::maybe_show_admin_notices' );
	}

	/**
	 * Adds extra PayPal credential fields required to manage subscriptions.
	 *
	 * @since 2.0
	 */
	public static function add_form_fields() {

		foreach ( WC()->payment_gateways->payment_gateways as $key => $gateway ) {

			if ( WC()->payment_gateways->payment_gateways[ $key ]->id !== 'paypal' ) {
				continue;
			}

			// Warn store managers not to change their PayPal Email address as it can break existing Subscriptions in WC2.0+
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['desc_tip']     = false;
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['description'] .= ' </p><p class="description">' . __( 'It is <strong>strongly recommended you do not change the Receiver Email address</strong> if you have active subscriptions with PayPal. Doing so can break existing subscriptions.', 'woocommerce-subscriptions' );
		}
	}

	/**
	 * Handle requests to check whether a PayPal account has Reference Transactions enabled
	 *
	 * @since 2.0
	 */
	public static function maybe_check_account() {

		if ( isset( $_GET['wcs_paypal'] ) && 'check_reference_transaction_support' === $_GET['wcs_paypal'] && wp_verify_nonce( $_GET['_wpnonce'], __CLASS__ ) ) {

			$redirect_url = remove_query_arg( array( 'wcs_paypal', '_wpnonce' ) );

			if ( WCS_PayPal::are_reference_transactions_enabled( 'bypass_cache' ) ) {
				$redirect_url = add_query_arg( array( 'wcs_paypal' => 'rt_enabled' ), $redirect_url );
			} else {
				$redirect_url = add_query_arg( array( 'wcs_paypal' => 'rt_not_enabled' ), $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
		}
	}

	/**
	 * Display an assortment of notices to administrators to encourage them to get PayPal setup right.
	 *
	 * @since 2.0
	 */
	public static function maybe_show_admin_notices() {

		self::maybe_disable_invalid_profile_notice();

		self::maybe_update_credentials_error_flag();

		if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_paypal_supported_currencies', array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB' ) ) ) ) {
			$valid_for_use = false;
		} else {
			$valid_for_use = true;
		}

		$payment_gateway_tab_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_paypal' );

		$notices = array();

		if ( $valid_for_use && 'yes' == WCS_PayPal::get_option( 'enabled' ) && ! has_action( 'admin_notices', 'WC_Subscriptions_Admin::admin_installed_notice' ) && current_user_can( 'manage_options' ) ) {

			if ( ! WCS_PayPal::are_credentials_set() ) {

				$notices[] = array(
					'type' => 'warning',
					// translators: placeholders are opening and closing link tags. 1$-2$: to docs on woothemes, 3$-4$ to gateway settings on the site
					'text'  => sprintf( esc_html__( 'PayPal is inactive for subscription transactions. Please %1$sset up the PayPal IPN%2$s and %3$senter your API credentials%4$s to enable PayPal for Subscriptions.', 'woocommerce-subscriptions' ),
						'<a href="http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-4" target="_blank">',
						'</a>',
						'<a href="' . esc_url( $payment_gateway_tab_url ) . '">',
						'</a>'
					),
				);

			} elseif ( 'woocommerce_page_wc-settings' === get_current_screen()->base && isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'subscriptions', 'checkout' ) ) && ! WCS_PayPal::are_reference_transactions_enabled() ) {

				$notices[] = array(
					'type' => 'warning',
					// translators: placeholders are opening and closing strong and link tags. 1$-2$: strong tags, 3$-8$ link to docs on woothemes
					'text'  => sprintf( esc_html__( '%1$sPayPal Reference Transactions are not enabled on your account%2$s, some subscription management features are not enabled. Please contact PayPal and request they %3$senable PayPal Reference Transactions%4$s on your account. %5$sCheck PayPal Account%6$s  %7$sLearn more %8$s', 'woocommerce-subscriptions' ),
						'<strong>',
						'</strong>',
						'<a href="http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-4" target="_blank">',
						'</a>',
						'</p><p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( 'wcs_paypal', 'check_reference_transaction_support' ), __CLASS__ ) ) . '">',
						'</a>',
						'<a class="button button-primary" href="http://docs.woothemes.com/document/subscriptions/store-manager-guide/#section-4" target="_blank">',
						'&raquo;</a>'
					),
				);

			}

			if ( isset( $_GET['wcs_paypal'] ) && 'rt_enabled' === $_GET['wcs_paypal'] ) {
				$notices[] = array(
					'type' => 'confirmation',
					// translators: placeholders are opening and closing strong tags.
					'text'  => sprintf( esc_html__( '%1$sPayPal Reference Transactions are enabled on your account%2$s. All subscription management features are now enabled. Happy selling!', 'woocommerce-subscriptions' ),
						'<strong>',
						'</strong>'
					),
				);
			}

			if ( false !== get_option( 'wcs_paypal_credentials_error' ) ) {
				$notices[] = array(
					'type' => 'error',
					// translators: placeholders are link opening and closing tags. 1$-2$: to gateway settings, 3$-4$: support docs on woothemes.com
					'text'  => sprintf( esc_html__( 'There is a problem with PayPal. Your API credentials may be incorrect. Please update your %1$sAPI credentials%2$s. %3$sLearn more%4$s.', 'woocommerce-subscriptions' ),
						'<a href="' . esc_url( $payment_gateway_tab_url ) . '">',
						'</a>',
						'<a href="https://support.woothemes.com/hc/en-us/articles/202882473#paypal-credentials" target="_blank">',
						'</a>'
					),
				);
			}

			if ( 'yes' == get_option( 'wcs_paypal_invalid_profile_id' ) ) {
				$notices[] = array(
					'type' => 'error',
					// translators: placeholders are opening and closing link tags. 1$-2$: docs on woothemes, 3$-4$: dismiss link
					'text'  => sprintf( esc_html__( 'There is a problem with PayPal. Your PayPal account is issuing out-of-date subscription IDs. %1$sLearn more%2$s. %3$sDismiss%4$s.', 'woocommerce-subscriptions' ),
						'<a href="https://support.woothemes.com/hc/en-us/articles/202882473#old-paypal-account" target="_blank">',
						'</a>',
						'<a href="' . esc_url( add_query_arg( 'wcs_disable_paypal_invalid_profile_id_notice', 'true' ) ) . '">',
						'</a>'
					),
				);
			}
		}

		if ( ! empty( $notices ) ) {
			include_once( dirname( __FILE__ ) . '/../templates/admin-notices.php' );
		}
	}

	/**
	 * Disable the invalid profile notice when requested.
	 *
	 * @since 2.0
	 */
	protected static function maybe_disable_invalid_profile_notice() {
		if ( isset( $_GET['wcs_disable_paypal_invalid_profile_id_notice'] ) ) {
			update_option( 'wcs_paypal_invalid_profile_id', 'disabled' );
		}
	}

	/**
	 * Remove the invalid credentials error flag whenever a new set of API credentials are saved.
	 *
	 * @since 2.0
	 */
	protected static function maybe_update_credentials_error_flag() {

		// Check if the API credentials are being saved - we can't do this on the 'woocommerce_update_options_payment_gateways_paypal' hook because it is triggered after 'admin_notices'
		if ( ! empty( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'woocommerce-settings' ) && isset( $_POST['woocommerce_paypal_api_username'] ) || isset( $_POST['woocommerce_paypal_api_password'] ) || isset( $_POST['woocommerce_paypal_api_signature'] ) ) {

			$credentials_updated = false;

			if ( isset( $_POST['woocommerce_paypal_api_username'] ) && WCS_PayPal::get_option( 'api_username' ) != $_POST['woocommerce_paypal_api_username'] ) {
				$credentials_updated = true;
			} elseif ( isset( $_POST['woocommerce_paypal_api_password'] ) && WCS_PayPal::get_option( 'api_password' ) != $_POST['woocommerce_paypal_api_password'] ) {
				$credentials_updated = true;
			} elseif ( isset( $_POST['woocommerce_paypal_api_signature'] ) && WCS_PayPal::get_option( 'api_signature' ) != $_POST['woocommerce_paypal_api_signature'] ) {
				$credentials_updated = true;
			}

			if ( $credentials_updated ) {
				delete_option( 'wcs_paypal_credentials_error' );
			}
		}

		do_action( 'wcs_paypal_admin_update_credentials' );
	}

}
