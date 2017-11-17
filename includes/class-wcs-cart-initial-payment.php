<?php
/**
 * Handles the initial payment for a pending subscription via the cart.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WCS_Cart_Initial_Payment
 * @category	Class
 * @author		Prospress
 * @since		2.0
 */

class WCS_Cart_Initial_Payment extends WCS_Cart_Renewal {

	/* The flag used to indicate if a cart item is for a initial payment */
	public $cart_item_key = 'subscription_initial_payment';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public function __construct() {

		$this->setup_hooks();
	}

	/**
	 * Setup the cart for paying for a delayed initial payment for a subscription.
	 *
	 * @since 2.0
	 */
	public function maybe_setup_cart() {
		global $wp;

		if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) && isset( $wp->query_vars['order-pay'] ) ) {

			// Pay for existing order
			$order_key    = $_GET['key'];
			$order_id     = ( isset( $wp->query_vars['order-pay'] ) ) ? $wp->query_vars['order-pay'] : absint( $_GET['order_id'] );
			$order        = wc_get_order( $wp->query_vars['order-pay'] );

			if ( wcs_get_objects_property( $order, 'order_key' ) == $order_key && $order->has_status( array( 'pending', 'failed' ) ) && wcs_order_contains_subscription( $order, 'parent' ) && ! wcs_order_contains_subscription( $order, 'resubscribe' ) ) {

				if ( ! is_user_logged_in() ) {

					$redirect = add_query_arg( array(
						'wcs_redirect'    => 'pay_for_order',
						'wcs_redirect_id' => $order_id,
					), get_permalink( wc_get_page_id( 'myaccount' ) ) );

					wp_safe_redirect( $redirect );
					exit;

				} elseif ( ! current_user_can( 'pay_for_order', $order_id ) ) {

					wc_add_notice( __( 'That doesn\'t appear to be your order.', 'woocommerce-subscriptions' ), 'error' );

					wp_safe_redirect( get_permalink( wc_get_page_id( 'myaccount' ) ) );
					exit;

				} else {

					// Setup cart with all the original order's line items
					$this->setup_cart( $order, array(
						'order_id' => $order_id,
					) );

					WC()->session->set( 'order_awaiting_payment', $order_id );

					// Set cart hash for orders paid in WC >= 2.6
					$this->set_cart_hash( $order_id );

					wp_safe_redirect( wc_get_checkout_url() );
					exit;
				}
			}
		}
	}

	/**
	 * Checks the cart to see if it contains an initial payment item.
	 *
	 * @return bool | Array The cart item containing the initial payment, else false.
	 * @since  2.0.13
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
	 * @since  2.0.13
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

}
new WCS_Cart_Initial_Payment();
