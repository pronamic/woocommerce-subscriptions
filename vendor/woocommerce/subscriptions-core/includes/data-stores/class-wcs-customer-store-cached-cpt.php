<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer data store for subscriptions stored in Custom Post Types, with caching.
 *
 * Adds a persistent caching layer on top of WCS_Customer_Store_CPT for more
 * performant queries to find a user's subscriptions.
 *
 * Cache is based on the current blog in case of a multisite environment.
 *
 * @version  2.3.0
 * @category Class
 * @author   Prospress
 */
class WCS_Customer_Store_Cached_CPT extends WCS_Customer_Store_CPT implements WCS_Cache_Updater {

	/**
	 * Keep cache up-to-date with changes to our meta data via WordPress post meta APIs
	 * by using a post meta cache manager.
	 *
	 * @var WCS_Post_Meta_Cache_Manager_Many_To_One
	 */
	protected $post_meta_cache_manager;

	/**
	 * Meta key used to store all of a customer's subscription IDs in their user meta.
	 *
	 * @var string
	 */
	const _CACHE_META_KEY = '_wcs_subscription_ids_cache';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->post_meta_cache_manager = new WCS_Post_Meta_Cache_Manager_Many_To_One( 'shop_subscription', array( $this->get_meta_key() ) );
	}

	/**
	 * Attach callbacks to keep user subscription caches up-to-date and provide debug tools for managing the cache.
	 */
	protected function init() {

		$this->post_meta_cache_manager->init();

		// When a user is first added, make sure the subscription cache is empty because it can not have any data yet, and we want to avoid running the query needlessly
		add_filter( 'user_register', array( $this, 'set_empty_cache' ) );

		// When the post for a subscription is change, make sure the corresponding cache is updated
		add_action( 'wcs_update_post_meta_caches', array( $this, 'maybe_update_for_post_meta_change' ), 10, 5 );
		add_action( 'wcs_delete_all_post_meta_caches', array( $this, 'maybe_delete_all_for_post_meta_change' ), 10, 1 );

		WCS_Debug_Tool_Factory::add_cache_tool( 'generator', __( 'Generate Customer Subscription Cache', 'woocommerce-subscriptions' ), __( 'This will generate the persistent cache for linking users with subscriptions. The caches will be generated overtime in the background (via Action Scheduler).', 'woocommerce-subscriptions' ), self::instance() );
		WCS_Debug_Tool_Factory::add_cache_tool( 'eraser', __( 'Delete Customer Subscription Cache', 'woocommerce-subscriptions' ), __( 'This will clear the persistent cache of all of subscriptions stored against users in your store. Expect slower performance of checkout, renewal and other subscription related functions after taking this action. The caches will be regenerated overtime as queries to find a given user\'s subscriptions are run.', 'woocommerce-subscriptions' ), self::instance() );
	}

	/* Public methods required by WCS_Customer_Store */

	/**
	 * Get the IDs for a given user's subscriptions.
	 *
	 * Wrapper to support getting a user's subscription regardless of whether they are cached or not yet,
	 * either in the old transient cache, or new persistent cache.
	 *
	 * @param int $user_id The id of the user whose subscriptions you want.
	 * @return array
	 */
	public function get_users_subscription_ids( $user_id ) {

		$subscription_ids = $this->get_users_subscription_ids_from_cache( $user_id );

		// get user meta returns an empty string when no matching row is found for the given key, meaning it's not set yet
		if ( '' === $subscription_ids ) {

			$transient_key = "wcs_user_subscriptions_{$user_id}";

			// We do this here rather than in get_users_subscription_ids_from_cache(), because we want to make sure the new persistent cache is updated too
			$subscription_ids = get_transient( $transient_key );

			if ( false === $subscription_ids ) {
				$subscription_ids = parent::get_users_subscription_ids( $user_id ); // no data in transient, query directly
			} else {
				delete_transient( $transient_key ); // migrate the data to our new cache
			}

			$this->update_subscription_id_cache( $user_id, $subscription_ids );
		}

		// Sort results in order to keep consistency between cached results and queried results.
		rsort( $subscription_ids );

		return $subscription_ids;
	}

	/* Internal methods for managing the cache */

	/**
	 * Find subscriptions for a given user from the cache.
	 *
	 * Applies the 'wcs_get_cached_users_subscription_ids' filter for backward compatibility with
	 * the now deprecated wcs_get_cached_user_subscription_ids() method.
	 *
	 * @param int $user_id The id of the user whose subscriptions you want.
	 * @return string|array An array of subscriptions in the cache, or an empty string when no matching row is found for the given key, meaning it's cache is not set yet or has been deleted
	 */
	protected function get_users_subscription_ids_from_cache( $user_id ) {

		// Empty user IDs, like 0 or '', are never cached
		$subscription_ids = empty( $user_id ) ? array() : get_user_meta( $user_id, $this->get_cache_meta_key(), true );

		return apply_filters( 'wcs_get_cached_users_subscription_ids', $subscription_ids, $user_id );
	}

	/**
	 * Add a subscription ID to the cached subscriptions for a given user.
	 *
	 * @param int $user_id The user the subscription belongs to.
	 * @param int $subscription_id A subscription to link the user in the cache.
	 */
	protected function add_subscription_id_to_cache( $user_id, $subscription_id ) {

		$subscription_ids = $this->get_users_subscription_ids( $user_id );

		if ( ! in_array( $subscription_id, $subscription_ids ) ) {
			$subscription_ids[] = $subscription_id;
			$this->update_subscription_id_cache( $user_id, $subscription_ids );
		}
	}

	/**
	 * Delete a subscription ID from the cached IDs for a given user.
	 *
	 * @param int $user_id The user the subscription belongs to.
	 * @param int $subscription_id A subscription to link the user in the cache.
	 */
	protected function delete_subscription_id_from_cache( $user_id, $subscription_id ) {

		$subscription_ids = $this->get_users_subscription_ids( $user_id );

		if ( ( $index = array_search( $subscription_id, $subscription_ids ) ) !== false ) {
			unset( $subscription_ids[ $index ] );
			$this->update_subscription_id_cache( $user_id, $subscription_ids );
		}
	}

	/**
	 * Helper function for setting subscription cache.
	 *
	 * @param int $user_id The id of the user who the subscriptions belongs to.
	 * @param array $subscription_ids Set of subscriptions to link with the given user.
	 * @return bool|int Returns meta ID if the key didn't exist; true on successful update; false on failure or if $subscription_ids is the same as the existing meta value in the database.
	 */
	protected function update_subscription_id_cache( $user_id, array $subscription_ids ) {

		// Never cache empty user IDs, like 0 or ''
		if ( empty( $user_id ) ) {
			return false;
		}

		rsort( $subscription_ids ); // the results from the database query are ordered by date/ID in DESC, so make sure the user cached values are ordered the same.
		return update_user_meta( $user_id, $this->get_cache_meta_key(), $subscription_ids );
	}

	/* Public methods used to bulk edit cache */

	/**
	 * Clear all caches for all subscriptions against all users.
	 */
	public function delete_caches_for_all_users() {
		delete_metadata( 'user', null, $this->get_cache_meta_key(), null, true );
	}

	/**
	 * Clears the cache for a given user.
	 *
	 * @param int $user_id The id of the user
	 */
	public function delete_cache_for_user( $user_id ) {
		if ( empty( $user_id ) ) {
			return;
		}

		delete_user_meta( $user_id, $this->get_cache_meta_key() );
	}

	/* Public methods used as callbacks on hooks for managing cache */

	/**
	 * Set empty subscription cache on a user.
	 *
	 * Newly registered users can't have subscriptions yet, so we set that cache to empty whenever a new user is added
	 * by attaching this to the 'user_register' hook.
	 *
	 * @param int $user_id The id of the user just created
	 */
	public function set_empty_cache( $user_id ) {
		$this->update_subscription_id_cache( $user_id, array() );
	}

	/* Public methods attached to WCS_Post_Meta_Cache_Manager_Many_To_One hooks for managing the cache */

	/**
	 * If there is a change to a subscription's post meta key, update the user meta cache.
	 *
	 * @param string $update_type The type of update to check. Can be 'add', 'update' or 'delete'.
	 * @param int $subscription_id The subscription's post ID where the customer is being changed.
	 * @param string $meta_key The post meta key being changed.
	 * @param mixed $user_id The meta value, which will be subscriber's user ID when $meta_key is '_customer_user'.
	 * @param mixed $old_user_id The previous value stored in the database for the subscription's '_customer_user'. Optional.
	 */
	public function maybe_update_for_post_meta_change( $update_type, $subscription_id, $meta_key, $user_id, $old_user_id = '' ) {

		if ( $this->get_meta_key() !== $meta_key ) {
			return;
		}

		switch ( $update_type ) {
			case 'add':
				$this->add_subscription_id_to_cache( $user_id, $subscription_id );
				break;
			case 'delete':
				// If we don't have a specific user ID, the post is being deleted as WCS_Post_Meta_Cache_Manager_Many_To_One doesn't pass the associated meta value for that event, so find the corresponding user ID from post meta directly
				if ( empty( $user_id ) ) {
					$user_id = get_post_meta( $subscription_id, $this->get_meta_key(), true );
				}
				$this->delete_subscription_id_from_cache( $user_id, $subscription_id );
				break;
			case 'update':
				if ( ! empty( $old_user_id ) ) {
					$this->delete_subscription_id_from_cache( $old_user_id, $subscription_id );
				}

				$this->add_subscription_id_to_cache( $user_id, $subscription_id );
				break;
		}
	}

	/**
	 * Remove all caches for a given meta key if all entries for that meta key are being deleted.
	 *
	 * This is very unlikely to ever happen, because it would be equivalent to deleting the linked
	 * customer on all orders and subscriptions. But it is handled here anyway in case of things
	 * like removing WooCommerce entirely.
	 *
	 * @param string $meta_key The post meta key being changed.
	 */
	public function maybe_delete_all_for_post_meta_change( $meta_key ) {
		if ( $this->get_meta_key() === $meta_key ) {
			$this->delete_caches_for_all_users();
		}
	}

	/**
	 * Get the IDs of users without a cache set.
	 *
	 * @param int $number The number of users to return. Use -1 to return all users.
	 * @return array
	 */
	protected function get_user_ids_without_cache( $number = 10 ) {
		return get_users( array(
			'fields'       => 'ids',
			'number'       => $number,
			'meta_key'     => $this->get_cache_meta_key(),
			'meta_compare' => 'NOT EXISTS',
		) );
	}

	/** Methods to implement WCS_Cache_Updater - wrap more accurately named methods for the sake of clarity */

	/**
	 * Get the items to be updated, if any.
	 *
	 * @return array An array of items to update, or empty array if there are no items to update.
	 */
	public function get_items_to_update() {
		return $this->get_user_ids_without_cache();
	}

	/**
	 * Run the update for a single item.
	 *
	 * @param mixed $item The item to update.
	 */
	public function update_items_cache( $user_id ) {
		// Getting the subscription IDs also sets the cache when it's not already set
		$this->get_users_subscription_ids( $user_id );
	}

	/**
	 * Clear all caches.
	 */
	public function delete_all_caches() {
		$this->delete_caches_for_all_users();
	}

	/**
	 * Gets the cache meta key.
	 *
	 * On multi-site installations, the current site ID is appended.
	 *
	 * @since 3.1.0
	 * @return string
	 */
	public function get_cache_meta_key() {
		if ( is_multisite() ) {
			return self::_CACHE_META_KEY . '_' . get_current_blog_id();
		}

		return self::_CACHE_META_KEY;
	}
}
