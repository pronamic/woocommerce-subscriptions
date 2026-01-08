<?php
/**
 * WCS_Admin_Assets Class
 *
 * Handles admin assets (scripts and styles) for WooCommerce Subscriptions.
 *
 * @package WooCommerce Subscriptions/Admin
 */
class WCS_Admin_Assets {

	/**
	 * Initialize the tour handler
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue required scripts and styles
	 */
	public static function enqueue_scripts() {
		$script_asset_path = \WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'build/admin.asset.php' );
		$script_asset      = file_exists( $script_asset_path )
			? require $script_asset_path
			: array(
				'dependencies' => array(
					'react',
					'wc-blocks-checkout',
					'wc-price-format',
					'wc-settings',
					'wp-element',
					'wp-i18n',
					'wp-plugins',
				),
				'version'      => WC_Subscriptions::$version,
			);

		wp_enqueue_script(
			'wcs-admin',
			plugins_url( '/build/admin.js', WC_Subscriptions::$plugin_file ),
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		wp_enqueue_style(
			'wcs-admin',
			plugins_url( '/build/style-admin.css', WC_Subscriptions::$plugin_file ),
			array( 'wp-components' ),
			$script_asset['version']
		);

		wp_set_script_translations(
			'wcs-admin',
			'woocommerce-subscriptions',
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'languages'
		);
	}
}
