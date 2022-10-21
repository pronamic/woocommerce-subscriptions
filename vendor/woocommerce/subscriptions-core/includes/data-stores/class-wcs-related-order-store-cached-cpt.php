<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related order data store for orders and subscriptions stored in Custom Post Types, with caching.
 *
 * Adds a persistent caching layer on top of WCS_Related_Order_Store_CPT for more
 * performant queries on related orders.
 *
 * @version  2.3.0
 * @category Class
 * @author   Prospress
 */
class WCS_Related_Order_Store_Cached_CPT extends WCS_Related_Order_Store_CPT implements WCS_Cache_Updater {

	/**
	 * Keep cache up-to-date with changes to our meta data via WordPress post meta APIs
	 * by using a post meta cache manager.
	 *
	 * @var WCS_Post_Meta_Cache_Manager
	 */
	protected $post_meta_cache_manager;

	/**
	 * Store order relations using post meta keys as the array key for more performant searches
	 * in @see $this->get_relation_type_for_meta_key() than using array_search().
	 *
	 * @var array $relation_keys Post meta key => Order Relationship
	 */
	private $relation_keys;

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		foreach ( $this->get_meta_keys() as $relation_type => $meta_key ) {
			$this->relation_keys[ $meta_key ] = $relation_type;
		}

