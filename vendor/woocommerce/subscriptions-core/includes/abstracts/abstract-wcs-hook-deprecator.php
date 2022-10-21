<?php
/**
 * Provide shared utilities for deprecating actions and filters.
 *
 * Because Subscriptions v2.0 changed the way subscription data is stored and accessed, it needed
 * to deprecate a number of hooks which passed callbacks deprecated data structions, like the old
 * subscription array instead of a WC_Subscription object.
 *
 * This is the base class for handling those deprecated hooks.
 *
 * @package WooCommerce Subscriptions
 * @subpackage WCS_Hook_Deprecator
 * @category Class
 * @author Prospress
 * @since 2.0
 */

abstract class WCS_Hook_Deprecator {

	/* The hooks that have been deprecated, 'new_hook' => 'old_hook' */
	protected $deprecated_hooks = array();

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public function __construct() {
		foreach ( $this->deprecated_hooks as $new_hook => $old_hook ) {
			add_filter( $new_hook, array( &$this, 'maybe_handle_deprecated_hook' ), -1000, 8 );
		}
	}

	/**
	 * Check if an old hook still has callbacks attached to it, and if so, display a notice and trigger the old hook.
	 *
	 * @since 2.0
	 */
	public function maybe_handle_deprecated_hook() {

		$new_hook  = current_filter();
		$old_hooks = ( isset( $this->deprecated_hooks[ $new_hook ] ) ) ? $this->deprecated_hooks[ $new_hook ] : '';

		$new_callback_args = func_get_args();
		$return_value      = $new_callback_args[0];

		if ( ! empty( $old_hooks ) ) {

			if ( is_array( $old_hooks ) ) {
				foreach ( $old_hooks as $old_hook ) {
					$return_value = $this->handle_deprecated_hook( $new_hook, $old_hook, $new_callback_args, $return_value );
				}
			} else {
				$return_value = $this->handle_deprecated_hook( $new_hook, $old_hooks, $new_callback_args, $return_value );
			}
		}

		return $return_value;
	}

	/**
	 * Check if an old hook still has callbacks attached to it, and if so, display a notice and trigger the old hook.
	 *
	 * @since 2.0
	 */
	protected function handle_deprecated_hook( $new_hook, $old_hook, $new_callback_args, $return_value ) {

		if ( has_filter( $old_hook ) ) {

			$this->display_notice( $old_hook, $new_hook );

			$return_value = $this->trigger_hook( $old_hook, $new_callback_args );
		}

		return $return_value;
	}

	/**
	 * Display a deprecated notice for old hooks.
	 *
	 * @since 2.0
	 */
	protected static function display_notice( $old_hook, $new_hook ) {
		_deprecated_function( sprintf( 'The "%s" hook uses out of date data structures so', esc_html( $old_hook ) ), '2.0 of WooCommerce Subscriptions', esc_html( $new_hook ) );
	}

	/**
	 * Trigger the old hook with the original callback parameters
	 *
	 * @since 2.0
	 */
	abstract protected function trigger_hook( $old_hook, $new_callback_args );

	/**
	 * Get the order for a subscription to pass to callbacks.
	 *
	 * Because a subscription can exist without an order in Subscriptions 2.0, the order might actually
	 * fallback to being the subscription rather than the order used to purchase the subscription.
	 *
	 * @since 2.0
	 */
	protected static function get_order( $subscription ) {
		return ( false == $subscription->get_parent_id() ) ? $subscription : $subscription->get_parent();
	}

	/**
	 * Get the order ID for a subscription to pass to callbacks.
	 *
	 * Because a subscription can exist without an order in Subscriptions 2.0, the order might actually
	 * fallback to being the subscription rather than the order used to purchase the subscription.
	 *
	 * @since 2.0
	 */
	protected static function get_order_id( $subscription ) {
		return ( false == $subscription->get_parent_id() ) ? $subscription->get_id() : $subscription->get_parent_id();
	}

	/**
	 * Get the first product ID for a subscription to pass to callbacks.
	 *
	 * @since 2.0
	 */
	protected static function get_product_id( $subscription ) {
		$order_items = $subscription->get_items();
		$product_id  = ( empty( $order_items ) ) ? 0 : WC_Subscriptions_Order::get_items_product_id( reset( $order_items ) );
		return $product_id;
	}
}
