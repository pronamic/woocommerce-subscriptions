<?php
/**
 * WCS_ATT_Integration_PAO class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.3.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility with Product Add-Ons.
 *
 * @class    WCS_ATT_Integration_PAO
 * @version  6.0.6
 */
class WCS_ATT_Integration_PAO {

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Hooks for PAO support.
	 */
	private static function add_hooks() {

		// Add price data to one-time option.
		add_filter( 'wcsatt_single_product_one_time_option_data', array( __CLASS__, 'maybe_add_one_time_option_price_data' ), 10, 3 );

		// Add price data to subscription options.
		add_filter( 'wcsatt_single_product_subscription_option_data', array( __CLASS__, 'maybe_add_subscription_option_price_data' ), 10, 4 );

		// Aggregate add-ons costs and calculate them after APFS has applied discounts.
		add_action( 'wcsatt_applied_cart_item_subscription_scheme', array( __CLASS__, 'backup_addons_price' ), 10 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'sync_price_offset' ), 10 );

		// Do not calculate scheme option prices when discounting addons.
		add_filter( 'wcsatt_single_product_subscription_option_price_html_args', array( __CLASS__, 'show_option_discount' ), 10, 4 );
		add_filter( 'wcsatt_single_product_subscription_dropdown_option_price_html_args', array( __CLASS__, 'show_option_discount' ), 10, 4 );

