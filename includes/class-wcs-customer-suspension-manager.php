<?php
/**
 * A class for managing the customer suspension feature.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Customer_Suspension_Manager {

	/**
	 * Initialise the class.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'add_settings' ), 5 );
		add_filter( 'wcs_can_user_put_subscription_on_hold', array( __CLASS__, 'can_customer_put_subscription_on_hold' ), 0, 3 );
		add_filter( 'wcs_view_subscription_actions', array( __CLASS__, 'add_customer_suspension_action' ), 0, 3 );
	}

	/**
	 * Adds the customer suspension setting.
	 *
	 * @since 4.0.0
	 *
	 * @param  array $settings Subscriptions settings.
	 * @return array Subscriptions settings.
	 */
	public static function add_settings( $settings ) {
		$suspension_setting = array(
			'name'     => __( 'Customer Suspensions', 'woocommerce-subscriptions' ),
			'desc'     => _x( 'suspensions per billing period.', 'there\'s a number immediately in front of this text', 'woocommerce-subscriptions' ),
			'id'       => WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions',
			'css'      => 'min-width:50px;',
			'default'  => 0,
			'type'     => 'select',
			'class'    => 'wc-enhanced-select',
			'options'  => apply_filters( 'woocommerce_subscriptions_max_customer_suspension_range', array_merge( range( 0, 12 ), array( 'unlimited' => 'Unlimited' ) ) ),
			'desc_tip' => __( 'Set a maximum number of times a customer can suspend their account for each billing period. For example, for a value of 3 and a subscription billed yearly, if the customer has suspended their account 3 times, they will not be presented with the option to suspend their account until the next year. Store managers will always be able to suspend an active subscription. Set this to 0 to turn off the customer suspension feature completely.', 'woocommerce-subscriptions' ),
		);

		WC_Subscriptions_Admin::insert_setting_after( $settings, WC_Subscriptions_Admin::$option_prefix . '_miscellaneous', $suspension_setting );
		return $settings;
	}

	/**
	 * Filters whether the current user can suspend the subscription.
	 *
	 * Allows the customer to suspend the subscription if the _max_customer_suspensions setting hasn't been reached.
	 *
	 * @since 4.0.0
	 *
	 * @param bool            $can_user_suspend Whether the current user can suspend the subscrption determined by @see wcs_can_user_put_subscription_on_hold().
	 * @param WC_Subscription $subscription     The subscription.
	 * @param WP_User         $user             The current user.
	 *
	 * @return bool Whether the subscription can be suspended by the user.
	 */
	public static function can_customer_put_subscription_on_hold( $can_user_suspend, $subscription, $user ) {

		// Exit early if the customer can already suspend the subscription.
		if ( $can_user_suspend ) {
			return $can_user_suspend;
		}

		// We're only interested in the customer who owns the subscription.
		if ( $subscription->get_user_id() !== $user->ID ) {
			return $can_user_suspend;
		}

		// Make sure subscription suspension count hasn't been reached
		$suspension_count    = intval( $subscription->get_suspension_count() );
		$allowed_suspensions = self::get_allowed_customer_suspensions();

		if ( 'unlimited' === $allowed_suspensions || $allowed_suspensions > $suspension_count ) { // 0 not > anything so prevents a customer ever being able to suspend
			$can_user_suspend = true;
		}

		return $can_user_suspend;
	}

	/**
	 * Adds the customer suspension action, if allowed.
	 *
	 * @since 4.0.0
	 *
	 * @param array           $actions      The actions a customer/user can make with a subscription.
	 * @param WC_Subscription $subscription The subscription.
	 * @param int             $user_id      The user viewing the subscription.
	 *
	 * @return array The customer's subscription actions.
	 */
	public static function add_customer_suspension_action( $actions, $subscription, $user_id ) {

		if ( ! $subscription->can_be_updated_to( 'on-hold' ) ) {
			return $actions;
		}

		if ( ! user_can( $user_id, 'edit_shop_subscription_status', $subscription->get_id() ) ) {
			return $actions;
		}

		if ( '0' === self::get_allowed_customer_suspensions() ) {
			return $actions;
		}

		if ( current_user_can( 'manage_woocommerce' ) || wcs_can_user_put_subscription_on_hold( $subscription, $user_id ) ) {
			$actions['suspend'] = array(
				'url'  => wcs_get_users_change_status_link( $subscription->get_id(), 'on-hold', $subscription->get_status() ),
				'name' => __( 'Suspend', 'woocommerce-subscriptions' ),
			);
		}

		return $actions;
	}

	/**
	 * Gets the number of suspensions a customer can make per billing period.
	 *
	 * @since 4.0.0
	 * @return string The number of suspensions a customer can make per billing period. Can 'unlimited' or the number of suspensions allowed.
	 */
	public static function get_allowed_customer_suspensions() {
		return get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions', '0' );
	}
}
