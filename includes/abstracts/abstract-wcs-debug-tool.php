<?php
/**
 * Subscriptions Debug Tool
 *
 * Add tools for debugging and managing Subscriptions to the
 * WooCommerce > System Status > Tools administration screen.
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
 * WCS_Debug_Tool Class
 *
 * Add a debug tool to the WooCommerce > System Status > Tools page.
 */
abstract class WCS_Debug_Tool {

	/**
	 * @var string $tool_key The key used to add the tool to the array of available tools.
	 */
	protected $tool_key;

	/**
	 * @var array $tool_data Data for this tool, containing:
	 *  - 'name': The section name given to the tool
	 *  - 'button': The text displayed on the tool's button
	 *  - 'desc': The long description for the tool.
	 *  - 'callback': The callback used to perform the tool's action.
	 */
	protected $tool_data;

	/**
	 * Attach callbacks to hooks and validate required properties are assigned values.
	 */
	public function init() {

		if ( empty( $this->tool_key ) ) {
			throw new RuntimeException( __CLASS__ . ' must assign a tool key to $this->tool_key' );
		}

		if ( empty( $this->tool_data ) ) {
			throw new RuntimeException( __CLASS__ . ' must assign an array of data about the tool to $this->tool_data' );
		}

		add_filter( 'woocommerce_debug_tools', array( $this, 'add_debug_tools' ) );
	}

	/**
	 * Add subscription related tools to display on the WooCommerce > System Status > Tools administration screen
	 *
	 * @param array $tools Arrays defining the tools displayed on the System Status screen
	 * @return array
	 */
	public function add_debug_tools( $tools ) {
		$tools[ $this->tool_key ] = $this->tool_data;
		return $tools;
	}
}
