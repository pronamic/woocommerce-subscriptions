<?php
/**
 * Handle deprecated actions.
 *
 * When triggering an action which has a deprecated equivalient from Subscriptions v1.n, check if the old
 * action had any callbacks attached to it, and if so, log a notice and trigger the old action with a set
 * of parameters in the deprecated format.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Hook_Deprecator
 * @category   Class
 * @author     Prospress
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

class WCS_Action_Deprecator extends WCS_Hook_Deprecator {

	// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned,WordPress.Arrays.MultipleStatementAlignment.LongIndexSpaceBeforeDoubleArrow
	/* The actions that have been deprecated, 'new_hook' => 'old_hook' */
	protected $deprecated_hooks = array(
		'woocommerce_scheduled_subscription_payment'                       => 'scheduled_subscription_payment',
		'woocommerce_subscription_payment_complete'                        => 'processed_subscription_payment',
		'woocommerce_subscription_renewal_payment_complete'                => 'processed_subscription_renewal_payment',
		'woocommerce_subscriptions_paid_for_failed_renewal_order'          => 'woocommerce_subscriptions_processed_failed_renewal_order_payment',
		'woocommerce_subscriptions_pre_update_payment_method'              => 'woocommerce_subscriptions_pre_update_recurring_payment_method',
		'woocommerce_subscription_payment_method_updated'                  => 'woocommerce_subscriptions_updated_recurring_payment_method',
		'woocommerce_subscription_failing_payment_method_updated'          => 'woocommerce_subscriptions_changed_failing_payment_method',
		'woocommerce_subscription_payment_failed'                          => 'processed_subscription_payment_failure',
		'woocommerce_subscription_change_payment_method_via_pay_shortcode' => 'woocommerce_subscriptions_change_payment_method_via_pay_shortcode',
		'subscriptions_put_on_hold_for_order'                              => 'subscriptions_suspended_for_order',
		'woocommerce_subscription_status_active'                           => 'activated_subscription',
		'woocommerce_subscription_status_on-hold'                          => array( 'suspended_subscription', 'subscription_put_on-hold' ),
		'woocommerce_subscription_status_cancelled'                        => 'cancelled_subscription',
		'woocommerce_subscription_status_on-hold_to_active'                => 'reactivated_subscription',
		'woocommerce_subscription_status_expired'                          => 'subscription_expired',
		'woocommerce_scheduled_subscription_trial_end'                     => 'subscription_trial_end',
		'woocommerce_scheduled_subscription_end_of_prepaid_term'           => 'subscription_end_of_prepaid_term',
	);
	// phpcs:enable

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Trigger the old action with the original callback parameters
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	protected function trigger_hook( $old_hook, $new_callback_args ) {

		switch ( $old_hook ) {

			// New arg spec: $subscription_id
			// Old arg spec: $user_id, $subscription_key
			case 'scheduled_subscription_payment':
			case 'subscription_end_of_prepaid_term':
			case 'subscription_trial_end':
				$subscription = wcs_get_subscription( $new_callback_args[0] );
				do_action( $old_hook, $subscription->get_user_id(), wcs_get_old_subscription_key( $subscription ) );
				break;

			// New arg spec: $subscription
			// Old arg spec: $user_id, $subscription_key
			case 'processed_subscription_payment':
			case 'processed_subscription_renewal_payment':
			case 'processed_subscription_payment_failure':
				$subscription = $new_callback_args[0];
				do_action( $old_hook, $subscription->get_user_id(), wcs_get_old_subscription_key( $subscription ) );
				break;

			// New arg spec: $renewal_order, $subscription
			// Old arg spec: $subscription_key, $original_order
			case 'woocommerce_subscriptions_processed_failed_renewal_order_payment':
				$renewal_order = $new_callback_args[0];
				$subscription  = $new_callback_args[1];
				do_action( $old_hook, wcs_get_old_subscription_key( $subscription ), self::get_order( $subscription ) );
				break;

			// New arg spec: $subscription, $new_payment_method, $old_payment_method
			// Old arg spec: $order, $subscription_key, $new_payment_method, $old_payment_method
			case 'woocommerce_subscriptions_pre_update_recurring_payment_method':
			case 'woocommerce_subscriptions_updated_recurring_payment_method':
				$subscription       = $new_callback_args[0];
				$new_payment_method = $new_callback_args[1];
				$old_payment_method = $new_callback_args[2];
				do_action( $old_hook, self::get_order( $subscription ), wcs_get_old_subscription_key( $subscription ), $new_payment_method, $old_payment_method );
				break;

			// New arg spec: $subscription, $renewal_order
			// Old arg spec: $original_order, $renewal_order, $subscription_key
			case 'woocommerce_subscriptions_changed_failing_payment_method':
				$subscription  = $new_callback_args[0];
				$renewal_order = $new_callback_args[1];
				do_action( $old_hook, self::get_order( $subscription ), $renewal_order, wcs_get_old_subscription_key( $subscription ) );
				break;

			// New arg spec: $order
			// Old arg spec: $order
			case 'subscriptions_suspended_for_order':
				do_action( $old_hook, $new_callback_args[0] );
				break;

			// New arg spec: $subscription
			// Old arg spec: $subscription_key, $order
			case 'woocommerce_subscriptions_change_payment_method_via_pay_shortcode':
				$subscription = $new_callback_args[0];
				do_action( $old_hook, wcs_get_old_subscription_key( $subscription ), self::get_order( $subscription ) );
				break;

			// New arg spec: $subscription
			// Old arg spec: $user_id, $subscription_key
			case 'activated_subscription':
			case 'subscription_put_on-hold':
			case 'suspended_subscription':
			case 'cancelled_subscription':
			case 'reactivated_subscription':
			case 'subscription_expired':
				$subscription  = $new_callback_args[0];
				do_action( $old_hook, $subscription->get_user_id(), wcs_get_old_subscription_key( $subscription ) );
				break;
		}
	}
}
