<?php
/**
 * WCS_Debug_Tool_Related_Order_Cache_Generator Class
 *
 * Add a debug tool to the WooCommerce > System Status > Tools page for generating a cache.
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
 * WCS_Debug_Tool_Cache_Generator Class
 *
 * Add a debug tool to the WooCommerce > System Status > Tools page for generating a cache.
 */
class WCS_Debug_Tool_Cache_Generator extends WCS_Debug_Tool_Cache_Updater {

	/**
	 * @var WCS_Background_Updater $update The instance used to generate the cache data in the background.
	 */
	protected $cache_updater;

	/**
	 * WCS_Debug_Tool_Cache_Generator constructor.
	 *
	 * @param string $tool_key The key used to add the tool to the array of available tools.
	 * @param string $tool_name The section name given to the tool on the admin screen.
	 * @param string $tool_description The long description for the tool displayed on the admin screen.
	 * @param WCS_Cache_Updater $data_store
	 * @param WCS_Background_Updater $cache_updater
	 */
	public function __construct( $tool_key, $tool_name, $tool_description, WCS_Cache_Updater $data_store, WCS_Background_Updater $cache_updater ) {
		$this->tool_key      = $tool_key;
		$this->data_store    = $data_store;
		$this->cache_updater = $cache_updater;
		$this->tool_data     = array(
			'name'     => $tool_name,
			'button'   => $tool_name,
			'desc'     => $tool_description,
			'callback' => array( $this, 'generate_caches' ),
		);
	}

	/**
	 * Attach callbacks and hooks, if the store supports getting uncached items, which is required to generate cache
	 * and also acts as a proxy to determine if the related order store is using caching
	 */
	public function init() {
		if ( $this->is_data_store_cached() ) {
			parent::init();
			$this->cache_updater->init();
		}
	}

	/**
	 * Generate the data store's cache by calling the @see $this->>cache_updater's update method.
	 */
	public function generate_caches() {
		$this->cache_updater->run_update();
	}
}
