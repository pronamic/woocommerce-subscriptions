<?php
/**
 * WooCommerce Subscriptions PayPal Administration Class.
 *
 * Hooks into WooCommerce's core PayPal class to display fields and notices relating to subscriptions.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  Gateways/PayPal
 * @category    Class
 * @author      Prospress
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_PayPal_Admin {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function init() {

		// Add PayPal API fields to PayPal form fields as required
		add_action( 'woocommerce_settings_start', __CLASS__ . '::add_form_fields', 100 );
		add_action( 'woocommerce_api_wc_gateway_paypal', __CLASS__ . '::add_form_fields', 100 );

		// Handle requests to check whether a PayPal account has Reference Transactions enabled
		add_action( 'admin_init', __CLASS__ . '::maybe_check_account' );

		// Maybe show notice to enter PayPal API credentials
		add_action( 'admin_notices', __CLASS__ . '::maybe_show_admin_notices' );

		// Add the PayPal subscription information to the billing information
		add_action( 'woocommerce_admin_order_data_after_billing_address', __CLASS__ . '::profile_link' );

		// Before WC updates the PayPal settings remove credentials error flag
		add_action( 'load-woocommerce_page_wc-settings', __CLASS__ . '::maybe_update_credentials_error_flag', 9 );

		// Add an enable for subscription purchases setting.
		add_action( 'woocommerce_settings_api_form_fields_paypal', array( __CLASS__, 'add_enable_for_subscriptions_setting' ) );
	}

	/**
	 * Adds extra PayPal credential fields required to manage subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function add_form_fields() {

		foreach ( WC()->payment_gateways->payment_gateways as $key => $gateway ) {

			if ( WC()->payment_gateways->payment_gateways[ $key ]->id !== 'paypal' ) {
				continue;
			}

			// Warn store managers not to change their PayPal Email address as it can break existing Subscriptions in WC2.0+
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['desc_tip']     = false;
			// translators: $1 and $2 are opening and closing strong tags, respectively.
			WC()->payment_gateways->payment_gateways[ $key ]->form_fields['receiver_email']['description'] .= ' </p><p class="description">' . sprintf( __( 'It is %1$sstrongly recommended you do not change the Receiver Email address%2$s if you have active subscriptions with PayPal. Doing so can break existing subscriptions.', 'woocommerce-subscriptions' ), '<strong>', '</strong>' );
		}
	}

	/**
	 * Handle requests to check whether a PayPal account has Reference Transactions enabled
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_show_admin_notices() {
		self::maybe_disable_invalid_profile_notice();

		$valid_paypal_currency = in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_paypal_supported_currencies', array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB' ) ) );
		$is_paypal_enabled = 'yes' === WCS_PayPal::get_option( 'enabled' );

		// We don't want to show Paypal warnings while showing the WC Subscriptions extension welcome notice
		$is_showing_wc_subscriptions_welcome_notice = class_exists( 'WC_Subscriptions_Core_Plugin' ) && has_action( 'admin_notices', array( WC_Subscriptions_Core_Plugin::instance(), 'admin_installed_notice' ) );

		if ( ! $is_paypal_enabled || ! $valid_paypal_currency || ! current_user_can( 'manage_options' ) || $is_showing_wc_subscriptions_welcome_notice ) {
			return;
		}

		$payment_gateway_tab_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paypal' );
		$notices                 = array();

		if ( ! WCS_PayPal::are_credentials_set() ) {
			if ( 'yes' === WCS_PayPal::get_option( 'enabled_for_subscriptions' ) ) {
				$notices[] = array(
					'type' => 'warning',
					// translators: placeholders are opening and closing link tags. 1$-2$: to docs on woocommerce, 3$-4$ to gateway settings on the site
					'text' => sprintf( esc_html__( 'PayPal is inactive for subscription transactions. Please %1$sset up the PayPal IPN%2$s and %3$senter your API credentials%4$s to enable PayPal for Subscriptions.', 'woocommerce-subscriptions' ),
						'<a href="https://docs.woocommerce.com/document/subscriptions/store-manager-guide/#ipn-setup" target="_blank">',
						'</a>',
						'<a href="' . esc_url( $payment_gateway_tab_url ) . '">',
						'</a>'
					),
				);
			}
		} elseif ( 'woocommerce_page_wc-settings' === get_current_screen()->base && isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'subscriptions', 'checkout' ) ) && ! WCS_PayPal::are_reference_transactions_enabled() ) {
			if ( 'yes' === WCS_PayPal::get_option( 'enabled_for_subscriptions' ) ) {
				$notice_type = 'warning';
				// translators: opening/closing tags - links to documentation.
				$notice_text = esc_html__( '%1$sPayPal Reference Transactions are not enabled on your account%2$s, some subscription management features are not enabled. Please contact PayPal and request they %3$senable PayPal Reference Transactions%4$s on your account. %5$sCheck PayPal Account%6$s  %3$sLearn more %7$s', 'woocommerce-subscriptions' );
			} else {
				$notice_type = 'info';
				// translators: opening/closing tags - links to documentation.
				$notice_text = esc_html__( '%1$sPayPal Reference Transactions are not enabled on your account%2$s. If you wish to use PayPal Reference Transactions with Subscriptions, please contact PayPal and request they %3$senable PayPal Reference Transactions%4$s on your account. %5$sCheck PayPal Account%6$s  %3$sLearn more %7$s', 'woocommerce-subscriptions' );
			}

			$notices[] = array(
				'type' => $notice_type,
				// translators: placeholders are opening and closing strong and link tags. 1$-2$: strong tags, 3$-8$ link to docs on woocommerce
				'text' => sprintf( $notice_text,
					'<strong>',
					'</strong>',
					'<a href="https://docs.woocommerce.com/document/subscriptions/faq/paypal-reference-transactions/" target="_blank">',
					'</a>',
					'</p><p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( 'wcs_paypal', 'check_reference_transaction_support' ), __CLASS__ ) ) . '">',
					'</a>',
					'&raquo;</a>'
				),
			);
		}

		if ( isset( $_GET['wcs_paypal'] ) && 'rt_enabled' === $_GET['wcs_paypal'] ) {
			$notices[] = array(
				'type' => 'confirmation',
				// translators: placeholders are opening and closing strong tags.
				'text' => sprintf( esc_html__( '%1$sPayPal Reference Transactions are enabled on your account%2$s. All subscription management features are now enabled. Happy selling!', 'woocommerce-subscriptions' ),
					'<strong>',
					'</strong>'
				),
			);
		}

		if ( false !== get_option( 'wcs_paypal_credentials_error' ) ) {
			$notices[] = array(
				'type' => 'error',
				// translators: placeholders are link opening and closing tags. 1$-2$: to gateway settings, 3$-4$: support docs on woocommerce.com
				'text' => sprintf( esc_html__( 'There is a problem with PayPal. Your API credentials may be incorrect. Please update your %1$sAPI credentials%2$s. %3$sLearn more%4$s.', 'woocommerce-subscriptions' ),
					'<a href="' . esc_url( $payment_gateway_tab_url ) . '">',
					'</a>',
					'<a href="https://docs.woocommerce.com/document/subscriptions-canceled-suspended-paypal/#section-2" target="_blank">',
					'</a>'
				),
			);
		}

		if ( 'yes' == get_option( 'wcs_paypal_invalid_profile_id' ) ) {
			$notices[] = array(
				'type' => 'error',
				// translators: placeholders are opening and closing link tags. 1$-2$: docs on woocommerce, 3$-4$: dismiss link
				'text' => sprintf( esc_html__( 'There is a problem with PayPal. Your PayPal account is issuing out-of-date subscription IDs. %1$sLearn more%2$s. %3$sDismiss%4$s.', 'woocommerce-subscriptions' ),
					'<a href="https://docs.woocommerce.com/document/subscriptions-canceled-suspended-paypal/#section-3" target="_blank">',
					'</a>',
					'<a href="' . esc_url( add_query_arg( 'wcs_disable_paypal_invalid_profile_id_notice', 'true' ) ) . '">',
					'</a>'
				),
			);
		}

		$last_ipn_error        = get_option( 'wcs_fatal_error_handling_ipn', '' );
		$failed_ipn_log_handle = 'wcs-ipn-failures';

		if ( ! empty( $last_ipn_error ) && ( false == get_option( 'wcs_fatal_error_handling_ipn_ignored', false ) || isset( $_GET['wcs_reveal_your_ipn_secrets'] ) ) ) {
			$notice = new WCS_Admin_Notice( 'error' );
			$notice->set_content_template( 'html-ipn-failure-notice.php', dirname( __FILE__ ) . '/../templates/', array(
				'failed_ipn_log_handle' => $failed_ipn_log_handle,
				'last_ipn_error'        => $last_ipn_error,
				'log_file_url'          => admin_url( sprintf( 'admin.php?page=wc-status&tab=logs&log_file=%s-%s-log', $failed_ipn_log_handle, sanitize_file_name( wp_hash( $failed_ipn_log_handle ) ) ) ),
			) );

			$notice->set_actions( array(
				array(
					'name'  => __( 'Ignore this error (not recommended)', 'woocommerce-subscriptions' ),
					'url'   => wp_nonce_url( add_query_arg( 'wcs_ipn_error_notice', 'ignore' ), 'wcs_ipn_error_notice', '_wcsnonce' ),
					'class' => 'button',
				),
				array(
					'name'  => __( 'Open a ticket', 'woocommerce-subscriptions' ),
					'url'   => 'https://woocommerce.com/my-account/marketplace-ticket-form/',
					'class' => 'button button-primary',
				),
			) );

			$notice->display();
		}

		if ( ! empty( $notices ) ) {
			include_once( dirname( __FILE__ ) . '/../templates/admin-notices.php' );
		}
	}

	/**
	 * Disable the invalid profile notice when requested.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected static function maybe_disable_invalid_profile_notice() {
		if ( isset( $_GET['wcs_disable_paypal_invalid_profile_id_notice'] ) ) {
			update_option( 'wcs_paypal_invalid_profile_id', 'disabled' );
		}

		if ( isset( $_GET['wcs_ipn_error_notice'] ) ) {
			update_option( 'wcs_fatal_error_handling_ipn_ignored', true );
		}
	}

	/**
	 * Remove the invalid credentials error flag whenever a new set of API credentials are saved.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_update_credentials_error_flag() {

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


	/**
	 * Prints link to the PayPal's profile related to the provided subscription
	 *
	 * @param WC_Subscription $subscription
	 */
	public static function profile_link( $subscription ) {
		if ( ! wcs_is_subscription( $subscription ) || $subscription->is_manual() || 'paypal' != $subscription->get_payment_method() ) {
			return;
		}

		$paypal_profile_id = wcs_get_paypal_id( $subscription );

		if ( empty( $paypal_profile_id ) ) {
			return;
		}

		$url    = '';
		$domain = WCS_PayPal::get_option( 'testmode' ) === 'yes' ? 'sandbox.paypal' : 'paypal';

		if ( false === wcs_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' ) ) {
			// Standard subscription
			$url = "https://www.{$domain}.com/?cmd=_profile-recurring-payments&encrypted_profile_id={$paypal_profile_id}";
		} elseif ( wcs_is_paypal_profile_a( $paypal_profile_id, 'billing_agreement' ) ) {
			// Reference Transaction subscription
			$url = "https://www.{$domain}.com/?cmd=_profile-merchant-pull&encrypted_profile_id={$paypal_profile_id}&mp_id={$paypal_profile_id}&return_to=merchant&flag_flow=merchant";
		}

		echo '<div class="address">';
		echo '<p class="paypal_subscription_info"><strong>';
		echo esc_html( __( 'PayPal Subscription ID:', 'woocommerce-subscriptions' ) );
		echo '</strong>';

		if ( ! empty( $url ) ) {
			echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $paypal_profile_id ) . '</a>';
		} else {
			echo esc_html( $paypal_profile_id );
		}

		echo '</p></div>';
	}

	/**
	 * Add the enabled or subscriptions setting.
	 *
	 * @param array $settings The WooCommerce PayPal Settings array.
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function add_enable_for_subscriptions_setting( $settings ) {
		if ( WCS_PayPal::are_reference_transactions_enabled() ) {
			return $settings;
		}

		$setting = array(
			'type'    => 'checkbox',
			'label'   => __( 'Enable PayPal Standard for Subscriptions', 'woocommerce-subscriptions' ),
			'default' => 'no',
		);

		// Display a description
		if ( 'no' === WCS_PayPal::get_option( 'enabled_for_subscriptions' ) ) {
			$setting['description'] = sprintf(
				/* translators: Placeholders are the opening and closing link tags.*/
				__( "Before enabling PayPal Standard for Subscriptions, please note, when using PayPal Standard, customers are locked into using PayPal Standard for the life of their subscription, and PayPal Standard has a number of limitations. Please read the guide on %1\$swhy we don't recommend PayPal Standard%2\$s for Subscriptions before choosing to enable this option.", 'woocommerce-subscriptions' ),
				'<a href="https://docs.woocommerce.com/document/subscriptions/payment-gateways/#paypal-limitations">', '</a>'
			);
		}

		$settings = wcs_array_insert_after( 'enabled', $settings, 'enabled_for_subscriptions', $setting );

		return $settings;
	}
}
