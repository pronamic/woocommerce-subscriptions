<?php
/**
 * Class for logging data during the upgrade process
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_Logger {

	/** @var WC_Logger_Interface instance */
	protected static $log = false;

	/** @var string File handle */
	public static $handle = 'wcs-upgrade';

	/** @var string File handle */
	public static $weeks_until_cleanup = 8;

	public static function init() {

		add_action( 'woocommerce_subscriptions_upgraded', array( __CLASS__, 'schedule_cleanup' ), 10, 2 );
		add_action( 'woocommerce_subscriptions_upgraded', array( __CLASS__, 'add_more_info' ), 10 );
	}

	/**
	 * Add an entry to the log
	 *
	 * @param string $message
	 */
	public static function add( $message, $handle = '' ) {
		$handle = ( '' === $handle ) ? self::$handle : $handle;

		if ( empty( self::$log ) ) {
			self::$log = new WC_Logger(); // can't use __get() no a static property unfortunately
		}
		self::$log->add( $handle, $message );
	}

	/**
	 * Clear entries from the upgrade log.
	 */
	public static function clear() {

		if ( wcs_is_woocommerce_pre( '3.0' ) ) {

			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}

			self::$log->clear( self::$handle );

		} else {

			$handler = new WC_Log_Handler_File();

			$handler->clear( self::$handle );
		}
	}

	/**
	 * Log more information during upgrade: Information about environment and active plugins
	 *
	 * @since 2.5.0
	 */
	public static function add_more_info() {
		global $wp_version;

		self::add( sprintf( 'Environment info:' ) );
		self::add( sprintf( '    WordPress Version : %s', $wp_version ) );

		$active_plugins = get_option( 'active_plugins' );

		// Check if get_plugins() function exists. This is required on the front end of the site.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		self::add( sprintf( 'Active Plugins:' ) );

		foreach ( $active_plugins as $plugin ) {
			$author = empty( $all_plugins[ $plugin ]['Author'] ) ? 'Unknown' : $all_plugins[ $plugin ]['Author'];
			$version = empty( $all_plugins[ $plugin ]['Version'] ) ? 'Unknown version' : $all_plugins[ $plugin ]['Version'];
			self::add( sprintf( '    %s by %s – %s', $all_plugins[ $plugin ]['Name'], $author, $version ) );
		}
	}

	/**
	 * Schedule a hook to automatically clear the log after 8 weeks
	 */
	public static function schedule_cleanup( $current_version, $old_version ) {
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'undefined';
		self::add( sprintf( '%s upgrade complete from Subscriptions v%s while WooCommerce WC_VERSION %s and database version %s was active.', $current_version, $old_version, $wc_version, get_option( 'woocommerce_db_version' ) ) );
	}
}

