<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related order data store for orders and subscriptions with caching.
 *
 * Subscription related orders (renewals, switch and resubscribe orders) record their relationship in order meta.
 * Historically finding subscription-related orders was costly as it required querying the database for all orders with specific meta key and meta value.
 * This required a performance heavy postmeta query and wp_post join. To fix this, in WC Subscriptions 2.3.0 we introduced a persistent caching layer. In
 * subscription metadata we now store a single key to keep track of the subscription's related orders.
 *
 * This class adds a persistent caching layer on top of WCS_Related_Order_Store_CPT for more
 * performant queries on related orders. This class contains the methods to fetch, update and delete the meta caches.
 *
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 */
class WCS_Related_Order_Store_Cached_CPT extends WCS_Related_Order_Store_CPT implements WCS_Cache_Updater {

	/**
	 * Keep cache up-to-date with changes to our meta data using a meta cache manager.
	 *
	 * @var WCS_Post_Meta_Cache_Manager|WCS_Object_Data_Cache_Manager
	 */
	protected $object_data_cache_manager;

	/**
	 * Store order relations using meta keys as the array key for more performant searches
	 * in @see $this->get_relation_type_for_meta_key() than using array_search().
	 *
	 * @var array $relation_keys meta key => Order Relationship
	 */
	private $relation_keys;

	/**
	 * A flag to indicate whether the related order cache keys should be ignored.
	 *
	 * By default the related order cache keys are ignored via $this->add_related_order_cache_props(). In order to fetch the subscription's
	 * meta with this cache's keys present, we need a way to bypass that function.
	 *
	 * Important: We use a static variable here because it is possible to have multiple instances of this class in memory, and we want to make sure we bypass
	 * the function in all instances. This is especially true in unit tests. We can't make add_related_order_cache_props static because it uses $this in scope.
	 *
	 * @var bool $override_ignored_props True if the related order cache keys should be ignored otherwise false.
	 */
	private static $override_ignored_props = false;

	/**
	 * A list of subscription IDs that are requesting multiple related order caches to be read.
	 *
	 * This is used by @see get_related_order_ids_by_types() to enable fetching multiple related order caches without reading the subscriptions meta query multiple times.
	 *
	 * @var array $batch_processing_subscriptions An array of subscription IDs.
	 */
	private static $batch_processing_related_orders = [];

	/**
	 * A cache of subscription meta data. Used when fetching multiple related order caches for a subscription to avoid multiple database queries.
	 *
	 * @var array $subscription_meta_cache An array of subscription meta data.
	 */
	private static $subscription_meta_cache = [];

	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

