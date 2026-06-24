<?php
/**
 * WCS_ATT_Management class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles subscription object management functions, e.g. add, edit/switch, delete.
 *
 * @class    WCS_ATT_Management
 * @version  3.2.0
 */
class WCS_ATT_Management extends WCS_ATT_Abstract_Module {

	/**
	 * Register modules.
	 */
	protected function register_modules() {

		// Initialize modules.
		$this->modules = apply_filters(
			'wcsatt_management_modules',
			array(
				'manage_add'    => 'WCS_ATT_Manage_Add',
				'manage_switch' => 'WCS_ATT_Manage_Switch',
			)
		);
	}
}
