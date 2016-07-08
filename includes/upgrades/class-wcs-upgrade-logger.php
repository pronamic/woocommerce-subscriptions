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

		add_action( 'woocommerce_subscriptions_clear_upgrade_log', __CLASS__ . '::clear' );
	}

	/**
	 * Add an entry to the log
	 *
	 * @param string $message
	 */
	public static function add( $message ) {
		if ( empty( self::$log ) ) {
			self::$log = new WC_Logger(); // can't use __get() no a static property unfortunately
		}
		self::$log->add( self::$handle, $message );
	}

	/**
	 * Clear entries from the upgrade log.
	 */
	public static function clear() {
		if ( empty( self::$log ) ) {
			self::$log = new WC_Logger();
		}
		self::$log->clear( self::$handle );
	}

	/**
	 * Schedule a hook to automatically clear the log after 8 weeks
	 */
	public static function schedule_cleanup() {
		$time_to_cleanup = gmdate( 'U' ) + self::$weeks_until_cleanup * WEEK_IN_SECONDS;
		self::add( sprintf( 'Upgrade complete. Scheduling log cleanup for %s GMT/UTC', date( 'Y-m-d H:i:s', $time_to_cleanup ) ) );
		wc_schedule_single_action( $time_to_cleanup, 'woocommerce_subscriptions_clear_upgrade_log' );
	}
}
WCS_Upgrade_Logger::init();
