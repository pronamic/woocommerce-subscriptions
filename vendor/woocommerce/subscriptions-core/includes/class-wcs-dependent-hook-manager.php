<?php
/**
 * WooCommerce Subscriptions Dependent Hook Manager
 *
 * An API for attaching callbacks which depend on WC versions.
 *
 * @package  WooCommerce Subscriptions
 * @category Class
 * @author   WooCommerce
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Dependent_Hook_Manager {

	/**
	 * An array of callbacks which need to be attached on for certain WC versions.
	 *
	 * @var array
	 */
	protected static $dependent_callbacks = array();

	/**
	 * Initialise the class.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'attach_woocommerce_dependent_hooks' ) );
	}

	/**
	 * Attach all the WooCommerce version dependent hooks.
	 *
	 * This attaches all the hooks registered via @see add_woocommerce_dependent_action()
	 * if the WooCommerce version requirements are met.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function attach_woocommerce_dependent_hooks() {
		if ( ! isset( self::$dependent_callbacks['woocommerce'] ) ) {
			return;
		}

		foreach ( self::$dependent_callbacks['woocommerce'] as $wc_version => $operators ) {
			foreach ( $operators as $operator => $callbacks ) {

				if ( ! version_compare( WC_VERSION, $wc_version, $operator ) ) {
					continue;
				}

				foreach ( $callbacks as $callback ) {
					add_action( $callback['tag'], $callback['function'], $callback['priority'], $callback['number_of_args'] );
				}
			}
		}
	}

	/**
	 * Attach function callback if a certain WooCommerce version is present.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 *
	 * @param string $tag The action or filter tag to attach the callback too.
	 * @param string|array $function The callable function to attach to the hook.
	 * @param string $woocommerce_version The WooCommerce version to do a compare on. For example '3.0.0'.
	 * @param string $operator The version compare operator to use. @see https://www.php.net/manual/en/function.version-compare.php
	 * @param integer $priority The priority to attach this callback to.
	 * @param integer $number_of_args The number of arguments to pass to the callback function
	 */
	public static function add_woocommerce_dependent_action( $tag, $function, $woocommerce_version, $operator, $priority = 10, $number_of_args = 1 ) {
		// Attach callbacks now if WooCommerce has already loaded.
		if ( did_action( 'plugins_loaded' ) && version_compare( WC_VERSION, $woocommerce_version, $operator ) ) {
			add_action( $tag, $function, $priority, $number_of_args );
			return;
		}

		self::$dependent_callbacks['woocommerce'][ $woocommerce_version ][ $operator ][] = array(
			'tag'            => $tag,
			'function'       => $function,
			'priority'       => $priority,
			'number_of_args' => $number_of_args,
		);
	}
}
