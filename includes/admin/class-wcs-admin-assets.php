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

		// Version the stylesheet by its own file modification time. The webpack
		// asset.php version ($script_asset['version']) is a content hash of the
		// JS bundle only, so CSS-only changes would otherwise reuse the same
		// `?ver` and browsers would serve a stale cached stylesheet.
		$style_admin_path = \WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'build/style-admin.css' );

		wp_enqueue_style(
			'wcs-admin',
			plugins_url( '/build/style-admin.css', WC_Subscriptions::$plugin_file ),
			array( 'wp-components' ),
			file_exists( $style_admin_path ) ? filemtime( $style_admin_path ) : $script_asset['version']
		);

		wp_set_script_translations(
			'wcs-admin',
			'woocommerce-subscriptions',
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'languages'
		);

		self::register_settings_ui_extension();
	}

	/**
	 * Register (but do not enqueue) the modern settings-ui extension bundle.
	 *
	 * The bundle registers Subscriptions field-component overrides with WooCommerce's modern settings renderer
	 * (see client/entrypoints/settings-ui.js). It is enqueued on demand by WooCommerce core when — and only when —
	 * the Subscriptions tab renders through that renderer, via the handles returned from
	 * {@see \Automattic\WooCommerce_Subscriptions\Internal\Admin\Settings\Settings_Page_Adapter::get_script_handles()}.
	 * Registering it here (rather than enqueuing) keeps it off every other admin screen and off the classic tab.
	 *
	 * The `wc-settings-ui` dependency is appended explicitly: the bundle calls `window.wcSettingsUI`, a global
	 * defined by that core handle, and does not import the package, so the auto-generated asset dependencies do not
	 * include it. No-op when the build asset is absent.
	 */
	private static function register_settings_ui_extension() {
		$asset_path = \WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'build/settings-ui.asset.php' );

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		$dependencies = array_merge( $asset['dependencies'], array( 'wc-settings-ui' ) );

		wp_register_script(
			'wcs-settings-ui',
			plugins_url( '/build/settings-ui.js', WC_Subscriptions::$plugin_file ),
			$dependencies,
			$asset['version'],
			true
		);

		wp_set_script_translations(
			'wcs-settings-ui',
			'woocommerce-subscriptions',
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'languages'
		);
	}
}
