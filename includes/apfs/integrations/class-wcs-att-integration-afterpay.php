<?php
/**
 * WCS_ATT_Integration_AfterPay class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 3.1.29
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility with AfterPay.
 *
 * @class    WCS_ATT_Integration_AfterPay
 * @version  3.3.2
 */
class WCS_ATT_Integration_AfterPay {

	/**
	 * Hook used in the single product page to display AfterPay buttons -- default = woocommerce_single_product_summary.
	 *
	 * @var string
	 */
	private static $single_product_page_hook = '';

	/**
	 * Priority of the hook stored in $single_product_page_hook -- default = 15.
	 *
	 * @var int
	 */
	private static $single_product_page_hook_priority = 15;

	/**
	 * Hook used in the category page to display AfterPay buttons -- default = 'woocommerce_after_shop_loop_item_title'.
	 *
	 * @var string
	 */
	private static $category_page_hook = '';

	/**
	 * Priority of the hook stored in $category_page_hook -- default = 99.
	 *
	 * @var int
	 */
	private static $category_page_hook_priority = 99;

	/**
	 * Instance of the AfterPay Gateway class.
	 *
	 * @var WC_Gateway_Afterpay
	 */
	private static $gateway = '';

	/**
	 * Initialize.
	 */
	public static function init() {

		self::$gateway = WC_Gateway_Afterpay::getInstance();
		$settings      = self::$gateway->getSettings();

		/**
		 * Retrieve hooks set by users in the AfterPay settings.
		 */
		self::$single_product_page_hook          = ! empty( $settings['product-pages-hook'] ) ? $settings['product-pages-hook'] : self::$single_product_page_hook;
		self::$single_product_page_hook_priority = ! empty( $settings['product-pages-priority'] ) ? $settings['product-pages-priority'] : self::$single_product_page_hook_priority;
		self::$category_page_hook                = ! empty( $settings['category-pages-hook'] ) ? $settings['category-pages-hook'] : self::$category_page_hook;
		self::$category_page_hook_priority       = ! empty( $settings['category-pages-priority'] ) ? $settings['category-pages-priority'] : self::$category_page_hook_priority;

		/**
		 * Hooks for AfterPay support.
		 */
		self::add_hooks();
	}

	/**
	 * Hooks for AfterPay support.
	 */
	private static function add_hooks() {

		// Hide AfterPay buttons in the single product page for products with Subscription plans.
		add_action( self::$single_product_page_hook, array( __CLASS__, 'single_product_page_handler' ), ( self::$single_product_page_hook_priority - 1 ) );

		// Hide AfterPay buttons in category pages for products with Subscription plans.
		add_action( self::$category_page_hook, array( __CLASS__, 'category_page_handler' ), ( self::$category_page_hook_priority - 1 ) );

		// Hide AfterPay buttons in the cart for products with Subscription plans.
		add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'cart_page_handler' ), 9 );
	}

	/**
	 * Hide AfterPay buttons in the single product page for products with Subscription plans.
	 */
	public static function single_product_page_handler() {

		global $product;

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			remove_action( self::$single_product_page_hook, array( self::$gateway, 'print_info_for_product_detail_page' ), self::$single_product_page_hook_priority );
			remove_filter( 'woocommerce_get_price_html', array( self::$gateway, 'filter_woocommerce_get_price_html' ) );
		}
	}

	/**
	 * Hide AfterPay buttons in category pages for products with Subscription plans.
	 */
	public static function category_page_handler() {

		global $product;

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			remove_action( self::$category_page_hook, array( self::$gateway, 'print_info_for_listed_products' ), self::$category_page_hook_priority );
		} elseif ( ! has_action( self::$category_page_hook, array( self::$gateway, 'print_info_for_listed_products' ) ) ) {
				add_action( self::$category_page_hook, array( self::$gateway, 'print_info_for_listed_products' ), self::$category_page_hook_priority );
		}
	}

	/**
	 * Hide AfterPay buttons in the cart for products with Subscription plans.
	 */
	public static function cart_page_handler() {

		$cart_contents = WC()->cart->cart_contents;

		foreach ( $cart_contents as $cart_item ) {

			if ( ! empty( $cart_item['wcsatt_data']['active_subscription_scheme'] ) ) {
				remove_action( 'woocommerce_cart_totals_after_order_total', array( self::$gateway, 'render_cart_page_elements' ) );
				break;
			}
		}
	}
}
