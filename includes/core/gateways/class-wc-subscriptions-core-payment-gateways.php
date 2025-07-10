<?php
/**
 * Subscriptions Core Payment Gateways
 * Hooks into the WooCommerce payment gateways class to add subscription specific functionality.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 */
class WC_Subscriptions_Core_Payment_Gateways {

	protected static $one_gateway_supports = array();

	/**
	 * @var bool $is_displaying_mini_cart
	 */
	protected static $is_displaying_mini_cart = false;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function init() {
		self::$is_displaying_mini_cart = false;

		add_action( 'init', array( get_called_class(), 'init_paypal' ), 5 ); // run before default priority 10 in case the site is using ALTERNATE_WP_CRON to avoid https://core.trac.wordpress.org/ticket/24160.

		add_filter( 'woocommerce_available_payment_gateways', array( get_called_class(), 'get_available_payment_gateways' ) );

		add_filter( 'woocommerce_no_available_payment_methods_message', array( get_called_class(), 'no_available_payment_methods_message' ) );

		add_filter( 'woocommerce_payment_gateways_renewal_support_status_html', array( get_called_class(), 'payment_gateways_support_tooltip' ), 11, 2 );

		// Create a gateway specific hooks for subscription events.
		add_action( 'woocommerce_subscription_status_updated', array( get_called_class(), 'trigger_gateway_status_updated_hook' ), 10, 2 );

		// Determine if the mini-cart widget is being displayed.
		add_filter( 'widget_title', array( get_called_class(), 'before_displaying_mini_cart' ), 0, 3 );
		add_filter( 'widget_title', array( get_called_class(), 'after_displaying_mini_cart' ), 1000, 3 );
	}

	/**
	 * Instantiate our custom PayPal class
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function init_paypal() {
		WCS_PayPal::init();
	}

	/**
	 * Returns a payment gateway object by gateway's ID, or false if it could not find the gateway.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2.4
	 */
	public static function get_payment_gateway( $gateway_id ) {
		$found_gateway = false;

		if ( WC()->payment_gateways ) {
			foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
				if ( $gateway_id == $gateway->id ) {
					$found_gateway = $gateway;
				}
			}
		}

