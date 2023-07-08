<?php
/**
 * Abstract Subscription Cache Manager Class
 *
 * Implements methods to deal with the soft caching layer
 *
 * @class    WCS_Cache_Manager
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 * @package  WooCommerce Subscriptions/Classes
 * @category Class
 * @author   Gabor Javorszky
 */
abstract class WCS_Cache_Manager {

	final public static function get_instance() {
		/**
		 * Modeled after WP_Session_Tokens
		 */
		$manager = apply_filters( 'wcs_cache_manager_class', 'WCS_Cached_Data_Manager' );
		return new $manager();
	}

	/**
	 * WCS_Cache_Manager constructor.
	 *
	 * Loads the logger if it's not overwritten.
	 */
	abstract function __construct();

	/**
	 * Initialises some form of logger
	 */
	abstract public function load_logger();

	/**
	 * This method should implement adding to the log file
	 * @return mixed
	 */
	abstract public function log( $message );

	/**
	 * Caches and returns data. Implementation can vary by classes.
	 *
	 * @return mixed
	 */
	abstract public function cache_and_get( $key, $callback, $params = array(), $expires = WEEK_IN_SECONDS );

	/**
	 * Deletes a cached version of data.
	 *
	 * @return mixed
	 */
	abstract public function delete_cached( $key );
}
