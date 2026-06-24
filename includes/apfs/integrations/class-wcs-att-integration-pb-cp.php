<?php
/**
 * WCS_ATT_Integration_PB_CP class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.3.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility with Product Bundles and Composite Products.
 *
 * @class    WCS_ATT_Integration_PB_CP
 * @version  9.0.0
 */
class WCS_ATT_Integration_PB_CP {

	/**
	 * Complex product types integrated with SATT.
	 *
	 * @var array
	 */
	private static $bundle_types = array();

	/**
	 * Complex type container cart item getter function names.
	 *
	 * @var array
	 */
	private static $container_cart_item_getters = array();

	/**
	 * Complex type container order item getter function names.
	 *
	 * @var array
	 */
	private static $container_order_item_getters = array();

	/**
	 * Complex type container cart item getter function names.
	 *
	 * @var array
	 */
	private static $child_cart_item_getters = array();

	/**
	 * Complex type container order item getter function names.
	 *
	 * @var array
	 */
	private static $child_order_item_getters = array();

	/**
	 * Complex type container cart item conditional function names.
	 *
	 * @var array
	 */
	private static $container_cart_item_conditionals = array();

	/**
	 * Complex type container order item conditional function names.
	 *
	 * @var array
	 */
	private static $container_order_item_conditionals = array();

	/**
	 * Complex type container cart item conditional function names.
	 *
	 * @var array
	 */
	private static $child_cart_item_conditionals = array();

	/**
	 * Complex type container order item conditional function names.
	 *
	 * @var array
	 */
	private static $child_order_item_conditionals = array();

	/**
	 * Runtime cache.
	 *
	 * @since  APFS 2.4.0
	 * @var    array
	 */
	private static $cache = array();

	/**
	 * Initialize.
	 */
	public static function init() {

		// Bundles.
		if ( class_exists( 'WC_Bundles' ) ) {
			self::$bundle_types[]                      = 'bundle';
			self::$container_cart_item_getters[]       = 'wc_pb_get_bundled_cart_item_container';
			self::$container_order_item_getters[]      = 'wc_pb_get_bundled_order_item_container';
			self::$child_cart_item_getters[]           = 'wc_pb_get_bundled_cart_items';
			self::$child_order_item_getters[]          = 'wc_pb_get_bundled_order_items';
			self::$container_cart_item_conditionals[]  = 'wc_pb_is_bundle_container_cart_item';
			self::$container_order_item_conditionals[] = 'wc_pb_is_bundle_container_order_item';
			self::$child_cart_item_conditionals[]      = 'wc_pb_is_bundled_cart_item';
			self::$child_order_item_conditionals[]     = 'wc_pb_is_bundled_order_item';
		}

		// Composites.
		if ( class_exists( 'WC_Composite_Products' ) ) {
			self::$bundle_types[]                      = 'composite';
			self::$container_cart_item_getters[]       = 'wc_cp_get_composited_cart_item_container';
			self::$container_order_item_getters[]      = 'wc_cp_get_composited_order_item_container';
			self::$child_cart_item_getters[]           = 'wc_cp_get_composited_cart_items';
			self::$child_order_item_getters[]          = 'wc_cp_get_composited_order_items';
			self::$container_cart_item_conditionals[]  = 'wc_cp_is_composite_container_cart_item';
			self::$container_order_item_conditionals[] = 'wc_cp_is_composite_container_order_item';
			self::$child_cart_item_conditionals[]      = 'wc_cp_is_composited_cart_item';
			self::$child_order_item_conditionals[]     = 'wc_cp_is_composited_order_item';
		}

		// Mix n Match.
		if ( class_exists( 'WC_Mix_and_Match' ) ) {
			if ( version_compare( WC_Mix_and_Match::instance()->version, '1.7.0', '<' ) ) {
				self::$bundle_types[]                      = 'mix-and-match';
				self::$container_cart_item_getters[]       = 'wc_mnm_get_mnm_cart_item_container';
				self::$container_order_item_getters[]      = 'wc_mnm_get_mnm_order_item_container';
				self::$child_cart_item_getters[]           = 'wc_mnm_get_mnm_cart_items';
				self::$child_order_item_getters[]          = 'wc_mnm_get_mnm_order_items';
				self::$container_cart_item_conditionals[]  = 'wc_mnm_is_mnm_container_cart_item';
				self::$container_order_item_conditionals[] = 'wc_mnm_is_mnm_container_order_item';
				self::$child_cart_item_conditionals[]      = 'wc_mnm_is_mnm_cart_item';
				self::$child_order_item_conditionals[]     = 'wc_mnm_is_mnm_order_item';
			} else {
				self::$bundle_types[]                      = 'mix-and-match';
				self::$container_cart_item_getters[]       = 'wc_mnm_get_cart_item_container';
				self::$container_order_item_getters[]      = 'wc_mnm_get_order_item_container';
				self::$child_cart_item_getters[]           = 'wc_mnm_get_child_cart_items';
				self::$child_order_item_getters[]          = 'wc_mnm_get_child_order_items';
				self::$container_cart_item_conditionals[]  = 'wc_mnm_is_container_cart_item';
				self::$container_order_item_conditionals[] = 'wc_mnm_is_container_cart_item';
				self::$child_cart_item_conditionals[]      = 'wc_mnm_is_child_cart_item';
				self::$child_order_item_conditionals[]     = 'wc_mnm_is_child_order_item';
			}
		}

		if ( ! empty( self::$bundle_types ) ) {
			self::add_hooks();
		}
	}

