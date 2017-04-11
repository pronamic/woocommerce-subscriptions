<?php
/**
 * Subscription Cached Data Manager Class
 *
 * @class    WCS_Cached_Data_Manager
 * @version  2.1.2
 * @package  WooCommerce Subscriptions/Classes
 * @category Class
 * @author   Prospress
 */
class WCS_Cached_Data_Manager extends WCS_Cache_Manager {

	public $logger = null;

	public function __construct() {
		add_action( 'woocommerce_loaded', array( $this, 'load_logger' ) );

		// Add filters for update / delete / trash post to purge cache
		add_action( 'trashed_post', array( $this, 'purge_delete' ), 9999 ); // trashed posts aren't included in 'any' queries
		add_action( 'untrashed_post', array( $this, 'purge_delete' ), 9999 ); // however untrashed posts are
		add_action( 'deleted_post', array( $this, 'purge_delete' ), 9999 ); // if forced delete is enabled
		add_action( 'updated_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to '_subscription_renewal', '_subscription_resubscribe' & '_subscription_switch' keys
		add_action( 'deleted_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to '_subscription_renewal', '_subscription_resubscribe' & '_subscription_switch' keys
		add_action( 'added_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to '_subscription_renewal', '_subscription_resubscribe' & '_subscription_switch' keys
	}

	/**
	 * Attaches logger
	 */
	public function load_logger() {
		$this->logger = new WC_Logger();
	}

	/**
	 * Wrapper function around WC_Logger->log
	 *
	 * @param string $message Message to log
	 */
	public function log( $message ) {
		if ( defined( 'WCS_DEBUG' ) && WCS_DEBUG ) {
			$this->logger->add( 'wcs-cache', $message );
		}
	}

	/**
	 * Helper function for fetching cached data or updating and storing new data provided by callback.
	 *
	 * @param string $key The key to cache/fetch the data with
	 * @param string|array $callback name of function, or array of class - method that fetches the data
	 * @param array $params arguments passed to $callback
	 * @param integer $expires number of seconds to keep the cache. Don't set it to 0, as the cache will be autoloaded. Default is a week.
	 *
	 * @return bool|mixed
	 */
	public function cache_and_get( $key, $callback, $params = array(), $expires = WEEK_IN_SECONDS ) {
		$expires = absint( $expires );
		$data    = get_transient( $key );

		// if there isn't a transient currently stored and we have a callback update function, fetch and store
		if ( false === $data && ! empty( $callback ) ) {
			$data = call_user_func_array( $callback, $params );
			set_transient( $key, $data, $expires );
		}

		return $data;
	}

	/**
	 * Clearing cache when a post is deleted
	 *
	 * @param $post_id integer the ID of a post
	 */
	public function purge_delete( $post_id ) {
		if ( 'shop_order' !== get_post_type( $post_id ) ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_order( $post_id, array( 'order_type' => 'any' ) ) as $subscription ) {
			$this->log( 'Calling purge delete on ' . current_filter() . ' for ' . $subscription->get_id() );
			$this->clear_related_order_cache( $subscription );
		}
	}

	/**
	 * When subscription related metadata is added / deleted / updated on an order, we need to invalidate the subscription related orders cache.
	 *
	 * @param $meta_id integer the ID of the meta in the meta table
	 * @param $object_id integer the ID of the post we're updating on, only concerned with order IDs
	 * @param $meta_key string the meta_key in the table, only concerned with '_subscription_renewal', '_subscription_resubscribe' & '_subscription_switch' keys
	 * @param $meta_value mixed the ID of the subscription that relates to the order
	 */
	public function purge_from_metadata( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( ! in_array( $meta_key, array( '_subscription_renewal', '_subscription_resubscribe', '_subscription_switch' ) ) || 'shop_order' !== get_post_type( $object_id ) ) {
			return;
		}

		$this->log( 'Calling purge from ' . current_filter() . ' on object ' . $object_id . ' and meta value ' . $meta_value . ' due to ' . $meta_key . ' meta key.' );

		$this->clear_related_order_cache( $meta_value );
	}

	/**
	 * Wrapper function to clear the cache that relates to related orders
	 *
	 * @param null $subscription_id
	 */
	protected function clear_related_order_cache( $subscription_id ) {

		// if it's not a Subscription, we don't deal with it
		if ( is_object( $subscription_id ) && $subscription_id instanceof WC_Subscription ) {
			$subscription_id = $subscription_id->get_id();
		} elseif ( is_numeric( $subscription_id ) ) {
			$subscription_id = absint( $subscription_id );
		} else {
			return;
		}

		$key = 'wcs-related-orders-to-' . $subscription_id;

		$this->log( 'In the clearing, key being purged is this: ' . print_r( $key, true ) );

		$this->delete_cached( $key );
	}

	/**
	 * Delete cached data with key
	 *
	 * @param string $key Key that needs deleting
	 */
	public function delete_cached( $key ) {
		if ( ! is_string( $key ) || empty( $key ) ) {
			return;
		}

		delete_transient( $key );
	}

	/* Deprecated Functions */

	/**
	 * Wrapper function to clear cache that relates to related orders
	 *
	 * @param null $subscription_id
	 */
	public function wcs_clear_related_order_cache( $subscription_id = null ) {
		_deprecated_function( __METHOD__, '2.1.2', __CLASS__ . '::clear_related_order_cache( $subscription_id )' );
		$this->clear_related_order_cache( $subscription_id );
	}

	/**
	 * Clearing for orders / subscriptions with sanitizing bits
	 *
	 * @param $post_id integer the ID of an order / subscription
	 */
	public function purge_subscription_cache_on_update( $post_id ) {
		_deprecated_function( __METHOD__, '2.1.2', __CLASS__ . '::clear_related_order_cache( $subscription_id )' );

		$post_type = get_post_type( $post_id );

		if ( 'shop_subscription' === $post_type ) {

			$this->clear_related_order_cache( $post_id );

		} elseif ( 'shop_order' === $post_type ) {

			$subscriptions = wcs_get_subscriptions_for_order( $post_id, array( 'order_type' => 'any' ) );

			if ( empty( $subscriptions ) ) {
				$this->log( 'No subscriptions for this ID: ' . $post_id );
			} else {
				foreach ( $subscriptions as $subscription ) {
					$this->log( 'Got subscription, calling clear_related_order_cache for ' . $subscription->get_id() );
					$this->clear_related_order_cache( $subscription );
				}
			}
		}
	}
}
