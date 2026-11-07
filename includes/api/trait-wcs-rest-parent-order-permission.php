<?php
/**
 * Shared parent-order authorization for the subscription REST controllers.
 *
 * @package WooCommerce Subscriptions
 * @since   9.4.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authorizes the parent order a REST request links a subscription to, or unlinks it from.
 *
 * Shared by the v1, v2 and v3 subscription REST controllers, which extend different WC order controller bases.
 * WooCommerce's batch endpoint runs these checks for each item, so batch requests are covered too. A controller
 * that uses this trait must not define these checks itself, because a class method replaces a trait method.
 *
 * @since 9.4.0
 */
trait WCS_REST_Parent_Order_Permission {

	/**
	 * Checks whether the request may create a subscription, including with the parent order it names.
	 *
	 * @since 9.4.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		$permission = parent::create_item_permissions_check( $request );

		if ( true !== $permission || null === $request['parent_id'] ) {
			return $permission;
		}

		return $this->check_parent_order_permission( $request, 0 );
	}

	/**
	 * Checks whether the request may update a subscription, including a change to its parent order.
	 *
	 * A request naming a subscription that does not exist is left to the callback.
	 *
	 * @since 9.4.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool|WP_Error True if the request has access, WP_Error otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$permission = parent::update_item_permissions_check( $request );

		if ( true !== $permission || null === $request['parent_id'] ) {
			return $permission;
		}

		$subscription = wcs_get_subscription( absint( $request['id'] ) );

		if ( ! $subscription ) {
			return true;
		}

		return $this->check_parent_order_permission( $request, $subscription->get_parent_id( 'edit' ) );
	}

	/**
	 * Requires edit permission on the current and the new parent order when the request changes the parent.
	 *
	 * The new parent must be an existing order. Otherwise a caller could link to the ID a future order will get,
	 * and no check would ever run on that order. As in WooCommerce's order routes, only a caller who may read every
	 * order is told that an ID is not an order; anyone else gets the same refusal as for an order they cannot edit.
	 *
	 * A current parent that no longer exists is skipped, because there is nothing left to protect and WordPress
	 * refuses every capability on a missing object.
	 *
	 * @param WP_REST_Request $request           Full details about the request.
	 * @param int             $current_parent_id The subscription's parent order ID before the request.
	 * @return bool|WP_Error True if the request has access, WP_Error otherwise.
	 */
	private function check_parent_order_permission( $request, $current_parent_id ) {
		$new_parent_id = absint( $request['parent_id'] );

		if ( $new_parent_id === $current_parent_id ) {
			return true;
		}

		$new_parent = $new_parent_id ? wc_get_order( $new_parent_id ) : false;

		if ( $new_parent_id && ! wcs_is_order( $new_parent ) ) {
			if ( ! wc_rest_check_post_permissions( 'shop_order', 'read' ) ) {
				return $this->get_parent_order_permission_error();
			}

			return new WP_Error( 'woocommerce_rest_invalid_parent_id', __( 'Invalid parent order ID.', 'woocommerce-subscriptions' ), array( 'status' => 400 ) );
		}

		$current_parent = $current_parent_id ? wc_get_order( $current_parent_id ) : false;

		foreach ( array( $current_parent, $new_parent ) as $order ) {
			if ( $order && ! wc_rest_check_post_permissions( 'shop_order', 'edit', $order->get_id() ) ) {
				return $this->get_parent_order_permission_error();
			}
		}

		return true;
	}

	/**
	 * The error for a request that may not change a subscription's parent order.
	 *
	 * @return WP_Error
	 */
	private function get_parent_order_permission_error() {
		return new WP_Error(
			'woocommerce_rest_cannot_edit',
			__( "Sorry, you are not allowed to change this subscription's parent order.", 'woocommerce-subscriptions' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
