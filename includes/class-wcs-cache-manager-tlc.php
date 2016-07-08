<?php
/**
 * Subscription Cache Manager Class using TLC transients
 *
 * Implements methods to deal with the soft caching layer
 *
 * @class    WCS_Cache_Manager_TLC
 * @version  2.0
 * @package  WooCommerce Subscriptions/Classes
 * @category Class
 * @author   Gabor Javorszky
 */
class WCS_Cache_Manager_TLC extends WCS_Cache_Manager {

	public $logger = null;

	public function __construct() {
		add_action( 'woocommerce_loaded', array( $this, 'load_logger' ) );

		// Add filters for update / delete / trash post to purge cache
		add_action( 'trashed_post', array( $this, 'purge_delete' ), 9999 ); // trashed posts aren't included in 'any' queries
		add_action( 'untrashed_post', array( $this, 'purge_delete' ), 9999 ); // however untrashed posts are
		add_action( 'deleted_post', array( $this, 'purge_delete' ), 9999 ); // if forced delete is enabled
		add_action( 'updated_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to _subscription_renewal
		add_action( 'deleted_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to _subscription_renewal
		add_action( 'added_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to _subscription_renewal
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
	 * Wrapper function around our cache library.
	 *
	 * @param string $key The key to cache the data with
	 * @param string|array $callback name of function, or array of class - method that fetches the data
	 * @param array $params arguments passed to $callback
	 *
	 * @return bool|mixed
	 */
	public function cache_and_get( $key, $callback, $params = array(), $expires = 0 ) {
		$expires = absint( $expires );

		$transient = tlc_transient( $key )
			->updates_with( $callback, $params );

		if ( $expires ) {
			$transient->expires_in( $expires );
		}

		return $transient->get();
	}

	/**
	 * Clearing for orders / subscriptions with sanitizing bits
	 *
	 * @param $post_id integer the ID of an order / subscription
	 */
	public function purge_subscription_cache_on_update( $post_id ) {
		$post_type = get_post_type( $post_id );

		if ( 'shop_subscription' !== $post_type && 'shop_order' !== $post_type ) {
			return;
		}

		if ( 'shop_subscription' === $post_type ) {
			$this->log( 'ID is subscription, calling wcs_clear_related_order_cache for ' . $post_id );

			$this->wcs_clear_related_order_cache( $post_id );
		} else {

			$this->log( 'ID is order, getting subscription.' );

			$subscription = wcs_get_subscriptions_for_order( $post_id );

			if ( empty( $subscription ) ) {
				$this->log( 'No sub for this ID: ' . $post_id );
				return;
			}
			$subscription = array_shift( $subscription );

			$this->log( 'Got subscription, calling wcs_clear_related_order_cache for ' . $subscription->id );

			$this->wcs_clear_related_order_cache( $subscription->id );
		}
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

		$linked_subscription = get_post_meta( $post_id, '_subscription_renewal', false );

		// don't call this if there's nothing to call on
		if ( $linked_subscription ) {
			$this->log( 'Calling purge from ' . current_filter() . ' on ' . $linked_subscription[0] );
			$this->purge_subscription_cache_on_update( $linked_subscription[0] );
		}
	}

	/**
	 * When the _subscription_renewal metadata is added / deleted / updated on the Order, we need to initiate cache invalidation for both the new
	 * value of the meta ($_meta_value), and the object it's being added to: $object_id.
	 *
	 * @param $meta_id integer the ID of the meta in the meta table
	 * @param $object_id integer the ID of the post we're updating on
	 * @param $meta_key string the meta_key in the table
	 * @param $_meta_value mixed the value we're deleting / adding / updating
	 */
	public function purge_from_metadata( $meta_id, $object_id, $meta_key, $_meta_value ) {
		if ( '_subscription_renewal' !== $meta_key || 'shop_order' !== get_post_type( $object_id ) ) {
			return;
		}

		$this->log( 'Calling purge from ' . current_filter() . ' on object ' . $object_id . ' and meta value ' . $_meta_value . ' due to _subscription_renewal meta.' );

		$this->purge_subscription_cache_on_update( $_meta_value );
		$this->purge_subscription_cache_on_update( $object_id );
	}

	/**
	 * Wrapper function to clear cache that relates to related orders
	 *
	 * @param null $id
	 */
	public function wcs_clear_related_order_cache( $id = null ) {
		// if nothing was passed in, there's nothing to delete
		if ( null === $id ) {
			return;
		}

		// if it's not a Subscription, we don't deal with it
		if ( is_object( $id ) && $id instanceof WC_Subscription ) {
			$id = $id->id;
		} elseif ( is_numeric( $id ) ) {
			$id = absint( $id );
		} else {
			return;
		}

		$key = tlc_transient( 'wcs-related-orders-to-' . $id )->key;

		$this->log( 'In the clearing, key being purged is this: ' . "\n\n{$key}\n\n" );

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
		// have to do this manually for now
		delete_transient( 'tlc__' . $key );
		delete_transient( 'tlc_up__' . $key );
	}
}
