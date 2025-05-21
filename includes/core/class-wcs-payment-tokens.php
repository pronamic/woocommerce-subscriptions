<?php
/**
 * WooCommerce Subscriptions Payment Tokens
 *
 * An API for storing and managing tokens for subscriptions.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Payment_Tokens extends WC_Payment_Tokens {

	// A cache of a customer's payment tokens to avoid running multiple queries in the same request.
	protected static $customer_tokens = array();

	/**
	 * Update the subscription payment meta to change from an old payment token to a new one.
	 *
	 * @param  WC_Subscription $subscription The subscription to update.
	 * @param  WC_Payment_Token $new_token   The new payment token.
	 * @param  WC_Payment_Token $old_token   The old payment token.
	 * @return bool Whether the subscription was updated or not.
	 */
	public static function update_subscription_token( $subscription, $new_token, $old_token ) {
		$token_payment_gateway = $old_token->get_gateway_id();
		$payment_meta_table    = self::get_subscription_payment_meta( $subscription, $token_payment_gateway );

		// Attempt to find the token meta key from the subscription payment meta and the old token.
		if ( is_array( $payment_meta_table ) ) {
			foreach ( $payment_meta_table as $meta ) {
				foreach ( $meta as $meta_key => $meta_data ) {
					if ( $old_token->get_token() === $meta_data['value'] ) {
						$subscription->update_meta_data( $meta_key, $new_token->get_token() );
						$subscription->save();
						break 2;
					}
				}
			}
		}

		// Copy the new token to the last renewal order if it needs payment so the retry system will pick up the new method.
		$last_renewal_order = $subscription->get_last_order( 'all', 'renewal' );

		if ( $last_renewal_order && $last_renewal_order->needs_payment() ) {
			wcs_copy_payment_method_to_order( $subscription, $last_renewal_order );
			$last_renewal_order->save();
		}

		/**
		 * Enable third-party plugins to run their own updates and filter whether the token was updated or not.
		 *
		 * @param bool Whether the token was updated. Default is true.
		 * @param WC_Subscription  $subscription
		 * @param WC_Payment_Token $new_token
		 * @param WC_Payment_Token $old_token
		 */
		$updated = apply_filters( 'woocommerce_subscriptions_update_subscription_token', true, $subscription, $new_token, $old_token );

		if ( $updated ) {
			do_action( 'woocommerce_subscription_token_changed', $subscription, $new_token, $old_token );
		}

		return $updated;
	}

	/**
	 * Get all payment meta on a subscription for a gateway.
	 *
	 * @param WC_Subscription $subscription The subscription to update.
	 * @param string $gateway_id The target gateway ID.
	 * @return bool|array Payment meta data. False if no meta is found.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function get_subscription_payment_meta( $subscription, $gateway_id ) {
		$payment_method_meta = apply_filters( 'woocommerce_subscription_payment_meta', array(), $subscription );
		if ( is_array( $payment_method_meta ) && isset( $payment_method_meta[ $gateway_id ] ) && is_array( $payment_method_meta[ $gateway_id ] ) ) {
			return $payment_method_meta[ $gateway_id ];
		}

		return false;
	}

	/**
	 * Get subscriptions by a WC_Payment_Token. All automatic subscriptions with the token's payment method,
	 * customer id and token value stored in post meta will be returned.
	 *
	 * @param  WC_Payment_Token $payment_token Payment token object.
	 * @return array subscription posts
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function get_subscriptions_from_token( $payment_token ) {
		$user_subscriptions     = array();
		$users_subscription_ids = WCS_Customer_Store::instance()->get_users_subscription_ids( $payment_token->get_user_id() );

		if ( ! empty( $users_subscription_ids ) ) {
			$subscription_ids = wcs_get_orders_with_meta_query(
				[
					'type'           => 'shop_subscription',
					'status'         => [ 'wc-pending', 'wc-active', 'wc-on-hold' ],
					'payment_method' => $payment_token->get_gateway_id(),
					'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'   => '_requires_manual_renewal',
							'value' => 'false',
						],
						[
							'value' => $payment_token->get_token(),
						],
					],
					'limit'          => -1,
					'return'         => 'ids',
					'post__in'       => $users_subscription_ids,
				]
			);

			if ( has_filter( 'woocommerce_subscriptions_by_payment_token' ) ) {
				wcs_deprecated_function( 'The "woocommerce_subscriptions_by_payment_token" hook should no longer be used. It previously filtered post objects and in moving to CRUD and Subscription APIs the "woocommerce_subscriptions_by_payment_token"', '2.5.0', 'woocommerce_subscriptions_from_payment_token' );

				$subscription_posts = apply_filters( 'woocommerce_subscriptions_by_payment_token', array_map( 'get_post', $subscription_ids ), $payment_token );
				$subscription_ids   = array_unique( array_merge( $subscription_ids, wp_list_pluck( $subscription_posts, 'ID' ) ) );
			}

			foreach ( $subscription_ids as $subscription_id ) {
				$user_subscriptions[ $subscription_id ] = wcs_get_subscription( $subscription_id );
			}
		}

		return apply_filters( 'woocommerce_subscriptions_from_payment_token', $user_subscriptions, $payment_token );
	}

	/**
	 * Get a list of customer payment tokens. Caches results to avoid multiple database queries per request
	 *
	 * @param  int (optional) The customer id - defaults to the current user.
	 * @param  string (optional) Gateway ID for getting tokens for a specific gateway.
	 * @return array of WC_Payment_Token objects.
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function get_customer_tokens( $customer_id = '', $gateway_id = '' ) {
		if ( '' === $customer_id ) {
			$customer_id = get_current_user_id();
		}

		if ( ! isset( self::$customer_tokens[ $customer_id ][ $gateway_id ] ) ) {
			self::$customer_tokens[ $customer_id ][ $gateway_id ] = parent::get_customer_tokens( $customer_id, $gateway_id );
		}

		return self::$customer_tokens[ $customer_id ][ $gateway_id ];
	}

	/**
	 * Get the customer's alternative token.
	 *
	 * @param  WC_Payment_Token $token The token to find an alternative for.
	 * @return WC_Payment_Token The customer's alternative token.
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function get_customers_alternative_token( $token ) {
		$payment_tokens    = self::get_customer_tokens( $token->get_user_id(), $token->get_gateway_id() );
		$alternative_token = null;

		// Remove the token we're trying to find an alternative for.
		unset( $payment_tokens[ $token->get_id() ] );

		if ( count( $payment_tokens ) === 1 ) {
			$alternative_token = reset( $payment_tokens );
		} else {
			foreach ( $payment_tokens as $payment_token ) {
				// If there is a default token we can use it as an alternative.
				if ( $payment_token->is_default() ) {
					$alternative_token = $payment_token;
					break;
				}
			}
		}

		return $alternative_token;
	}

	/**
	 * Determine if the customer has an alternative token.
	 *
	 * @param  WC_Payment_Token $token Payment token object.
	 * @return bool
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function customer_has_alternative_token( $token ) {
		return self::get_customers_alternative_token( $token ) !== null;
	}

}
