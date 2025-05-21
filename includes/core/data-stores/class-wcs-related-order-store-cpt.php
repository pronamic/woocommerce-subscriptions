<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related order data store for orders.
 *
 * Importantly, this class uses WC_Data API methods, like WC_Data::add_meta_data() and WC_Data::get_meta(), to manage the
 * relationships instead of add_post_meta() or get_post_meta(). This ensures that the relationship is stored, regardless
 * of the order data store being used.
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 */
class WCS_Related_Order_Store_CPT extends WCS_Related_Order_Store {

	/**
	 * Meta keys used to link an order with a subscription for each type of relationship.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 * @var array $meta_keys Relationship => Meta key
	 */
	private $meta_keys;

	/**
	 * Constructor: sets meta keys used for storing each order relation.
	 */
	public function __construct() {
		foreach ( $this->get_relation_types() as $relation_type ) {
			$this->meta_keys[ $relation_type ] = sprintf( '_subscription_%s', $relation_type );
		}
	}

	/**
	 * Find orders related to a given subscription in a given way.
	 *
	 * @param WC_Order $subscription  The ID of the subscription for which calling code wants the related orders.
	 * @param string   $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 *
	 * @return array
	 */
	public function get_related_order_ids( WC_Order $subscription, $relation_type ) {
		return wcs_get_orders_with_meta_query(
			[
				'limit'      => -1,
				'type'       => 'shop_order',
				'status'     => 'any',
				'return'     => 'ids',
				'orderby'    => 'ID',
				'order'      => 'DESC',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => $this->get_meta_key( $relation_type ),
						'compare' => '=',
						'value'   => $subscription->get_id(),
					],
				],
			]
		);
	}

	/**
	 * Find subscriptions related to a given order in a given way, if any.
	 *
	 * @param WC_Order $order         The ID of an order that may be linked with subscriptions.
	 * @param string   $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 *
	 * @return array
	 */
	public function get_related_subscription_ids( WC_Order $order, $relation_type ) {
		$related_order_meta_key = $this->get_meta_key( $relation_type );

		$related_subscription_ids = $order->get_meta( $related_order_meta_key, false );
		// Normalise the return value: WooCommerce returns a set of WC_Meta_Data objects, with values cast to strings, even if they're integers
		$related_subscription_ids = wp_list_pluck( $related_subscription_ids, 'value' );
		$related_subscription_ids = array_map( 'absint', $related_subscription_ids );
		$related_subscription_ids = array_values( $related_subscription_ids );

		return apply_filters( 'wcs_orders_related_subscription_ids', $related_subscription_ids, $order, $relation_type );
	}

	/**
	 * Helper function for linking an order to a subscription via a given relationship.
	 *
	 * Existing order relationships of the same type will not be overwritten. This only adds a relationship. To overwrite,
	 * you must also remove any existing relationship with @see $this->delete_relation().
	 *
	 * @param WC_Order $order         The order to link with the subscription.
	 * @param WC_Order $subscription  The order or subscription to link the order to.
	 * @param string   $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function add_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {
		// We can't rely on $subscription->get_id() being available here, because we only require a WC_Order, not a WC_Subscription, and WC_Order does not have get_id() available with WC < 3.0
		$subscription_id        = $subscription->get_id();
		$related_order_meta_key = $this->get_meta_key( $relation_type );

		// We want to allow more than one piece of meta per key on the order, but we don't want to duplicate the same meta key => value combination, so we need to check if it is set first
		$existing_relations   = $order->get_meta( $related_order_meta_key, false );
		$existing_related_ids = wp_list_pluck( $existing_relations, 'value' );
		$existing_related_ids = array_map( 'absint', $existing_related_ids );

		if ( empty( $existing_relations ) || ! in_array( $subscription_id, $existing_related_ids, true ) ) {
			$order->add_meta_data( $related_order_meta_key, $subscription_id, false );
			$order->save();
		}

		do_action( 'wcs_orders_add_relation', $order, $subscription, $relation_type );
	}

	/**
	 * Remove the relationship between a given order and subscription.
	 *
	 * This data store links the relationship for a renewal order and a subscription in meta data against the order.
	 *
	 * @param WC_Order $order         An order that may be linked with subscriptions.
	 * @param WC_Order $subscription  A subscription or order to unlink the order with, if a relation exists.
	 * @param string   $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {
		$related_order_meta_key = $this->get_meta_key( $relation_type );

		foreach ( $order->get_meta_data() as $meta ) {
			if ( $related_order_meta_key === $meta->key && $subscription->get_id() === (int) $meta->value ) {
				$order->delete_meta_data_by_mid( $meta->id );
			}
		}

		$order->save();

		do_action( 'wcs_orders_delete_relation', $order, $subscription, $relation_type );
	}

	/**
	 * Remove all related orders/subscriptions of a given type from an order.
	 *
	 * @param WC_Order $order         An order that may be linked with subscriptions.
	 * @param string   $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relations( WC_Order $order, $relation_type ) {
		$related_order_meta_key = $this->get_meta_key( $relation_type );
		$order->delete_meta_data( $related_order_meta_key );
		$order->save();
	}

	/**
	 * Get the meta keys used to link orders with subscriptions.
	 *
	 * @return array
	 */
	protected function get_meta_keys() {
		return $this->meta_keys;
	}

	/**
	 * Get the meta key used to link an order with a subscription based on the type of relationship.
	 *
	 * @param string $relation_type   The order's relationship with the subscription. Must be 'renewal', 'switch' or 'resubscribe'.
	 * @param string $prefix_meta_key Whether to add the underscore prefix to the meta key or not. 'prefix' to prefix the key. 'do_not_prefix' to not prefix the key.
	 *
	 * @return string
	 */
	protected function get_meta_key( $relation_type, $prefix_meta_key = 'prefix' ) {

		$this->check_relation_type( $relation_type );

		$meta_key = $this->meta_keys[ $relation_type ];

		if ( 'do_not_prefix' === $prefix_meta_key ) {
			$meta_key = wcs_maybe_unprefix_key( $meta_key );
		}

		return $meta_key;
	}
}
