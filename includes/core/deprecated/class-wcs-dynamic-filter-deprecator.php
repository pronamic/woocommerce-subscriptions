<?php
/**
 * Deprecate filters that use a dynamic hook by appending a variable, like a payment gateway's name.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Hook_Deprecator
 * @category   Class
 * @author     Prospress
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

class WCS_Dynamic_Filter_Deprecator extends WCS_Dynamic_Hook_Deprecator {

	/* The prefixes of hooks that have been deprecated, 'new_hook' => 'old_hook_prefix' */
	protected $deprecated_hook_prefixes = array(
		'woocommerce_can_subscription_be_updated_to_' => 'woocommerce_subscription_can_be_changed_to_',
	);

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

		// Return value is always the first param
		$return_value = $new_callback_args[0];

		if ( 0 === strpos( $old_hook, 'woocommerce_subscription_can_be_changed_to_' ) ) {
			// New arg spec: $can_be_updated, $subscription
			// Old arg spec: $can_be_changed, $subscription, $order
			$subscription = $new_callback_args[1];
			$return_value = apply_filters( $old_hook, $return_value, wcs_get_subscription_in_deprecated_structure( $subscription ), self::get_order( $subscription ) );
		}

		return $return_value;
	}
}
