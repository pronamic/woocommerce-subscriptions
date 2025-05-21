<?php
/**
 * Deprecate actions that use a dynamic hook by appending a variable, like a payment gateway's name.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Hook_Deprecator
 * @category   Class
 * @author     Prospress
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

class WCS_Dynamic_Action_Deprecator extends WCS_Dynamic_Hook_Deprecator {

	// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned,WordPress.Arrays.MultipleStatementAlignment.LongIndexSpaceBeforeDoubleArrow
	/* The prefixes of hooks that have been deprecated, 'new_hook' => 'old_hook_prefix' */
	protected $deprecated_hook_prefixes = array(
		'woocommerce_admin_changed_subscription_to_'               => 'admin_changed_subscription_to_',
		'woocommerce_scheduled_subscription_payment_'              => 'scheduled_subscription_payment_',
		'woocommerce_customer_changed_subscription_to_'            => 'customer_changed_subscription_to_',
		'woocommerce_subscription_payment_method_updated_to_'      => 'woocommerce_subscriptions_updated_recurring_payment_method_to_',
		'woocommerce_subscription_payment_method_updated_from_'    => 'woocommerce_subscriptions_updated_recurring_payment_method_from_',
		'woocommerce_subscription_failing_payment_method_updated_' => 'woocommerce_subscriptions_changed_failing_payment_method_',

		// Gateway status change hooks
		'woocommerce_subscription_activated_'                      => array(
			'activated_subscription_',
			'reactivated_subscription_',
		),
		'woocommerce_subscription_on-hold_'                        => 'subscription_put_on-hold_',
		'woocommerce_subscription_cancelled_'                      => 'cancelled_subscription_',
		'woocommerce_subscription_expired_'                        => 'subscription_expired_',
	);
	// phpcs:enable

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * We need to use the special 'all' hook here because we don't actually know the full hook names
	 * in advance, just their prefix.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Display a notice if functions are hooked to the old filter and apply the old filters args
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function trigger_hook( $old_hook, $new_callback_args ) {

		if ( 0 === strpos( $old_hook, 'admin_changed_subscription_to_' ) ) {

			// New arg spec: $subscription_id
			// Old arg spec: $subscription_key
			$subscription = wcs_get_subscription( $new_callback_args[0] );
			do_action( $old_hook, wcs_get_old_subscription_key( $subscription ) );

		} elseif ( 0 === strpos( $old_hook, 'scheduled_subscription_payment_' ) ) {

			// New arg spec: $amount, $renewal_order
			// Old arg spec: $amount, $original_order, $product_id
			$subscription  = $new_callback_args[0];
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $new_callback_args[1] );

			if ( ! empty( $subscriptions ) ) {
				$subscription = array_pop( $subscriptions );
				do_action( $old_hook, $new_callback_args[0], self::get_order( $subscription ), self::get_product_id( $subscription ) );
			}
		} elseif ( 0 === strpos( $old_hook, 'activated_subscription_' ) || 0 === strpos( $old_hook, 'reactivated_subscription_' ) || 0 === strpos( $old_hook, 'subscription_put_on-hold_' ) || 0 === strpos( $old_hook, 'cancelled_subscription_' ) || 0 === strpos( $old_hook, 'subscription_expired_' ) ) {

			// New arg spec: $subscription
			// Old arg spec: $order, $product_id
			$subscription = $new_callback_args[0];
			do_action( $old_hook, self::get_order( $subscription ), self::get_product_id( $subscription ) );

		} elseif ( 0 === strpos( $old_hook, 'customer_changed_subscription_to_' ) ) {

			// New arg spec: $subscription
			// Old arg spec: $subscription_key
			do_action( $old_hook, wcs_get_old_subscription_key( $new_callback_args[0] ) );

		} elseif ( 0 === strpos( $old_hook, 'woocommerce_subscriptions_updated_recurring_payment_method_to_' ) ) {

			// New arg spec: $subscription, $old_payment_method
			// Old arg spec: $order, $subscription_key, $old_payment_method
			$subscription       = $new_callback_args[0];
			$old_payment_method = $new_callback_args[2];
			do_action( $old_hook, self::get_order( $subscription ), wcs_get_old_subscription_key( $subscription ), $old_payment_method );

		} elseif ( 0 === strpos( $old_hook, 'woocommerce_subscriptions_updated_recurring_payment_method_from_' ) ) {

			// New arg spec: $subscription, $new_payment_method
			// Old arg spec: $order, $subscription_key, $new_payment_method
			$subscription       = $new_callback_args[0];
			$new_payment_method = $new_callback_args[1];
			do_action( $old_hook, self::get_order( $subscription ), wcs_get_old_subscription_key( $subscription ), $new_payment_method );

		} elseif ( 0 === strpos( $old_hook, 'woocommerce_subscriptions_changed_failing_payment_method_' ) ) {

			// New arg spec: $subscription, $renewal_order
			// Old arg spec: $original_order, $renewal_order, $subscription_key
			$subscription  = $new_callback_args[0];
			do_action( $old_hook, self::get_order( $subscription ), $new_callback_args[1], wcs_get_old_subscription_key( $subscription ) );

		}
	}
}