		// Add flag to adjust form elements depending on whether plans are allowed to discount add-ons.
		add_filter( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'add_discount_addons_data' ), 10 );

		// Discount add-ons of single-plan forced-subscription products.
		add_filter( 'woocommerce_product_addons_option_price_raw', array( __CLASS__, 'filter_addons_price' ), 10 );

		// Use alternative method to render variation options.
		add_filter( 'wcsatt_modify_variation_data_price_html', array( __CLASS__, 'modify_variation_data_price_html' ), 10, 3 );
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Whether to apply price discounts after addons have been added to the product price.
	 * Important: Does not work with "Override Price" plans.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function discount_addons( $product ) {

		$schemes         = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
		$discount_addons = false === WCS_ATT_Product_Schemes::price_filter_exists( $schemes, WCS_ATT_Scheme::MODE_OVERRIDE );

		return apply_filters( 'wcsatt_discount_addons', $discount_addons, $product );
	}

	/**
	 * Used to tell if a product has (required) addons.
	 *
	 * @since  APFS 2.3.0
	 *
	 * @param  mixed   $product
	 * @param  boolean $required
	 * @return boolean
	 */
	public static function has_addons( $product, $required = false ) {

		if ( is_object( $product ) && is_a( $product, 'WC_Product' ) ) {
			$product_id = $product->get_id();
		} else {
			$product_id = absint( $product );
		}

		$has_addons = false;
		$cache_key  = 'product_addons_' . $product_id;

		$addons = WCS_ATT_Helpers::cache_get( $cache_key );

		if ( is_null( $addons ) ) {
			$addons = WC_Product_Addons_Helper::get_product_addons( $product_id, false, false );
			WCS_ATT_Helpers::cache_set( $cache_key, $addons );
		}

		if ( ! empty( $addons ) ) {

			if ( $required ) {

				foreach ( $addons as $addon ) {

					$type = ! empty( $addon['type'] ) ? $addon['type'] : '';

					if ( 'heading' !== $type && isset( $addon['required'] ) && '1' == $addon['required'] ) {
						$has_addons = true;
						break;
					}
				}
			} else {
				$has_addons = true;
			}
		}

		return $has_addons;
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks - Application
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add price data to one-time option.
	 *
	 * @param  array      $data
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function maybe_add_one_time_option_price_data( $data, $product, $parent_product ) {
		return self::maybe_add_option_price_data( $data, false, $product, $parent_product );
	}

	/**
	 * Add price data to subscription options.
	 *
	 * @param  array      $data
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function maybe_add_subscription_option_price_data( $data, $scheme, $product, $parent_product ) {
		return self::maybe_add_option_price_data( $data, $scheme->get_key(), $product, $parent_product );
	}

	/**
	 * Add price data to SATT options.
	 *
	 * @param  array      $data
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function maybe_add_option_price_data( $data, $scheme_key, $product, $parent_product ) {

		if ( ! WCS_ATT_Product_Schemes::price_filter_exists( WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) ) ) {
			return $data;
		}

		if ( ! self::has_addons( $parent_product ? $parent_product : $product ) ) {
			return $data;
		}

		$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );
		$raw_price        = WCS_ATT_Product_Prices::get_price( $product, $scheme_key );
		$display_price    = 'incl' === $tax_display_mode ? wc_get_price_including_tax( $product, array( 'price' => $raw_price ) ) : wc_get_price_excluding_tax( $product, array( 'price' => $raw_price ) );

		$data['raw_price']     = $raw_price;
		$data['display_price'] = $display_price;

		return $data;
	}

	/**
	 * Triggers the re-calculation of the 'price_offset' runtime meta when
	 * the cart item quantity changes.
	 *
	 * @since  APFS 6.0.6
	 *
	 * @param  string $cart_item_key Cart item key.
	 */
	public static function sync_price_offset( $cart_item_key ) {

		if ( empty( WC()->cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$cart_item = WC()->cart->get_cart_item( $cart_item_key );
		self::backup_addons_price( $cart_item );
	}

	/**
	 * Aggregate add-ons costs and calculate them after APFS has applied discounts.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  array $cart_item
	 * @return array
	 */
	public static function backup_addons_price( $cart_item ) {

		if ( empty( $cart_item['addons'] ) ) {
			return $cart_item;
		}

		if ( self::discount_addons( $cart_item['data'] ) ) {
			return $cart_item;
		}

		if ( ! WCS_ATT_Product::is_subscription( $cart_item['data'] ) ) {
			return $cart_item;
		}

		$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'], 'object' );

		if ( ! empty( $subscription_scheme ) && $subscription_scheme->has_price_filter() ) {

			$price_offset_pct = array();
			$price_offset     = 0.0;

			foreach ( $cart_item['addons'] as $addon_key => $addon ) {

				if ( 'percentage_based' === $addon['price_type'] ) {
					continue;
				}

				if ( 'flat_fee' === $addon['price_type'] ) {
					$price_offset += (float) $addon['price'] / $cart_item['quantity'];
				} else {
					$price_offset += (float) $addon['price'];
				}
			}

			WCS_ATT_Product::set_runtime_meta( $cart_item['data'], 'price_offset', $price_offset );
		}

		return $cart_item;
	}

	/**
	 * Replace scheme option price html with discount.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  array           $args
	 * @param  WCS_ATT_Scheme  $scheme
	 * @param  WC_Product      $product
	 * @param  WC_Product|null $parent_product
	 * @return array
	 */
	public static function show_option_discount( $args, $scheme, $product, $parent_product ) {
		if ( self::has_addons( $parent_product ? $parent_product : $product ) && self::discount_addons( $product ) ) {
			if ( 'radio' === $args['context'] ) {
				// Can't add add-on prices, only show discount if available.
				$args['force_discount'] = true;
				$args['hide_price']     = true;
			} elseif ( 'dropdown' === $args['context'] ) {
				// Discount is appended anyway in dropdown options.
				$args['hide_price'] = true;
			}
		}
		return $args;
	}

	/**
	 * Add data to determine if addons will be discounted.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @return array
	 */
	public static function add_discount_addons_data() {

		global $product;

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) && self::has_addons( $product ) ) {

			$schemes             = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
			$price_filter_exists = WCS_ATT_Product_Schemes::price_filter_exists( $schemes );
			$discount_addons     = $price_filter_exists && self::discount_addons( $product ) ? 'yes' : 'no';

			echo '<div class="wcsatt-pao-data" data-discount_addons="' . esc_attr( $discount_addons ) . '"></div>';
		}
	}

	/**
	 * Filter add-on prices when dealing with single-plan forced subscription products.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  string $price
	 * @return array
	 */
	public static function filter_addons_price( $price ) {

		global $product;

		if ( WCS_ATT_Product_Schemes::has_single_forced_subscription_scheme( $product ) ) {
			$scheme = WCS_ATT_Product_Schemes::get_default_subscription_scheme( $product, 'object' );
			if ( $scheme->has_price_filter() && $discount = $scheme->get_discount() ) {
				$price = round( (float) $price * ( 100 - $discount ) / 100, wc_get_price_decimals() );
			}
		}

		return $price;
	}

	/**
	 * Use alternative method to render variation options.
	 *
	 * @since  APFS 2.4.1
	 *
	 * @param  bool                $modify
	 * @param  WC_Product_Variable $variable_product
	 * @return bool
	 */
	public static function modify_variation_data_price_html( $modify, $variable_product ) {

		if ( self::has_addons( $variable_product ) ) {
			$modify = false;
		}

		return $modify;
	}
}
