<?php
/**
 * WCS_ATT_Display_Cart class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart template modifications.
 *
 * @class    WCS_ATT_Display_Cart
 * @version  6.0.0
 */
class WCS_ATT_Display_Cart {

	/**
	 * Runtime cache.
	 *
	 * @var bool
	 */
	private static $display_prices_incl_tax;

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Hook-in.
	 */
	private static function add_hooks() {

		// Use radio buttons to mark a cart item as a one-time sale or as a subscription.
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'show_cart_item_subscription_options' ), 1000, 3 );
	}

	/*
	|--------------------------------------------------------------------------
	| Functions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Back-compat wrapper for 'WC_Cart::display_price_including_tax'.
	 *
	 * @since  APFS 3.1.15
	 *
	 * @return string
	 */
	public static function display_prices_including_tax() {

		if ( is_null( self::$display_prices_incl_tax ) ) {
			self::$display_prices_incl_tax = WC()->cart->display_prices_including_tax();
		}

		return self::$display_prices_incl_tax;
	}

	/*
	|--------------------------------------------------------------------------
	| Filters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Displays cart item options for purchasing a product once or creating a subscription from it.
	 *
	 * @param  string $price
	 * @param  array  $cart_item
	 * @param  string $cart_item_key
	 * @return string
	 */
	public static function show_cart_item_subscription_options( $price, $cart_item, $cart_item_key ) {

		$product       = $cart_item['data'];
		$supports_args = array(
			'cart_item'     => $cart_item,
			'cart_item_key' => $cart_item_key,
		);

		if ( ! WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_options_product_cart', $supports_args ) ) {
			return $price;
		}

		$is_mini_cart = did_action( 'woocommerce_before_mini_cart' ) !== did_action( 'woocommerce_after_mini_cart' );

		// Only show options in cart.
		if ( ! is_cart() || $is_mini_cart ) {
			return $price;
		}

		$subscription_schemes           = WCS_ATT_Cart::get_subscription_schemes( $cart_item );
		$active_subscription_scheme_key = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
		$force_subscription             = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product );
		$price_filter_exists            = WCS_ATT_Product_Schemes::price_filter_exists( $subscription_schemes );
		$options                        = array();

		// Non-recurring (one-time) option.
		if ( false === $force_subscription ) {

			if ( $price_filter_exists ) {

				if ( false === $active_subscription_scheme_key ) {
					$description = $price;
				} else {
					$description = WCS_ATT_Cart::get_product_price( $cart_item, false );
				}

				$description = '<span class="one-time-option-price">' . $description . '</span>';

			} else {
				$description = _x( 'one time', 'cart item subscription selection - negative response', 'woocommerce-subscriptions' );
			}

			$options[] = array(
				'class'       => 'one-time-option',
				'description' => $description,
				'value'       => WCS_ATT_Product_Schemes::stringify_subscription_scheme_key( false ),
				'selected'    => false === $active_subscription_scheme_key,
			);
		}

		// Subscription options.
		foreach ( $subscription_schemes as $subscription_scheme ) {

			$subscription_scheme_key = $subscription_scheme->get_key();

			if ( $price_filter_exists ) {

				if ( $active_subscription_scheme_key === $subscription_scheme_key ) {
					$description = $price;
				} else {
					$description = WCS_ATT_Product_Prices::get_price_string(
						$product,
						array(
							'scheme_key' => $subscription_scheme_key,
							'price'      => WCS_ATT_Cart::get_product_price( $cart_item, $subscription_scheme_key ),
						)
					);
				}

				$price_class = 'price';

			} else {

				$description = WCS_ATT_Product_Prices::get_price_string(
					$product,
					array(
						'scheme_key'         => $subscription_scheme_key,
						'subscription_price' => false === $subscription_scheme->is_synced() ? false : true,
						'price'              => '',
					)
				);

				$price_class = 'no-price';
			}

			$description = '<span class="' . $price_class . ' subscription-price">' . $description . '</span>';

			$options[] = array(
				'class'       => 'subscription-option',
				'description' => $description,
				'value'       => $subscription_scheme_key,
				'selected'    => $active_subscription_scheme_key === $subscription_scheme_key,
			);
		}

		$options = apply_filters( 'wcsatt_cart_item_options', $options, $subscription_schemes, $cart_item, $cart_item_key );

		// If there's just one option to display, it means that one-time purchases are not allowed and there's only one sub scheme on offer -- so don't show any options.
		if ( count( $options ) === 1 ) {
			return $price;
		}

		ob_start();

		$classes = $price_filter_exists ? array( 'overrides_exist' ) : array();

		wc_get_template(
			'cart/cart-item-subscription-options.php',
			array(
				'options'       => $options,
				'cart_item_key' => $cart_item_key,
				'classes'       => implode( ' ', $classes ),
			),
			false,
			plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/apfs/'
		);

		$convert_to_sub_options = ob_get_clean();

		if ( $price_filter_exists ) {

			$price = $convert_to_sub_options;

		} else {

			// Grab bare price without subscription details.
			remove_filter( 'woocommerce_cart_product_price', array( 'WC_Subscriptions_Cart', 'cart_product_price' ), 10, 2 );
			remove_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'show_cart_item_subscription_options' ), 1000, 3 );

			$price = WC()->cart->get_product_price( $cart_item['data'] );

			add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'show_cart_item_subscription_options' ), 1000, 3 );
			add_filter( 'woocommerce_cart_product_price', array( 'WC_Subscriptions_Cart', 'cart_product_price' ), 10, 2 );

			// Concatenate stuff.
			$price = $price . $convert_to_sub_options;
		}

		return $price;
	}
}
