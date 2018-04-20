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
		add_action( 'before_delete_post', array( $this, 'purge_delete' ), 9999 ); // if forced delete is enabled
		add_action( 'update_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 );
		add_action( 'updated_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to '_subscription_renewal', '_subscription_resubscribe' & '_subscription_switch' keys
		add_action( 'deleted_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to '_subscription_renewal', '_subscription_resubscribe' & '_subscription_switch' keys
		add_action( 'added_post_meta', array( $this, 'purge_from_metadata' ), 9999, 4 ); // tied to '_subscription_renewal', '_subscription_resubscribe' & '_subscription_switch' keys

		add_action( 'admin_init', array( $this, 'initialize_cron_check_size' ) ); // setup cron task to truncate big logs.
		add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_schedule' ) ); // create a weekly cron schedule

		// Add actions to handle cache purge for users.
		add_action( 'save_post', array( $this, 'purge_delete' ), 9999, 2 );
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
		if ( is_object( $this->logger ) && defined( 'WCS_DEBUG' ) && WCS_DEBUG ) {
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
	 * @param int     $post_id The ID of a post
	 * @param WP_Post $post    The post object (on certain hooks).
	 */
	public function purge_delete( $post_id, $post = null ) {
		$post_type = get_post_type( $post_id );
		if ( 'shop_order' === $post_type ) {
			foreach ( wcs_get_subscriptions_for_order( $post_id, array( 'order_type' => 'renewal' ) ) as $subscription ) {
				$this->log( 'Calling purge delete on ' . current_filter() . ' for ' . $subscription->get_id() );
				$this->clear_related_order_cache( $subscription );
			}
		}

		if ( 'shop_subscription' === $post_type ) {
			// Purge wcs_do_subscriptions_exist cache, but only on the before_delete_post hook.
			if ( doing_action( 'before_delete_post' ) ) {
				$this->log( "Subscription {$post_id} deleted. Purging subscription cache." );
				$this->delete_cached( 'wcs_do_subscriptions_exist' );
			}

			// Purge cache for a specific user on the save_post hook.
			if ( doing_action( 'save_post' ) ) {
				$this->purge_subscription_user_cache( $post_id );
			}
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
		static $combined_keys = null;
		static $order_keys = array(
			'_subscription_renewal'     => 1,
			'_subscription_resubscribe' => 1,
			'_subscription_switch'      => 1,
		);
		static $subscription_keys = array(
			'_customer_user' => 1,
		);

		if ( null === $combined_keys ) {
			$combined_keys = array_merge( $order_keys, $subscription_keys );
		}

		// Ensure we're handling a meta key we actually care about.
		if ( ! isset( $combined_keys[ $meta_key ] ) ) {
			return;
		}

		if ( 'shop_order' === get_post_type( $object_id ) && isset( $order_keys[ $meta_key ] ) ) {
			$this->log( sprintf(
				'Calling purge from %1$s on object %2$s and meta value %3$s due to %4$s meta key.',
				current_filter(),
				$object_id,
				$meta_value,
				$meta_key
			) );
			$this->clear_related_order_cache( $meta_value );
		} elseif ( 'shop_subscription' === get_post_type( $object_id ) && isset( $subscription_keys[ $meta_key ] ) ) {
			$this->purge_subscription_user_cache( $object_id );
		}
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
	 *
	 * @return bool
	 */
	public function delete_cached( $key ) {
		if ( ! is_string( $key ) || empty( $key ) ) {
			return false;
		}

		return delete_transient( $key );
	}

	/**
	 * If the log is bigger than a threshold it will be
	 * truncated to 0 bytes.
	 */
	public static function cleanup_logs() {
		$file = wc_get_log_file_path( 'wcs-cache' );
		$max_cache_size = apply_filters( 'wcs_max_log_size', 50 * 1024 * 1024 );

		if ( filesize( $file ) >= $max_cache_size ) {
			$size_to_keep = apply_filters( 'wcs_log_size_to_keep', 25 * 1024 );
			$lines_to_keep = apply_filters( 'wcs_log_lines_to_keep', 1000 );

			$fp = fopen( $file, 'r' );
			fseek( $fp, -1 * $size_to_keep, SEEK_END );
			$data = '';
			while ( ! feof( $fp ) ) {
				$data .= fread( $fp, $size_to_keep );
			}
			fclose( $fp );

			// Remove first line (which is probably incomplete) and also any empty line
			$lines = explode( "\n", $data );
			$lines = array_filter( array_slice( $lines, 1 ) );
			$lines = array_slice( $lines, -1000 );
			$lines[] = '---- log file automatically truncated ' . gmdate( 'Y-m-d H:i:s' ) . ' ---';

			file_put_contents( $file, implode( "\n", $lines ), LOCK_EX );
		}
	}

	/**
	 * Check once each week if the log file has exceeded the limits.
	 *
	 * @since 2.2.9
	 */
	public function initialize_cron_check_size() {

		$hook = 'wcs_cleanup_big_logs';

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), 'weekly', $hook );
		}

		add_action( $hook, __CLASS__ . '::cleanup_logs' );
	}

	/**
	 * Add a weekly schedule for clearing up the cache
	 *
	 * @param $scheduled array
	 * @since 2.2.9
	 */
	function add_weekly_cron_schedule( $schedules ) {

		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Weekly', 'woocommerce-subscriptions' ),
			);
		}

		return $schedules;
	}

	/**
	 * Purge the cache for the subscription's user.
	 *
	 * @author Jeremy Pry
	 *
	 * @param int $subscription_id The subscription to purge.
	 */
	protected function purge_subscription_user_cache( $subscription_id ) {
		$subscription         = wcs_get_subscription( $subscription_id );
		$subscription_user_id = $subscription->get_user_id();
		$this->log( sprintf(
			'Clearing cache for user ID %1$s on %2$s hook.',
			$subscription_user_id,
			current_action()
		) );
		$this->delete_cached( "wcs_user_subscriptions_{$subscription_user_id}" );
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