		$this->post_meta_cache_manager = new WCS_Post_Meta_Cache_Manager( 'shop_order', $this->get_meta_keys() );
	}

	/**
	 * Attach callbacks to keep related order caches up-to-date and make sure
	 * the cache doesn't mess with other data stores.
	 */
	protected function init() {

		$this->post_meta_cache_manager->init();

		// Don't load cached related order meta data into subscriptions
		add_filter( 'wcs_subscription_data_store_props_to_ignore', array( $this, 'add_related_order_cache_props' ), 10, 2 );

		// When a subscription is first created, make sure its renewal order cache is empty because it can not have any renewals yet, and we want to avoid running the query needlessly
		add_filter( 'wcs_created_subscription', array( $this, 'set_empty_renewal_order_cache' ), -1000 );

		// When the post for a related order is deleted or untrashed, make sure the corresponding related order cache is updated
		add_action( 'wcs_update_post_meta_caches', array( $this, 'maybe_update_for_post_meta_change' ), 10, 5 );
		add_action( 'wcs_delete_all_post_meta_caches', array( $this, 'maybe_delete_all_for_post_meta_change' ), 10, 1 );

		// When copying meta from a subscription to a renewal order, don't copy cache related order meta keys.
		add_filter( 'wcs_renewal_order_meta', array( $this, 'remove_related_order_cache_keys' ), 10, 1 );

		WCS_Debug_Tool_Factory::add_cache_tool( 'generator', __( 'Generate Related Order Cache', 'woocommerce-subscriptions' ), __( 'This will generate the persistent cache of all renewal, switch, resubscribe and other order types for all subscriptions in your store. The caches will be generated overtime in the background (via Action Scheduler).', 'woocommerce-subscriptions' ), self::instance() );
		WCS_Debug_Tool_Factory::add_cache_tool( 'eraser', __( 'Delete Related Order Cache', 'woocommerce-subscriptions' ), __( 'This will clear the persistent cache of all renewal, switch, resubscribe and other order types for all subscriptions in your store. Expect slower performance of checkout, renewal and other subscription related functions after taking this action. The caches will be regenerated overtime as related order queries are run.', 'woocommerce-subscriptions' ), self::instance() );
	}

	/* Public methods required by WCS_Related_Order_Store */

	/**
	 * Find orders related to a given subscription in a given way.
	 *
	 * Wrapper to support getting related orders regardless of whether they are cached or not yet,
	 * either in the old transient cache, or new persistent cache.
	 *
	 * @param WC_Order $subscription The ID of the subscription for which calling code wants the related orders.
	 * @param string $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 *
	 * @return array
	 */
	public function get_related_order_ids( WC_Order $subscription, $relation_type ) {
		$subscription_id   = wcs_get_objects_property( $subscription, 'id' ); // We can't rely on $subscription->get_id() being available because we only require a WC_Order, not a WC_Subscription, and WC_Order does not have get_id() available with WC < 3.0
		$related_order_ids = $this->get_related_order_ids_from_cache( $subscription_id, $relation_type );

		// get_post_meta returns false if the post ID is invalid. This can arise when the subscription hasn't been created yet. In any case, the related IDs should be an empty array to avoid a boolean return from this function.
		if ( false === $related_order_ids ) {
			$related_order_ids = array();
		}

		// get post meta returns an empty string when no matching row is found for the given key, meaning it's not set yet
		if ( '' === $related_order_ids ) {

			if ( 'renewal' === $relation_type ) {
				$transient_key = "wcs-related-orders-to-{$subscription_id}"; // despite the name, this transient only stores renewal orders, not all related orders, so we can only use it for finding renewal orders

				// We do this here rather than in get_related_order_ids_from_cache(), because we want to make sure the new persistent cache is updated too
				$related_order_ids = get_transient( $transient_key );
			} else {
				$related_order_ids = false;
			}

			if ( false === $related_order_ids ) {
				$related_order_ids = parent::get_related_order_ids( $subscription, $relation_type ); // no data in transient, query directly
			} else {
				rsort( $related_order_ids ); // queries are ordered from newest ID to oldest, so make sure the transient value is too
				delete_transient( $transient_key ); // we migrate the data to our new cache so want to remote this cache
			}

			$this->update_related_order_id_cache( $subscription_id, $related_order_ids, $relation_type );
		}

		return $related_order_ids;
	}

	/**
	 * Helper function for linking an order to a subscription via a given relationship.
	 *
	 * @param WC_Order $order The order to link with the subscription.
	 * @param WC_Order $subscription The order or subscription to link the order to.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function add_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {
		$this->add_related_order_id_to_cache( wcs_get_objects_property( $order, 'id' ), wcs_get_objects_property( $subscription, 'id' ), $relation_type );
		parent::add_relation( $order, $subscription, $relation_type );
	}

	/**
	 * Remove the relationship between a given order and subscription.
	 *
	 * @param WC_Order $order An order that may be linked with subscriptions.
	 * @param WC_Order $subscription A subscription or order to unlink the order with, if a relation exists.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {
		$this->delete_related_order_id_from_cache( wcs_get_objects_property( $order, 'id' ), wcs_get_objects_property( $subscription, 'id' ), $relation_type );
		parent::delete_relation( $order, $subscription, $relation_type );
	}

	/**
	 * Remove all related orders/subscriptions of a given type from an order.
	 *
	 * @param WC_Order $order An order that may be linked with subscriptions.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relations( WC_Order $order, $relation_type ) {
		$this->delete_related_order_id_from_caches( wcs_get_objects_property( $order, 'id' ), $relation_type );
		parent::delete_relations( $order, $relation_type );
	}

	/* Internal methods for managing the cache */

	/**
	 * Find orders related to a given subscription in a given way from the cache.
	 *
	 * @param int $subscription_id The ID of the subscription for which calling code wants the related orders.
	 * @param string $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 * @return string|array An array of related orders in the cache, or an empty string when no matching row is found for the given key, meaning it's cache is not set yet or has been deleted
	 */
	protected function get_related_order_ids_from_cache( $subscription_id, $relation_type ) {
		return get_post_meta( $subscription_id, $this->get_cache_meta_key( $relation_type ), true );
	}

	/**
	 * Add a related order ID to the cached related order IDs for a given order relationship.
	 *
	 * @param int $order_id An order to link with the subscription.
	 * @param int $subscription_id A subscription to link the order to.
	 * @param string $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 */
	protected function add_related_order_id_to_cache( $order_id, $subscription_id, $relation_type ) {

		$subscription = wcs_get_subscription( $subscription_id );

		// If we can't get a valid subscription, we can't update its cache
		if ( false === $subscription ) {
			return;
		}

		$related_order_ids = $this->get_related_order_ids( $subscription, $relation_type );

		if ( ! in_array( $order_id, $related_order_ids ) ) {
			// Add the new order to the beginning of the array to preserve sort order from newest to oldest
			array_unshift( $related_order_ids, $order_id );
			$this->update_related_order_id_cache( $subscription_id, $related_order_ids, $relation_type );
		}
	}

	/**
	 * Delete a related order ID from the cached related order IDs for a given order relationship.
	 *
	 * @param int $order_id The order that may be linked with subscriptions.
	 * @param int $subscription_id A subscription to remove a linked order from.
	 * @param string $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 */
	protected function delete_related_order_id_from_cache( $order_id, $subscription_id, $relation_type ) {

		$subscription = wcs_get_subscription( $subscription_id );

		// If we can't get a valid subscription, we can't udpate its cache
		if ( false === $subscription ) {
			return;
		}

		$related_order_ids = $this->get_related_order_ids( $subscription, $relation_type );

		if ( ( $index = array_search( $order_id, $related_order_ids ) ) !== false ) {
			unset( $related_order_ids[ $index ] );
			$this->update_related_order_id_cache( $subscription_id, $related_order_ids, $relation_type );
		}
	}

	/**
	 * Helper function for setting related order cache.
	 *
	 * @param int $subscription_id A subscription to update the linked order IDs for.
	 * @param array $related_order_ids Set of orders related to the given subscription.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 * @return bool|int Returns related order cache's meta ID if it doesn't exist yet, otherwise returns true on success and false on failure. NOTE: If the $related_order_ids passed to this function are the same as those already in the database, this function returns false.
	 */
	protected function update_related_order_id_cache( $subscription_id, array $related_order_ids, $relation_type ) {
		return update_post_meta( $subscription_id, $this->get_cache_meta_key( $relation_type ), $related_order_ids );
	}

	/**
	 * Get the meta key used to store the cache of linked order with a subscription, based on the type of relationship.
	 *
	 * @param string $relation_type The order's relationship with the subscription. Must be 'renewal', 'switch' or 'resubscribe'.
	 * @param string $prefix_meta_key Whether to add the underscore prefix to the meta key or not. 'prefix' to prefix the key. 'do_not_prefix' to not prefix the key.
	 * @return string
	 */
	protected function get_cache_meta_key( $relation_type, $prefix_meta_key = 'prefix' ) {
		return sprintf( '%s_order_ids_cache', $this->get_meta_key( $relation_type, $prefix_meta_key ) );
	}

	/* Public methods used to bulk edit cache */

	/**
	 * Clear related order caches for a given subscription.
	 *
	 * @param int $subscription_id The ID of a subscription that may have linked orders.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented. Use 'any' to delete all cached.
	 */
	public function delete_caches_for_subscription( $subscription_id, $relation_type = 'any' ) {
		foreach ( $this->get_relation_types() as $possible_relation_type ) {
			if ( 'any' === $relation_type || $relation_type === $possible_relation_type ) {
				delete_post_meta( $subscription_id, $this->get_cache_meta_key( $possible_relation_type ) );
			}
		}
	}

	/**
	 * Remove an order from all related order caches.
	 *
	 * @param int $order_id The order that may be linked with subscriptions.
	 * @param string $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented. Use 'any' to delete all cached.
	 */
	public function delete_related_order_id_from_caches( $order_id, $relation_type = 'any' ) {
		$relation_types = 'any' === $relation_type ? $this->get_relation_types() : array( $relation_type );
		foreach ( wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => $relation_types ) ) as $subscription_id => $subscription ) {
			foreach ( $relation_types as $type ) {
				$this->delete_related_order_id_from_cache( $order_id, $subscription_id, $type );
			}
		}
	}

	/**
	 * Clear all related order caches for all subscriptions.
	 *
	 * @param array $relation_types The relations to clear, or an empty array to clear all relations (default).
	 */
	public function delete_caches_for_all_subscriptions( $relation_types = array() ) {

		if ( empty( $relation_types ) ) {
			$relation_types = $this->get_relation_types();
		}

		// Set variables to workaround ambiguous parameters of delete_metadata()
		$delete_all   = true;
		$null_post_id = $null_meta_value = null;
		foreach ( $relation_types as $relation_type ) {
			delete_metadata( 'post', $null_post_id, $this->get_cache_meta_key( $relation_type ), $null_meta_value, $delete_all );
		}
	}

	/* Public methods used as callbacks on hooks for managing cache */

	/**
	 * Add related order cache meta keys to a set of props for a subscription data store.
	 *
	 * Related order cache APIs need to be handled by querying a central data source directly, instead of using
	 * data set on an instance of the subscription, as it can be changed by other events outside of that instance's
	 * knowledge or access. For now, this is done via the database. That may be changed in future to use an object
	 * cache, but regardless, the prop should never be a source of that data. This method is attached to the filter
	 * 'wcs_subscription_data_store_props_to_ignore' so that cache keys are ignored.
	 *
	 * @param array $props_to_ignore A mapping of meta keys => prop names.
	 * @param WCS_Subscription_Data_Store_CPT $data_store
	 * @return array A mapping of meta keys => prop names, filtered by ones that should be updated.
	 */
	public function add_related_order_cache_props( $props_to_ignore, $data_store ) {

		if ( is_a( $data_store, 'WCS_Subscription_Data_Store_CPT' ) ) {
			foreach ( $this->get_meta_keys() as $relation_type => $meta_key ) {
				$props_to_ignore[ $this->get_cache_meta_key( $relation_type ) ] = $this->get_cache_meta_key( $relation_type, 'do_not_prefix' );
			}
		}

		return $props_to_ignore;
	}

	/**
	 * Set empty renewal order cache on a subscription.
	 *
	 * Newly created subscriptions can't have renewal orders yet, so we set that cache to empty whenever a new
	 * subscription is created. They can have switch or resubscribe orders, which may have been created before them on
	 * checkout, so we don't touch those caches.
	 *
	 * @param WC_Subscription $subscription A subscription to set empty renewal cache against.
	 * @return WC_Subscription Return the instance of the subscription, required as method is attached to the 'wcs_created_subscription' filter
	 */
	public function set_empty_renewal_order_cache( WC_Subscription $subscription ) {
		$this->update_related_order_id_cache( $subscription->get_id(), array(), 'renewal' );
		return $subscription;
	}

	/* Public methods attached to WCS_Post_Meta_Cache_Manager hooks for managing the cache */

	/**
	 * If there is a change to a related order post meta key, update the cache.
	 *
	 * @param string $update_type The type of update to check. Can be 'add', 'update' or 'delete'.
	 * @param int $order_id The post the meta is being changed on.
	 * @param string $post_meta_key The post meta key being changed.
	 * @param mixed $subscription_id The related subscription's ID, as stored in meta value (only when the meta key is a related order meta key).
	 * @param mixed $old_subscription_id The previous value stored in the database for the related subscription. Optional.
	 */
	public function maybe_update_for_post_meta_change( $update_type, $order_id, $post_meta_key, $subscription_id, $old_subscription_id = '' ) {

		$relation_type = $this->get_relation_type_for_meta_key( $post_meta_key );

		if ( false === $relation_type ) {
			return;
		}

		switch ( $update_type ) {
			case 'add':
				$this->add_related_order_id_to_cache( $order_id, $subscription_id, $relation_type );
				break;
			case 'delete':
				// If we don't have a specific subscription ID, the order/post is being deleted, so clear it from all caches
				if ( empty( $subscription_id ) ) {
					$this->delete_related_order_id_from_caches( $order_id, $relation_type );
				} else {
					$this->delete_related_order_id_from_cache( $order_id, $subscription_id, $relation_type );
				}
				break;
			case 'update':
				if ( ! empty( $old_subscription_id ) ) {
					$this->delete_related_order_id_from_cache( $order_id, $old_subscription_id, $relation_type );
				}

				$this->add_related_order_id_to_cache( $order_id, $subscription_id, $relation_type );
				break;
		}
	}

	/**
	 * Remove all caches for a given meta key if all entries for that meta key are being deleted.
	 *
	 * @param string $post_meta_key The post meta key being changed.
	 */
	public function maybe_delete_all_for_post_meta_change( $post_meta_key ) {

		$relation_type = $this->get_relation_type_for_meta_key( $post_meta_key );

		if ( $relation_type ) {
			$this->delete_caches_for_all_subscriptions( array( $relation_type ) );
		}
	}

	/**
	 * Get the IDs of subscriptions without related order cache set for a give relation type or types.
	 *
	 * If more than one relation is specified, a batch of subscription IDs will be returned that are missing
	 * either of those relations, not both.
	 *
	 * @param array $relation_types The relations to check, or an empty array to check for any relation type (default).
	 * @param int $batch_size The number of subscriptions to return. Use -1 to return all subscriptions.
	 * @return array
	 */
	protected function get_subscription_ids_without_cache( $relation_types = array(), $batch_size = 10 ) {
		global $wpdb;

		if ( empty( $relation_types ) ) {
			$relation_types = $this->get_relation_types();
		}

		$subscription_ids = array();

		foreach ( $relation_types as $relation_type ) {

			$limit = $batch_size - count( $subscription_ids );

			// Use a subquery instead of a meta query with get_posts() as it's more performant than the multiple joins created by get_posts()
			$post_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM $wpdb->posts
					WHERE post_type = 'shop_subscription'
					AND post_status NOT IN ('trash','auto-draft')
					AND ID NOT IN (
						SELECT post_id FROM $wpdb->postmeta
						WHERE meta_key = %s
					)
					LIMIT 0, %d",
				$this->get_cache_meta_key( $relation_type ),
				$limit
			) );

			if ( $post_ids ) {
				$subscription_ids += $post_ids;

				if ( count( $subscription_ids ) >= $batch_size ) {
					break;
				}
			}
		}

		return $subscription_ids;
	}

	/**
	 * Get the order relation for a given post meta key.
	 *
	 * @param string $meta_key The post meta key being changed.
	 * @return bool|string The order relation if it exists, or false if no such meta key exists.
	 */
	private function get_relation_type_for_meta_key( $meta_key ) {
		return isset( $this->relation_keys[ $meta_key ] ) ? $this->relation_keys[ $meta_key ] : false;
	}

	/**
	 * Remove related order cache meta data from order meta copied from subscriptions to renewal orders.
	 *
	 * @param  array $meta An order's meta data.
	 * @return array Filtered order meta data to be copied.
	 */
	public function remove_related_order_cache_keys( $meta ) {

		$cache_meta_keys = array_map( array( $this, 'get_cache_meta_key' ), $this->get_relation_types() );

		foreach ( $meta as $index => $meta_data ) {
			if ( ! empty( $meta_data['meta_key'] ) && in_array( $meta_data['meta_key'], $cache_meta_keys ) ) {
				unset( $meta[ $index ] );
			}
		}

		return $meta;
	}

	/** Methods to implement WCS_Cache_Updater - wrap more accurately named methods for the sake of clarity */

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 */
	public function get_items_to_update() {
		return $this->get_subscription_ids_without_cache();
	}

	/**
	 * Run the update for a single item.
	 *
	 * @param mixed $item The item to update.
	 */
	public function update_items_cache( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );
		if ( $subscription ) {
			foreach ( $this->get_relation_types() as $relation_type ) {
				// Getting the related IDs also sets the cache when it's not already set
				$this->get_related_order_ids( $subscription, $relation_type );
			}
		}
	}

	/**
	 * Clear all caches.
	 */
	public function delete_all_caches() {
		$this->delete_caches_for_all_subscriptions();
	}
}
