<?php
/**
 * WCS_ATT_Integration_Blocks class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 3.3.2
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Blocks Compatibility.
 *
 * @version 3.3.2
 */
class WCS_ATT_Integration_Blocks {

	/**
	 * Initialize.
	 */
	public static function init() {

		if ( ! did_action( 'woocommerce_blocks_loaded' ) ) {
			return;
		}

		WCS_ATT_Store_API::init();
	}
}
