<?php
/**
 * Subscriptions Payment Gateways
 *
 * Hooks into the WooCommerce payment gateways class to add subscription specific functionality.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Payment_Gateways
 * @category   Class
 * @author     Brent Shepherd
 * @since      1.0
 */
class WC_Subscriptions_Payment_Gateways extends WC_Subscriptions_Core_Payment_Gateways {

	/**
	 * Init WC_Subscriptions_Payment_Gateways actions & filters.
	 *
	 * @since 4.0.0
	 */
	public static function init() {
		parent::init();
		// Trigger a hook for gateways to charge recurring payments.
		add_action( 'woocommerce_scheduled_subscription_payment', array( __CLASS__, 'gateway_scheduled_subscription_payment' ), 10, 1 );

		add_filter( 'woocommerce_subscriptions_admin_recurring_payment_information', array( __CLASS__, 'add_recurring_payment_gateway_information' ), 10, 2 );
	}

	/**
	 * Display the gateways which support subscriptions if manual payments are not allowed.
	 *
	 * @since 1.0
	 */
	public static function get_available_payment_gateways( $available_gateways ) {
		// We don't want to filter the available payment methods while the customer is paying for a standard order via the order-pay screen.
		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return $available_gateways;
		}

		if ( ! WC_Subscriptions_Cart::cart_contains_subscription() && ( ! isset( $_GET['order_id'] ) || ! wcs_order_contains_subscription( absint( $_GET['order_id'] ) ) ) ) {
			return $available_gateways;
		}

		$accept_manual_renewals = wcs_is_manual_renewal_enabled();
		$subscriptions_in_cart  = is_array( WC()->cart->recurring_carts ) ? count( WC()->cart->recurring_carts ) : 0;

		foreach ( $available_gateways as $gateway_id => $gateway ) {

			$supports_subscriptions = $gateway->supports( 'subscriptions' );

			// Remove the payment gateway if there are multiple subscriptions in the cart and this gateway either doesn't support multiple subscriptions or isn't manual (all manual gateways support multiple subscriptions)
			if ( $subscriptions_in_cart > 1 && $gateway->supports( 'multiple_subscriptions' ) !== true && ( $supports_subscriptions || ! $accept_manual_renewals ) ) {
				unset( $available_gateways[ $gateway_id ] );

			// If there is just the one subscription the cart, remove the payment gateway if manual renewals are disabled and this gateway doesn't support automatic payments
			} elseif ( ! $supports_subscriptions && ! $accept_manual_renewals ) {
				unset( $available_gateways[ $gateway_id ] );
			}
		}

		return $available_gateways;
	}

	/**
	 * Fire a gateway specific hook for when a subscription payment is due.
	 *
	 * @since 1.0
	 */
	public static function gateway_scheduled_subscription_payment( $subscription_id, $deprecated = null ) {
		if ( ! is_object( $subscription_id ) ) {
			$subscription = wcs_get_subscription( $subscription_id );
		} else {
			$subscription = $subscription_id;
		}

		if ( false === $subscription ) {
			// translators: %d: subscription ID.
			throw new InvalidArgumentException( sprintf( __( 'Subscription doesn\'t exist in scheduled action: %d', 'woocommerce-subscriptions' ), $subscription_id ) );
		}

		// If the subscription's payment method uses gateway scheduled payments, don't process the payment here. The gateway will handle it.
		if ( $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) {
			return;
		}

		if ( ! $subscription->is_manual() && ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) {
			$latest_renewal_order = $subscription->get_last_order( 'all', 'renewal' );

			if ( empty( $latest_renewal_order ) ) {
				$subscription->add_order_note( __( "Renewal order payment processing was skipped because we couldn't locate the latest renewal order.", 'woocommerce_subscriptions' ) );
				return;
			}

			if ( $latest_renewal_order->needs_payment() ) {
				self::trigger_gateway_renewal_payment_hook( $latest_renewal_order );
			} elseif ( $latest_renewal_order->get_total() > 0 ) {
				$subscription->add_order_note(
					sprintf(
						/* Translators: 1: placeholder is a subscription renewal order ID as a link, 2: placeholder the order's current status */
						__( 'Payment processing of the renewal order %1$s was skipped because it is already paid (%2$s).', 'woocommerce_subscriptions' ),
						'<a href="' . esc_url( $latest_renewal_order->get_edit_order_url() ) . '">' . _x( '#', 'hash before order number', 'woocommerce' ) . $latest_renewal_order->get_order_number() . '</a>',
						wc_get_order_status_name( $latest_renewal_order->get_status() )
					)
				);
			}
		}
	}

	/**
	 * Fire a gateway specific hook for when a subscription renewal payment is due.
	 *
	 * @param WC_Order|false $renewal_order The renewal order to trigger the payment gateway hook for.
	 * @since 2.1.0
	 */
	public static function trigger_gateway_renewal_payment_hook( $renewal_order ) {
		if ( ! empty( $renewal_order ) && $renewal_order->get_total() > 0 && $renewal_order->get_payment_method() ) {

			// Make sure gateways are setup.
			WC()->payment_gateways();

			do_action( 'woocommerce_scheduled_subscription_payment_' . $renewal_order->get_payment_method(), $renewal_order->get_total(), $renewal_order );
		}
	}

	/**
	 * Returns whether the subscription payment gateway has an available gateway.
	 *
	 * @since 4.0.0
	 * @param WC_Subscription $subscription Subscription to check if the gateway is available.
	 * @return bool
	 */
	public static function has_available_payment_method( $subscription ) {
		return wc_get_payment_gateway_by_order( $subscription ) ? true : false;
	}

	/**
	 * Returns whether the gateway supports subscriptions and automatic renewals.
	 *
	 * @since 4.0.0
	 * @param WC_Payment_Gateway $gateway Gateway to check if it supports subscriptions.
	 * @return bool
	 */
	public static function gateway_supports_subscriptions( $gateway ) {
		return ( is_array( $gateway->supports ) && in_array( 'subscriptions', $gateway->supports, true ) ) || 'paypal' === $gateway->id;
	}

	/**
	 * Add links to find additional payment gateways to information after the Settings->Payments->Payment Methods table.
	 */
	public static function add_recurring_payment_gateway_information( $settings, $option_prefix ) {
		$settings[] = array(
			// translators: $1-$2: opening and closing tags. Link to documents->payment gateways, 3$-4$: opening and closing tags. Link to WooCommerce extensions shop page
			'desc' => sprintf( __( 'Find new gateways that %1$ssupport automatic subscription payments%2$s in the official %3$sWooCommerce Marketplace%4$s.', 'woocommerce-subscriptions' ), '<a href="' . esc_url( 'https://woocommerce.com/document/subscriptions/payment-gateways/' ) . '">', '</a>', '<a href="' . esc_url( 'http://www.woocommerce.com/product-category/woocommerce-extensions/' ) . '">', '</a>' ),
			'id'   => $option_prefix . '_payment_gateways_additional',
			'type' => 'informational',
		);
		return $settings;
	}
}
