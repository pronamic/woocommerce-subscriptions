<?php
/**
 * Manage the process of deleting, adding, assigning default payment tokens associated with automatic subscriptions
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    2.2.7
 */
class WCS_My_Account_Payment_Methods {

	/* A cache of a customer's payment tokens to avoid running multiple queries in the same request */
	protected static $customer_tokens = array();

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 2.2.7
	 */
	public static function init() {

		// Only hook class functions if the payment token object exists
		if ( class_exists( 'WC_Payment_Token' ) ) {
			add_filter( 'woocommerce_payment_methods_list_item', __CLASS__ . '::flag_subscription_payment_token_deletions', 10, 2 );
			add_action( 'woocommerce_payment_token_deleted', __CLASS__ . '::maybe_update_subscriptions_payment_meta', 10, 2 );
		}
	}

	/**
	 * Add additional query args to delete token URLs which are being used for subscription automatic payments.
	 *
	 * @param  array data about the token including a list of actions which can be triggered by the customer from their my account page
	 * @param  WC_Payment_Token payment token object
	 * @return array payment token data
	 * @since  2.2.7
	 */
	public static function flag_subscription_payment_token_deletions( $payment_token_data, $payment_token ) {

		if ( $payment_token instanceof WC_Payment_Token && isset( $payment_token_data['actions']['delete']['url'] ) ) {

			if ( 0 < count( self::get_subscriptions_by_token( $payment_token ) ) ) {
				if ( self::customer_has_alternative_token( $payment_token ) ) {
					$delete_subscription_token_args = array(
						'delete_subscription_token' => $payment_token->get_id(),
						'wcs_nonce'                 => wp_create_nonce( 'delete_subscription_token_' . $payment_token->get_id() ),
					);

					$payment_token_data['actions']['delete']['url'] = add_query_arg( $delete_subscription_token_args, $payment_token_data['actions']['delete']['url'] );
				} else {
					// Cannot delete a token used for active subscriptions where there is no alternative
					unset( $payment_token_data['actions']['delete'] );
				}
			}
		}

		return $payment_token_data;
	}

