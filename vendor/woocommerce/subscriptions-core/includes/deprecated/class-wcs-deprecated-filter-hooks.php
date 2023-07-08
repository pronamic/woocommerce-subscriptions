<?php
/**
 * Handle WC3.0 deprecated filters.
 *
 * When triggering a new WC 3.0+ filter which has a deprecated equivalent from WooCommerce 2.6.x, check if the old
 * filter had any callbacks attached to it, and if so, log a notice and trigger the old action.
 * This class extends @see WC_Deprecated_Filter_Hooks which handles most of the attaching and triggering of old hooks.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Prospress
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles deprecation notices and triggering of legacy filter hooks when WC 3.0+ subscription filters are triggered.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 */
class WCS_Deprecated_Filter_Hooks extends WC_Deprecated_Filter_Hooks {

	/**
	 * Array of deprecated hooks we need to handle in the format array( new_hook => old_hook )
	 *
	 * @var array
	 */
	protected $deprecated_hooks = array(
		'woocommerce_subscription_get_currency'       => 'woocommerce_get_currency',
		'woocommerce_subscription_get_discount_total' => 'woocommerce_order_amount_discount_total',
		'woocommerce_subscription_get_discount_tax'   => 'woocommerce_order_amount_discount_tax',
		'woocommerce_subscription_get_shipping_total' => 'woocommerce_order_amount_shipping_total',
		'woocommerce_subscription_get_shipping_tax'   => 'woocommerce_order_amount_shipping_tax',
		'woocommerce_subscription_get_cart_tax'       => 'woocommerce_order_amount_cart_tax',
		'woocommerce_subscription_get_total'          => 'woocommerce_order_amount_total',
		'woocommerce_subscription_get_total_tax'      => 'woocommerce_order_amount_total_tax',
		'woocommerce_subscription_get_total_discount' => 'woocommerce_order_amount_total_discount',
		'woocommerce_subscription_get_subtotal'       => 'woocommerce_order_amount_subtotal',
		'woocommerce_subscription_get_tax_totals'     => 'woocommerce_order_tax_totals',
	);

	/**
	 * Display a deprecated notice for old hooks.
	 *
	 * @param string $old_hook
	 * @param string $new_hook
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	protected function display_notice( $old_hook, $new_hook ) {
		wcs_deprecated_function( sprintf( 'The "%s" hook uses out of date data structures and', esc_html( $old_hook ) ), '2.2.0', esc_html( $new_hook ) . ' to filter subscription properties' );
	}
}
