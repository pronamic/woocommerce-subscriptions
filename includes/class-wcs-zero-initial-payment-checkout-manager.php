<?php
/**
 * A class for managing the zero initial payment feature requiring payment.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Zero_Initial_Payment_Checkout_Manager {

	/**
	 * Initialise the class.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ) );
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 5 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'order_needs_payment' ), 5 );
	}

	/**
	 * Adds the $0 initial checkout setting.
	 *
	 * @since 4.0.0
	 * @return array WC Subscriptions settings.
	 */
	public static function add_settings( $settings ) {
		$setting = array(
			'name'     => __( '$0 Initial Checkout', 'woocommerce-subscriptions' ),
			'desc'     => __( 'Allow $0 initial checkout without a payment method.', 'woocommerce-subscriptions' ),
			'id'       => WC_Subscriptions_Admin::$option_prefix . '_zero_initial_payment_requires_payment',
			'default'  => 'no',
			'type'     => 'checkbox',
			'desc_tip' => __( 'Allow a subscription product with a $0 initial payment to be purchased without providing a payment method. The customer will be required to provide a payment method at the end of the initial period to keep the subscription active.', 'woocommerce-subscriptions' ),
		);

		WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Admin::$option_prefix . '_miscellaneous', $setting );
		return $settings;
	}

	/**
	 * Checks if a $0 checkout requires a payment method.
	 *
	 * @since 4.0.0
	 * @return bool Whether a $0 initial checkout requires a payment method.
	 */
	public static function zero_initial_checkout_requires_payment() {
		return 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_zero_initial_payment_requires_payment', 'no' );
	}

	/**
	 * Unhooks core Subscriptions functionality that requires payment on checkout for $0 subscription purchases,
	 * if the store has opted to bypass that via this feature.
	 *
	 * @since 4.0.0
	 *
	 * @param bool $cart_needs_payment Whether the cart requires payment.
	 * @return bool
	 */
	public static function cart_needs_payment( $cart_needs_payment ) {
		if ( ! self::zero_initial_checkout_requires_payment() ) {
			remove_filter( 'woocommerce_cart_needs_payment', 'WC_Subscriptions_Cart::cart_needs_payment' );
		}

		return $cart_needs_payment;
	}

	/**
	 * Unhooks core Subscriptions functionality that requires payment for a $0 subscription order,
	 * if the store has opted to bypass that via this feature.
	 *
	 * @since 4.0.0
	 *
	 * @param bool $needs_payment
	 * @return bool
	 */
	public static function order_needs_payment( $needs_payment ) {
		if ( ! self::zero_initial_checkout_requires_payment() ) {
			remove_filter( 'woocommerce_order_needs_payment', 'WC_Subscriptions_Order::order_needs_payment' );
		}

		return $needs_payment;
	}
}
