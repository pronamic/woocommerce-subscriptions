<?php
/**
 * Manage the process of deleting, adding, assigning default payment tokens associated with automatic subscriptions
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
 */
class WCS_My_Account_Payment_Methods {

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function init() {
		// Only hook class functions if the payment token object exists
		if ( ! class_exists( 'WC_Payment_Token' ) ) {
			return;
		}

		add_filter( 'woocommerce_payment_methods_list_item', array( __CLASS__, 'flag_subscription_payment_token_deletions' ), 10, 2 );

		// This needs to run after the payment plugins had a chance to execute their delete actions.
		add_action( 'woocommerce_payment_token_deleted', array( __CLASS__, 'maybe_update_subscriptions_payment_meta' ), 11, 2 );
		add_action( 'woocommerce_payment_token_set_default', array( __CLASS__, 'display_default_payment_token_change_notice' ), 10, 2 );
		add_action( 'wp', array( __CLASS__, 'update_subscription_tokens' ) );

		add_action( 'woocommerce_before_account_payment_methods', array( __CLASS__, 'print_deleting_notices' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_scripts' ) );
	}

	/**
	 * Add additional query args to delete token URLs which are being used for subscription automatic payments.
	 *
	 * @param array $payment_token_data data about the token including a list of actions which can be triggered by the customer from their my account page
	 * @param WC_Payment_Token $payment_token payment token object
	 * @return array payment token data
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function flag_subscription_payment_token_deletions( $payment_token_data, $payment_token ) {

		if ( $payment_token instanceof WC_Payment_Token && isset( $payment_token_data['actions']['delete']['url'] ) ) {

			if ( 0 < count( WCS_Payment_Tokens::get_subscriptions_from_token( $payment_token ) ) ) {
				if ( WCS_Payment_Tokens::customer_has_alternative_token( $payment_token ) ) {
					$delete_subscription_token_args = array(
						'delete_subscription_token' => $payment_token->get_id(),
						'wcs_nonce'                 => wp_create_nonce( 'delete_subscription_token_' . $payment_token->get_id() ),
					);

					$payment_token_data['actions']['delete']['url'] = add_query_arg( $delete_subscription_token_args, $payment_token_data['actions']['delete']['url'] );
				} else {
					/**
					 * Allow third-party gateways to override whether the token delete button should be removed.
					 *
					 * Some gateways, like Bambora, don't allow customers to add a new card with the same card number but different expiry or cvv.
					 * This means customers updating their expiring card need to delete the existing card first before adding the new one. This
					 * isn't possible however because we prevent deleting tokens linked to active subscriptions.
					 *
					 * Gateways can use this filter to make their own checks to allow deletion.
					 *
					 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
					 *
					 * @param bool $allow_deletion Whether the delete button should be shown for tokens linked to a subscription. true - show, false - not shown (default).
					 * @param WC_Payment_Token $payment_token The payment token in question.
					 */
					if ( isset( $payment_token_data['actions']['delete'] ) && ! apply_filters( 'wc_subscriptions_allow_subscription_token_deletion', false, $payment_token ) ) {
						// Cannot delete a token used for active subscriptions where there is no alternative.
						// Override the delete URL. We'll display a notice explaining why you cant delete that method instead.
						$payment_token_data['actions']['wcs_deletion_error'] = $payment_token_data['actions']['delete'];
						unset( $payment_token_data['actions']['delete'] );

						// Determine which notice we need to display. The 'choose a default' or 'add a payment method'.
						// If they have more than 1 alternative method, they need to select a default, otherwise they need to add one.
						if ( count( WCS_Payment_Tokens::get_customer_tokens( $payment_token->get_user_id(), $payment_token->get_gateway_id() ) ) > 2 ) {
							$notice_to_display = 'choose_default';
						} else {
							$notice_to_display = 'add_method';
						}

						$payment_token_data['actions']['wcs_deletion_error']['url'] = "#{$notice_to_display}";
					}
				}
			}
		}

		return $payment_token_data; // nosemgrep: audit.php.wp.security.xss.query-arg -- False positive. This URL is escaped in the WC template when the token links are outputted.
	}

	/**
	 * Update subscriptions using a deleted token to use a new token. Subscriptions with the
	 * old token value stored in post meta will be updated using the same meta key to use the
	 * new token value.
	 *
	 * @param int $deleted_token_id The deleted token id.
	 * @param WC_Payment_Token $deleted_token The deleted token object.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function maybe_update_subscriptions_payment_meta( $deleted_token_id, $deleted_token ) {
		if ( ! isset( $_GET['delete_subscription_token'] ) || empty( $_GET['wcs_nonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['wcs_nonce'] ) ), 'delete_subscription_token_' . wc_clean( wp_unslash( $_GET['delete_subscription_token'] ) ) ) ) {
			return;
		}

		// init payment gateways
		WC()->payment_gateways();

		$new_token = WCS_Payment_Tokens::get_customers_alternative_token( $deleted_token );

		// @phpstan-ignore empty.variable
		if ( empty( $new_token ) ) {
			$notice = esc_html__( 'The deleted payment method was used for automatic subscription payments, we couldn\'t find an alternative token payment method token to change your subscriptions to.', 'woocommerce-subscriptions' );
			wc_add_notice( $notice, 'error' );
			return;
		}

		$subscriptions = WCS_Payment_Tokens::get_subscriptions_from_token( $deleted_token );

		if ( empty( $subscriptions ) ) {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( empty( $subscription ) ) {
				continue;
			}

			if ( WCS_Payment_Tokens::update_subscription_token( $subscription, $new_token, $deleted_token ) ) {
				// translators: 1: deleted token, 2: new token.
				$subscription->add_order_note( sprintf( _x( 'Payment method meta updated after customer deleted a token from their My Account page. Payment meta changed from %1$s to %2$s', 'used in subscription note', 'woocommerce-subscriptions' ), $deleted_token->get_token(), $new_token->get_token() ) );
			}
		}

		// translators: $1: the token/credit card label, 2$-3$: opening and closing strong and link tags
		$notice = sprintf( esc_html__( 'The deleted payment method was used for automatic subscription payments. To avoid failed renewal payments in future the subscriptions using this payment method have been updated to use your %1$s. To change the payment method of individual subscriptions go to your %2$sMy Account > Subscriptions%3$s page.', 'woocommerce-subscriptions' ),
			$new_token->get_display_name(),
			'<a href="' . esc_url( wc_get_account_endpoint_url( get_option( 'woocommerce_myaccount_subscriptions_endpoint', 'subscriptions' ) ) ) . '"><strong>',
			'</strong></a>'
		);

		wc_add_notice( $notice, 'notice' );
	}

	/**
	 * Get a WC_Payment_Token label. eg Visa ending in 1234
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.7.2
	 *
	 * @param WC_Payment_Token $token payment token object
	 * @return string WC_Payment_Token label
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function get_token_label( $token ) {
		wcs_deprecated_function( __METHOD__, '2.7.2', '$token->get_display_name()' );
		return $token->get_display_name();
	}

	/**
	 * Display a notice when a customer sets a new default token notifying them of what this means for their subscriptions.
	 *
	 * @param int $default_token_id The default token id.
	 * @param WC_Payment_Token $default_token The default token object.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.3
	 */
	public static function display_default_payment_token_change_notice( $default_token_id, $default_token ) {
		global $wp;

		// Only display the change token notice on the My Account > Add Payment Method page.
		if ( ! isset( $wp->query_vars['set-default-payment-method'] ) ) {
			return;
		}

		$display_notice  = false;
		$customer_tokens = WCS_Payment_Tokens::get_customer_tokens( $default_token->get_user_id(), $default_token->get_gateway_id() );
		unset( $customer_tokens[ $default_token_id ] );

		// Check if there are subscriptions for one of the customer's other tokens.
		foreach ( $customer_tokens as $token ) {
			if ( count( WCS_Payment_Tokens::get_subscriptions_from_token( $token ) ) > 0 ) {
				$display_notice = true;
				break;
			}
		}

		if ( ! $display_notice ) {
			return;
		}

		$notice = sprintf(
			// translators: 1: token display name, 2: opening link tag, 4: closing link tag, 3: opening link tag.
			esc_html__( 'Would you like to update your subscriptions to use this new payment method - %1$s?%2$sYes%4$s | %3$sNo%4$s', 'woocommerce-subscriptions' ),
			$default_token->get_display_name(),
			'<br><a href="' . esc_url(
				add_query_arg(
					array(
						'update-subscription-tokens' => 'true',
						'token-id'                   => $default_token_id,
						'_wcsnonce'                  => wp_create_nonce( 'wcs-update-subscription-tokens' ),
					),
					wc_get_account_endpoint_url( 'payment-methods' )
				)
			) . '"><strong>',
			'<a href=""><strong>',
			'</strong></a>'
		);

		wc_add_notice( $notice, 'notice' );
	}

	/**
	 * Update the customer's subscription tokens if they opted to from their My Account page.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.3
	 */
	public static function update_subscription_tokens() {
		if ( ! isset( $_GET['update-subscription-tokens'], $_GET['token-id'], $_GET['_wcsnonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_GET['_wcsnonce'] ) ), 'wcs-update-subscription-tokens' ) ) {
			return;
		}

		// init payment gateways
		WC()->payment_gateways();

		$default_token_id = wc_clean( wp_unslash( $_GET['token-id'] ) );
		$default_token    = WC_Payment_Tokens::get( $default_token_id );

		if ( ! $default_token ) {
			return;
		}

		$tokens = WCS_Payment_Tokens::get_customer_tokens( $default_token->get_user_id(), $default_token->get_gateway_id() );
		unset( $tokens[ $default_token_id ] );

		foreach ( $tokens as $old_token ) {
			foreach ( WCS_Payment_Tokens::get_subscriptions_from_token( $old_token ) as $subscription ) {
				if ( ! empty( $subscription ) && WCS_Payment_Tokens::update_subscription_token( $subscription, $default_token, $old_token ) ) {
					// translators: 1: previous token, 2: new token.
					$subscription->add_order_note( sprintf( _x( 'Payment method meta updated after customer changed their default token and opted to update their subscriptions. Payment meta changed from %1$s to %2$s', 'used in subscription note', 'woocommerce-subscriptions' ), $old_token->get_token(), $default_token->get_token() ) );
				}
			}
		}

		wp_safe_redirect( remove_query_arg( array( 'update-subscription-tokens', 'token-id', '_wcsnonce' ) ) );
		exit();
	}

	/**
	 * Enqueues the frontend scripts for the My account > Payment methods page.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public static function enqueue_frontend_scripts() {
		if ( 'payment-methods' !== WC()->query->get_current_endpoint() ) {
			return;
		}

		$script_params = array(
			'add_method_error'     => sprintf(
				// translators: %1$s opening strong HTML tag, %2$s closing strong HTML tag.
				__( 'That payment method cannot be deleted because it is linked to an automatic subscription. Please %1$sadd a payment method%2$s, before trying again.', 'woocommerce-subscriptions' ),
				'<strong>',
				'</strong>'
			),
			'choose_default_error' => sprintf(
				// translators: %1$s opening strong and em HTML tags, %2$s closing em HTML tag, %3$s closing strong HTML tag.
				__( 'That payment method cannot be deleted because it is linked to an automatic subscription. Please choose a %1$sdefault%2$s payment method%3$s, before trying again.', 'woocommerce-subscriptions' ),
				'<strong><em>',
				'</em>',
				'</strong>'
			),
		);

		wp_enqueue_script( 'wc-subscriptions-payment-methods', WC_Subscriptions_Core_Plugin::instance()->get_subscriptions_core_directory_url( 'assets/js/frontend/payment-methods.js' ), array( 'jquery' ), WC_Subscriptions_Core_Plugin::instance()->get_library_version(), true );
		wp_localize_script( 'wc-subscriptions-payment-methods', 'wcs_payment_methods', $script_params );
	}

	/**
	 * Prints an error notice stub, to be used when a customer attempts to delete a payment token used by a subscription.
	 *
	 * @see self::enqueue_frontend_scripts()                  For the error message content.
	 * @see self::flag_subscription_payment_token_deletions() For the determination of when a token cannot be deleted.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public static function print_deleting_notices() {
		// The notice is hidden on load, and only shown when a token delete request is made.
		echo '<div id="wcs_delete_token_warning" style="display: none;">';
		wc_print_notice( '', 'error' );
		echo '</div>';
	}

	/**
	 * Get subscriptions by a WC_Payment_Token. All automatic subscriptions with the token's payment method,
	 * customer id and token value stored in post meta will be returned.
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function get_subscriptions_by_token( $payment_token ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::get_subscriptions_from_token()' );
		return WCS_Payment_Tokens::get_subscriptions_from_token( $payment_token );
	}

	/**
	 * Get a list of customer payment tokens. Caches results to avoid multiple database queries per request
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function get_customer_tokens( $gateway_id = '', $customer_id = '' ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::get_customer_tokens()' );
		return WCS_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );
	}

	/**
	 * Get the customer's alternative token.
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function get_customers_alternative_token( $token ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::get_customers_alternative_token()' );
		return WCS_Payment_Tokens::get_customers_alternative_token( $token );
	}

	/**
	 * Determine if the customer has an alternative token.
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function customer_has_alternative_token( $token ) {
		_deprecated_function( __METHOD__, '2.5.0', 'WCS_Payment_Tokens::customer_has_alternative_token()' );
		return WCS_Payment_Tokens::customer_has_alternative_token( $token );
	}
}
