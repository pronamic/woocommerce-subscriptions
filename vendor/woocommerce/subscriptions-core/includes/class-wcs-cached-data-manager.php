<?php
/**
 * Subscription Cached Data Manager Class
 *
 * @class    WCS_Cached_Data_Manager
 * @version  2.3.0
 * @since    2.1.2
 * @package  WooCommerce Subscriptions/Classes
 * @category Class
 * @author   Prospress
 */
class WCS_Cached_Data_Manager extends WCS_Cache_Manager {

	/**
	 * @var  WC_Logger_Interface|null
	 */
	public $logger = null;

	public function __construct() {
		add_action( 'woocommerce_loaded', array( $this, 'load_logger' ) );

		add_action( 'admin_init', array( $this, 'initialize_cron_check_size' ) ); // setup cron task to truncate big logs.
		add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_schedule' ) ); // create a weekly cron schedule
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
			$data = call_user_func_array( $callback, array_values( $params ) );
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
		wcs_deprecated_function( __METHOD__, '2.3.0' );

		$post_type = get_post_type( $post_id );

		if ( 'shop_order' === $post_type ) {
			wcs_deprecated_argument( __METHOD__, '2.3.0', sprintf( __( 'Related order caching is now handled by %1$s.', 'woocommerce-subscriptions' ), 'WCS_Related_Order_Store' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
			if ( is_callable( array( WCS_Related_Order_Store::instance(), 'delete_related_order_id_from_caches' ) ) ) {
				WCS_Related_Order_Store::instance()->delete_related_order_id_from_caches( $post_id );
			}
		}

		if ( 'shop_subscription' === $post_type ) {
			wcs_deprecated_argument( __METHOD__, '2.3.0', sprintf( __( 'Customer subscription caching is now handled by %1$s.', 'woocommerce-subscriptions' ), 'WCS_Customer_Store_Cached_CPT' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment

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
	 * @param $meta_key string the meta_key in the table, only concerned with the '_customer_user' key
	 * @param $meta_value mixed the ID of the subscription that relates to the order
	 */
	public function purge_from_metadata( $meta_id, $object_id, $meta_key, $meta_value ) {
		wcs_deprecated_argument( __METHOD__, '2.3.0', sprintf( __( 'Customer subscription caching is now handled by %1$s and %2$s.', 'woocommerce-subscriptions' ), 'WCS_Customer_Store_Cached_CPT', 'WCS_Post_Meta_Cache_Manager' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment

		// Ensure we're handling a meta key we actually care about.
		if ( '_customer_user' !== $meta_key || 'shop_subscription' !== get_post_type( $object_id ) ) {
			return;
		}

		$this->purge_subscription_user_cache( $object_id );
	}

	/**
	 * Wrapper function to clear the cache that relates to related orders
	 *
	 * @param null $subscription_id
	 * @deprecated 2.3.0
	 */
	protected function clear_related_order_cache( $subscription_id ) {
		wcs_deprecated_function( __METHOD__, '2.3.0', __( 'new related order methods in WCS_Related_Order_Store', 'woocommerce-subscriptions' ) );

		// if it's not a Subscription, we don't deal with it
		if ( is_object( $subscription_id ) && $subscription_id instanceof WC_Subscription ) {
			$subscription_id = $subscription_id->get_id();
		} elseif ( is_numeric( $subscription_id ) ) {
			$subscription_id = absint( $subscription_id );
		} else {
			return;
		}

		// Clear the new cache, to honour the method call
		if ( is_callable( array( WCS_Related_Order_Store::instance(), 'delete_caches_for_subscription' ) ) ) {
			WCS_Related_Order_Store::instance()->delete_caches_for_subscription( $subscription_id );
		}

		// Clear the old cache, just in case it's still got data
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
		wcs_deprecated_argument( __METHOD__, '2.3.0' );

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
		wcs_deprecated_argument( __METHOD__, '2.3.0', sprintf( __( 'Customer subscription caching is now handled by %1$s and %2$s.', 'woocommerce-subscriptions' ), 'WCS_Customer_Store_Cached_CPT', 'WCS_Post_Meta_Cache_Manager' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment

		$subscription         = wcs_get_subscription( $subscription_id );
		$subscription_user_id = $subscription->get_user_id();
		$this->log( sprintf(
			'Clearing cache for user ID %1$s on %2$s hook.',
			$subscription_user_id,
			current_action()
		) );
		$this->delete_cached( "wcs_user_subscriptions_{$subscription_user_id}" );
	}
}
