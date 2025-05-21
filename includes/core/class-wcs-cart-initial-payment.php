<?php
/**
 * Handles the initial payment for a pending subscription via the cart.
 *
 * @package WooCommerce Subscriptions
 * @subpackage WCS_Cart_Initial_Payment
 * @category Class
 * @author Prospress
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

class WCS_Cart_Initial_Payment extends WCS_Cart_Renewal {

	/* The flag used to indicate if a cart item is for a initial payment */
	public $cart_item_key = 'subscription_initial_payment';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct() {
		$this->setup_hooks();

		// When an order is paid for via checkout, ensure a new order isn't created due to mismatched cart hashes
		add_filter( 'woocommerce_create_order', array( &$this, 'update_cart_hash' ), 10, 1 );
		// Apply initial discounts when there is a pending initial order
		add_action( 'woocommerce_setup_cart_for_subscription_initial_payment', array( $this, 'setup_discounts' ) );

		// Initialise the stock manager.
		WCS_Initial_Cart_Stock_Manager::attach_callbacks();
	}

	/**
	 * Setup the cart for paying for a delayed initial payment for a subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function maybe_setup_cart() {
		global $wp;

		if ( ! isset( $_GET['pay_for_order'] ) || ! isset( $_GET['key'] ) || ! isset( $wp->query_vars['order-pay'] ) ) {
			return;
		}

		// Pay for existing order
		$order_key = $_GET['key'];
		$order_id  = absint( $wp->query_vars['order-pay'] );
		$order     = wc_get_order( $order_id );

		if ( wcs_get_objects_property( $order, 'order_key' ) !== $order_key || ! $order->has_status( array( 'pending', 'failed' ) ) || ! wcs_order_contains_subscription( $order, 'parent' ) || wcs_order_contains_subscription( $order, 'resubscribe' ) ) {
			return;
		}

		/**
		 * Filter whether to set up the cart during the pay-for-order payment flow.
		 *
		 * Allows developers to bypass cart setup for the pay-for-order payment flow.
		 * This is intended for situations in which re-creating the cart will result in
		 * the loss of order data.
		 *
		 * @since 6.2.0
		 *
		 * @param bool     $recreate_cart Whether to recreate the initial payment order. Default true.
		 * @param WC_Order $order         The order object.
		 * @param string   $order_key     The order key.
		 * @param int      $order_id      The order ID.
		 */
		$recreate_cart = apply_filters( "wcs_setup_cart_for_{$this->cart_item_key}", true, $order, $order_key, $order_id );

		if ( ! $recreate_cart ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			// Allow the customer to login first and then redirect them back.
			$redirect = add_query_arg(
				array(
					'wcs_redirect'    => 'pay_for_order',
					'wcs_redirect_id' => $order_id,
				),
				get_permalink( wc_get_page_id( 'myaccount' ) )
			);
		} elseif ( ! current_user_can( 'pay_for_order', $order_id ) ) {
			wc_add_notice( __( 'That doesn\'t appear to be your order.', 'woocommerce-subscriptions' ), 'error' );

			$redirect = get_permalink( wc_get_page_id( 'myaccount' ) );
		} else {
			$subscriptions = wcs_get_subscriptions_for_order( $order );
			do_action( 'wcs_before_parent_order_setup_cart', $subscriptions, $order );

			// Add the existing order items to the cart
			$this->setup_cart(
				$order,
				array(
					'order_id' => $order_id,
				)
			);

			do_action( 'wcs_after_parent_order_setup_cart', $subscriptions, $order );

			// Store order's ID in the session so it can be re-used after payment
			$this->set_order_awaiting_payment( $order_id );
			$redirect = wc_get_checkout_url();
		}

		if ( ! empty( $redirect ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Checks the cart to see if it contains an initial payment item.
	 *
	 * @return bool | Array The cart item containing the initial payment, else false.
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	protected function cart_contains() {

		$contains_initial_payment = false;

		if ( ! empty( WC()->cart->cart_contents ) ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( isset( $cart_item[ $this->cart_item_key ] ) ) {
					$contains_initial_payment = $cart_item;
					break;
				}
			}
		}

		return apply_filters( 'wcs_cart_contains_initial_payment', $contains_initial_payment );
	}

	/**
	 * Get the order object used to construct the initial payment cart.
	 *
	 * @param Array The initial payment cart item.
	 * @return WC_Order | The order object
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	protected function get_order( $cart_item = '' ) {
		$order = false;

		if ( empty( $cart_item ) ) {
			$cart_item = $this->cart_contains();
		}

		if ( false !== $cart_item && isset( $cart_item[ $this->cart_item_key ] ) ) {
			$order = wc_get_order( $cart_item[ $this->cart_item_key ]['order_id'] );
		}

		return $order;
	}

	/**
	 * Determines if the cart should honor the grandfathered subscription/order line item total.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.10
	 *
	 * @param array $cart_item The cart item to check.
	 * @return bool Whether the cart should honor the order's prices.
	 */
	public function should_honor_subscription_prices( $cart_item ) {
		$order = $this->get_order( $cart_item );
		return $order && $order->meta_exists( '_manual_price_increases_locked' );
	}
}
