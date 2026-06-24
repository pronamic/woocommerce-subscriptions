<?php
/**
 * WCS_ATT_Integration_NYP class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.3.1
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility with Name Your Price.
 *
 * @class    WCS_ATT_Integration_NYP
 * @version  2.3.1
 */
class WCS_ATT_Integration_NYP {

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Hooks for NYP support.
	 */
	private static function add_hooks() {

		// Clear discount data if NYP is enabled.
		add_filter( 'wcsatt_product_subscription_schemes', array( __CLASS__, 'reset_discount_data' ), 10, 3 );

		// Use alternative method to render variation options.
		add_filter( 'wcsatt_modify_variation_data_price_html', array( __CLASS__, 'modify_variation_data_price_html' ), 10, 3 );
	}

	/**
	 * Helper function to prevent subscription plan option prices from appearing as empty strings.
	 * Prevents NYP from emptying price strings + makes empty price string go through WCS price filters.
	 */
	public static function before_subscription_option_get_price_html() {
		// Add filter to prevent NYP from emptying the price string.
		if ( is_callable( array( 'WC_Name_Your_Price_Compatibility', 'is_nyp_gte' ) ) && WC_Name_Your_Price_Compatibility::is_nyp_gte( '3.0' ) ) {
			add_filter( 'wc_nyp_is_nyp', '__return_false' );
		} else {
			add_filter( 'woocommerce_is_nyp', '__return_false' );
		}
		// Add filter to make empty price string go through WCS price filters.
		add_filter( 'woocommerce_empty_price_html', array( __CLASS__, 'before_subscription_option_empty_price_html' ), 10, 2 );
	}

	/**
	 * See 'before_subscription_option_get_price_html'.
	 */
	public static function after_subscription_option_get_price_html() {
		// Remove filter that prevents NYP from emptying the price string.
		if ( is_callable( array( 'WC_Name_Your_Price_Compatibility', 'is_nyp_gte' ) ) && WC_Name_Your_Price_Compatibility::is_nyp_gte( '3.0' ) ) {
			remove_filter( 'wc_nyp_is_nyp', '__return_false' );
		} else {
			remove_filter( 'woocommerce_is_nyp', '__return_false' );
		}
		// Remove filter that makes empty price string go through WCS price filters.
		remove_filter( 'woocommerce_empty_price_html', array( __CLASS__, 'before_subscription_option_empty_price_html' ), 10 );
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Makes empty price string go through WCS price filters.
	 *
	 * @param  string     $price_html
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function before_subscription_option_empty_price_html( $price_html, $product ) {
		return ' ';
	}

	/**
	 * Clear discount data if NYP is enabled.
	 *
	 * @param  array      $schemes
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function reset_discount_data( $schemes, $product ) {

		if ( WC_Name_Your_Price_Helpers::is_nyp( $product ) ) {
			foreach ( $schemes as $scheme ) {
				$scheme->set_pricing_mode( 'inherit' );
				$scheme->set_discount( '' );
			}
		}

		return $schemes;
	}

	/**
	 * Use alternative method to render variation options.
	 *
	 * @param  bool                $modify
	 * @param  WC_Product_Variable $variable_product
	 * @return bool
	 */
	public static function modify_variation_data_price_html( $modify, $variable_product ) {

		if ( WC_Name_Your_Price_Helpers::has_nyp( $variable_product ) ) {
			$modify = false;
		}

		return $modify;
	}
}
