<?php
/**
 * Class for managing Auto Renew Toggle on View Subscription page of My Account
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
 */
class WCS_My_Account_Auto_Renew_Toggle {

	/**
	 * The auto-renewal toggle setting ID.
	 *
	 * @var string
	 */
	protected static $setting_id;

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function init() {
		self::$setting_id = WC_Subscriptions_Admin::$option_prefix . '_enable_auto_renewal_toggle';

		add_action( 'wp_ajax_wcs_disable_auto_renew', array( __CLASS__, 'disable_auto_renew' ) );
		add_action( 'wp_ajax_wcs_enable_auto_renew', array( __CLASS__, 'enable_auto_renew' ) );
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_setting' ), 20 );
	}

	/**
	 * Check all conditions for whether auto-renewal can be changed is possible
	 *
	 * @param WC_Subscription $subscription The subscription for which the checks for auto-renewal needs to be made
	 * @return boolean
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function can_subscription_auto_renewal_be_changed( $subscription ) {

		if ( ! self::is_enabled() ) {
			return false;
		}
		// Cannot change to auto-renewal for a subscription with status other than active
		if ( ! $subscription->has_status( 'active' ) ) {
			return false;
		}
		// Cannot change to auto-renewal for a subscription with 0 total
		if ( 0 == $subscription->get_total() ) { // Not using strict comparison intentionally
			return false;
		}
		// Cannot change to auto-renewal for a subscription in the final billing period. No next renewal date.
		if ( 0 == $subscription->get_date( 'next_payment' ) ) { // Not using strict comparison intentionally
			return false;
		}
		// If it is not a manual subscription, and the payment gateway is PayPal Standard
		if ( ! $subscription->is_manual() && $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) {
			return false;
		}

		// Looks like changing to auto-renewal is indeed possible
		return true;
	}

	/**
	 * Determines if a subscription is eligible for toggling auto renewal and whether the user, or current user has permission to do so.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.1
	 *
	 * @param WC_Subscription $subscription The subscription to check if auto renewal is allowed.
	 * @param int             $user_id      The user ID to check if they have permission. Optional. Default is current user.
	 *
	 * @return bool Whether the subscription can be toggled and the user has the permission to do so.
	 */
	public static function can_user_toggle_auto_renewal( $subscription, $user_id = 0 ) {
		if ( empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		return user_can( absint( $user_id ), 'toggle_shop_subscription_auto_renewal', $subscription->get_id() ) && self::can_subscription_auto_renewal_be_changed( $subscription );
	}

	/**
	 * Disable auto renewal of subscription
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function disable_auto_renew() {

		if ( ! isset( $_POST['subscription_id'] ) ) {
			return -1;
		}

		$subscription_id = absint( $_POST['subscription_id'] );
		check_ajax_referer( "toggle-auto-renew-{$subscription_id}", 'security' );

		$subscription = wcs_get_subscription( $subscription_id );

		if ( $subscription && self::can_user_toggle_auto_renewal( $subscription ) ) {
			$subscription->set_requires_manual_renewal( true );
			$subscription->add_order_note( __( 'Customer turned off automatic renewals via their My Account page.', 'woocommerce-subscriptions' ) );
			$subscription->save();

			self::send_ajax_response( $subscription );
		}
	}

	/**
	 * Enable auto renewal of subscription
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function enable_auto_renew() {

		if ( ! isset( $_POST['subscription_id'] ) ) {
			return -1;
		}

		$subscription_id = absint( $_POST['subscription_id'] );
		check_ajax_referer( "toggle-auto-renew-{$subscription_id}", 'security' );

		$subscription = wcs_get_subscription( $subscription_id );

		if ( wc_get_payment_gateway_by_order( $subscription ) && self::can_user_toggle_auto_renewal( $subscription ) ) {
			$subscription->set_requires_manual_renewal( false );
			$subscription->add_order_note( __( 'Customer turned on automatic renewals via their My Account page.', 'woocommerce-subscriptions' ) );
			$subscription->save();

			self::send_ajax_response( $subscription );
		}
	}

	/**
	 * Send a response after processing the AJAX request so the page can be updated.
	 *
	 * @param WC_Subscription $subscription
	 */
	protected static function send_ajax_response( $subscription ) {
		wp_send_json( array(
			'payment_method' => esc_attr( $subscription->get_payment_method_to_display( 'customer' ) ),
			'is_manual'      => wc_bool_to_string( $subscription->is_manual() ),
		) );
	}

	/**
	 * Add a setting to allow store managers to enable or disable the auto-renewal toggle.
	 *
	 * @param array $settings
	 * @return array
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function add_setting( $settings ) {
		WC_Subscriptions_Admin::insert_setting_after( $settings, 'woocommerce_subscriptions_turn_off_automatic_payments', array(
			'id'       => self::$setting_id,
			'name'     => __( 'Auto Renewal Toggle', 'woocommerce-subscriptions' ),
			'desc'     => __( 'Display the auto renewal toggle', 'woocommerce-subscriptions' ),
			'desc_tip' => __( 'Allow customers to turn on and off automatic renewals from their View Subscription page.', 'woocommerce-subscriptions' ),
			'default'  => 'no',
			'type'     => 'checkbox',
		) );

		return $settings;
	}

	/**
	 * Checks if the store has enabled the auto-renewal toggle.
	 *
	 * @return bool true if the toggle is enabled, otherwise false.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::$setting_id, 'no' );
	}
}