	/**
	 * Hooks for PB/CP support.
	 */
	private static function add_hooks() {

		/*
		 * All types: Application layer integration.
		 */

		// Schemes attached on bundles should not work if the bundle contains non-supported products, such as "legacy" subscription products.
		add_filter( 'wcsatt_product_subscription_schemes', array( __CLASS__, 'get_product_bundle_schemes' ), 10, 2 );

		// Hide child cart item options.
		add_filter( 'wcsatt_show_cart_item_options', array( __CLASS__, 'hide_child_item_options' ), 10, 3 );

		// Bundled/child items inherit the active subscription scheme of their parent.
		add_filter( 'wcsatt_set_subscription_scheme_id', array( __CLASS__, 'set_child_item_subscription_scheme' ), 10, 3 );

		// Bundled cart items inherit the subscription schemes of their parent, with some modifications.
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'apply_child_item_subscription_schemes' ), 0 );

		// Bundled cart items inherit the subscription schemes of their parent, with some modifications (first add).
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'set_child_item_schemes' ), 0, 2 );

		// Pass subscription details placeholder to JS script.
		add_filter( 'wcsatt_single_product_one_time_option_data', array( __CLASS__, 'bundle_one_time_option_data' ), 10, 2 );
		add_filter( 'wcsatt_single_product_subscription_option_data', array( __CLASS__, 'bundle_subscription_option_data' ), 10, 3 );

		// Make sure child order items inherit the subscription plans of their parent.
		add_filter( 'woocommerce_order_item_product', array( __CLASS__, 'restore_bundle_type_product_from_order_item' ), 5, 2 );

		/*
		 * All types: Display/templates integration.
		 */

		/*
		 * Cart.
		 */

		// Mark bundle-type child item details as hidden in the block cart to prevent trailing separators.
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'hide_container_component_details_in_blocks' ), 999, 2 );

		// Add subscription details next to price of per-item-priced bundle-type container cart items.
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'filter_container_item_price' ), 999, 3 );

		// Add subscription details next to subtotal of per-item-priced bundle-type container cart items.
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'filter_container_item_subtotal' ), 999, 3 );

		// Modify bundle container cart item options to include child item prices.
		add_filter( 'wcsatt_cart_item_options', array( __CLASS__, 'container_item_options' ), 10, 4 );

		/*
		 * Subscriptions management: 'My Account > Subscriptions' actions.
		 */

		// Don't count bundle-type child items and hidden bundle-type container/child items.
		add_filter( 'wcs_can_items_be_removed', array( __CLASS__, 'can_remove_subscription_items' ), 10, 2 );

		// Hide "Remove" buttons of child line items under 'My Account > Subscriptions'.
		add_filter( 'wcs_can_item_be_removed', array( __CLASS__, 'can_remove_child_subscription_item' ), 10, 3 );

		// Handle parent subscription line item removals under 'My Account > Subscriptions'.
		add_action( 'wcs_user_removed_item', array( __CLASS__, 'user_removed_parent_subscription_item' ), 10, 2 );

		// Handle parent subscription line item re-additions under 'My Account > Subscriptions'.
		add_action( 'wcs_user_readded_item', array( __CLASS__, 'user_readded_parent_subscription_item' ), 10, 2 );

		/*
		 * Subscriptions management: Switching.
		 */
		if ( WCS_ATT()->is_module_registered( 'manage' ) ) {

			// Add extra 'Allow Switching' options. See 'WCS_ATT_Admin::allow_switching_options'.
			add_filter( 'woocommerce_subscriptions_allow_switching_options', array( __CLASS__, 'add_bundle_switching_options' ), 11 );

			// Hide "Upgrade or Downgrade" switching buttons of bundle-type line items under 'My Account > Subscriptions'.
			add_filter( 'woocommerce_subscriptions_can_item_be_switched', array( __CLASS__, 'can_switch_bundle_type_item' ), 10, 3 );

			// Add content switching support to Bundle-type products.
			add_filter( 'wcsatt_product_supports_feature', array( __CLASS__, 'bundle_supports_switching' ), 10, 4 );

			// Make WCS see products with a switched scheme as non-identical ones.
			add_filter( 'woocommerce_subscriptions_switch_is_identical_product', array( __CLASS__, 'bundle_is_identical' ), 10, 6 );

			// Only allow content switching: Bundle schemes should be limited to the one matching the subscription while the product is being switched.
			add_filter( 'wcsatt_product_subscription_schemes', array( __CLASS__, 'limit_switched_bundle_type_schemes' ), 100, 2 );

			// Disallow plan switching for bundle types. Only content switching permitted!
			add_filter( 'wcsatt_force_subscription', array( __CLASS__, 'force_switched_bundle_type_subscription' ), 10, 2 );

			// Restore bundle configuration when switching.
			add_filter( 'woocommerce_subscriptions_switch_url', array( __CLASS__, 'bundle_type_switch_configuration_url' ), 10, 4 );

			// Change the order item status of old child items when the new parent is added.
			add_action( 'woocommerce_subscription_item_switched', array( __CLASS__, 'remove_switched_subscription_child_items' ), 10, 4 );

			// Disable proration when switching.
			add_filter( 'wcs_switch_proration_switch_type', array( __CLASS__, 'force_bundle_switch_type' ), 10, 3 );
			add_filter( 'woocommerce_before_calculate_totals', array( __CLASS__, 'restore_bundle_switch_type' ), 100 );

			if ( class_exists( 'WC_Bundles' ) ) {

				// Copy switch parameters from parent item.
				add_filter( 'woocommerce_bundled_item_cart_data', array( __CLASS__, 'bundled_item_switch_cart_data' ), 10, 2 );
			}

			if ( class_exists( 'WC_Composite_Products' ) ) {

				// Copy switch parameters from parent item.
				add_filter( 'woocommerce_composited_cart_item_data', array( __CLASS__, 'composited_item_switch_cart_data' ), 10, 2 );
			}
		}

		/*
		 * Subscriptions management: Add products/carts to subscriptions.
		 */

		// Modify the validation context when adding a bundle to an order.
		add_action( 'wcsatt_pre_add_product_to_subscription_validation', array( __CLASS__, 'set_bundle_type_validation_context' ), 10 );

		// Modify the validation context when adding a bundle to an order.
		add_action( 'wcsatt_post_add_product_to_subscription_validation', array( __CLASS__, 'reset_bundle_type_validation_context' ), 10 );

		// Don't attempt to increment the quantity of bundle-type subscription items when adding to an existing subscription.
		add_filter( 'wcsatt_add_cart_to_subscription_found_item', array( __CLASS__, 'found_bundle_in_subscription' ), 10, 5 );

		// Add bundles/composites to subscriptions.
		add_filter( 'wscatt_add_cart_item_to_subscription_callback', array( __CLASS__, 'add_bundle_to_subscription_callback' ), 10, 3 );

		// Match bundle/composite child items by slot identifier when calculating sign-up fees.
		add_filter( 'woocommerce_subscription_match_order_item_for_sign_up_fee', array( __CLASS__, 'match_order_item_for_sign_up_fee' ), 10, 4 );

		/*
		 * Bundles.
		 */

		if ( class_exists( 'WC_Bundles' ) ) {

			// When loading bundled items, always set the active bundle scheme on the bundled objects.
			add_filter( 'woocommerce_bundled_items', array( __CLASS__, 'set_bundled_items_scheme' ), 10, 2 );

			// Add scheme data to runtime price cache hashes.
			add_filter( 'woocommerce_bundle_prices_hash', array( __CLASS__, 'bundle_prices_hash' ), 10, 2 );

			// Temporarily disable APFS price filters when getting the bundled item Regular price.
			add_action( 'woocommerce_bundled_item_get_unfiltered_regular_price_start', array( __CLASS__, 'remove_price_filters' ) );
			add_action( 'woocommerce_bundled_item_get_unfiltered_regular_price_end', array( __CLASS__, 'add_price_filters' ) );

		}

		/*
		 * Composites.
		 */

		if ( class_exists( 'WC_Composite_Products' ) ) {

			// Set the default scheme when one-time purchases are disabled, no scheme is set on the object, and only a single sub scheme exists.
			add_action( 'woocommerce_composite_synced', array( __CLASS__, 'set_single_composite_subscription_scheme' ) );

			// Ensure composites in cached component objects have up-to-date scheme data.
			add_action( 'wcsatt_set_product_subscription_scheme', array( __CLASS__, 'set_composite_product_scheme' ), 10, 3 );

			// Products in component option objects inherit the subscription schemes of their container object -- SLOW!
			add_filter( 'woocommerce_composite_component_option', array( __CLASS__, 'set_component_option_scheme' ), 10, 3 );

			// Add scheme data to runtime component cache hashes.
			add_filter( 'woocommerce_composite_component_hash', array( __CLASS__, 'component_hash' ), 10, 2 );

			// Add scheme data to runtime price cache hashes.
			add_filter( 'woocommerce_composite_prices_hash', array( __CLASS__, 'composite_prices_hash' ), 10, 2 );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Checks if the passed product is of a supported bundle type. Returns the type if yes, or false if not.
	 *
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function is_bundle_type_product( $product ) {
		return $product->is_type( self::$bundle_types );
	}

	/**
	 * Given a bundle-type child cart item, find and return its container cart item or its cart id when the $return_id arg is true.
	 *
	 * @param  array   $cart_item
	 * @param  array   $cart_contents
	 * @param  boolean $return_id
	 * @return mixed
	 */
	public static function get_bundle_type_cart_item_container( $cart_item, $cart_contents = false, $return_id = false ) {

		$container = false;

		foreach ( self::$container_cart_item_getters as $container_cart_item_getter ) {
			$container = call_user_func_array( $container_cart_item_getter, array( $cart_item, $cart_contents, $return_id ) );
			if ( ! empty( $container ) ) {
				break;
			}
		}

		return $container;
	}

	/**
	 * Given a bundle-type container cart item, find and return its child cart items - or their cart ids when the $return_ids arg is true.
	 *
	 * @param  array   $cart_item
	 * @param  array   $cart_contents
	 * @param  boolean $return_ids
	 * @return mixed
	 */
	public static function get_bundle_type_cart_items( $cart_item, $cart_contents = false, $return_ids = false ) {

		$children = array();

		foreach ( self::$child_cart_item_getters as $child_cart_item_getter ) {
			$children = call_user_func_array( $child_cart_item_getter, array( $cart_item, $cart_contents, $return_ids ) );
			if ( ! empty( $children ) ) {
				break;
			}
		}

		return $children;
	}

	/**
	 * True if a cart item appears to be a bundle-type container item.
	 *
	 * @param  array $cart_item
	 * @return boolean
	 */
	public static function is_bundle_type_container_cart_item( $cart_item ) {

		$is = false;

		foreach ( self::$container_cart_item_conditionals as $container_cart_item_conditional ) {
			$is = call_user_func_array( $container_cart_item_conditional, array( $cart_item ) );
			if ( $is ) {
				break;
			}
		}

		return $is;
	}

	/**
	 * True if a cart item is part of a bundle-type product.
	 *
	 * @param  array $cart_item
	 * @param  array $cart_contents
	 * @return boolean
	 */
	public static function is_bundle_type_cart_item( $cart_item, $cart_contents = false ) {

		$is = false;

		foreach ( self::$child_cart_item_conditionals as $child_cart_item_conditional ) {
			$is = call_user_func_array( $child_cart_item_conditional, array( $cart_item, $cart_contents ) );
			if ( $is ) {
				break;
			}
		}

		return $is;
	}

	/**
	 * Given a bundle-type child order item, find and return its container order item or its order item id when the $return_id arg is true.
	 *
	 * @param  array    $order_item
	 * @param  WC_Order $order
	 * @param  boolean  $return_id
	 * @return mixed
	 */
	public static function get_bundle_type_order_item_container( $order_item, $order = false, $return_id = false ) {

		$container = false;

		foreach ( self::$container_order_item_getters as $container_order_item_getter ) {
			$container = call_user_func_array( $container_order_item_getter, array( $order_item, $order, $return_id ) );
			if ( ! empty( $container ) ) {
				break;
			}
		}

		return $container;
	}

	/**
	 * Given a bundle-type container order item, find and return its child order items - or their order item ids when the $return_ids arg is true.
	 *
	 * @param  array    $order_item
	 * @param  WC_Order $order
	 * @param  boolean  $return_ids
	 * @param  boolean  $deep
	 * @return mixed
	 */
	public static function get_bundle_type_order_items( $order_item, $order = false, $return_ids = false, $deep = false ) {

		$children = array();

		if ( $deep && function_exists( 'wc_cp_is_composite_container_order_item' ) && wc_cp_is_composite_container_order_item( $order_item ) ) {

			$children = wc_cp_get_composited_order_items( $order_item, $order, $return_ids, true );

		} else {

			foreach ( self::$child_order_item_getters as $child_order_item_getter ) {
				$children = call_user_func_array( $child_order_item_getter, array( $order_item, $order, $return_ids ) );
				if ( ! empty( $children ) ) {
					break;
				}
			}
		}

		return $children;
	}

	/**
	 * True if an order item appears to be a bundle-type container item.
	 *
	 * @param  array    $order_item
	 * @param  WC_Order $order
	 * @return boolean
	 */
	public static function is_bundle_type_container_order_item( $order_item, $order = false ) {

		$is = false;

		foreach ( self::$container_order_item_conditionals as $container_order_item_conditional ) {
			$is = call_user_func_array( $container_order_item_conditional, array( $order_item, $order ) );
			if ( $is ) {
				break;
			}
		}

		return $is;
	}

	/**
	 * True if an order item is part of a bundle-type product.
	 *
	 * @param  array    $cart_item
	 * @param  WC_Order $order
	 * @return boolean
	 */
	public static function is_bundle_type_order_item( $order_item, $order = false ) {

		$is = false;

		foreach ( self::$child_order_item_conditionals as $child_order_item_conditional ) {
			$is = call_user_func_array( $child_order_item_conditional, array( $order_item, $order ) );
			if ( $is ) {
				break;
			}
		}

		return $is;
	}

	/**
	 * True if there are sub schemes inherited from a container.
	 *
	 * @param  array $cart_item
	 * @return boolean
	 */
	private static function has_scheme_data( $cart_item ) {
		return ! is_null( WCS_ATT_Cart::get_subscription_scheme( $cart_item ) );
	}

	/**
	 * WC_Product_Bundle 'contains_sub' back-compat wrapper.
	 *
	 * @param  WC_Product_Bundle $bundle
	 * @return boolean
	 */
	private static function bundle_contains_subscription( $bundle ) {

		if ( version_compare( WC_PB()->version, '5.0.0' ) < 0 ) {
			return $bundle->contains_sub();
		} else {
			return $bundle->contains( 'subscriptions' );
		}
	}

	/**
	 * Set the active bundle scheme on a bundled item.
	 *
	 * @param  WC_Bundled_Item   $bundled_item
	 * @param  WC_Product_Bundle $bundle
	 */
	public static function set_bundled_item_scheme( $bundled_item, $bundle ) {

		// Callable since PB 5.2.4.
		if ( is_callable( array( $bundled_item, 'get_product' ) ) ) {

			$having = array(
				'price',
				'regular_price',
			);

			$what = array(
				'min',
				'max',
			);

			if ( $bundled_product = $bundled_item->get_product() ) {
				self::set_bundled_product_subscription_schemes( $bundled_product, $bundle );
			}

			foreach ( $having as $price ) {
				foreach ( $what as $min_or_max ) {
					if ( $bundled_product = $bundled_item->get_product(
						array(
							'having' => $price,
							'what'   => $min_or_max,
						)
					) ) {
						self::set_bundled_product_subscription_schemes( $bundled_product, $bundle );
					}
				}
			}
		}
	}

	/**
	 * Calculates bundle container item subtotals.
	 *
	 * @param  array  $cart_item
	 * @param  string $scheme_key
	 * @param  string $tax
	 * @return double
	 */
	private static function calculate_container_item_subtotal( $cart_item, $scheme_key, $tax = '' ) {

		$product                 = $cart_item['data'];
		$display_prices_incl_tax = '' === $tax ? WCS_ATT_Display_Cart::display_prices_including_tax() : ( 'incl' === $tax );

		if ( ! $display_prices_incl_tax ) {
			$subtotal = (float) wc_get_price_excluding_tax(
				$product,
				array(
					'price' => WCS_ATT_Product_Prices::get_price( $product, $scheme_key ),
					'qty'   => $cart_item['quantity'],
				)
			);
		} else {
			$subtotal = (float) wc_get_price_including_tax(
				$product,
				array(
					'price' => WCS_ATT_Product_Prices::get_price( $product, $scheme_key ),
					'qty'   => $cart_item['quantity'],
				)
			);
		}

		$child_items = self::get_bundle_type_cart_items( $cart_item );
		$child_items = function_exists( 'wc_cp_is_composite_container_cart_item' ) && wc_cp_is_composite_container_cart_item( $cart_item ) ? wc_cp_get_composited_cart_items( $cart_item, false, false, true ) : self::get_bundle_type_cart_items( $cart_item );

		if ( ! empty( $child_items ) ) {

			foreach ( $child_items as $child_key => $child_item ) {

				if ( ! $display_prices_incl_tax ) {
					$subtotal += (float) wc_get_price_excluding_tax(
						$child_item['data'],
						array(
							'price' => WCS_ATT_Product_Prices::get_price( $child_item['data'], $scheme_key ),
							'qty'   => $child_item['quantity'],
						)
					);
				} else {
					$subtotal += (float) wc_get_price_including_tax(
						$child_item['data'],
						array(
							'price' => WCS_ATT_Product_Prices::get_price( $child_item['data'], $scheme_key ),
							'qty'   => $child_item['quantity'],
						)
					);
				}
			}
		}

		return $subtotal;
	}

	/**
	 * Calculates bundle container item prices.
	 *
	 * @param  array  $cart_item
	 * @param  string $scheme_key
	 * @param  string $tax
	 * @return double
	 */
	private static function calculate_container_item_price( $cart_item, $scheme_key, $tax = '' ) {

		$product                 = $cart_item['data'];
		$display_prices_incl_tax = '' === $tax ? WCS_ATT_Display_Cart::display_prices_including_tax() : ( 'incl' === $tax );

		if ( ! $display_prices_incl_tax ) {
			$price = (float) wc_get_price_excluding_tax( $product, array( 'price' => WCS_ATT_Product_Prices::get_price( $product, $scheme_key ) ) );
		} else {
			$price = (float) wc_get_price_including_tax( $product, array( 'price' => WCS_ATT_Product_Prices::get_price( $product, $scheme_key ) ) );
		}

		$child_items = self::get_bundle_type_cart_items( $cart_item );
		$child_items = function_exists( 'wc_cp_is_composite_container_cart_item' ) && wc_cp_is_composite_container_cart_item( $cart_item ) ? wc_cp_get_composited_cart_items( $cart_item, false, false, true ) : self::get_bundle_type_cart_items( $cart_item );

		if ( ! empty( $child_items ) ) {

			foreach ( $child_items as $child_key => $child_item ) {

				$child_qty = ceil( $child_item['quantity'] / $cart_item['quantity'] );

				if ( ! $display_prices_incl_tax ) {
					$price += (float) wc_get_price_excluding_tax(
						$child_item['data'],
						array(
							'price' => WCS_ATT_Product_Prices::get_price( $child_item['data'], $scheme_key ),
							'qty'   => $child_qty,
						)
					);
				} else {
					$price += (float) wc_get_price_including_tax(
						$child_item['data'],
						array(
							'price' => WCS_ATT_Product_Prices::get_price( $child_item['data'], $scheme_key ),
							'qty'   => $child_qty,
						)
					);
				}
			}
		}

		return $price;
	}

	/**
	 * Add bundles to subscriptions using 'WC_PB_Order::add_bundle_to_order'.
	 *
	 * @param  WC_Subscription $subscription
	 * @param  array           $cart_item
	 * @param  WC_Cart         $recurring_cart
	 */
	public static function add_bundle_to_order( $subscription, $cart_item, $recurring_cart ) {

		$configuration = $cart_item['stamp'];

		// Copy child item totals over from recurring cart.
		foreach ( wc_pb_get_bundled_cart_items( $cart_item, $recurring_cart->cart_contents ) as $child_cart_item_key => $child_cart_item ) {

			$bundled_item_id = $child_cart_item['bundled_item_id'];

			$configuration[ $bundled_item_id ]['args'] = array(
				'subtotal' => $child_cart_item['line_total'],
				'total'    => $child_cart_item['line_subtotal'],
			);
		}

		return WC_PB()->order->add_bundle_to_order( $cart_item['data'], $subscription, $cart_item['quantity'], array( 'configuration' => $configuration ) );
	}

	/**
	 * Add composites to subscriptions using 'WC_CP_Order::add_composite_to_order'.
	 *
	 * @param  WC_Subscription $subscription
	 * @param  array           $cart_item
	 * @param  WC_Cart         $recurring_cart
	 */
	public static function add_composite_to_order( $subscription, $cart_item, $recurring_cart ) {

		$configuration = $cart_item['composite_data'];

		// Copy child item totals over from recurring cart.
		foreach ( wc_cp_get_composited_cart_items( $cart_item, $recurring_cart->cart_contents ) as $child_cart_item_key => $child_cart_item ) {

			$component_id = $child_cart_item['composite_item'];

			$configuration[ $component_id ]['args'] = array(
				'subtotal' => $child_cart_item['line_total'],
				'total'    => $child_cart_item['line_subtotal'],
			);
		}

		return WC_CP()->order->add_composite_to_order( $cart_item['data'], $subscription, $cart_item['quantity'], array( 'configuration' => $configuration ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks - Application
	|--------------------------------------------------------------------------
	*/

	/**
	 * Sub schemes attached on a Product Bundle should not work if the bundle contains a non-convertible product, such as a "legacy" subscription.
	 *
	 * @param  array      $schemes
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function get_product_bundle_schemes( $schemes, $product ) {

		if ( self::is_bundle_type_product( $product ) ) {
			if ( $product->is_type( 'bundle' ) && self::bundle_contains_subscription( $product ) ) {
				$schemes = array();
			} elseif ( $product->is_type( 'mix-and-match' ) && $product->is_priced_per_product() ) {
				$schemes = array();
			}
		}

		return $schemes;
	}

	/**
	 * Hide bundled cart item subscription options.
	 *
	 * @param  boolean $show
	 * @param  array   $cart_item
	 * @param  string  $cart_item_key
	 * @return boolean
	 */
	public static function hide_child_item_options( $show, $cart_item, $cart_item_key ) {

		if ( $container_cart_item = self::get_bundle_type_cart_item_container( $cart_item ) ) {
			if ( self::has_scheme_data( $container_cart_item ) ) {
				$show = false;
			}
		}

		return $show;
	}

	/**
	 * Bundled items inherit the active subscription scheme id of their parent.
	 *
	 * @param  string $scheme_key
	 * @param  array  $cart_item
	 * @param  array  $cart_level_schemes
	 * @return string
	 */
	public static function set_child_item_subscription_scheme( $scheme_key, $cart_item, $cart_level_schemes ) {

		if ( $container_cart_item = self::get_bundle_type_cart_item_container( $cart_item ) ) {
			if ( self::has_scheme_data( $container_cart_item ) ) {
				$scheme_key = $container_cart_item['wcsatt_data']['active_subscription_scheme'];
			}
		}

		return $scheme_key;
	}

	/**
	 * Bundled cart items inherit the subscription schemes of their parent, with some modifications.
	 *
	 * @param  WC_Cart $cart
	 * @return void
	 */
	public static function apply_child_item_subscription_schemes( $cart ) {

		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {

			// Is it a bundled item?
			if ( $container_cart_item = self::get_bundle_type_cart_item_container( $cart_item ) ) {
				if ( self::has_scheme_data( $container_cart_item ) ) {
					self::set_bundled_product_subscription_schemes( $cart_item['data'], $container_cart_item['data'] );
				} elseif ( WCS_ATT_Product_Schemes::has_subscription_schemes( $cart_item['data'] ) ) {
					WCS_ATT_Product_Schemes::set_subscription_schemes( $cart_item['data'], array() );
					if ( isset( $cart_item['wcsatt_data'] ) ) {
						$cart->cart_contents[ $cart_item_key ]['wcsatt_data']['active_subscription_scheme'] = null;
					}
				}
			}
		}
	}

	/**
	 * Copies product schemes to a child product.
	 *
	 * @param  WC_Product $bundled_product
	 * @param  WC_Product $container_product
	 */
	private static function set_bundled_product_subscription_schemes( $bundled_product, $container_product ) {

		// Guard against infinite recursion: computing the bundle item prices (needed for proportional
		// fixed-discount conversion) calls get_bundled_items(), which fires a filter that re-enters
		// this function. Exit early for the same container while it is already being processed.
		static $processing_containers = array();
		$container_id                 = $container_product->get_id();
		if ( isset( $processing_containers[ $container_id ] ) ) {
			return;
		}
		$processing_containers[ $container_id ] = true;

		$container_schemes       = WCS_ATT_Product_Schemes::get_subscription_schemes( $container_product );
		$bundled_product_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $bundled_product );

		$container_schemes_hash       = '';
		$bundled_product_schemes_hash = '';

		foreach ( $container_schemes as $scheme_key => $scheme ) {
			$container_schemes_hash .= $scheme->get_hash();
		}

		foreach ( $bundled_product_schemes as $scheme_key => $scheme ) {
			$bundled_product_schemes_hash .= $scheme->get_hash();
		}

		// Copy container schemes to child.
		// Also force-copy when any container scheme uses MODE_FIXED_DISCOUNT: child items may independently
		// have identical scheme data, but the fixed discount must be distributed correctly so the total
		// discount equals the intended amount rather than the fixed amount per item.
		$has_container_fixed_discount = false;
		foreach ( $container_schemes as $_scheme ) {
			if ( $_scheme->has_price_filter() && WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $_scheme->get_pricing_mode() ) {
				$has_container_fixed_discount = true;
				break;
			}
		}

		if ( $has_container_fixed_discount || $container_schemes_hash !== $bundled_product_schemes_hash ) {

			$bundled_product_schemes = array();

			// Modify child object schemes: "Override" pricing mode is only applicable for container.
			foreach ( $container_schemes as $scheme_key => $scheme ) {

				$bundled_product_schemes[ $scheme_key ] = clone $scheme;
				$bundled_product_scheme                 = $bundled_product_schemes[ $scheme_key ];

				if ( $bundled_product_scheme->has_price_filter() && WCS_ATT_Scheme::MODE_OVERRIDE === $bundled_product_scheme->get_pricing_mode() ) {
					$bundled_product_scheme->set_pricing_mode( WCS_ATT_Scheme::MODE_INHERIT );
					$bundled_product_scheme->set_discount( '' );
				} elseif ( $bundled_product_scheme->has_price_filter() && WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $bundled_product_scheme->get_pricing_mode() ) {
					// Distribute the fixed discount correctly across child item lines.
					//
					// When a bundle has non-individually-priced items, their prices are rolled into
					// the container's own price, and the container's scheme already applies the full
					// fixed discount to cover those items. In that case, individually-priced children
					// must receive no additional discount (otherwise the total discount would exceed
					// the intended amount). We detect this by checking whether the container product
					// itself carries a non-zero price.
					//
					// When ALL items are individually priced the container price is $0 (no price of
					// its own), so the discount must be split equally across all defined child items.
					$container_base_price = (float) $container_product->get_price();

					if ( $container_base_price > 0.0 ) {
						// Container has its own price (non-individually-priced items are rolled in).
						// Remove the fixed discount from this child — the container handles it all.
						$bundled_product_scheme->set_pricing_mode( WCS_ATT_Scheme::MODE_INHERIT );
						$bundled_product_scheme->set_discount( '' );
					} else {
						// All items are individually priced; split the discount equally across children.
						$child_item_count = 0;
						if ( is_a( $container_product, 'WC_Product_Bundle' ) && method_exists( $container_product, 'get_bundled_items' ) ) {
							// @phpstan-ignore class.notFound
							$child_item_count = count( $container_product->get_bundled_items() );
						} elseif ( is_a( $container_product, 'WC_Product_Composite' ) && method_exists( $container_product, 'get_components' ) ) {
							// @phpstan-ignore class.notFound
							$child_item_count = count( $container_product->get_components() );
						}
						if ( $child_item_count > 0 ) {
							$bundled_product_scheme->set_discount( round( $bundled_product_scheme->get_discount() / $child_item_count, 6 ) );
						} else {
							$bundled_product_scheme->set_pricing_mode( WCS_ATT_Scheme::MODE_INHERIT );
							$bundled_product_scheme->set_discount( '' );
						}
					}
				}

				// Signup fees are a container-level concept. The fee must never be applied to
				// child items, as that would cause it to be counted once per child in cart totals
				// and displayed incorrectly on each bundled line item.
				$bundled_product_scheme->set_signup_fee( 0.0 );
			}

			WCS_ATT_Product_Schemes::set_subscription_schemes( $bundled_product, $bundled_product_schemes );
		}

		$container_scheme       = WCS_ATT_Product_Schemes::get_subscription_scheme( $container_product );
		$bundled_product_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $bundled_product );
		$scheme_to_set          = is_null( $container_scheme ) ? false : $container_scheme;

		// Set active container scheme on child.
		if ( $scheme_to_set !== $bundled_product_scheme ) {
			WCS_ATT_Product_Schemes::set_subscription_scheme( $bundled_product, $scheme_to_set );
		}

		// Copy "Force Subscription" state.
		$bundled_product_has_forced_scheme = $scheme_to_set ? WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $container_product ) : false;
		if ( WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $bundled_product ) !== $bundled_product_has_forced_scheme ) {
			WCS_ATT_Product_Schemes::set_forced_subscription_scheme( $bundled_product, $bundled_product_has_forced_scheme );
		}

		unset( $processing_containers[ $container_id ] );
	}

	/**
	 * Bundled cart items inherit the subscription schemes of their parent, with some modifications (first add).
	 *
	 * @param  array  $cart_item
	 * @param  string $cart_item_key
	 * @return array
	 */
	public static function set_child_item_schemes( $cart_item, $cart_item_key ) {

		// Is it a bundled item?
		if ( $container_cart_item = self::get_bundle_type_cart_item_container( $cart_item ) ) {
			if ( self::has_scheme_data( $container_cart_item ) ) {
				self::set_bundled_product_subscription_schemes( $cart_item['data'], $container_cart_item['data'] );
			}
		}

		return $cart_item;
	}

	/**
	 * Pass one-time option price placeholder to JS script.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  array      $data
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function bundle_one_time_option_data( $data, $product ) {

		if ( self::is_bundle_type_product( $product ) ) {
			$data['prompt_details_html'] = sprintf( __( 'One-time for %s', 'woocommerce-subscriptions' ), '<span class="price one-time-price">%p</span>' );
			$data['option_details_html'] = sprintf( _x( '%s one time', 'product subscription selection - negative response', 'woocommerce-subscriptions' ), '%p' );
		}

		return $data;
	}

	/**
	 * Pass subscription details placeholder to JS script.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  array          $data
	 * @param  WCS_ATT_Scheme $subscription_scheme
	 * @param  WC_Product     $product
	 * @return array
	 */
	public static function bundle_subscription_option_data( $data, $subscription_scheme, $product ) {

		if ( self::is_bundle_type_product( $product ) ) {

			$subscription_schemes  = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
			$force_subscription    = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product );
			$dropdown_details_html = isset( $data['dropdown_details_html'] ) ? $data['dropdown_details_html'] : WCS_ATT_Product_Prices::get_price_html(
				$product,
				$subscription_scheme->get_key(),
				array(
					'context'      => 'dropdown',
					'price'        => '%p',
					'append_price' => false === $force_subscription,
					'hide_price'   => $subscription_scheme->get_length() > 0 && false === $force_subscription, // "Deliver every month for 6 months for $8.00 (10% off)" is just too confusing, isn't it?
				)
			);

			// Base scheme defines the prompt string.
			if ( $data['subscription_scheme']['is_base'] ) {
				$data['prompt_details_html'] = WCS_ATT_Product_Prices::get_price_html(
					$product,
					null,
					array(
						'context'    => 'prompt',
						'base_price' => '%p',
					)
				);
			}

			$data['option_details_html'] = WCS_ATT_Product_Prices::get_price_html(
				$product,
				$subscription_scheme->get_key(),
				array(
					'context'         => 1 === count( $subscription_schemes ) && $force_subscription ? 'catalog' : 'options',
					'append_discount' => 1 < count( $subscription_schemes ) || ! $force_subscription,
					'price'           => '%p',
				)
			);

			$data['option_has_price']           = false !== strpos( $data['option_details_html'], '%p' );
			$data['dropdown_format']            = ucfirst( trim( wp_kses( $dropdown_details_html, array() ) ) );
			$data['dropdown_discounted_format'] = sprintf( _x( '%1$s (%2$s off)', 'discounted dropdown option price', 'woocommerce-subscriptions' ), '%p', sprintf( _x( '%s%%', 'dropdown option discount', 'woocommerce-subscriptions' ), '%d' ) );
			$data['dropdown_discount_decimals'] = WCS_ATT_Product_Prices::get_formatted_discount_precision();
			$data['dropdown_sale_format']       = sprintf( _x( '%1$s (was %2$s)', 'dropdown option sale price', 'woocommerce-subscriptions' ), '%p', '%r' );

			if ( WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $subscription_scheme->get_pricing_mode() && $subscription_scheme->get_discount() ) {
				// translators: %1$s is the subscription price, %2$s is the fixed discount amount.
				$data['dropdown_fixed_discounted_format'] = sprintf( _x( '%1$s (%2$s off)', 'discounted dropdown option price', 'woocommerce-subscriptions' ), '%p', wp_strip_all_tags( wc_price( $subscription_scheme->get_discount() ) ) );
			}
		}

		return $data;
	}

	/**
	 * Reorders cart item data so bundle/composite component details come after subscription details,
	 * and marks the last subscription detail with a CSS class for separator hiding.
	 *
	 * Bundle/Composite plugins add component details (e.g. "Includes: Polo × 1") without setting
	 * the 'hidden' property. The block cart's ProductDetails component includes them when determining
	 * " / " separator placement, but they are visually hidden via CSS in the cart. If they appear
	 * after subscription details like "Free trial" or "Sign up fee", a trailing " / " separator
	 * is rendered on the last subscription detail.
	 *
	 * We can't set 'hidden: true' on these entries because the Store API serves the same response
	 * to both the block cart and block checkout — hiding them would also remove component details
	 * from the checkout order summary where they are useful context for the customer.
	 *
	 * Instead, this method:
	 * 1. Moves entries without 'hidden' (component details) to the end of the array.
	 * 2. Adds a 'wcs-last-subscription-detail' CSS class to the last subscription detail.
	 * 3. A CSS rule scoped to .wc-block-cart hides the separator on that class (see index.scss).
	 *
	 * @param  array $item_data Cart item data.
	 * @param  array $cart_item Cart item.
	 * @return array
	 */
	public static function hide_container_component_details_in_blocks( $item_data, $cart_item ) {

		if ( ! self::is_bundle_type_container_cart_item( $cart_item ) ) {
			return $item_data;
		}

		// Move entries without an explicit 'hidden' property to the end of the array.
		// Bundle/Composite plugins add component details (e.g. "Includes: Polo × 1")
		// without setting 'hidden'. In the block cart, these are CSS-hidden but still
		// counted by the ProductDetails separator logic, causing trailing " / " after
		// subscription details. By moving them to the end, the separator appears on
		// them instead — where it's invisible because they are CSS-hidden.
		$managed   = array();
		$unmanaged = array();

		foreach ( $item_data as $data ) {
			if ( array_key_exists( 'hidden', $data ) ) {
				$managed[] = $data;
			} else {
				$unmanaged[] = $data;
			}
		}

		if ( ! empty( $managed ) && ! empty( $unmanaged ) ) {
			// Mark the last subscription detail so CSS can hide its trailing separator.
			// Managed items include both subscription details (Free trial, Sign up fee)
			// and other hidden items (gifting item_key, etc.) — all have 'hidden' => true.
			// Subscription details are distinguished by 'wcs_subscription_detail' => true
			// and are the ones visually displayed in the cart. We must target the last one
			// so its trailing separator (leading into hidden/unmanaged items) is hidden.
			// The Blocks ProductDetails component uses `className` if set, otherwise it
			// auto-generates from the name. We must include both the auto-generated class
			// and our marker class to avoid overriding the default.
			$last_detail_key = null;

			foreach ( $managed as $key => $data ) {
				if ( ! empty( $data['wcs_subscription_detail'] ) ) {
					$last_detail_key = $key;
				}
			}

			// Fall back to the very last managed item if no subscription details found.
			if ( null === $last_detail_key ) {
				$last_detail_key = array_key_last( $managed );
			}

			$name                                     = isset( $managed[ $last_detail_key ]['name'] ) ? $managed[ $last_detail_key ]['name'] : '';
			$default                                  = $name ? 'wc-block-components-product-details__' . str_replace( '_', '-', sanitize_title( $name ) ) : '';
			$managed[ $last_detail_key ]['className'] = trim( $default . ' wcs-last-subscription-detail' );
		}

		return array_merge( $managed, $unmanaged );
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks - Cart Templates
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add subscription details next to price of per-item-priced bundle-type container cart items.
	 *
	 * @param  string $price
	 * @param  array  $cart_item
	 * @param  string $cart_item_key
	 * @return string
	 */
	public static function filter_container_item_price( $price, $cart_item, $cart_item_key ) {

		// MnM container subtotals originally modified by WCS are not overwritten by MnM.
		if ( $cart_item['data']->is_type( 'mix-and-match' ) ) {
			return $price;
		}

		if ( self::is_bundle_type_container_cart_item( $cart_item ) && self::has_scheme_data( $cart_item ) ) {

			if ( ! WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'] ) ) {
				return $price;
			}

			// Not aggregating subtotals? Then PB hasn't filtered 'woocommerce_cart_item_price'.
			if ( function_exists( 'wc_pb_is_bundle_container_cart_item' ) && wc_pb_is_bundle_container_cart_item( $cart_item ) && ! WC_Product_Bundle::group_mode_has( $cart_item['data']->get_group_mode(), 'aggregated_subtotals' ) ) {
				return $price;
				// Not aggregating subtotals? Then CP hasn't filtered 'woocommerce_cart_item_price'.
			} elseif ( function_exists( 'wc_cp_is_composite_container_cart_item' ) && wc_cp_is_composite_container_cart_item( $cart_item ) && ! apply_filters( 'woocommerce_add_composited_cart_item_subtotals', true, $cart_item, $cart_item_key ) ) {
				return $price;
				/*
				* PB/CP has done something here, so unless APFS is adding plan options next to the cart item, the billing schedule might be missing.
				* See 'WCS_ATT_Display_Cart::show_cart_item_subscription_options'
				*/
			}

			if ( $scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'], 'object' ) ) {

				if ( false === strpos( $price, 'subscription-details' ) && has_filter( 'woocommerce_cart_item_price', array( 'WCS_ATT_Display_Cart', 'show_cart_item_subscription_options' ), 1000 ) ) {
					$price = WCS_ATT_Product_Prices::get_price_string(
						$cart_item['data'],
						array(
							'price' => $price,
						)
					);
				}
			}
		}

		return $price;
	}

	/**
	 * Add subscription details next to subtotal of per-item-priced bundle-type container cart items.
	 *
	 * @param  string $subtotal
	 * @param  array  $cart_item
	 * @param  string $cart_item_key
	 * @return string
	 */
	public static function filter_container_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {

		// MnM container subtotals originally modified by WCS are not overwritten by MnM.
		if ( $cart_item['data']->is_type( 'mix-and-match' ) ) {
			return $subtotal;
		}

		if ( self::is_bundle_type_container_cart_item( $cart_item ) && self::has_scheme_data( $cart_item ) ) {

			if ( function_exists( 'wc_pb_is_bundle_container_cart_item' ) && wc_pb_is_bundle_container_cart_item( $cart_item ) && ! WC_Product_Bundle::group_mode_has( $cart_item['data']->get_group_mode(), 'aggregated_subtotals' ) ) {
				return $subtotal;
			} elseif ( function_exists( 'wc_cp_is_composite_container_cart_item' ) && wc_cp_is_composite_container_cart_item( $cart_item ) && ! apply_filters( 'woocommerce_add_composited_cart_item_subtotals', true, $cart_item, $cart_item_key ) ) {
				return $subtotal;
			}

			if ( $scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'], 'object' ) ) {

				if ( false === strpos( $subtotal, 'subscription-details' ) ) {
					$subtotal = WCS_ATT_Product_Prices::get_price_string(
						$cart_item['data'],
						array(
							'price' => $subtotal,
						)
					);
				}
			}

			if ( WCS_ATT()->is_module_registered( 'manage' ) && WC_Subscriptions_Switcher::cart_contains_switches() ) {
				$subtotal = WC_Subscriptions_Switcher::add_cart_item_switch_direction( $subtotal, $cart_item, $cart_item_key );
			}
		}

		return $subtotal;
	}

	/**
	 * Modify bundle container cart item subscription options to include child item prices.
	 *
	 * @param  array  $options
	 * @param  array  $subscription_schemes
	 * @param  array  $cart_item
	 * @param  string $cart_item_key
	 * @return boolean
	 */
	public static function container_item_options( $options, $subscription_schemes, $cart_item, $cart_item_key ) {

		$child_items = self::get_bundle_type_cart_items( $cart_item );

		if ( ! empty( $child_items ) ) {

			$product                        = $cart_item['data'];
			$price_filter_exists            = WCS_ATT_Product_Schemes::price_filter_exists( $subscription_schemes );
			$force_subscription             = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product );
			$active_subscription_scheme_key = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
			$scheme_keys                    = array_merge( $force_subscription ? array() : array( false ), array_keys( $subscription_schemes ) );

			if ( $price_filter_exists ) {

				$bundle_price = array();

				foreach ( $scheme_keys as $scheme_key ) {
					$price_key                  = WCS_ATT_Product_Schemes::stringify_subscription_scheme_key( $scheme_key );
					$bundle_price[ $price_key ] = self::calculate_container_item_price( $cart_item, $scheme_key );
				}

				$options = array();

				// Non-recurring (one-time) option.
				if ( false === $force_subscription ) {

					$options[] = array(
						'class'       => 'one-time-option',
						'description' => wc_price( $bundle_price['0'] ),
						'value'       => '0',
						'selected'    => false === $active_subscription_scheme_key,
					);
				}

				// Subscription options.
				foreach ( $subscription_schemes as $subscription_scheme ) {

					$subscription_scheme_key = $subscription_scheme->get_key();

					$description = WCS_ATT_Product_Prices::get_price_string(
						$product,
						array(
							'scheme_key' => $subscription_scheme_key,
							'price'      => wc_price( $bundle_price[ $subscription_scheme_key ] ),
						)
					);

					$options[] = array(
						'class'       => 'subscription-option',
						'description' => $description,
						'value'       => $subscription_scheme_key,
						'selected'    => $active_subscription_scheme_key === $subscription_scheme_key,
					);
				}
			}
		}

		return $options;
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks - Subscriptions View
	|--------------------------------------------------------------------------
	*/

	/**
	 * Don't count bundle-type child items and hidden bundle-type container/child items.
	 *
	 * @param  boolean         $can
	 * @param  WC_Subscription $subscription
	 * @return boolean
	 */
	public static function can_remove_subscription_items( $can, $subscription ) {

		if ( $can ) {

			$items    = $subscription->get_items();
			$count    = count( $items );
			$subtract = 0;

			foreach ( $items as $item ) {

				if ( self::is_bundle_type_container_order_item( $item, $subscription ) ) {

					$parent_item_visible = apply_filters( 'woocommerce_order_item_visible', true, $item );

					if ( ! $parent_item_visible ) {
						$subtract += 1;
					}

					$bundled_order_items = self::get_bundle_type_order_items( $item, $subscription );

					foreach ( $bundled_order_items as $bundled_item_key => $bundled_order_item ) {
						if ( ! $parent_item_visible ) {
							if ( ! apply_filters( 'woocommerce_order_item_visible', true, $bundled_order_item ) ) {
								$subtract += 1;
							}
						} else {
							$subtract += 1;
						}
					}
				}
			}

			$can = $count - $subtract > 1;
		}

		return $can;
	}

	/**
	 * Prevent direct removal of child subscription items from 'My Account > Subscriptions'.
	 * Does ~nothing~ to prevent removal at an application level, e.g. via a REST API call.
	 *
	 * @param  boolean         $can
	 * @param  WC_Order_Item   $item
	 * @param  WC_Subscription $subscription
	 * @return boolean
	 */
	public static function can_remove_child_subscription_item( $can, $item, $subscription ) {

		if ( self::is_bundle_type_order_item( $item, $subscription ) ) {
			$can = false;
		}

		return $can;
	}

	/**
	 * Handle parent subscription line item removals under 'My Account > Subscriptions'.
	 *
	 * @param  WC_Order_Item $item
	 * @param  WC_Order      $subscription
	 * @return void
	 */
	public static function user_removed_parent_subscription_item( $item, $subscription ) {

		if ( self::is_bundle_type_container_order_item( $item, $subscription ) ) {

			$bundled_items     = self::get_bundle_type_order_items( $item, $subscription );
			$bundled_item_keys = array();

			if ( ! empty( $bundled_items ) ) {
				foreach ( $bundled_items as $bundled_item ) {

					$bundled_item_keys[] = $bundled_item->get_id();

					$bundled_product_id = wcs_get_canonical_product_id( $bundled_item );

					// Remove the line item from subscription but preserve its data in the DB.
					wcs_update_order_item_type( $bundled_item->get_id(), 'line_item_removed', $subscription->get_id() );

					WCS_Download_Handler::revoke_downloadable_file_permission( $bundled_product_id, $subscription->get_id(), $subscription->get_user_id() );

					// Add order note.
					$subscription->add_order_note( sprintf( _x( '"%1$s" (Product ID: #%2$d) removal triggered by "%3$s" via the My Account page.', 'used in order note', 'woocommerce-subscriptions' ), wcs_get_line_item_name( $bundled_item ), $bundled_product_id, wcs_get_line_item_name( $item ) ) );

					// Trigger WCS action.
					do_action( 'wcs_user_removed_item', $bundled_item, $subscription );
				}

				// Update session data for un-doing.
				$removed_bundled_item_ids                    = WC()->session->get( 'removed_bundled_subscription_items', array() );
				$removed_bundled_item_ids[ $item->get_id() ] = $bundled_item_keys;
				WC()->session->set( 'removed_bundled_subscription_items', $removed_bundled_item_ids );
			}
		}
	}

	/**
	 * Handle parent subscription line item re-additions under 'My Account > Subscriptions'.
	 *
	 * @param  WC_Order_Item $item
	 * @param  WC_Order      $subscription
	 * @return void
	 */
	public static function user_readded_parent_subscription_item( $item, $subscription ) {

		if ( self::is_bundle_type_container_order_item( $item, $subscription ) ) {

			$removed_bundled_item_ids = WC()->session->get( 'removed_bundled_subscription_items', array() );
			$removed_bundled_item_ids = isset( $removed_bundled_item_ids[ $item->get_id() ] ) ? $removed_bundled_item_ids[ $item->get_id() ] : array();

			if ( ! empty( $removed_bundled_item_ids ) ) {

				foreach ( $removed_bundled_item_ids as $removed_bundled_item_id ) {

					// Update the line item type.
					wcs_update_order_item_type( $removed_bundled_item_id, 'line_item', $subscription->get_id() );
				}
			}

			$bundled_items = self::get_bundle_type_order_items( $item, $subscription );

			if ( ! empty( $bundled_items ) ) {
				foreach ( $bundled_items as $bundled_item ) {

					$bundled_product    = $subscription->get_product_from_item( $bundled_item );
					$bundled_product_id = wcs_get_canonical_product_id( $bundled_item );

					if ( $bundled_product && $bundled_product->exists() && $bundled_product->is_downloadable() ) {

						$downloads = wcs_get_objects_property( $bundled_product, 'downloads' );

						foreach ( array_keys( $downloads ) as $download_id ) {
							wc_downloadable_file_permission( $download_id, $bundled_product_id, $subscription, $bundled_item['qty'] );
						}
					}

					// Add order note.
					$subscription->add_order_note( sprintf( _x( '"%1$s" (Product ID: #%2$d) removal un-done by "%3$s" via the My Account page.', 'used in order note', 'woocommerce-subscriptions' ), wcs_get_line_item_name( $bundled_item ), wcs_get_canonical_product_id( $bundled_item ), wcs_get_line_item_name( $item ) ) );

					// Trigger WCS action.
					do_action( 'wcs_user_readded_item', $bundled_item, $subscription );
				}
			}
		}
	}

	/**
	 * Add extra 'Allow Switching' options for content switching of Bundles/Composites. See 'WCS_ATT_Admin::allow_switching_options'.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  array $data
	 * @return array
	 */
	public static function add_bundle_switching_options( $data ) {

		if ( class_exists( 'WC_Bundles' ) ) {

			$switch_option_bundle_contents = get_option( 'woocommerce_subscriptions_allow_switching_product_bundle_contents', '' );

			if ( '' === $switch_option_bundle_contents ) {
				update_option( 'woocommerce_subscriptions_allow_switching_product_bundle_contents', 'yes' );
			}

			$data[] = array(
				'id'    => 'product_bundle_contents',
				'label' => __( 'Between Product Bundle Configurations', 'woocommerce-subscriptions' ),
			);
		}

		if ( class_exists( 'WC_Composite_Products' ) ) {

			$switch_option_composite_contents = get_option( 'woocommerce_subscriptions_allow_switching_composite_product_contents', '' );

			if ( '' === $switch_option_composite_contents ) {
				update_option( 'woocommerce_subscriptions_allow_switching_composite_product_contents', 'yes' );
			}

			$data[] = array(
				'id'    => 'composite_product_contents',
				'label' => __( 'Between Composite Product Configurations', 'woocommerce-subscriptions' ),
			);
		}

		return $data;
	}

	/**
	 * Prevent direct switching of child subscription items from 'My Account > Subscriptions'.
	 * Allow content switching for parent items only, which means that a matching scheme must exist.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  boolean         $can
	 * @param  WC_Order_Item   $item
	 * @param  WC_Subscription $subscription
	 * @return boolean
	 */
	public static function can_switch_bundle_type_item( $can, $item, $subscription ) {

		$is_bundle_type_order_item           = self::is_bundle_type_order_item( $item, $subscription );
		$is_bundle_type_container_order_item = self::is_bundle_type_container_order_item( $item, $subscription );

		if ( $is_bundle_type_container_order_item && ! $is_bundle_type_order_item ) {

			// See 'WCS_ATT_Manage_Switch::can_switch_item'.

		} elseif ( $is_bundle_type_order_item ) {

			// Don't render 'Upgrade/Downgrade' button for child items: Switches are handled through the parent!
			if ( doing_action( 'woocommerce_order_item_meta_end' ) ) {
				$can = false;
				// If the parent is switchable, then the child is switchable, too!
			} elseif ( WC_Subscriptions_Switcher::cart_contains_switches() ) {
					$can = WC_Subscriptions_Switcher::can_item_be_switched( self::get_bundle_type_order_item_container( $item, $subscription ), $subscription );
			} else {
				$can = false;
			}
		}

		return $can;
	}

	/**
	 * Add content switching support to Bundles and Composites.
	 *
	 * @param  bool       $is_feature_supported
	 * @param  WC_Product $product
	 * @param  string     $feature
	 * @param  array      $args
	 * @return bool
	 */
	public static function bundle_supports_switching( $is_feature_supported, $product, $feature, $args ) {

		if ( 'subscription_scheme_switching' === $feature && self::is_bundle_type_product( $product ) ) {

			$is_feature_supported = false;

		} elseif ( 'subscription_content_switching' === $feature && self::is_bundle_type_product( $product ) ) {

			// Switching Bundles/Composites required changes in WCS that are available after v2.6.0.
			if ( version_compare( WC_Subscriptions::$version, '2.6.0' ) >= 0 ) {

				$subscription_has_fixed_length = isset( $args['subscription'] ) ? $args['subscription']->get_time( 'end', '' ) : false;
				// Length Proration must be enabled for switching to be possible when the current subscription/plan has a fixed length.
				if ( $subscription_has_fixed_length && 'yes' !== get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'no' ) ) {

					$is_feature_supported = false;

				} elseif ( $product->is_type( 'bundle' ) ) {

						$option_value = get_option( 'woocommerce_subscriptions_allow_switching_product_bundle_contents', 'yes' );

					if ( 'no' !== $option_value ) {
						$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
						$is_feature_supported = count( $subscription_schemes ) && ( $product->contains( 'options' ) || $product->contains( 'priced_indefinitely' ) );
					}
				} elseif ( $product->is_type( 'composite' ) ) {

						$option_value = get_option( 'woocommerce_subscriptions_allow_switching_composite_product_contents', 'yes' );

					if ( 'no' !== $option_value ) {
						$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
						$is_feature_supported = count( $subscription_schemes );
					}
				}
			}
		}

		return $is_feature_supported;
	}

	/**
	 * Make WCS see bundles with a switched content as non-identical ones.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  boolean       $is_identical
	 * @param  int           $product_id
	 * @param  int           $quantity
	 * @param  int           $variation_id
	 * @param  WC_Order      $subscription
	 * @param  WC_Order_Item $item
	 * @return boolean
	 */
	public static function bundle_is_identical( $is_identical, $product_id, $quantity, $variation_id, $subscription, $item ) {

		if ( $is_identical ) {

			if ( self::is_bundle_type_container_order_item( $item, $subscription ) ) {

				$product = wc_get_product( $product_id );

				if ( $product->is_type( 'bundle' ) ) {

					$configuration = WC_PB()->cart->get_posted_bundle_configuration( $product_id );

					foreach ( $configuration as $bundled_item_id => $bundled_item_configuration ) {

						/**
						 * 'woocommerce_bundled_item_cart_item_identifier' filter.
						 *
						 * Filters the config data array - use this to add any bundle-specific data that should result in unique container item ids being produced when the input data changes, such as add-ons data.
						 *
						 * @param  array  $posted_item_config
						 * @param  int    $bundled_item_id
						 * @param  mixed  $product_id
						 */
						$configuration[ $bundled_item_id ] = apply_filters( 'woocommerce_bundled_item_cart_item_identifier', $bundled_item_configuration, $bundled_item_id, $product_id );
					}

					$is_identical = $item->get_meta( '_stamp', true ) === $configuration;

				} elseif ( $product->is_type( 'composite' ) ) {

					$configuration = WC_CP()->cart->get_posted_composite_configuration( $product_id );

					foreach ( $configuration as $composited_item_id => $composited_item_configuration ) {

						/**
						 * 'woocommerce_composited_item_cart_item_identifier' filter.
						 *
						 * Filters the config data array - use this to add any composite-specific data that should result in unique container item ids being produced when the input data changes, such as add-ons data.
						 *
						 * @param  array  $posted_item_config
						 * @param  int    $composited_item_id
						 * @param  mixed  $product_id
						 */
						$configuration[ $composited_item_id ] = apply_filters( 'woocommerce_composited_item_cart_item_identifier', $composited_item_configuration, $composited_item_id, $product_id );
					}

					$is_identical = $item->get_meta( '_composite_data', true ) === $configuration;
				}
			}
		}

		return $is_identical;
	}

	/**
	 * Match a subscription line item to its corresponding order item by bundle/composite slot identifier.
	 *
	 * When the same product appears in multiple bundle/composite slots at different prices,
	 * product ID matching alone is insufficient. This method matches by `_bundled_item_id`
	 * or `_composite_item` meta to find the correct order item.
	 *
	 * @param  WC_Order_Item|null     $matched_item  The currently matched item (null if none).
	 * @param  WC_Order_Item_Product  $line_item     The subscription line item.
	 * @param  WC_Order               $parent_order  The parent order.
	 * @param  WC_Subscription        $subscription  The subscription. Unused but part of the filter signature.
	 * @return WC_Order_Item|null The matched order item, or null if no match found.
	 */
	public static function match_order_item_for_sign_up_fee( $matched_item, $line_item, $parent_order, $subscription ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( null !== $matched_item ) {
			return $matched_item;
		}

		$slot_meta_keys    = array( '_bundled_item_id', '_composite_item' );
		$line_item_slot_id = '';

		foreach ( $slot_meta_keys as $meta_key ) {
			$meta_value = $line_item->get_meta( $meta_key, true );
			if ( '' !== $meta_value && false !== $meta_value ) {
				$line_item_slot_id = (string) $meta_value;
				break;
			}
		}

		if ( '' === $line_item_slot_id ) {
			return null;
		}

		foreach ( $parent_order->get_items() as $order_item ) {
			foreach ( $slot_meta_keys as $meta_key ) {
				$order_slot_id = $order_item->get_meta( $meta_key, true );
				if ( '' !== $order_slot_id && false !== $order_slot_id && (string) $order_slot_id === $line_item_slot_id ) {
					return $order_item;
				}
			}
		}

		return null;
	}

	/**
	 * Retrieve subscription switch-related parameters of child items from the parent cart item data array.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  array $bundled_item_cart_data
	 * @param  array $cart_item_data
	 * @return array
	 */
	public static function bundled_item_switch_cart_data( $bundled_item_cart_data, $cart_item_data ) {

		if ( ! isset( $_GET['switch-subscription'] ) ) {
			return $bundled_item_cart_data;
		}

		if ( empty( $cart_item_data['subscription_switch'] ) ) {
			return $bundled_item_cart_data;
		}

		if ( ! isset( $cart_item_data['subscription_switch']['subscription_id'], $cart_item_data['subscription_switch']['item_id'], $cart_item_data['subscription_switch']['next_payment_timestamp'] ) ) {
			return $bundled_item_cart_data;
		}

		$subscription_id   = $cart_item_data['subscription_switch']['subscription_id'];
		$container_item_id = $cart_item_data['subscription_switch']['item_id'];

		$bundled_item_cart_data['subscription_switch'] = array(
			'subscription_id'        => $subscription_id,
			'item_id'                => '',
			'next_payment_timestamp' => $cart_item_data['subscription_switch']['next_payment_timestamp'],
			'upgraded_or_downgraded' => '',
		);

		$subscription = wcs_get_subscription( $subscription_id );

		if ( $container_item_id ) {

			$parent_item         = wcs_get_order_item( $container_item_id, $subscription );
			$bundled_item_id     = $bundled_item_cart_data['bundled_item_id'];
			$bundled_order_items = wc_pb_get_bundled_order_items( $parent_item, $subscription );

			foreach ( $bundled_order_items as $bundled_order_item_id => $bundled_order_item ) {
				if ( absint( $bundled_item_id ) === absint( $bundled_order_item->get_meta( '_bundled_item_id', true ) ) ) {
					$bundled_item_cart_data['subscription_switch']['item_id'] = $bundled_order_item_id;
					break;
				}
			}
		}

		return $bundled_item_cart_data;
	}

	/**
	 * Retrieve subscription switch-related parameters of child items from the parent cart item data array.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  array $composited_item_cart_data
	 * @param  array $cart_item_data
	 * @return array
	 */
	public static function composited_item_switch_cart_data( $composited_item_cart_data, $cart_item_data ) {

		if ( ! isset( $_GET['switch-subscription'] ) ) {
			return $composited_item_cart_data;
		}

		if ( empty( $cart_item_data['subscription_switch'] ) ) {
			return $composited_item_cart_data;
		}

		if ( ! isset( $cart_item_data['subscription_switch']['subscription_id'], $cart_item_data['subscription_switch']['item_id'], $cart_item_data['subscription_switch']['next_payment_timestamp'] ) ) {
			return $composited_item_cart_data;
		}

		$subscription_id   = $cart_item_data['subscription_switch']['subscription_id'];
		$container_item_id = $cart_item_data['subscription_switch']['item_id'];

		$composited_item_cart_data['subscription_switch'] = array(
			'subscription_id'        => $subscription_id,
			'item_id'                => '',
			'next_payment_timestamp' => $cart_item_data['subscription_switch']['next_payment_timestamp'],
			'upgraded_or_downgraded' => '',
		);

		$subscription = wcs_get_subscription( $subscription_id );
		$parent_item  = wcs_get_order_item( $container_item_id, $subscription );

		$composited_item_id     = $composited_item_cart_data['composite_item'];
		$composited_order_items = wc_cp_get_composited_order_items( $parent_item, $subscription );

		foreach ( $composited_order_items as $composited_order_item_id => $composited_order_item ) {
			if ( absint( $composited_item_id ) === absint( $composited_order_item->get_meta( '_composite_item', true ) ) ) {
				$composited_item_cart_data['subscription_switch']['item_id'] = $composited_order_item_id;
				break;
			}
		}

		return $composited_item_cart_data;
	}

	/**
	 * Restore bundle configuration when switching.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  string          $url
	 * @param  int             $item_id
	 * @param  WC_Order_Item   $item
	 * @param  WC_Subscription $subscription
	 * @return string
	 */
	public static function bundle_type_switch_configuration_url( $url, $item_id, $item, $subscription ) {

		if ( self::is_bundle_type_container_order_item( $item, $subscription ) ) {

			if ( class_exists( 'WC_Bundles' ) && $configuration = WC_PB_Order::get_current_bundle_configuration( $item, $subscription ) ) {

				$args = WC_PB()->cart->rebuild_posted_bundle_form_data( $configuration );

				$args_data = array_map( 'urlencode', $args );
				$args_keys = array_map( 'urlencode', array_keys( $args ) );

				if ( ! empty( $args ) ) {
					$url = add_query_arg( array_combine( $args_keys, $args_data ), $url );
				}
			} elseif ( class_exists( 'WC_Composite_Products' ) && $configuration = WC_CP_Order::get_current_composite_configuration( $item, $subscription ) ) {

				$args = WC_CP()->cart->rebuild_posted_composite_form_data( $configuration );
				$args = WC_CP_Helpers::urlencode_recursive( $args );

				if ( ! empty( $args ) ) {
					$url = add_query_arg( $args, $url );
				}
			}
		}

		// It's safe to ignore the warning. The url returned is escaped downstream in class-wc-subscriptions-switcher.php .
		// nosemgrep: audit.php.wp.security.xss.query-arg
		return $url;
	}

	/**
	 * Changes the order item status of old child items when the new parent is added.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  WC_Order        $order
	 * @param  WC_Subscription $subscription
	 * @param  int             $adding_item_id
	 * @param  int             $removing_item_id
	 * @return void
	 */
	public static function remove_switched_subscription_child_items( $order, $subscription, $adding_item_id, $removing_item_id ) {

		$removing_item = $subscription->get_item( $removing_item_id );

		if ( $child_items = self::get_bundle_type_order_items( $removing_item, $subscription, true, true ) ) {
			foreach ( $child_items as $child_item ) {
				wcs_update_order_item_type( $child_item, 'line_item_switched', $subscription->get_id() );
			}
		}
	}

	/**
	 * Disallow plan switching for bundle types. Only content switching permitted!
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  boolean    $is_forced
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function force_switched_bundle_type_subscription( $is_forced, $product ) {

		if ( self::is_bundle_type_product( $product ) ) {
			if ( ! $is_forced && WCS_ATT_Manage_Switch::is_switch_request() ) {
				$is_forced = WCS_ATT_Manage_Switch::is_switch_request_for_product( $product );
			}
		}

		return $is_forced;
	}

	/**
	 * Bundle schemes should be limited to the one matching the subscription while the product is being switched.
	 * This is the meaning of 'content switching': It's not permitted to apply plan changes, only content changes are allowed.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  array      $schemes
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function limit_switched_bundle_type_schemes( $schemes, $product ) {

		if ( self::is_bundle_type_product( $product ) ) {
			if ( WCS_ATT_Manage_Switch::is_switch_request_for_product( $product ) ) {

				if ( ! isset( $_GET['switch-subscription'] ) ) {
					return $schemes;
				}

				$subscription = wcs_get_subscription( absint( $_GET['switch-subscription'] ) );

				if ( ! $subscription ) {
					return $schemes;
				}

				// Does a matching scheme exist?
				foreach ( $schemes as $scheme_id => $scheme ) {
					if ( $scheme->matches_subscription( $subscription, array( 'upcoming_renewals' => false ) ) ) {
						$schemes = array( $scheme_id => $scheme );
						break;
					}
				}

				// We should never make any plans available for switching here if a match was not found.
				if ( count( $schemes ) > 1 ) {
					$schemes = array();
				}
			}
		}

		return $schemes;
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks - Add to Subscription
	|--------------------------------------------------------------------------
	*/

	/**
	 * Modify the validation context when adding a bundle-type product to an order.
	 *
	 * @param  int $product_id
	 */
	public static function set_bundle_type_validation_context( $product_id ) {
		add_filter( 'woocommerce_composite_validation_context', array( __CLASS__, 'set_add_to_order_validation_context' ) );
		add_filter( 'woocommerce_bundle_validation_context', array( __CLASS__, 'set_add_to_order_validation_context' ) );
		add_filter( 'woocommerce_add_to_order_bundle_validation', array( __CLASS__, 'validate_bundle_type_stock' ), 10, 4 );
		add_filter( 'woocommerce_add_to_order_composite_validation', array( __CLASS__, 'validate_bundle_type_stock' ), 10, 4 );
	}

	/**
	 * Modify the validation context when adding a bundle-type product to an order.
	 *
	 * @param  int $product_id
	 */
	public static function reset_bundle_type_validation_context( $product_id ) {
		remove_filter( 'woocommerce_composite_validation_context', array( __CLASS__, 'set_add_to_order_validation_context' ) );
		remove_filter( 'woocommerce_bundle_validation_context', array( __CLASS__, 'set_add_to_order_validation_context' ) );
		remove_filter( 'woocommerce_add_to_order_bundle_validation', array( __CLASS__, 'validate_bundle_type_stock' ) );
		remove_filter( 'woocommerce_add_to_order_composite_validation', array( __CLASS__, 'validate_bundle_type_stock' ) );
	}

	/**
	 * Sets the validation context to 'add-to-order'.
	 *
	 * @param  WC_Product_Bundle $bundle
	 */
	public static function set_add_to_order_validation_context( $product ) {
		return 'add-to-order';
	}

	/**
	 * Validates bundle-type stock in 'add-to-order' context.
	 *
	 * @param  boolean $is_valid
	 */
	public static function validate_bundle_type_stock( $is_valid, $bundle_id, $stock_manager, $configuration ) {

		if ( $is_valid ) {

			try {

				$stock_manager->validate_stock(
					array(
						'throw_exception' => true,
						'context'         => 'add-to-order',
					)
				);

			} catch ( Exception $e ) {

				$notice = $e->getMessage();

				if ( $notice ) {
					wc_add_notice( $notice, 'error' );
				}

				$is_valid = false;
			}
		}

		return $is_valid;
	}

	/**
	 * Don't attempt to increment the quantity of bundle-type subscription items when adding to an existing subscription.
	 * Also omit child items -- they'll be added by their parent.
	 *
	 * @param  false|WC_Order_Item_Product $found_order_item
	 * @param  array                       $matching_cart_item
	 * @param  WC_Cart                     $recurring_cart
	 * @param  WC_Subscription             $subscription
	 * @param  WC_Order_Item               $order_item
	 * @return false|WC_Order_Item_Product
	 */
	public static function found_bundle_in_subscription( $found_order_item, $matching_cart_item, $recurring_cart, $subscription, $order_item ) {

		if ( $found_order_item ) {
			if ( self::is_bundle_type_product( $matching_cart_item['data'] ) ) {
				$found_order_item = false;
			} elseif ( self::is_bundle_type_cart_item( $matching_cart_item, $recurring_cart->cart_contents ) ) {
				$found_order_item = false;
			} elseif ( self::is_bundle_type_order_item( $order_item, $subscription ) ) {
				$found_order_item = false;
			}
		}

		return $found_order_item;
	}

	/**
	 * Return 'add_bundle_to_order' as a callback for adding bundles to subscriptions.
	 * Do not add child items as they'll be added by their parent.
	 *
	 * @param  array   $callback
	 * @param  array   $cart_item
	 * @param  WC_Cart $recurring_cart
	 */
	public static function add_bundle_to_subscription_callback( $callback, $cart_item, $recurring_cart ) {

		if ( self::is_bundle_type_container_cart_item( $cart_item, $recurring_cart->cart_contents ) ) {

			if ( $cart_item['data']->is_type( 'bundle' ) ) {
				$callback = array( __CLASS__, 'add_bundle_to_order' );
			} elseif ( $cart_item['data']->is_type( 'composite' ) ) {
				$callback = array( __CLASS__, 'add_composite_to_order' );
			}
		} elseif ( self::is_bundle_type_cart_item( $cart_item, $recurring_cart->cart_contents ) ) {
			$callback = null;
		}

		return $callback;
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks - Bundles
	|--------------------------------------------------------------------------
	*/

	/**
	 * Build a fingerprint of the bundle's effective scheme state.
	 *
	 * @param  WC_Product_Bundle $bundle         Bundle.
	 * @param  array             $bundled_items  Bundle Items.
	 * @return string
	 */
	private static function build_bundle_fingerprint( $bundle, $bundled_items ) {
		$schemes      = WCS_ATT_Product_Schemes::get_subscription_schemes( $bundle );
		$schemes_hash = '';
		foreach ( (array) $schemes as $scheme ) {
			$schemes_hash .= $scheme->get_hash();
		}

		$active_key     = WCS_ATT_Product_Schemes::get_subscription_scheme( $bundle, 'key' );
		$active_token   = is_null( $active_key ) ? 'null' : ( false === $active_key ? 'false' : (string) $active_key );
		$forced_token   = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $bundle ) ? '1' : '0';
		$item_ids_token = implode( ',', array_keys( $bundled_items ) );

		$fingerprint = md5( implode( '|', array( $schemes_hash, $active_token, $forced_token, $item_ids_token ) ) );
		return $fingerprint;
	}

	/**
	 * When loading bundled items, always set the active bundle scheme on the bundled objects.
	 *
	 * @param  array             $bundled_items
	 * @param  WC_Product_Bundle $bundle
	 */
	public static function set_bundled_items_scheme( $bundled_items, $bundle ) {
		if ( empty( $bundled_items ) || ! $bundle->is_synced() ) {
			return $bundled_items;
		}

		// Set the default scheme when one-time purchases are disabled, no scheme is set on the object, and only a single sub scheme exists.
		if ( WCS_ATT_Product_Schemes::has_single_forced_subscription_scheme( $bundle ) && ! WCS_ATT_Product_Schemes::get_subscription_scheme( $bundle ) ) {
			WCS_ATT_Product_Schemes::set_subscription_scheme( $bundle, WCS_ATT_Product_Schemes::get_default_subscription_scheme( $bundle ) );
		}

		// Early return if nothing has changed since last time.
		if ( ! self::bundle_changed( $bundle, $bundled_items, $out_fingerprint ) ) {
			return $bundled_items;
		}

		if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $bundle ) ) {
			foreach ( $bundled_items as $bundled_item ) {
				self::set_bundled_item_scheme( $bundled_item, $bundle );
			}
		} else {
			foreach ( $bundled_items as $bundled_item ) {
				self::reset_bundled_item_scheme( $bundled_item );
			}
		}

		WCS_ATT_Product::set_runtime_meta( $bundle, 'apfs_bundled_items_fp', $out_fingerprint );

		return $bundled_items;
	}

	/**
	 * Clear all subscription schemes from a bundled item's product.
	 *
	 * @param WC_Bundled_Item $bundled_item Bundled Item.
	 * @return void
	 */
	private static function reset_bundled_item_scheme( $bundled_item ) {
		if ( is_callable( array( $bundled_item, 'get_product' ) ) ) {
			$bundled_product = $bundled_item->get_product();
			if ( $bundled_product ) {
				WCS_ATT_Product_Schemes::set_subscription_schemes( $bundled_product, array() );
			}
		}
	}

	/**
	 * Compare current fingerprint to last stored one.
	 *
	 * @param  WC_Product_Bundle $bundle Bundle.
	 * @param  array             $bundled_items Bundled Items.
	 * @param  string            $out_fp  (by reference) current fingerprint.
	 * @return bool                       true if changed (i.e., we should update children)
	 */
	private static function bundle_changed( $bundle, $bundled_items, &$out_fp = null ) {
		$out_fp  = self::build_bundle_fingerprint( $bundle, $bundled_items );
		$last_fp = WCS_ATT_Product::get_runtime_meta( $bundle, 'apfs_bundled_items_fp' );
		return $last_fp !== $out_fp;
	}

	/**
	 * Add scheme data to runtime price cache hashes.
	 *
	 * @param  array             $hash
	 * @param  WC_Product_Bundle $bundle
	 * @return array
	 */
	public static function bundle_prices_hash( $hash, $bundle ) {

		if ( $scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $bundle ) ) {
			$hash['satt_scheme'] = $scheme;
		}

		return $hash;
	}

	/**
	 * Remove APFS price filters before retrieving the bundled item Regular Price.
	 */
	public static function remove_price_filters() {
		WCS_ATT_Product_Price_Filters::remove( 'price' );
	}

	/**
	 * Re-add APFS price filters after retrieving the bundled item Regular Price.
	 */
	public static function add_price_filters() {
		WCS_ATT_Product_Price_Filters::add( 'price' );
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks - Composites
	|--------------------------------------------------------------------------
	*/

	/**
	 * Set the default scheme when one-time purchases are disabled, no scheme is set on the object, and only a single sub scheme exists.
	 *
	 * @param  WC_Product_Composite $composite
	 */
	public static function set_single_composite_subscription_scheme( $composite ) {
		if ( WCS_ATT_Product_Schemes::has_single_forced_subscription_scheme( $composite ) && ! WCS_ATT_Product_Schemes::get_subscription_scheme( $composite ) ) {
			WCS_ATT_Product_Schemes::set_subscription_scheme( $composite, WCS_ATT_Product_Schemes::get_default_subscription_scheme( $composite ) );
		}
	}

	/**
	 * Ensure composites in cached component objects have up-to-date scheme data.
	 *
	 * @param  string     $scheme_key
	 * @param  string     $previous_scheme_key
	 * @param  WC_Product $product
	 */
	public static function set_composite_product_scheme( $scheme_key, $previous_scheme_key, $product ) {

		if ( $product->is_type( 'composite' ) && $scheme_key !== $previous_scheme_key ) {

			/*
			 * Do not propagate a null scheme to other instances. A null scheme is a transient "undefined" state
			 * that can originate from temporary scheme changes during price calculations (e.g. in Store API product
			 * schema responses). Propagating it would incorrectly clear the active scheme from cart item product
			 * objects that share component instances via WC CP's component cache.
			 */
			if ( null === $scheme_key ) {
				return;
			}

			$components = $product->get_components();

			if ( ! empty( $components ) ) {
				foreach ( $components as $component ) {

					if ( WCS_ATT_Product::get_instance_id( $product ) === WCS_ATT_Product::get_instance_id( $component->get_composite() ) ) {
						continue;
					}

					WCS_ATT_Product_Schemes::set_subscription_scheme( $component->get_composite(), WCS_ATT_Product_Schemes::get_subscription_scheme( $product ) );
				}
			}
		}
	}

	/**
	 * Composited products inherit the subscription schemes of their container object.
	 *
	 * @param  WC_CP_Product        $component_option
	 * @param  string               $component_id
	 * @param  WC_Product_Composite $composite
	 */
	public static function set_component_option_scheme( $component_option, $component_id, $composite ) {

		if ( $component_option ) {

			if ( ! $product = $component_option->get_product() ) {
				return $component_option;
			}

			if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $composite ) ) {

				$having = array(
					'price',
					'regular_price',
				);

				$what = array(
					'min',
					'max',
				);

				self::set_bundled_product_subscription_schemes( $product, $composite );

				foreach ( $having as $price ) {
					foreach ( $what as $min_or_max ) {
						if ( $product = $component_option->get_product(
							array(
								'having' => $price,
								'what'   => $min_or_max,
							)
						) ) {
							self::set_bundled_product_subscription_schemes( $product, $composite );
						}
					}
				}
			} else {
				WCS_ATT_Product_Schemes::set_subscription_schemes( $product, array() );
			}
		}

		return $component_option;
	}

	/**
	 * Adds scheme data to runtime component cache hashes.
	 *
	 * @param  array                $hash
	 * @param  WC_Product_Composite $composite
	 * @return array
	 */
	public static function component_hash( $hash, $composite ) {

		if ( $scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $composite ) ) {
			$hash[] = $scheme;
		}

		return $hash;
	}

	/**
	 * Add scheme data to runtime price cache hashes.
	 *
	 * @param  array                $hash
	 * @param  WC_Product_Composite $composite
	 * @return array
	 */
	public static function composite_prices_hash( $hash, $composite ) {

		if ( $scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $composite ) ) {
			$hash['satt_scheme'] = $scheme;
		}

		return $hash;
	}

	/**
	 * Make sure child order items inherit the subscription plans of their parent.
	 *
	 * @since  APFS 3.1.8
	 *
	 * @param  WC_Product    $product
	 * @param  WC_Order_Item $order_item
	 * @return WC_Product
	 */
	public static function restore_bundle_type_product_from_order_item( $product, $order_item ) {

		if ( empty( $product ) || ! ( $product instanceof WC_Product ) ) {
			return $product;
		}

		$parent_product = $order_item->get_id() ? WCS_ATT_Helpers::cache_get( 'order_item_parent_product_' . $order_item->get_id() ) : false;

		if ( is_null( $parent_product ) ) {

			WCS_ATT_Helpers::cache_set( 'order_item_parent_product_' . $order_item->get_id(), false );

			if ( $parent_item = self::get_bundle_type_order_item_container( $order_item ) ) {

				$parent_product = $parent_item->get_product();

				if ( $parent_product ) {
					WCS_ATT_Helpers::cache_set( 'order_item_parent_product_' . $order_item->get_id(), $parent_product );
				}
			}
		}

		if ( ( $parent_product instanceof WC_Product ) && WCS_ATT_Product_Schemes::has_subscription_schemes( $parent_product ) ) {
			self::set_bundled_product_subscription_schemes( $product, $parent_product );
		}

		return $product;
	}

	/**
	 * Calculate correct switch type for bundle containers and force crossgrade to disable proration calculations. Remember to cache the initial value.
	 *
	 * @since APFS 2.4.0
	 *
	 * @param  string          $switch_type
	 * @param  WC_Subscription $subscription
	 * @param  array           $cart_item
	 * @return string
	 */
	public static function force_bundle_switch_type( $switch_type, $subscription, $cart_item ) {

		$is_bundle_type_container_cart_item = self::is_bundle_type_container_cart_item( $cart_item );
		$is_bundle_type_cart_item           = self::is_bundle_type_cart_item( $cart_item );

		// If it's a bundle parent/child item, fake a crossgrade switch type as APFS doesn't support switch proration for these types.
		if ( $is_bundle_type_container_cart_item || $is_bundle_type_cart_item ) {

			// Calculate correct switch type based on aggregated parent/child costs.
			if ( $is_bundle_type_container_cart_item && ! empty( $cart_item['subscription_switch']['item_id'] ) ) {

				$item = $subscription->get_item( $cart_item['subscription_switch']['item_id'] );

				if ( $item instanceof WC_Order_Item_Product ) {
					$aggregated_total_old = $item->get_total();
					$child_items          = self::get_bundle_type_order_items( $item, $subscription, false, true );

					if ( ! empty( $child_items ) ) {
						/** @var WC_Order_Item_Product $child_item */
						foreach ( $child_items as $child_item ) {
							$aggregated_total_old += $child_item->get_total();
						}
					}

					remove_filter( 'woocommerce_product_get_price', 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation', 100 );
					$aggregated_total_new = self::calculate_container_item_subtotal( $cart_item, '', 'excl' );
					add_filter( 'woocommerce_product_get_price', 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation', 100, 2 );

					if ( $aggregated_total_old < $aggregated_total_new ) {
						$switch_type = 'upgrade';
					} elseif ( $aggregated_total_old > $aggregated_total_new && $aggregated_total_new >= 0 ) {
						$switch_type = 'downgrade';
					} else {
						$switch_type = 'crossgrade';
					}
				}
			}

			if ( isset( $cart_item['key'] ) ) {
				self::$cache['wcs_switch_types'][ $cart_item['key'] ] = sprintf( '%sd', $switch_type );
			}

			$switch_type = 'crossgrade';
		}

		return $switch_type;
	}

	/**
	 * Restore initial switch type if applicable.
	 *
	 * @since APFS 2.4.0
	 *
	 * @param  WC_Cart $cart
	 * @return void
	 */
	public static function restore_bundle_switch_type( $cart ) {

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
			if ( self::is_bundle_type_container_cart_item( $cart_item ) || self::is_bundle_type_cart_item( $cart_item ) ) {
				if ( isset( self::$cache['wcs_switch_types'][ $cart_item_key ], $cart_item['subscription_switch'], $cart_item['subscription_switch']['upgraded_or_downgraded'] ) ) {
					WC()->cart->cart_contents[ $cart_item_key ]['subscription_switch']['upgraded_or_downgraded'] = self::$cache['wcs_switch_types'][ $cart_item_key ];
				}
			}
		}
	}
}