		foreach ( $this->get_meta_keys() as $relation_type => $meta_key ) {
			$this->relation_keys[ $meta_key ] = $relation_type;
		}

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$this->object_data_cache_manager = new WCS_Object_Data_Cache_Manager( 'order', $this->get_meta_keys() );
		} else {
			$this->object_data_cache_manager = new WCS_Post_Meta_Cache_Manager( 'shop_order', $this->get_meta_keys() );
		}
	}

	/**
	 * Gets the legacy protected variables for backwards compatibility.
	 *
	 * Throws a deprecated warning if accessing the now deprecated variables.
	 *
	 * @param string $name The variable name.
	 * @return WCS_Post_Meta_Cache_Manager_Many_To_One|WCS_Object_Data_Cache_Manager_Many_To_One Depending on the HPOS environment.
	 */
	public function __get( $name ) {
		if ( 'post_meta_cache_manager' !== $name ) {
			return;
		}

		$old         = get_class( $this ) . '::post_meta_cache_manager';
		$replacement = get_class( $this ) . '::object_data_cache_manager';

		wcs_doing_it_wrong( $old, "$old has been deprecated, use $replacement instead.", '5.2.0' );

		return $this->object_data_cache_manager;
	}

	/**
	 * Attaches callbacks to keep related order caches up-to-date.
	 */
	protected function init() {

		$this->object_data_cache_manager->init();

		add_action( 'init', array( $this, 'register_debug_tools' ) );

		// When a subscription is being read from the database, don't load cached related order meta data into subscriptions.
		add_filter( 'wcs_subscription_data_store_props_to_ignore', array( $this, 'add_related_order_cache_props' ), 10, 2 );

		// When a subscription is first created, make sure its renewal order cache is empty because it can not have any renewals yet, and we want to avoid running the query needlessly.
		add_filter( 'wcs_created_subscription', array( $this, 'set_empty_renewal_order_cache' ), -1000 );

		// When the post for a related order is deleted or untrashed, make sure the corresponding related order cache is updated.
		add_action( 'wcs_update_post_meta_caches', array( $this, 'maybe_update_for_post_meta_change' ), 10, 5 );
		add_action( 'wcs_delete_all_post_meta_caches', array( $this, 'maybe_delete_all_for_post_meta_change' ), 10, 1 );

		// When copying meta from a subscription to a renewal order, don't copy cache related order meta keys.
		add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'remove_related_order_cache_keys' ), 10, 1 );
	}

	/**
	 * Register debug tools for managing the cache.
	 */
	public function register_debug_tools() {
		WCS_Debug_Tool_Factory::add_cache_tool( 'generator', __( 'Generate Related Order Cache', 'woocommerce-subscriptions' ), __( 'This will generate the persistent cache of all renewal, switch, resubscribe and other order types for all subscriptions in your store. The caches will be generated overtime in the background (via Action Scheduler).', 'woocommerce-subscriptions' ), self::instance() );
		WCS_Debug_Tool_Factory::add_cache_tool( 'eraser', __( 'Delete Related Order Cache', 'woocommerce-subscriptions' ), __( 'This will clear the persistent cache of all renewal, switch, resubscribe and other order types for all subscriptions in your store. Expect slower performance of checkout, renewal and other subscription related functions after taking this action. The caches will be regenerated overtime as related order queries are run.', 'woocommerce-subscriptions' ), self::instance() );
	}

	/* Public methods required by WCS_Related_Order_Store */

	/**
	 * Finds orders related to a given subscription.
	 *
	 * This function is a wrapper to support getting related orders regardless of whether they are cached or not yet,
	 * either in the old transient cache, or new persistent cache.
	 *
	 * @param WC_Order $subscription  The ID of the subscription for which calling code wants the related orders.
	 * @param string   $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 *
	 * @return array An array of related order IDs.
	 */
	public function get_related_order_ids( WC_Order $subscription, $relation_type ) {
		$related_order_ids = $this->get_related_order_ids_from_cache( $subscription, $relation_type );

		// get_related_order_ids_from_cache() returns false if the ID is invalid. This can arise when the subscription hasn't been created yet. In any case, the related IDs should be an empty array to avoid a boolean return from this function.
		if ( false === $related_order_ids ) {
			$related_order_ids = array();
		}

		// get_related_order_ids_from_cache() returns an empty string when no matching row is found for the given key, meaning it's not set yet.
		if ( '' === $related_order_ids ) {

			// If the cache is empty attempt to get the renewal order IDs from the old transient cache.
			if ( 'renewal' === $relation_type ) {
				$transient_key = "wcs-related-orders-to-{$subscription->get_id()}"; // Despite the name, this transient only stores renewal orders, not all related orders, so we can only use it for finding renewal orders.

				// We do this here rather than in get_related_order_ids_from_cache(), because we want to make sure the new persistent cache is updated too.
				$related_order_ids = get_transient( $transient_key );
			} else {
				$related_order_ids = false;
			}

			if ( false === $related_order_ids ) {
				$related_order_ids = parent::get_related_order_ids( $subscription, $relation_type ); // No data in transient, query directly.
			} else {
				rsort( $related_order_ids ); // Queries are ordered from newest ID to oldest, so make sure the transient value is too.
				delete_transient( $transient_key ); // We migrate the data to our new cache so can delete the old one.
			}

			$this->update_related_order_id_cache( $subscription, $related_order_ids, $relation_type );
		}

		return $related_order_ids;
	}

	/**
	 * Links an order to a subscription via a given relationship.
	 *
	 * @param WC_Order $order         The order to link with the subscription.
	 * @param WC_Order $subscription  The order or subscription to link the order to.
	 * @param string   $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function add_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {
		$this->add_related_order_id_to_cache( $order->get_id(), $subscription, $relation_type );
		parent::add_relation( $order, $subscription, $relation_type );
	}

	/**
	 * Removes the relationship between a given order and subscription.
	 *
	 * @param WC_Order $order         An order that may be linked with subscriptions.
	 * @param WC_Order $subscription  A subscription or order to unlink the order with, if a relation exists.
	 * @param string   $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relation( WC_Order $order, WC_Order $subscription, $relation_type ) {
		$this->delete_related_order_id_from_cache( $order->get_id(), $subscription, $relation_type );
		parent::delete_relation( $order, $subscription, $relation_type );
	}

	/**
	 * Removes all related orders/subscriptions of a given type from an order.
	 *
	 * @param WC_Order $order         An order that may be linked with subscriptions.
	 * @param string   $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 */
	public function delete_relations( WC_Order $order, $relation_type ) {
		$this->delete_related_order_id_from_caches( $order->get_id(), $relation_type );
		parent::delete_relations( $order, $relation_type );
	}

	/* Internal methods for managing the cache */

	/**
	 * Finds orders related to a given subscription in a given way from the cache.
	 *
	 * @param WC_Subscription|int $subscription_id The Subscription ID or subscription object to fetch related orders.
	 * @param string              $relation_type   The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.
	 *
	 * @return string|array An array of related orders in the cache, or an empty string when no matching row is found for the given key, meaning it's cache is not set yet or has been deleted
	 */
	public function get_related_order_ids_from_cache( $subscription, $relation_type ) {
		$subscription = is_object( $subscription ) ? $subscription : wcs_get_subscription( $subscription );

		if ( ! wcs_is_subscription( $subscription ) ) {
			return false;
		}

		$meta_data = $this->get_related_order_metadata( $subscription, $relation_type );

		$related_orders = $meta_data ? maybe_unserialize( $meta_data->meta_value ) : '';

		return is_array( $related_orders ) ? $related_orders : '';
	}

	/**
	 * Adds an order ID to a subscription's related order cache for a given relationship.
	 *
	 * @param int                 $order_id      An order to link with the subscription.
	 * @param WC_Subscription|int $subscription  A subscription to link the order to. Accepts a subscription object or ID.
	 * @param string              $relation_type The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe.
	 */
	protected function add_related_order_id_to_cache( $order_id, $subscription, $relation_type ) {
		$subscription = is_object( $subscription ) ? $subscription : wcs_get_subscription( $subscription );

		// If we can't get a valid subscription, we can't update its cache.
		if ( false === $subscription ) {
			return;
		}

		$related_order_ids = $this->get_related_order_ids( $subscription, $relation_type );

		if ( ! in_array( $order_id, $related_order_ids, true ) ) {
			// Add the new order to the beginning of the array to preserve sort order from newest to oldest.
			array_unshift( $related_order_ids, $order_id );
			$this->update_related_order_id_cache( $subscription, $related_order_ids, $relation_type );
		}
	}

	/**
	 * Deletes a related order ID from a subscription's related orders cache for a given order relationship.
	 *
	 * @param int                 $order_id      The order that may be linked with subscriptions.
	 * @param WC_Subscription|int $subscription  A subscription to remove a linked order from. Accepts a subscription object or ID.
	 * @param string              $relation_type The relationship between the subscription and the orders. Must be 'renewal', 'switch' or 'resubscribe.e.
	 */
	protected function delete_related_order_id_from_cache( $order_id, $subscription, $relation_type ) {
		$subscription = is_object( $subscription ) ? $subscription : wcs_get_subscription( $subscription );

		// If we can't get a valid subscription, we can't update its cache.
		if ( false === $subscription ) {
			return;
		}

		$related_order_ids   = $this->get_related_order_ids( $subscription, $relation_type );
		$related_order_ids   = array_map( 'absint', $related_order_ids );
		$related_order_index = array_search( $order_id, $related_order_ids, true );

		if ( false !== $related_order_index ) {
			unset( $related_order_ids[ $related_order_index ] );
			$this->update_related_order_id_cache( $subscription, $related_order_ids, $relation_type );
		}
	}

	/**
	 * Sets a subscription's related order cache for a given relationship.
	 *
	 * @param WC_Subscription|int $subscription      A subscription to update the linked order IDs for.
	 * @param array               $related_order_ids Set of orders related to the given subscription.
	 * @param string              $relation_type     The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented.
	 *
	 * @return bool|int Returns the related order cache's meta ID if it didn't exist, otherwise returns true on success and false on failure. NOTE: If the $related_order_ids passed to this function are the same as those already in the database, this function returns false.
	 */
	protected function update_related_order_id_cache( $subscription, array $related_order_ids, $relation_type ) {

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );

			if ( ! $subscription ) {
				return false;
			}
		}

		$subscription_data_store = $subscription->get_data_store();
		$current_metadata        = $this->get_related_order_metadata( $subscription, $relation_type );
		$new_metadata            = array(
			'key'   => $this->get_cache_meta_key( $relation_type ),
			'value' => $related_order_ids,
		);

		// Update the subscription's modified date if the related order cache has changed. Only necessary on non-HPOS environments.
		if ( ! wcs_is_custom_order_tables_usage_enabled() ) {
			$this->update_modified_date_for_related_order_cache( $subscription, $related_order_ids, $current_metadata );
		}

		// If there is metadata for this key, update it, otherwise add it.
		if ( $current_metadata ) {
			$new_metadata['id'] = $current_metadata->meta_id;
			$return             = $subscription_data_store->update_meta( $subscription, (object) $new_metadata );
		} else {
			$return = $subscription_data_store->add_meta( $subscription, (object) $new_metadata );
		}

		/**
		 * Trigger update actions after modifying the subscription's related order cache metadata.
		 *
		 * This ensures that functions fired after a subscription update, such as webhooks and those in the DataSynchronizer,
		 * which sync CPT post data to HPOS tables, are executed.
		 */
		do_action( 'woocommerce_update_order', $subscription->get_id(), $subscription );
		do_action( 'woocommerce_update_subscription', $subscription->get_id(), $subscription );

		return $return;
	}

	/**
	 * Backfills the related order cache for a subscription when the "Keep the posts table and the orders tables synchronized"
	 * setting is enabled.
	 *
	 * In this class we update the related orders cache metadata directly to ensure the
	 * proper value is written to the database. To do this we use the data store's update_meta() and
	 * add_meta() functions.
	 *
	 * Using these functions bypasses the DataSynchronizer resulting in order and post data becoming out of sync.
	 * To fix this, this function manually updates the post meta table with the new values.
	 *
	 * @param WC_Subscription $subscription  The subscription object to backfill.
	 * @param string          $relation_type The related order relationship type. Can be 'renewal', 'switch' or 'resubscribe'.
	 * @param array           $metadata      The metadata to set update/add in the CPT data store. Should be an array with 'key' and 'value' keys.
	 *
	 * @deprecated 7.3.0 - Backfilling is already handled by the Order/Subscriptions Data Store.
	 */
	protected function maybe_backfill_related_order_cache( $subscription, $relation_type, $metadata ) {
		wcs_deprecated_function( __METHOD__, '7.3.0' );

		if ( ! wcs_is_custom_order_tables_usage_enabled() || ! wcs_is_custom_order_tables_data_sync_enabled() || empty( $metadata['key'] ) ) {
			return;
		}

		$cpt_data_store   = $subscription->get_data_store()->get_cpt_data_store_instance();
		$current_metadata = $this->get_related_order_metadata( $subscription, $relation_type, $cpt_data_store );

		if ( $current_metadata ) {
			$metadata['id'] = $current_metadata->meta_id;
			$cpt_data_store->update_meta( $subscription, (object) $metadata );
		} else {
			$cpt_data_store->add_meta( $subscription, (object) $metadata );
		}
	}

	/**
	 * Gets the meta key used to store the cache of linked order with a subscription, based on the type of relationship.
	 *
	 * @param string $relation_type   The order's relationship with the subscription. Must be 'renewal', 'switch' or 'resubscribe'.
	 * @param string $prefix_meta_key Whether to add the underscore prefix to the meta key or not. 'prefix' to prefix the key. 'do_not_prefix' to not prefix the key.
	 *
	 * @return string The related order cache meta key.
	 */
	protected function get_cache_meta_key( $relation_type, $prefix_meta_key = 'prefix' ) {
		return sprintf( '%s_order_ids_cache', $this->get_meta_key( $relation_type, $prefix_meta_key ) );
	}

	/* Public methods used to bulk edit cache */

	/**
	 * Clears all related order caches for a given subscription.
	 *
	 * @param WC_Subscription|int $subscription_id The ID of a subscription that may have linked orders.
	 * @param string              $relation_type   The relationship between the subscription and the order. Must be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented. Use 'any' to delete all cached.
	 */
	public function delete_caches_for_subscription( $subscription, $relation_type = 'any' ) {
		$subscription = is_object( $subscription ) ? $subscription : wcs_get_subscription( $subscription );

		foreach ( $this->get_relation_types() as $possible_relation_type ) {
			if ( 'any' === $relation_type || $relation_type === $possible_relation_type ) {
				$metadata = $this->get_related_order_metadata( $subscription, $possible_relation_type );

				if ( $metadata ) {
					$subscription->get_data_store()->delete_meta( $subscription, (object) [ 'id' => $metadata->meta_id ] );
				}
			}
		}
	}

	/**
	 * Removes an order from all related order caches.
	 *
	 * @param int    $order_id      The order ID that must be removed.
	 * @param string $relation_type Optional. The relationship between the subscription and the order. Can be 'renewal', 'switch' or 'resubscribe' unless custom relationships are implemented. Default is 'any' which deletes the ID from all cache types.
	 */
	public function delete_related_order_id_from_caches( $order_id, $relation_type = 'any' ) {
		$relation_types = 'any' === $relation_type ? $this->get_relation_types() : array( $relation_type );
		$subscriptions  = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => $relation_types ) );

		foreach ( $subscriptions as $subscription ) {
			foreach ( $relation_types as $type ) {
				$this->delete_related_order_id_from_cache( $order_id, $subscription, $type );
			}
		}
	}

	/**
	 * Clears all related order caches for all subscriptions.
	 *
	 * @param array $relation_types Optional. The order relations to clear. Default is an empty array which clears all relations.
	 */
	public function delete_caches_for_all_subscriptions( $relation_types = array() ) {
		if ( empty( $relation_types ) ) {
			$relation_types = $this->get_relation_types();
		}

		foreach ( $relation_types as $relation_type ) {
			WC_Data_Store::load( 'subscription' )->delete_all_metadata_by_key( $this->get_cache_meta_key( $relation_type ) );
		}
	}

	/* Public methods used as callbacks on hooks for managing cache */

	/**
	 * Adds related order cache meta keys to a set of props for a subscription data store.
	 *
	 * Related order cache APIs need to be handled by querying a central data source directly, instead of using
	 * data set on an instance of the subscription, as it can be changed by other events outside of that instance's
	 * knowledge or access. For now, this is done via the database. That may be changed in future to use an object
	 * cache, but regardless, the prop should never be a source of that data. This method is attached to the filter
	 * 'wcs_subscription_data_store_props_to_ignore' so that cache keys are ignored.
	 *
	 * @param array                           $props_to_ignore A mapping of meta keys => prop names.
	 * @param WCS_Subscription_Data_Store_CPT $data_store      Subscriptions Data Store
	 *
	 * @return array A mapping of meta keys => prop names, filtered by ones that should be updated.
	 */
	public function add_related_order_cache_props( $props_to_ignore, $data_store ) {

		// Bail out early if the flag to bypass ignored cache props is set to true.
		if ( self::$override_ignored_props ) {
			return $props_to_ignore;
		}

		if ( is_a( $data_store, 'WCS_Subscription_Data_Store_CPT' ) ) {
			foreach ( $this->get_meta_keys() as $relation_type => $meta_key ) {
				$props_to_ignore[ $this->get_cache_meta_key( $relation_type ) ] = $this->get_cache_meta_key( $relation_type, 'do_not_prefix' );
			}
		}

		return $props_to_ignore;
	}

	/**
	 * Sets an empty renewal order cache on a subscription.
	 *
	 * Newly created subscriptions cannot have renewal orders yet, so we set that cache to empty whenever a new
	 * subscription is created. Subscriptions can have switch or resubscribe orders, which may have been created before the subscription on
	 * checkout, so we don't touch those caches.
	 *
	 * @param WC_Subscription $subscription A subscription to set an empty renewal cache against.
	 *
	 * @return WC_Subscription The instance of the subscription. Required as this method is attached to the 'wcs_created_subscription' filter
	 */
	public function set_empty_renewal_order_cache( WC_Subscription $subscription ) {
		$this->update_related_order_id_cache( $subscription, array(), 'renewal' );
		return $subscription;
	}

	/* Public methods attached to WCS_Post_Meta_Cache_Manager hooks for managing the cache */

	/**
	 * Updates the cache when there is a change to a related order meta key.
	 *
	 * @param string $update_type         The type of update to check. Can be 'add', 'update' or 'delete'.
	 * @param int    $order_id            The order ID the meta is being changed on.
	 * @param string $post_meta_key       The meta key being changed.
	 * @param mixed  $subscription_id     The related subscription's ID, as stored in meta value (only when the meta key is a related order meta key).
	 * @param mixed  $old_subscription_id Optional. The previous value stored in the database for the related subscription.
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
				// If we don't have a specific subscription ID, the order is being deleted, so clear it from all caches.
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
	 * Removes all caches for a given meta key.
	 *
	 * Used by caching clearing tools if all entries for that meta key are being deleted.
	 *
	 * @param string $meta_key The meta key to delete.
	 */
	public function maybe_delete_all_for_post_meta_change( $meta_key ) {

		$relation_type = $this->get_relation_type_for_meta_key( $meta_key );

		if ( $relation_type ) {
			$this->delete_caches_for_all_subscriptions( array( $relation_type ) );
		}
	}

	/**
	 * Gets a list of IDs for subscriptions without a related order cache set for a give relation type or types.
	 *
	 * If more than one relation is specified, a batch of subscription IDs will be returned that are missing
	 * either of those relations, not both.
	 *
	 * @param array $relation_types Optional. The relations to check. Default is an empty array which checks for any relation type.
	 * @param int   $batch_size     Optional. The number of subscriptions to return. Use -1 to return all subscriptions. Default is 10.
	 *
	 * @return array An array of subscription IDs missing the given relation type(s)
	 */
	protected function get_subscription_ids_without_cache( $relation_types = array(), $batch_size = 10 ) {

		if ( empty( $relation_types ) ) {
			$relation_types = $this->get_relation_types();
		}

		$subscription_ids = array();

		foreach ( $relation_types as $relation_type ) {
			$limit = $batch_size - count( $subscription_ids );
			$ids   = wcs_get_orders_with_meta_query(
				[
					'limit'      => $limit,
					'return'     => 'ids',
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'type'       => 'shop_subscription',
					'status'     => array_keys( wcs_get_subscription_statuses() ),
					'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'     => $this->get_cache_meta_key( $relation_type ),
							'compare' => 'NOT EXISTS',
						],
					],
				]
			);

			if ( $ids ) {
				$subscription_ids += $ids;

				if ( count( $subscription_ids ) >= $batch_size ) {
					break;
				}
			}
		}

		return $subscription_ids;
	}

	/**
	 * Gets the order relation for a given meta key.
	 *
	 * @param string $meta_key The meta key to get the subscription-relation for.
	 *
	 * @return bool|string The order relation if it exists, or false if no such meta key exists.
	 */
	private function get_relation_type_for_meta_key( $meta_key ) {
		return isset( $this->relation_keys[ $meta_key ] ) ? $this->relation_keys[ $meta_key ] : false;
	}

	/**
	 * Removes related order cache meta data from order meta copied from subscriptions to renewal orders.
	 *
	 * @param array $meta An order's meta data.
	 *
	 * @return array Filtered order meta data to be copied.
	 */
	public function remove_related_order_cache_keys( $meta ) {

		$cache_meta_keys = array_map( array( $this, 'get_cache_meta_key' ), $this->get_relation_types() );

		foreach ( $cache_meta_keys as $cache_meta_key ) {
			unset( $meta[ $cache_meta_key ] );
		}

		return $meta;
	}

	/** Methods to implement WCS_Cache_Updater - wrap more accurately named methods for the sake of clarity */

	/**
	 * Gets the subscriptions without caches that need to be updated, if any.
	 *
	 * This function is used in the background updater to determine which subscriptions have missing caches that need generating.
	 *
	 * @return array An array of subscriptions without any related order caches.
	 */
	public function get_items_to_update() {
		return $this->get_subscription_ids_without_cache();
	}

	/**
	 * Generates a related order cache for a given subscription.
	 *
	 * This function is used in the background updater to generate caches for subscriptions that are missing them.
	 *
	 * @param int $subscription_id The subscription to generate the cache for.
	 */
	public function update_items_cache( $subscription_id ) {
		$subscription = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return;
		}

		$this->get_related_order_ids_by_types( $subscription, $this->get_relation_types() );
	}

	/**
	 * Clears all caches for all subscriptions.
	 */
	public function delete_all_caches() {
		$this->delete_caches_for_all_subscriptions();
	}

	/**
	 * Gets the subscription's related order cached stored in meta.
	 *
	 * @param WC_Subscription $subscription  The subscription to get the cache meta for.
	 * @param string          $relation_type The relation type to get the cache meta for.
	 * @param mixed           $data_store    The data store to use to get the meta. Defaults to the current subscription's data store.
	 *
	 * @return stdClass|bool The meta data object if it exists, or false if it doesn't.
	 */
	protected function get_related_order_metadata( WC_Subscription $subscription, $relation_type, $data_store = null ) {
		$cache_meta_key = $this->get_cache_meta_key( $relation_type );
		$data_store     = $data_store ?? $subscription->get_data_store();

		foreach ( $this->get_subscription_meta( $subscription, $data_store ) as $meta ) {
			if ( isset( $meta->meta_key ) && $cache_meta_key === $meta->meta_key ) {
				return $meta;
			}
		}

		return false;
	}

	/**
	 * Updates the subscription's modified date if the related order cache has changed.
	 *
	 * @param WC_Subscription $subscription      The subscription to update the modified date for.
	 * @param array           $related_order_ids The related order IDs to compare with the current related order IDs.
	 * @param object          $current_metadata  The current related order cache metadata.
	 */
	protected function update_modified_date_for_related_order_cache( $subscription, $related_order_ids, $current_metadata ) {
		$subscription_modified = $subscription->get_date_modified( 'edit' );

		// If the subscription's modified date is already up-to-date, don't update it again.
		if ( $subscription_modified && (int) $subscription_modified->getTimestamp() === time() ) {
			return;
		}

		$current_related_order_ids = $current_metadata ? maybe_unserialize( $current_metadata->meta_value ) : [];

		// If the new related order IDs are different from the current ones, update the subscription's modified date.
		if ( $current_related_order_ids !== $related_order_ids ) {
			$subscription->set_date_modified( time() );
			$subscription->save();
		}
	}

	/**
	 * Gets the subscription's meta data.
	 *
	 * @param WC_Subscription $subscription The subscription to get the meta for.
	 * @param mixed           $data_store   The data store to use to get the meta. Defaults to the current subscription's data store.
	 *
	 * @return array The subscription's meta data.
	 */
	private function get_subscription_meta( WC_Subscription $subscription, $data_store ) {
		$subscription_id     = $subscription->get_id();
		$cache_key           = $this->get_batch_processing_cache_key( $subscription, $data_store );
		$is_batch_processing = $this->is_batch_processing( $cache_key );

		// If we are in batch processing mode, and there are cached results return the cached meta data.
		if ( $is_batch_processing && isset( self::$subscription_meta_cache[ $cache_key ] ) ) {
			return self::$subscription_meta_cache[ $cache_key ];
		}

		/**
		 * Bypass the related order cache keys being ignored when fetching subscription meta.
		 *
		 * By default the related order cache keys are ignored via $this->add_related_order_cache_props(). In order to fetch the subscription's
		 * meta with those keys, we need to bypass that function.
		 *
		 * We use a static variable because it is possible to have multiple instances of this class in memory, and we want to make sure we bypass
		 * the function in all instances.
		 */
		self::$override_ignored_props = true;
		$subscription_meta            = $data_store->read_meta( $subscription );
		self::$override_ignored_props = false;

		// If we are in batch processing mode, cache the meta data so it can be returned for subsequent calls.
		if ( $is_batch_processing ) {
			self::$subscription_meta_cache[ $cache_key ] = $subscription_meta;
		}

		return $subscription_meta;
	}

	/**
	 * Gets the related order IDs for a subscription by multiple relation types.
	 *
	 * This function is a more efficient way to get related order IDs for multiple relation types at once.
	 * It will only query the database once for all cache data, and then return the related order IDs for each relation type.
	 *
	 * The alternative of calling the get_related_order_ids() function for each relation type will result in a full subscription meta read for each relation type.
	 *
	 * @param WC_Order $subscription        The subscription to get related order IDs for.
	 * @param array    $related_order_types The related order types to get IDs for. Must be an array of supported relation types.
	 *
	 * @return array An array of related order IDs for each relation type.
	 */
	public function get_related_order_ids_by_types( WC_Order $subscription, $related_order_types ) {
		$subscription_id   = $subscription->get_id();
		$related_order_ids = [];

		// Declare batch processing mode for this subscription.
		$cache_key = $this->start_batch_processing_mode( $subscription );

		foreach ( $related_order_types as $relation_type ) {
			$related_order_ids[ $relation_type ] = $this->get_related_order_ids( $subscription, $relation_type );
		}

		$this->stop_batch_processing_mode( $cache_key );

		return $related_order_ids;
	}

	/**
	 * Starts batch processing mode for a subscription.
	 *
	 * @param WC_Subscription $subscription The subscription to start batch processing mode for.
	 * @return string The cache key for the subscription.
	 */
	private function start_batch_processing_mode( $subscription ) {
		$cache_key = $this->get_batch_processing_cache_key( $subscription );

		self::$batch_processing_related_orders[ $cache_key ] = true;
		return $cache_key;
	}

	/**
	 * Stops batch processing mode for a subscription.
	 *
	 * Destroys the cache and removes the cache key.
	 *
	 * @param string $cache_key The batch processing cache key.
	 */
	private function stop_batch_processing_mode( $cache_key ) {
		unset( self::$batch_processing_related_orders[ $cache_key ] );
		unset( self::$subscription_meta_cache[ $cache_key ] );
	}

	/**
	 * Checks if batch processing mode is active for a subscription.
	 *
	 * @param string $cache_key The batch processing cache key.
	 * @return bool True if batch processing mode is active, false otherwise.
	 */
	private function is_batch_processing( $cache_key ) {
		return isset( self::$batch_processing_related_orders[ $cache_key ] );
	}

	/**
	 * Gets the batch processing cache key for a subscription.
	 *
	 * The cache key is a unique combination of the subscription ID and the data store class name.
	 *
	 * @param WC_Subscription $subscription The subscription to get the cache key for.
	 * @param bool|object     $data_store   The data store which will be used to read the subscription meta. Defaults to the current subscription's data store.
	 *
	 * @return string The cache key for the subscription.
	 */
	private function get_batch_processing_cache_key( $subscription, $data_store = null ) {
		// If no data store is provided, use the subscription object's data store or load the default data store if no subscription data store is found.
		$data_store       = $data_store ?? $subscription->get_data_store() ?? WC_Data_Store::load( 'subscriptions' );
		$data_store_class = is_a( $data_store, 'WC_Data_Store' ) ? $data_store->get_current_class_name() : '';
		return $data_store_class . '-' . $subscription->get_id();
	}
}
