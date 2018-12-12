<?php
/**
 * Stores facade.
 *
 * @package        WooCommerce Subscriptions
 * @subpackage     WCS_Retry_Store
 * @category       Class
 * @author         Prospress
 * @since          2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WCS_Retry_Stores {
	/**
	 * Where we're saving/migrating our data.
	 *
	 * @var WCS_Retry_Store
	 */
	private static $database_store;

	/**
	 * Where the data comes from.
	 *
	 * @var WCS_Retry_Store
	 */
	private static $post_store;

	/**
	 * Access the object used to interface with the destination store.
	 *
	 * @return WCS_Retry_Store
	 * @since 2.4
	 */
	public static function get_database_store() {
		if ( empty( self::$database_store ) ) {
			$class                = self::get_database_store_class();
			self::$database_store = new $class();
			self::$database_store->init();
		}

		return self::$database_store;
	}

	/**
	 * Get the class used for instantiating retry storage via self::destination_store()
	 *
	 * @return string
	 * @since 2.4
	 */
	public static function get_database_store_class() {
		return apply_filters( 'wcs_retry_database_store_class', 'WCS_Retry_Database_Store' );
	}

	/**
	 * Access the object used to interface with the source store.
	 *
	 * @return WCS_Retry_Store
	 * @since 2.4
	 */
	public static function get_post_store() {
		if ( empty( self::$post_store ) ) {
			$class            = self::get_post_store_class();
			self::$post_store = new $class();
			self::$post_store->init();
		}

		return self::$post_store;
	}

	/**
	 * Get the class used for instantiating retry storage via self::source_store()
	 *
	 * @return string
	 * @since 2.4
	 */
	public static function get_post_store_class() {
		return apply_filters( 'wcs_retry_post_store_class', 'WCS_Retry_Post_Store' );
	}
}
