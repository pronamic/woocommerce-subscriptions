<?php
/**
 * Plugin Name: WooCommerce Subscriptions
 * Plugin URI: https://www.woocommerce.com/products/woocommerce-subscriptions/
 * Description: Sell products and services with recurring payments in your WooCommerce Store.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Version: 4.6.0
 *
 * WC requires at least: 4.4
 * WC tested up to: 6.5.0
 * Woo: 27147:6115e6d7e297b623a169fdcf5728b224
 *
 * Copyright 2019 WooCommerce
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package WooCommerce Subscriptions
 * @author  WooCommerce.
 * @since   1.0
 */

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) || ! function_exists( 'is_woocommerce_active' ) ) {
	require_once dirname( __FILE__ ) . '/woo-includes/woo-functions.php';
}

/**
 * Check if WooCommerce is active and at the required minimum version, and if it isn't, disable Subscriptions.
 *
 * @since 1.0
 */
if ( ! is_woocommerce_active() || version_compare( get_option( 'woocommerce_db_version' ), WC_Subscriptions::$wc_minimum_supported_version, '<' ) ) {
	add_action( 'admin_notices', 'WC_Subscriptions::woocommerce_inactive_notice' );
	return;
}

/**
 * Declare plugin incompatibility with WooCommerce HPOS.
 *
 * @since 4.6.0
 */
add_action(
	'before_woocommerce_init',
	function() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, false );
		}
	}
);

// Subscribe to automated translations.
add_action( 'woocommerce_translations_updates_for_woocommerce-subscriptions', '__return_true' );

// Load and set up the Autoloader
$wcs_autoloader = wcs_init_autoloader();

/**
 * The main subscriptions class.
 *
 * @since 1.0
 */
class WC_Subscriptions {

	/** @var string */
	public static $name = 'subscription';

	/** @var string */
	public static $activation_transient = 'woocommerce_subscriptions_activated';

	/** @var string */
	public static $plugin_file = __FILE__;

	/** @var string */
	public static $version = '4.6.0';

	/** @var string */
	public static $wc_minimum_supported_version = '4.4';

	/** @var WCS_Cache_Manager */
	public static $cache;

	/** @var WCS_Autoloader */
	protected static $autoloader;

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.0
	 *
	 * @param WCS_Autoloader $autoloader Autoloader instance.
	 */
	public static function init( $autoloader = null ) {
		$plugin           = new WC_Subscriptions_Plugin( $autoloader );
		self::$cache      = $plugin->cache;
		self::$autoloader = $plugin->get_autoloader();
	}

	/*
	 * Plugin House Keeping
	 */

	/**
	 * Called when WooCommerce is inactive or running and out-of-date version to display an inactive notice.
	 *
	 * @since 1.2
	 */
	public static function woocommerce_inactive_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			$admin_notice_content = '';

			if ( ! is_woocommerce_active() ) {
				$install_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'install-plugin',
							'plugin' => 'woocommerce',
						),
						admin_url( 'update.php' )
					),
					'install-plugin_woocommerce'
				);

				// translators: 1$-2$: opening and closing <strong> tags, 3$-4$: link tags, takes to woocommerce plugin on wp.org, 5$-6$: opening and closing link tags, leads to plugins.php in admin
				$admin_notice_content = sprintf( esc_html__( '%1$sWooCommerce Subscriptions is inactive.%2$s The %3$sWooCommerce plugin%4$s must be active for WooCommerce Subscriptions to work. Please %5$sinstall & activate WooCommerce &raquo;%6$s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', '<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>', '<a href="' . esc_url( $install_url ) . '">', '</a>' );
			} elseif ( version_compare( get_option( 'woocommerce_db_version' ), self::$wc_minimum_supported_version, '<' ) ) {
				// translators: 1$-2$: opening and closing <strong> tags, 3$: minimum supported WooCommerce version, 4$-5$: opening and closing link tags, leads to plugin admin
				$admin_notice_content = sprintf( esc_html__( '%1$sWooCommerce Subscriptions is inactive.%2$s This version of Subscriptions requires WooCommerce %3$s or newer. Please %4$supdate WooCommerce to version %3$s or newer &raquo;%5$s', 'woocommerce-subscriptions' ), '<strong>', '</strong>', self::$wc_minimum_supported_version, '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">', '</a>' );
			}

			if ( $admin_notice_content ) {
				echo '<div class="error">';
				echo '<p>' . wp_kses_post( $admin_notice_content ) . '</p>';
				echo '</div>';
			}
		}
	}

	/* Deprecated Functions */

	/**
	 * Handle deprecation function calls.
	 *
	 * @since 4.0.0
	 *
	 * @param string $function  The name of the method being called.
	 * @param array  $arguments An array containing the parameters passed to the method.
	 *
	 * @return mixed The value returned from a deprecated function replacement or null.
	 */
	public static function __callStatic( $method, $arguments ) {
		static $deprecation_handler = null;

		// Initialise the handler if we dont have one already.
		if ( ! $deprecation_handler ) {
			$deprecation_handler = new WC_Subscriptions_Deprecation_Handler();
		}

		if ( $deprecation_handler->is_deprecated( $method ) ) {
			$deprecation_handler->trigger_notice( $method );

			return $deprecation_handler->call_replacement( $method, $arguments );
		} else {
			// Trigger an error consistant with PHP if the function called doesn't exist.
			$class = __CLASS__;
			$trace = debug_backtrace();
			$file  = $trace[0]['file'];
			$line  = $trace[0]['line'];
			trigger_error( "Call to undefined method $class::$method() in $file on line $line", E_USER_ERROR ); //phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
		}
	}
}

/**
 * Add woocommerce_inbox_variant for the Remote Inbox Notification.
 *
 * P2 post can be found at https://wp.me/paJDYF-1uJ.
 */
if ( ! function_exists( 'add_woocommerce_inbox_variant' ) ) {
	function add_woocommerce_inbox_variant() {
		$config_name = 'woocommerce_inbox_variant_assignment';
		if ( false === get_option( $config_name, false ) ) {
			update_option( $config_name, wp_rand( 1, 12 ) );
		}
	}
}
add_action( 'woocommerce_subscriptions_upgraded', 'add_woocommerce_inbox_variant', 10 );
register_activation_hook( __FILE__, 'add_woocommerce_inbox_variant' );


/**
 * Load and set up the Autoloader
 *
 * If the `woocommerce-subscriptions-core` plugin is active, setup the autoloader using this plugin directory
 * as the base file path for loading subscription core classes.
 *
 * @since 4.0.0
 * @return WCS_Autoloader
 */
function wcs_init_autoloader() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$wcs_core_plugin_slug = 'woocommerce-subscriptions-core/woocommerce-subscriptions-core.php';
	$is_wcs_core_active   = ( isset( $_GET['action'], $_GET['plugin'] ) && 'activate' === $_GET['action'] && $wcs_core_plugin_slug === $_GET['plugin'] ) || is_plugin_active( $wcs_core_plugin_slug ); //phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$wcs_core_path        = $is_wcs_core_active ? WP_PLUGIN_DIR . '/woocommerce-subscriptions-core/' : dirname( __FILE__ ) . '/vendor/woocommerce/subscriptions-core/';

	require_once $wcs_core_path . 'includes/class-wcs-core-autoloader.php';
	require_once dirname( __FILE__ ) . '/includes/class-wcs-autoloader.php';

	$wcs_autoloader = new WCS_Autoloader( $wcs_core_path );
	$wcs_autoloader->register();

	return $wcs_autoloader;
}

WC_Subscriptions::init( $wcs_autoloader );
