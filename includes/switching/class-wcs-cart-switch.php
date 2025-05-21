<?php
/**
 * Subscriptions switching cart
 *
 * @author   Prospress
 * @since    2.1
 */
class WCS_Cart_Switch extends WCS_Cart_Renewal {

	/* The flag used to indicate if a cart item is a subscription switch */
	public $cart_item_key = 'subscription_switch';

	/**
	 * Initialise class hooks & filters when the file is loaded
	 *
	 * @since 2.1
	 */
	public function __construct() {

		// Attach hooks which depend on WooCommerce constants
		add_action( 'woocommerce_loaded', array( &$this, 'attach_dependant_hooks' ), 10 );

		// Set checkout payment URL parameter for subscription switch orders
		add_filter( 'woocommerce_get_checkout_payment_url', array( &$this, 'get_checkout_payment_url' ), 10, 2 );

		// Check if a user is requesting to pay for a switch order, needs to happen after $wp->query_vars are set
		add_action( 'template_redirect', array( &$this, 'maybe_setup_cart' ), 99 );

		// Filters the Place order button text
		add_filter( 'woocommerce_order_button_text', array( $this, 'order_button_text' ), 15 );
	}

	/**
	 * Attach WooCommerce version dependent hooks
	 *
	 * @since 2.2.0
	 */
	public function attach_dependant_hooks() {
		parent::attach_dependant_hooks();

		// Remove version dependent callbacks which don't apply to switch carts
		remove_filter( 'woocommerce_checkout_update_customer_data', array( &$this, 'maybe_update_subscription_customer_data' ) );
		remove_filter( 'woocommerce_checkout_update_user_meta', array( &$this, 'maybe_update_subscription_address_data' ) );
		remove_filter( 'woocommerce_store_api_checkout_update_customer_from_request', array( &$this, 'maybe_update_subscription_address_data_from_store_api' ) );
	}

	/**
	 * Add flag to payment url for failed/ pending switch orders.
	 *
	 * @since 2.1
	 */
	public function get_checkout_payment_url( $pay_url, $order ) {

		if ( wcs_order_contains_switch( $order ) ) {
			$switch_order_data = wcs_get_objects_property( $order, 'subscription_switch_data' );

			if ( ! empty( $switch_order_data ) ) {
				$pay_url = add_query_arg(
					array(
						'subscription_switch' => 'true',
						'_wcsnonce'           => wp_create_nonce( 'wcs_switch_request' ),
					),
					$pay_url
				);
			}
		}

		return $pay_url; // nosemgrep: audit.php.wp.security.xss.query-arg -- False positive. $pay_url is escaped in the template and escaping URLs should be done at the point of output or usage.
	}

	/**
	 * Check if a payment is being made on a switch order from 'My Account'. If so,
	 * reconstruct the cart with the order contents. If the order item is part of a switch, load the necessary data
	 * into $_GET and $_POST to ensure the switch validation occurs and the switch cart item meta is correctly loaded.
	 *
	 * @since 2.1
	 */
	public function maybe_setup_cart() {
		global $wp;

		if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) && isset( $wp->query_vars['order-pay'] ) && isset( $_GET['subscription_switch'] ) ) {

			// Pay for existing order
			$order_key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			$order_id  = ( isset( $wp->query_vars['order-pay'] ) ) ? $wp->query_vars['order-pay'] : absint( $_GET['order_id'] );
			$order     = wc_get_order( $wp->query_vars['order-pay'] );

			if ( wcs_get_objects_property( $order, 'order_key' ) == $order_key && $order->has_status( array( 'pending', 'failed' ) ) && wcs_order_contains_switch( $order ) ) {
				WC()->cart->empty_cart( true );

				$switch_order_data = wcs_get_objects_property( $order, 'subscription_switch_data' );

				foreach ( $order->get_items() as $item_id => $line_item ) {

					// clear the GET args so we can add non-switch items to the cart cleanly
					unset( $_GET['switch-subscription'] );
					unset( $_GET['item'] );

					// check if this order item is for a switch
					foreach ( $switch_order_data as $subscription_id => $switch_data ) {

						if ( isset( $switch_data['switches'] ) && in_array( $item_id, array_keys( $switch_data['switches'] ) ) ) {

							$_GET['switch-subscription'] = $subscription_id;

							// Backwards compatibility (2.1 -> 2.1.2)
							$subscription_item_id_key = ( isset( $switch_data['switches'][ $item_id ]['subscription_item_id'] ) ) ? 'subscription_item_id' : 'remove_line_item';
							$_GET['item']             = $switch_data['switches'][ $item_id ][ $subscription_item_id_key ];
							break;
						}
					}

					$order_item = wcs_get_order_item( $item_id, $order );
					$product    = wc_get_product( wcs_get_canonical_product_id( $order_item ) );
					$product_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

					$order_product_data = array(
						'_qty'          => (int) $line_item['qty'],
						'_variation_id' => (int) $line_item['variation_id'],
					);

					$variations = array();

					foreach ( $order_item['item_meta'] as $meta_key => $meta_value ) {
						$meta_value = is_array( $meta_value ) ? $meta_value[0] : $meta_value; // In WC 3.0 the meta values are no longer arrays

						if ( taxonomy_is_product_attribute( $meta_key ) || meta_is_product_attribute( $meta_key, $meta_value, $product_id ) ) {
							$variations[ $meta_key ]           = $meta_value;
							$_POST[ 'attribute_' . $meta_key ] = $meta_value;
						}
					}

					$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $order_product_data['_qty'], $order_product_data['_variation_id'] );

					if ( $passed_validation ) {
						$cart_item_key = WC()->cart->add_to_cart( $product_id, $order_product_data['_qty'], $order_product_data['_variation_id'], $variations, array() );
					}
				}
			}

			$this->set_order_awaiting_payment( $order_id );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Store the order line item id so it can be retrieved when we're processing the switch on checkout.
	 *
	 * @param string $cart_item_key
	 * @param int $order_item_id
	 * @since 2.2.1
	 */
	protected function set_cart_item_order_item_id( $cart_item_key, $order_item_id ) {

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {

			// If this cart item belongs to this recurring cart
			if ( in_array( $cart_item_key, array_keys( $recurring_cart->cart_contents ) ) && isset( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ][ $this->cart_item_key ] ) ) {

				WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['order_line_item_id'] = $order_item_id;

				wc_add_order_item_meta( WC()->cart->recurring_carts[ $recurring_cart_key ]->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['item_id'], '_switched_subscription_new_item_id', $order_item_id, true );
			}
		}
	}

	/**
	 * Overrides the place order button text on the checkout when the cart contains only switch requests.
	 *
	 * @since 3.1.0
	 *
	 * @param string $place_order_text The place order button text.
	 * @return string The place order button text. 'Switch subscription' if the cart contains only switches, otherwise the default.
	 */
	public function order_button_text( $place_order_text ) {
		$cart_switches = WC_Subscriptions_Switcher::cart_contains_switches();

		if ( isset( WC()->cart ) && $cart_switches && count( $cart_switches ) === count( WC()->cart->get_cart() ) ) {
			$place_order_text = _x( 'Switch subscription', 'The place order button text while switching a subscription', 'woocommerce-subscriptions' );
		}

		return $place_order_text;
	}
}