		return $found_gateway;
	}

	/**
	 * Only display the gateways which subscriptions-core supports
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 * @param array $available_gateways
	 * @return array
	 */
	public static function get_available_payment_gateways( $available_gateways ) {
		global $post;

		// We don't want to filter the available payment methods while the customer is paying for a standard order via the order-pay screen.
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return $available_gateways;
		}

		// If a gateway is getting available payment methods for the mini-cart widget or when setting the mini-cart cache, make sure the correct methods are available.
		$is_mini_cart = did_action( 'woocommerce_before_mini_cart' ) !== did_action( 'woocommerce_after_mini_cart' );

		if ( ( $is_mini_cart || self::$is_displaying_mini_cart ) && ! wcs_cart_contains_renewal() && ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return $available_gateways;
		}

		// If there's a subscription in the cart and you're viewing a WC Product page, make sure the correct available payment methods are returned for a simple WC Product.
		if ( is_product() && isset( $post->post_type, $post->ID ) && 'product' === $post->post_type ) {
			$product = wc_get_product( $post->ID );

			if ( $product && ! $product->is_type( array( 'subscription', 'variable-subscription', 'subscription_variation' ) ) ) {
				return $available_gateways;
			}
		} elseif (
			! wcs_cart_contains_renewal() &&
			! WC_Subscriptions_Cart::cart_contains_subscription() &&
			( ! isset( $_GET['order_id'] ) || ! wcs_order_contains_subscription( wc_clean( wp_unslash( $_GET['order_id'] ) ) ) )
		) {
			return $available_gateways;
		}

		foreach ( $available_gateways as $gateway_id => $gateway ) {
			if ( 'woocommerce_payments' !== $gateway_id ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		return $available_gateways;
	}

	/**
	 * Check the content of the cart and add required payment methods.
	 *
	 * @return array list of features required by cart items.
	 */
	public static function inject_payment_feature_requirements_for_cart_api() {

		// No subscriptions in the cart, no need to add anything.
		if ( ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return array();
		}

		// Manual renewals are accepted - all payment gateways are suitable.
		if ( wcs_is_manual_renewal_enabled() ) {
			return array();
		}

		$subscriptions_in_cart = is_array( WC()->cart->recurring_carts ) ? count( WC()->cart->recurring_carts ) : 0;

		$features = array();
		if ( $subscriptions_in_cart > 1 && ! in_array( 'multiple_subscriptions', $features, true ) ) {
			$features[] = 'multiple_subscriptions';
		} elseif ( ! in_array( 'subscriptions', $features, true ) ) {
			$features[] = 'subscriptions';
		}

		return $features;
	}

	/**
	 * Helper function to check if at least one payment gateway on the site supports a certain subscription feature.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function one_gateway_supports( $supports_flag ) {

		// Only check if we haven't already run the check
		if ( ! isset( self::$one_gateway_supports[ $supports_flag ] ) ) {

			self::$one_gateway_supports[ $supports_flag ] = false;

			foreach ( WC()->payment_gateways->get_available_payment_gateways() as $gateway ) {
				if ( $gateway->supports( $supports_flag ) ) {
					self::$one_gateway_supports[ $supports_flag ] = true;
					break;
				}
			}
		}

		return self::$one_gateway_supports[ $supports_flag ];
	}

	/**
	 * Improve message displayed on checkout when a subscription is in the cart but not gateways support subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.2
	 */
	public static function no_available_payment_methods_message( $no_gateways_message ) {
		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! wcs_is_manual_renewal_enabled() ) {
			if ( current_user_can( 'manage_woocommerce' ) ) {
				// translators: 1-2: opening/closing tags - link to documentation.
				$no_gateways_message = sprintf( __( 'Sorry, it seems there are no available payment methods which support subscriptions. Please see %1$sEnabling Payment Gateways for Subscriptions%2$s if you require assistance.', 'woocommerce-subscriptions' ), '<a href="https://woocommerce.com/document/subscriptions/payment-gateways/enabling-payment-gateways-for-subscriptions/">', '</a>' );
			} else {
				$no_gateways_message = __( 'Sorry, it seems there are no available payment methods which support subscriptions. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce-subscriptions' );
			}
		}

		return $no_gateways_message;
	}

	/**
	 * Fire a gateway specific whenever a subscription's status is changed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function trigger_gateway_status_updated_hook( $subscription, $new_status ) {

		if ( $subscription->is_manual() ) {
			return;
		}

		switch ( $new_status ) {
			case 'active':
				$hook_prefix = 'woocommerce_subscription_activated_';
				break;
			case 'on-hold':
				$hook_prefix = 'woocommerce_subscription_on-hold_';
				break;
			case 'pending-cancel':
				$hook_prefix = 'woocommerce_subscription_pending-cancel_';
				break;
			case 'cancelled':
				$hook_prefix = 'woocommerce_subscription_cancelled_';
				break;
			case 'expired':
				$hook_prefix = 'woocommerce_subscription_expired_';
				break;
			default:
				$hook_prefix = apply_filters( 'woocommerce_subscriptions_gateway_status_updated_hook_prefix', 'woocommerce_subscription_status_updated_', $subscription, $new_status );
				break;
		}

		do_action( $hook_prefix . $subscription->get_payment_method(), $subscription );
	}

	/**
	 * Display a list of each gateway supported features in a tooltip
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function payment_gateways_support_tooltip( $status_html, $gateway ) {

		if ( ! static::gateway_supports_subscriptions( $gateway ) ) {
			return $status_html;
		}

		$core_features         = (array) apply_filters( 'woocommerce_subscriptions_payment_gateway_features_list', $gateway->supports, $gateway );
		$subscription_features = $change_payment_method_features = array();

		foreach ( $core_features as $key => $feature ) {

			// Skip any non-subscription related features.
			if ( 'gateway_scheduled_payments' !== $feature && false === strpos( $feature, 'subscription' ) ) {
				continue;
			}

			$feature = str_replace( 'subscription_', '', $feature );

			if ( 0 === strpos( $feature, 'payment_method' ) ) {
				switch ( $feature ) {
					case 'payment_method_change':
						$change_payment_method_features[] = 'payment method change';
						break;

					case 'payment_method_change_customer':
						$change_payment_method_features[] = 'customer change payment';
						break;

					case 'payment_method_change_admin':
						$change_payment_method_features[] = 'admin change payment';
						break;

					default:
						$change_payment_method_features[] = str_replace( 'payment_method', ' ', $feature );
						break;
				}
			} else {
				$subscription_features[] = $feature;
			}

			unset( $core_features[ $key ] );
		}

		$status_html .= '<span class="payment-method-features-info tips" data-tip="';
		$status_html .= esc_attr( '<strong><u>' . __( 'Supported features:', 'woocommerce-subscriptions' ) . '</u></strong></br>' . implode( '<br />', str_replace( '_', ' ', $core_features ) ) );

		if ( ! empty( $subscription_features ) ) {
			$status_html .= esc_attr( '</br><strong><u>' . __( 'Subscription features:', 'woocommerce-subscriptions' ) . '</u></strong></br>' . implode( '<br />', str_replace( '_', ' ', $subscription_features ) ) );
		}

		if ( ! empty( $change_payment_method_features ) ) {
			$status_html .= esc_attr( '</br><strong><u>' . __( 'Change payment features:', 'woocommerce-subscriptions' ) . '</u></strong></br>' . implode( '<br />', str_replace( '_', ' ', $change_payment_method_features ) ) );
		}

		$status_html .= '"></span>';

		$allowed_html = wp_kses_allowed_html( 'post' );
		$allowed_html['span']['data-tip'] = true;

		return wp_kses( $status_html, $allowed_html );
	}

	/**
	 * Returns whether the subscription has an available payment gateway that's supported by subscriptions-core.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0.0
	 * @param WC_Subscription $subscription Subscription to check if the gateway is available.
	 * @return bool
	 */
	public static function has_available_payment_method( $subscription ) {
		return 'woocommerce_payments' === $subscription->get_payment_method() && method_exists( WC_Payments_Subscription_Service::class, 'is_wcpay_subscription' ) && WC_Payments_Subscription_Service::is_wcpay_subscription( $subscription ) ? true : false;
	}

	/**
	 * Determines if subscriptions with a total of nothing (0) are allowed.
	 *
	 * @return bool
	 */
	public static function are_zero_total_subscriptions_allowed() {
		return get_called_class() !== 'WC_Subscriptions_Core_Payment_Gateways';
	}

	/**
	 * Returns whether the gateway supports subscriptions and automatic renewals.
	 *
	 * @since 1.3.0
	 * @param WC_Gateway $gateway Gateway to check if it supports subscriptions.
	 * @return bool
	 */
	public static function gateway_supports_subscriptions( $gateway ) {
		return ! empty( $gateway->id ) && 'woocommerce_payments' === $gateway->id;
	}

	/**
	 * The PayPal Checkout plugin checks for available payment methods on this hook
	 * before enqueuing their SPB JS when displaying the buttons in the mini-cart widget.
	 *
	 * This function is hooked on to 0 priority to make sure we set $is_displaying_mini_cart to true before displaying the mini-cart.
	 *
	 * @since 1.6.0
	 *
	 * @param string $title   Widget title.
	 * @param array $instance Array of widget data.
	 * @param string $widget_id  ID/name of the widget being displayed.
	 *
	 * @return string
	 */
	public static function before_displaying_mini_cart( $title, $instance = array(), $widget_id = null ) {
		self::$is_displaying_mini_cart = 'woocommerce_widget_cart' === $widget_id;
		return $title;
	}

	/**
	 * The PayPal Checkout plugin checks for available payment methods on this hook
	 * before enqueuing their SPB JS when displaying the buttons in the mini-cart widget.
	 *
	 * This function is hooked on to priority 1000 to make sure we set $is_displaying_mini_cart back to false after any JS is enqueued for the mini-cart.
	 *
	 * @since 1.6.0
	 *
	 * @param string $title   Widget title.
	 * @param array $instance Array of widget data.
	 * @param string $widget_id  ID/name of the widget being displayed.
	 *
	 * @return string
	 */
	public static function after_displaying_mini_cart( $title, $instance = array(), $widget_id = null ) {
		self::$is_displaying_mini_cart = false;
		return $title;
	}

	/**
	 * Fire a gateway specific hook for when a subscription is activated.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function trigger_gateway_activated_subscription_hook( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::trigger_gateway_status_updated_hook()' );
		self::trigger_gateway_status_updated_hook( wcs_get_subscription_from_key( $subscription_key ), 'active' );
	}

	/**
	 * Fire a gateway specific hook for when a subscription is activated.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function trigger_gateway_reactivated_subscription_hook( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::trigger_gateway_status_updated_hook()' );
		self::trigger_gateway_status_updated_hook( wcs_get_subscription_from_key( $subscription_key ), 'active' );
	}

	/**
	 * Fire a gateway specific hook for when a subscription is on-hold.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function trigger_gateway_subscription_put_on_hold_hook( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::trigger_gateway_status_updated_hook()' );
		self::trigger_gateway_status_updated_hook( wcs_get_subscription_from_key( $subscription_key ), 'on-hold' );
	}

	/**
	 * Fire a gateway specific when a subscription is cancelled.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function trigger_gateway_cancelled_subscription_hook( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::trigger_gateway_status_updated_hook()' );
		self::trigger_gateway_status_updated_hook( wcs_get_subscription_from_key( $subscription_key ), 'cancelled' );
	}

	/**
	 * Fire a gateway specific hook when a subscription expires.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function trigger_gateway_subscription_expired_hook( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::trigger_gateway_status_updated_hook()' );
		self::trigger_gateway_status_updated_hook( wcs_get_subscription_from_key( $subscription_key ), 'expired' );
	}
}
