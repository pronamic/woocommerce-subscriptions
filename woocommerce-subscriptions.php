<?php
/**
 * Plugin Name: WooCommerce Subscriptions
 * Plugin URI: https://www.woocommerce.com/products/woocommerce-subscriptions/
 * Description: Sell products and services with recurring payments in your WooCommerce Store.
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 * Version: 9.2.0
 * Requires Plugins: woocommerce
 *
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 7.4
 *
 * WC requires at least: 11.0
 * WC tested up to: 11.1
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Copyright: © 2025 WooCommerce
 *
 * @package WooCommerce Subscriptions
 * @author  WooCommerce.
 * @since   1.0
 * Woo: 27147:6115e6d7e297b623a169fdcf5728b224

 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/class-wc-subscriptions-dependency-manager.php';
$dependency_manager = new WC_Subscriptions_Dependency_Manager( WC_Subscriptions::$wc_minimum_supported_version );

// Clear the cached WooCommerce version whenever the active plugin lists change. This closes the stale-cache window
// on manual deactivation and on the classic updater's silent deactivate_plugins( $plugin, true ), which skips the
// deactivation hooks but still updates the option. Registered before the dependency gate below so the listeners are
// attached even on requests where the gate fails. On multisite, the network-wide listener only clears the transient
// of the site the request runs on; other subsites keep their cached version until the 1h TTL expires, and stay safe
// in the meantime because the dependency manager re-checks WooCommerce presence on every request before trusting it.
add_action( 'update_option_active_plugins', [ $dependency_manager, 'delete_woocommerce_active_version_cache' ] );
add_action( 'update_site_option_active_sitewide_plugins', [ $dependency_manager, 'delete_woocommerce_active_version_cache' ] );

// Check the dependencies before loading the plugin. If the dependencies are not met, display an admin notice and exit
if ( ! $dependency_manager->has_valid_dependencies() ) {
	add_action( 'admin_notices', [ $dependency_manager, 'display_dependency_admin_notice' ] );
	return;
}

/**
 * Declare plugin compatibility with WooCommerce HPOS.
 *
 * @since 4.9.0
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'woocommerce_init',
	function () {
		// Overrides the WooCommerce version to the WC()->version.
		// This prevents the wrong version being stored in the transient during a WooCommerce core downgrade.
		set_transient( WC_Subscriptions_Dependency_Manager::WC_ACTIVE_VERSION_TRANSIENT, WC()->version, HOUR_IN_SECONDS );
	}
);

// Subscribe to automated translations.
add_filter( 'woocommerce_translations_updates_for_woocommerce-subscriptions', '__return_true' );

/**
 * The main subscriptions class.
 *
 * @since 1.0
 */
class WC_Subscriptions {
	/**
	 * Name of the option field used to store the active plugin version. This is principally
	 * used in support of plugin update logic.
	 */
	public const PLUGIN_VERSION_OPTION_NAME = 'wcs_plugin_version';

	/** @var string */
	public static $name = 'subscription';

	/** @var string */
	public static $activation_transient = 'woocommerce_subscriptions_activated';

	/** @var string */
	public static $plugin_file = __FILE__;

	/**
	 * The version this install reports and stores.
	 *
	 * During a release candidate this deliberately differs from the `Version:` plugin header
	 * above: the header carries the `-rcN` suffix, this does not. The header is what WordPress
	 * and the WooCommerce.com updater compare, so it has to sort below the final release or a
	 * tester is never offered the update. This value is what
	 * {@see WC_Subscriptions_Upgrader::upgrade_complete()} writes into the `wcs_plugin_version`
	 * option, and every upgrade routine gates on that option - carrying `-rcN` here would make
	 * `version_compare( '9.2.0-rc2', '9.2.0', '<' )` true and re-run the whole 9.2.0 upgrade
	 * block when a tester updates to the release, on a store that has been in use for a week.
	 *
	 * Do not "fix" the mismatch. See `.ai/commands/release-candidate.md`.
	 *
	 * @var string
	 */
	public static $version = '9.2.0'; // WRCS: DEFINED_VERSION.

	/** @var string */
	public static $wc_minimum_supported_version = '7.7';

	/** @var WCS_Cache_Manager */
	public static $cache;

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.0
	 * @since 8.8.0 The $autoloader parameter is no longer used; classes are loaded via Composer.
	 *
	 * @param mixed $autoloader Unused. Retained for backwards compatibility.
	 */
	public static function init( $autoloader = null ) {
		$plugin      = new WC_Subscriptions_Plugin( $autoloader );
		self::$cache = $plugin->cache;
	}

	/*
	 * Plugin House Keeping
	 */

	/**
	 * Called when WooCommerce is inactive or running and out-of-date version to display an inactive notice.
	 *
	 * @deprecated 5.0.0
	 *
	 * @since 1.2
	 */
	public static function woocommerce_inactive_notice() {
		_deprecated_function( __METHOD__, '5.0.0', 'WC_Subscriptions_Dependency_Manager::display_dependency_admin_notice' );
		$dependency_manager = new WC_Subscriptions_Dependency_Manager( self::$wc_minimum_supported_version );
		$dependency_manager->display_dependency_admin_notice();
	}

	/* Deprecated Functions */

	/**
	 * Handle deprecation function calls.
	 *
	 * @since 4.0.0
	 *
	 * @param string $method    The name of the method being called.
	 * @param array  $arguments An array containing the parameters passed to the method.
	 *
	 * @return void|mixed The value returned from a deprecated function replacement or null.
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
			$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 1 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace, QITStandard.PHP.DebugCode.DebugFunctionFound -- Reports the caller in the error below, mirroring PHP's native undefined-method message. Not leftover debug output.
			$file  = $trace[0]['file'];
			$line  = $trace[0]['line'];
			throw new Error( "Call to undefined method $class::$method() in $file on line $line" ); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

WC_Subscriptions::init();