	/**
	 * Update subscriptions using a deleted token to use a new token. Subscriptions with the
	 * old token value stored in post meta will be updated using the same meta key to use the
	 * new token value.
	 *
	 * @param int The deleted token id
	 * @param WC_Payment_Token The deleted token object
	 * @since 2.2.7
	 */
	public static function maybe_update_subscriptions_payment_meta( $deleted_token_id, $deleted_token ) {
		if ( isset( $_GET['delete_subscription_token'] ) && ! empty( $_GET['wcs_nonce'] ) && wp_verify_nonce( $_GET['wcs_nonce'], 'delete_subscription_token_' . $_GET['delete_subscription_token'] ) ) {
			// init payment gateways
			WC()->payment_gateways();

			$new_token = self::get_customers_alternative_token( $deleted_token );

			if ( empty( $new_token ) ) {
				$notice = esc_html__( 'The deleted payment method was used for automatic subscription payments, we couldn\'t find an alternative token payment method token to change your subscriptions to.', 'woocommerce-subscriptions' );
				wc_add_notice( $notice, 'error' );
				return;
			}

			$subscriptions    = self::get_subscriptions_by_token( $deleted_token );
			$token_meta_key   = '';
			$notice_displayed = false;

			if ( ! empty( $subscriptions ) ) {
				// translators: $1: the token/credit card label, 2$-3$: opening and closing strong and link tags
				$notice = sprintf( esc_html__( 'The deleted payment method was used for automatic subscription payments. To avoid failed renewal payments in future the subscriptions using this payment method have been updated to use your %1$s. To change the payment method of individual subscriptions go to your %2$sMy Account > Subscriptions%3$s page.', 'woocommerce-subscriptions' ),
					self::get_token_label( $new_token ),
					'<a href="' . esc_url( wc_get_account_endpoint_url( get_option( 'woocommerce_myaccount_subscriptions_endpoint', 'subscriptions' ) ) ) . '"><strong>',
					'</strong></a>'
				);

				wc_add_notice( $notice , 'notice' );
				foreach ( $subscriptions as $subscription ) {
					$subscription = wcs_get_subscription( $subscription );

					if ( empty( $subscription ) ) {
						continue;
					}

					// Attempt to find the token meta key if we haven't already found it.
					if ( empty( $token_meta_key ) ) {
						$payment_method_meta = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );

						if ( is_array( $payment_method_meta ) && isset( $payment_method_meta[ $deleted_token->get_gateway_id() ] ) && is_array( $payment_method_meta[ $deleted_token->get_gateway_id() ] ) ) {
							foreach ( $payment_method_meta[ $deleted_token->get_gateway_id() ] as $meta_table => $meta ) {
								foreach ( $meta as $meta_key => $meta_data ) {
									if ( $deleted_token->get_token() === $meta_data['value'] ) {
										$token_meta_key = $meta_key;
										break 2;
									}
								}
							}
						}
					}

					$updated = update_post_meta( $subscription->get_id(), $token_meta_key, $new_token->get_token(), $deleted_token->get_token() );

					if ( $updated ) {
						$subscription->add_order_note( sprintf( _x( 'Payment method meta updated after customer deleted a token from their My Account page. Payment meta changed from %1$s to %2$s', 'used in subscription note', 'woocommerce-subscriptions' ), $deleted_token->get_token(), $new_token->get_token() ) );
						do_action( 'woocommerce_subscription_token_changed', $subscription, $new_token, $deleted_token );
					}
				}
			}
		}
	}

	/**
	 * Get subscriptions by a WC_Payment_Token. All automatic subscriptions with the token's payment method,
	 * customer id and token value stored in post meta will be returned.
	 *
	 * @param  WC_Payment_Token payment token object
	 * @return array subscription posts
	 * @since  2.2.7
	 */
	public static function get_subscriptions_by_token( $payment_token ) {

		$meta_query = array(
			array(
				'key'   => '_payment_method',
				'value' => $payment_token->get_gateway_id(),
			),
			array(
				'key'   => '_requires_manual_renewal',
				'value' => 'false',
			),
			array(
				'key'   => '_customer_user',
				'value' => $payment_token->get_user_id(),
				'type'  => 'numeric',
			),
			array(
				'value' => $payment_token->get_token(),
			),
		);

		$user_subscriptions = get_posts( array(
			'post_type'      => 'shop_subscription',
			'post_status'    => array( 'wc-pending', 'wc-active', 'wc-on-hold' ),
			'meta_query'     => $meta_query,
			'posts_per_page' => -1,
		) );

		return apply_filters( 'woocommerce_subscriptions_by_payment_token', $user_subscriptions, $payment_token );
	}

	/**
	 * Get a WC_Payment_Token label. eg Visa ending in 1234
	 *
	 * @param  WC_Payment_Token payment token object
	 * @return string WC_Payment_Token label
	 * @since  2.2.7
	 */
	public static function get_token_label( $token ) {

		if ( method_exists( $token, 'get_last4' ) && $token->get_last4() ) {
			$label = sprintf( __( '%s ending in %s', 'woocommerce-subscriptions' ), esc_html( wc_get_credit_card_type_label( $token->get_card_type() ) ), esc_html( $token->get_last4() ) );
		} else {
			$label = esc_html( wc_get_credit_card_type_label( $token->get_card_type() ) );
		}

		return $label;
	}

	/**
	 * Get a list of customer payment tokens. Caches results to avoid multiple database queries per request
	 *
	 * @param  string (optional) Gateway ID for getting tokens for a specific gateway.
	 * @param  int (optional) The customer id - defaults to the current user.
	 * @return array of WC_Payment_Token objects
	 * @since  2.2.7
	 */
	public static function get_customer_tokens( $gateway_id = '', $customer_id = '' ) {
		if ( '' === $customer_id ) {
			$customer_id = get_current_user_id();
		}

		if ( ! isset( self::$customer_tokens[ $customer_id ][ $gateway_id ] ) ) {
			self::$customer_tokens[ $customer_id ][ $gateway_id ] = WC_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );
		}

		return self::$customer_tokens[ $customer_id ][ $gateway_id ];
	}

	/**
	 * Get the customer's alternative token.
	 *
	 * @param  WC_Payment_Token the token to find an alternative for
	 * @return WC_Payment_Token the customer's alternative token
	 * @since  2.2.7
	 */
	public static function get_customers_alternative_token( $token ) {
		$payment_tokens         = self::get_customer_tokens( $token->get_gateway_id(), $token->get_user_id() );
		$alternative_token      = null;
		$has_single_alternative = count( $payment_tokens ) === 2; // if there are 2 tokens in total there is only 1 other alternative

		foreach ( $payment_tokens as $payment_token ) {
			// if there is a default token which is different we can use it as an alternative.
			if ( $payment_token->get_id() !== $token->get_id() && ( $payment_token->is_default() || $has_single_alternative ) ) {
				$alternative_token = $payment_token;
				break;
			}
		}

		return $alternative_token;
	}

	/**
	 * Determine if the customer has an alternative token.
	 *
	 * @param  WC_Payment_Token payment token object
	 * @return bool
	 * @since  2.2.7
	 */
	public static function customer_has_alternative_token( $token ) {
		return self::get_customers_alternative_token( $token ) !== null;
	}
}
WCS_My_Account_Payment_Methods::init();
