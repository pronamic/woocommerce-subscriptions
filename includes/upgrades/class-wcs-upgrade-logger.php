<?php
/**
 * Class for logging data during the upgrade process
 *
 * @author		Prospress
 * @category	Admin
 * @package		WooCommerce Subscriptions/Admin/Upgrades
 * @version		2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_Logger {

	/** @var WC_Logger instance */
	protected static $log = false;

	/** @var string File handle */
	public static $handle = 'wcs-upgrade';

	/** @var string File handle */
	public static $weeks_until_cleanup = 8;

	public static function init() {

		add_action( 'woocommerce_subscriptions_upgraded', __CLASS__ . '::schedule_cleanup' );
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

		if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {

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
	 * Schedule a hook to automatically clear the log after 8 weeks
	 */
	public static function schedule_cleanup() {
		self::add( sprintf( '%s upgrade complete.', WC_Subscriptions::$version ) );
	}
}
WCS_Upgrade_Logger::init();
