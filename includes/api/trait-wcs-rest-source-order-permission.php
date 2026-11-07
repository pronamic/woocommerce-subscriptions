<?php
/**
 * Shared source-order authorization for the subscription REST controllers.
 *
 * @package WooCommerce Subscriptions
 * @since   9.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authorizes the source order behind the create-subscriptions-from-order routes.
 *
 * Shared by the v2 and v3 subscription REST controllers, which extend different WC order controller
 * bases and so have no common subscription ancestor to host this. Kept out of the global function
 * namespace deliberately - it is an internal detail of these controllers, not a store-wide API.
 *
 * @since 9.4.0
 */
trait WCS_REST_Source_Order_Permission {

	/**
	 * Checks whether the request may create subscriptions from the order it names.
	 *
	 * The standard create check only covers the subscriptions being created. It says nothing about the
	 * order they are created from, which this route reads the customer, addresses, payment method, meta
	 * and line items out of, and then relates the new subscriptions back to. So the caller must also hold
	 * edit permission on that order. The 'read' context used by the sibling GET handlers would not do here,
	 * because it resolves to read_private_shop_orders, a capability that ignores the order ID.
	 *
	 * A request naming an order that does not exist is left to the callback, which answers it with a 404.
	 *
	 * @since 9.4.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function create_subscriptions_from_order_permissions_check( $request ) {
		$permission = $this->create_item_permissions_check( $request );

		if ( true !== $permission ) {
			return $permission;
		}

		$order = wc_get_order( absint( $request['id'] ) );

		if ( $order && $order->get_id() && ! wc_rest_check_post_permissions( 'shop_order', 'edit', $order->get_id() ) ) {
			return new WP_Error(
				'woocommerce_rest_cannot_edit',
				__( 'Sorry, you are not allowed to create subscriptions from this order.', 'woocommerce-subscriptions' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}
}
