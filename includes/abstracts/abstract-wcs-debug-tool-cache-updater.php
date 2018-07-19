<?php
/**
 * WCS_Debug_Tool_Cache_Updater Class
 *
 * Shared methods for tool on the WooCommerce > System Status > Tools page that need to
 * update a cached data store's cache.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  2.3
 * @since    2.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * WCS_Debug_Tool_Cache_Updater Class
 *
 * Shared methods for tool on the WooCommerce > System Status > Tools page that need to
 * update a cached data store's cache.
 */
abstract class WCS_Debug_Tool_Cache_Updater extends WCS_Debug_Tool {

	/**
	 * @var mixed $data_Store The store used for updating the cache.
	 */
	protected $data_store;

	/**
	 * Attach callbacks and hooks, if the class's data store is using caching.
	 */
	public function init() {
		if ( $this->is_data_store_cached() ) {
			parent::init();
		}
	}

	/**
	 * Check if the store is a cache updater, and has methods required to erase or generate cache.
	 */
	protected function is_data_store_cached() {
		return is_a( $this->data_store, 'WCS_Cache_Updater' );
	}
}
