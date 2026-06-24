<?php
/**
 * WCS_ATT_Integration_FS class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 3.1.18
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flatsome integration.
 *
 * @version  4.1.5
 */
class WCS_ATT_Integration_FS {

	public static function init() {
		// Add hooks if the active parent theme is Flatsome.
		add_action( 'after_setup_theme', array( __CLASS__, 'maybe_add_hooks' ) );
	}

	/**
	 * Add hooks if the active parent theme is Flatsome.
	 */
	public static function maybe_add_hooks() {

		if ( function_exists( 'flatsome_quickview' ) ) {
			// Initialize bundles in quick view modals.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'add_quickview_integration' ), 999 );
		}
	}

	/**
	 * Initializes subscriptions in quick view modals.
	 *
	 * @return array
	 */
	public static function add_quickview_integration() {

		wp_enqueue_script( 'wcsatt-single-product' );

		wp_register_script( 'wcsatt-flatsome-quickview', WCS_ATT()->plugin_url() . '/assets/js/apfs/integrations/flatsome-quickview.js', array( 'jquery', 'wc-country-select', 'wc-address-i18n' ), WC_Subscriptions::$version, true );
		wp_script_add_data( 'wcsatt-flatsome-quickview', 'strategy', 'defer' );
		wp_enqueue_script( 'wcsatt-flatsome-quickview' );
	}
}
