<?php
/**
 * A Initial Cart Stock Manager class.
 *
 * Contains functions which assists in overriding WC core functionality to allow initial carts to bypass stock validation.
 * Only initial/parent order carts which have already handled stock need to be handled by this class.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   Automattic
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v3.0.6
 */

defined( 'ABSPATH' ) || exit;

class WCS_Initial_Cart_Stock_Manager extends WCS_Renewal_Cart_Stock_Manager {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.6
	 */
	public static function attach_callbacks() {
		parent::attach_callbacks();

		// The parent class attaches a filter not needed for initial carts. So we remove it and attach the parent order equivalent.
		remove_action( 'wcs_before_renewal_setup_cart_subscription', 'WCS_Renewal_Cart_Stock_Manager::maybe_adjust_stock_cart', 10 );
		add_action( 'wcs_before_parent_order_setup_cart', array( get_called_class(), 'maybe_adjust_stock_cart' ), 10, 2 );
	}

	/**
	 * Gets the parent order from the cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.6
	 * @return WC_Order|bool Parent order obtained from the cart contents or false if the cart doesn't contain a parent order which has handled stock.
	 */
	protected static function get_order_from_cart() {
		$parent_order = false;

		if ( ! empty( WC()->cart->cart_contents ) ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item['subscription_initial_payment'] ) ) {
					$order = wc_get_order( $cart_item['subscription_initial_payment']['order_id'] );

					if ( static::has_handled_stock( $order ) ) {
						$parent_order = $order;
						break;
					}
				}
			}
		}

		return $parent_order;
	}

	/**
	 * Gets the parent order from order-pay query vars.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.6
	 * @return WC_Order|bool Parent order obtained from query vars or false if not set or if no handling is required.
	 */
	protected static function get_order_from_query_vars() {
		global $wp;
		$parent_order = false;

		if ( isset( $wp->query_vars['order-pay'] ) ) {
			$order = wc_get_order( $wp->query_vars['order-pay'] );

			if ( $order && static::has_handled_stock( $order ) && wcs_order_contains_subscription( $order, 'parent' ) ) {
				$parent_order = $order;
			}
		}

		return $parent_order;
	}

	/**
	 * Checks if an order has already reduced stock.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.6
	 * @param WC_Order $order
	 * @return bool Whether the order has reduced stock.
	 */
	protected static function has_handled_stock( $order ) {
		return (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );
	}
}
