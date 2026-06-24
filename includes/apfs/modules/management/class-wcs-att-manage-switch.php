<?php
/**
 * WCS_ATT_Manage_Switch class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles scheme switching for SATT items.
 *
 * @class    WCS_ATT_Manage_Switch
 * @version  5.0.5
 */
class WCS_ATT_Manage_Switch extends WCS_ATT_Abstract_Module {

	/**
	 * Runtime switched product cache.
	 *
	 * @var WC_Product
	 */
	private static $switched_product;

	/**
	 * Runtime cache.
	 *
	 * @var bool
	 */
	private static $is_switched_product_identical;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_core_hooks() {

		// Allow scheme switching for SATT products with more than 1 scheme.
		add_filter( 'wcs_is_product_switchable', array( __CLASS__, 'is_product_switchable' ), 10, 2 );

		// Prevent content switching when plan switching is disabled and a matching scheme can't be found.
		add_filter( 'woocommerce_subscriptions_can_item_be_switched', array( __CLASS__, 'can_switch_item' ), 5, 3 );

		// Disable one-time purchases when switching.
		add_filter( 'wcsatt_force_subscription', array( __CLASS__, 'force_subscription' ), 10, 2 );

		// Prevent plan switching when 'Between Subscription Plans' is disabled.
		add_filter( 'wcsatt_product_subscription_schemes', array( __CLASS__, 'variable_product_subscription_schemes' ), 10, 2 );

		// Allow WCS to recognize any supported product as a subscription when validating a switch: Add filter.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'add_is_subscription_filter' ), 9 );

		// Allow WCS to recognize any supported product as a subscription when validating a switch: Remove filter.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'remove_is_subscription_filter' ), 11 );

		// Make WCS see products with a switched scheme as non-identical ones.
		add_filter( 'woocommerce_subscriptions_switch_is_identical_product', array( __CLASS__, 'is_identical_product' ), 100, 6 );

		// Prevent variation switching when 'Between Subscription Variations' is disabled.
		add_filter( 'woocommerce_subscriptions_is_switch_valid', array( __CLASS__, 'is_variation_switch_valid' ), 10, 6 );

		// Modify cart item being switched.
		add_action( 'wcsatt_applied_cart_item_subscription_scheme', array( __CLASS__, 'edit_switched_cart_item' ), 10, 2 );

		if ( self::is_switch_request() ) {
			// Pass the switch arguments to the group items.
			add_filter( 'post_type_link', array( __CLASS__, 'add_switch_query_arg_post_link' ), 12, 2 );

			// Change the add to cart text to "switch subscription" when switch args are present.
			add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'filter_add_to_cart_text' ), 120, 1 );
			add_filter( 'wcsatt_single_add_to_cart_text', array( __CLASS__, 'filter_add_to_cart_text' ), 1200, 1 );
		}
	}

	/**
	 * True if switching is in progress.
	 *
	 * @return boolean
	 */
	public static function is_switch_request() {
		return isset( $_GET['switch-subscription'] ) && isset( $_GET['item'] );
	}

	/**
	 * True if a subscribed product scheme/configuration is being switched.
	 *
	 * @param  WC_Product $product_switched
	 * @return boolean
	 */
	public static function is_switch_request_for_product( $product_switched ) {

		$is_switch_request_for_product = false;

		if ( self::is_switch_request() ) {

			if ( is_product() ) {

				global $product;

				if ( is_object( $product ) && $product->get_id() === $product_switched->get_id() ) {
					$is_switch_request_for_product = true;
				}
			} elseif ( ! empty( $_REQUEST['add-to-cart'] ) && is_numeric( $_REQUEST['add-to-cart'] ) ) {

				$product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_REQUEST['add-to-cart'] ) );

				$posted_subscription_scheme_key = WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $product_id );

				if ( null !== $posted_subscription_scheme_key ) {
					$is_switch_request_for_product = ! empty( $posted_subscription_scheme_key );
				}
			}
		}

		/**
		 * 'wcsatt_is_switch_request_for_product' filter.
		 *
		 * @since APFS 2.2.5
		 *
		 * @param  bool        $is_switch_request_for_product
		 * @param  WC_Product  $product_switched
		 */
		return apply_filters( 'wcsatt_is_switch_request_for_product', $is_switch_request_for_product, $product_switched );
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Allow scheme switching for SATT products with more than 1 subscription scheme or products with switchable content (variations and bundle/composite configurations).
	 *
	 * @param  boolean    $is_switchable
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function is_product_switchable( $is_switchable, $product ) {

		if ( empty( $product ) || ! ( $product instanceof WC_Product ) ) {
			return $is_switchable;
		}

		if ( ! $is_switchable ) {
			$is_switchable = WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_switching' ) || WCS_ATT_Product::supports_feature( $product, 'subscription_content_switching' );
		}

		return $is_switchable;
	}

	/**
	 * Prevent content switching when plan switching is disabled and a matching scheme can't be found.
	 *
	 * @since  APFS 3.1.17
	 *
	 * @param  boolean         $can
	 * @param  WC_Order_Item   $item
	 * @param  WC_Subscription $subscription
	 * @return boolean
	 */
	public static function can_switch_item( $can, $item, $subscription ) {

		if ( ! $item instanceof WC_Order_Item ) {
			return $can;
		}

		$product = $item->get_product();

		if ( WCS_ATT_Product::supports_feature( $product, 'subscription_schemes' ) && ! WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_switching' ) ) {

			if ( WCS_ATT_Product::supports_feature( $product, 'subscription_content_switching', array( 'subscription' => $subscription ) ) ) {

				$schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
				$found   = false;

				// Does a matching scheme exist?
				foreach ( $schemes as $scheme ) {
					if ( $scheme->matches_subscription( $subscription, array( 'upcoming_renewals' => false ) ) ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					$can = false;
				}
			} else {
				$can = false;
			}
		}

		return $can;
	}

	/**
	 * Disable one-time purchases when switching.
	 *
	 * @param  boolean    $is_forced
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function force_subscription( $is_forced, $product ) {

		if ( ! $is_forced && self::is_switch_request() ) {
			$is_forced = self::is_switch_request_for_product( $product );
		}

		return $is_forced;
	}

	/**
	 * When switching 'Between Subscription Plans' is disabled and 'Between Subscription Variations' is enabled, plan switching should not be possible.
	 * This is the meaning of 'content switching': It's not permitted to apply plan changes, only content changes are allowed.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  array      $schemes
	 * @param  WC_Product $product
	 * @return array
	 */
	public static function variable_product_subscription_schemes( $schemes, $product ) {

		if ( ! self::is_switch_request_for_product( $product ) ) {
			return $schemes;
		}

		if ( ! $product->is_type( 'variable', 'variation' ) ) {
			return $schemes;
		}

		// Prevent infinite loops.
		if ( ! is_null( WCS_ATT()->product_data->get( $product, 'wcsatt_bypass_switch_filter' ) ) ) {
			return $schemes;
		}

		// Is switching 'Between Subscription Plans' possible?
		WCS_ATT()->product_data->set( $product, 'wcsatt_bypass_switch_filter', true );
		if ( WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_switching' ) ) {
			return $schemes;
		}
		WCS_ATT()->product_data->delete( $product, 'wcsatt_bypass_switch_filter' );

		if ( ! isset( $_GET['switch-subscription'] ) ) {
			return $schemes;
		}

		$subscription = wcs_get_subscription( absint( $_GET['switch-subscription'] ) );

		if ( ! $subscription ) {
			return $schemes;
		}

		// Does a matching scheme exist?
		foreach ( $schemes as $scheme_id => $scheme ) {
			if ( $scheme->matches_subscription( $subscription ) ) {
				$schemes = array( $scheme_id => $scheme );
				break;
			}
		}

		return $schemes;
	}

	/**
	 * Allow WCS to recognize any supported product as a subscription when validating a switch: Add filter.
	 *
	 * @param  boolean $is_valid
	 * @return boolean
	 */
	public static function add_is_subscription_filter( $is_valid ) {

		if ( self::is_switch_request() ) {
			add_filter( 'woocommerce_is_subscription', array( __CLASS__, 'filter_is_subscription' ), 11, 3 );
		}

		return $is_valid;
	}

	/**
	 * Allow WCS to recognize any supported product as a subscription when validating a switch: Remove filter.
	 *
	 * @param  boolean $is_valid
	 * @return boolean
	 */
	public static function remove_is_subscription_filter( $is_valid ) {

		if ( self::is_switch_request() ) {

			// Clear caches.
			self::$switched_product              = null;
			self::$is_switched_product_identical = null;

			remove_filter( 'woocommerce_is_subscription', array( __CLASS__, 'filter_is_subscription' ), 11, 3 );
		}

		return $is_valid;
	}

	/**
	 * Hooks onto 'woocommerce_is_subscription' to trick WCS into thinking it is dealing with a subscription-type product when switching.
	 *
	 * @param  boolean    $is
	 * @param  int        $product_id
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function filter_is_subscription( $is, $product_id, $product ) {

		if ( ! self::is_switch_request() ) {
			return $is;
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = wc_get_product( $product_id );
		}

		if ( ! $product ) {
			return $is;
		}

		if ( self::is_switch_request_for_product( $product ) && WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			self::$switched_product = $product;
			$is                     = true;
		}

		return $is;
	}

	/**
	 * Make WCS see products with a switched scheme as non-identical ones.
	 *
	 * @param  boolean       $is_identical
	 * @param  int           $product_id
	 * @param  int           $quantity
	 * @param  int           $variation_id
	 * @param  WC_Order      $subscription
	 * @param  WC_Order_Item $item
	 * @return boolean
	 */
	public static function is_identical_product( $is_identical, $product_id, $quantity, $variation_id, $subscription, $item ) {

		self::$is_switched_product_identical = $is_identical;

		if ( $is_identical ) {
			$is_identical = self::is_posted_subscription_scheme_identical( $product_id, $item );
		}

		return $is_identical;
	}

	/**
	 * Checks if the posted subscription plan during a switch is identical with the plan of the item being switched.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  int           $product_id
	 * @param  WC_Order_Item $item
	 * @return boolean
	 */
	private static function is_posted_subscription_scheme_identical( $product_id, $item ) {

		// Default to true - if no scheme is posted, assume products are identical (WCS already validated this)
		$is_identical                   = true;
		$posted_subscription_scheme_key = WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $product_id );

		if ( null !== $posted_subscription_scheme_key ) {

			$new_subscription_scheme_key = $posted_subscription_scheme_key;
			$old_subscription_scheme_key = WCS_ATT_Order::get_subscription_scheme( $item );

			// Only identical if schemes match
			if ( $new_subscription_scheme_key && $new_subscription_scheme_key === $old_subscription_scheme_key ) {
				$is_identical = true;
			} else {
				$is_identical = false;
			}
		}

		return $is_identical;
	}

	/**
	 * Prevent variation switching when 'Between Subscription Variations' is disabled.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  bool            $is_valid
	 * @param  int             $product_id
	 * @param  int             $quantity
	 * @param  int             $variation_id
	 * @param  WC_Subscription $subscription
	 * @param  WC_Order_Item   $item
	 * @return boolean
	 */
	public static function is_variation_switch_valid( $is_valid, $product_id, $quantity, $variation_id, $subscription, $item ) {

		// Invalidate switches.
		if ( ! $is_valid ) {
			return false;
		}

		// Only invalidate switches to different flavors.
		if ( false !== self::$is_switched_product_identical ) {
			return $is_valid;
		}

		// Invalidate variation switches.
		if ( empty( $variation_id ) ) {
			return $is_valid;
		}

		$product = self::$switched_product;

		if ( ! $product || ! $product->is_type( 'variable', 'variation' ) ) {
			return $is_valid;
		}

		// Is plan switching allowed?
		if ( WCS_ATT_Product::supports_feature( $product, 'subscription_scheme_switching' ) ) {

			// If variation switching is not allowed, throw an error as at this point we know that an attribute changed.
			if ( false === WCS_ATT_Product::supports_feature( $product, 'subscription_content_switching', array( 'subscription' => $subscription ) ) ) {
				$is_valid = false;
				wc_add_notice( __( 'Switching product options is not allowed. You may only switch to a different subscription plan.', 'woocommerce-subscriptions' ), 'error' );
			}
		} else {

			// Throw an error if the plan is being switched.
			if ( false === self::is_posted_subscription_scheme_identical( $product_id, $item ) ) {
				$is_valid = false;
				wc_add_notice( __( 'Switching to a different subscription plan is not allowed.', 'woocommerce-subscriptions' ), 'error' );
			}
		}

		return $is_valid;
	}

	/**
	 * Modify cart item being switched.
	 *
	 * @param  array  $cart_item
	 * @param  string $cart_item_key
	 * @return void
	 */
	public static function edit_switched_cart_item( $cart_item, $cart_item_key ) {

		/*
		 * Keep only the applied scheme when switching.
		 * If we don't do this, then multiple scheme options will show up next to the cart item.
		 */
		if ( isset( $cart_item['subscription_switch'] ) ) {

			$applied_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'] );
			$schemes        = array();

			foreach ( WCS_ATT_Cart::get_subscription_schemes( $cart_item ) as $scheme_key => $scheme ) {

				if ( $scheme_key === $applied_scheme ) {
					$schemes[ $scheme_key ] = $scheme;
				}
			}

			WCS_ATT_Product_Schemes::set_subscription_schemes( WC()->cart->cart_contents[ $cart_item_key ]['data'], $schemes );
			WCS_ATT_Product_Schemes::set_forced_subscription_scheme( WC()->cart->cart_contents[ $cart_item_key ]['data'], true );
		}
	}

	/**
	 * Change the "select options" link to include the switch query args, similar to what WooCommerce Subscriptions does.
	 * Applies to variable products belonging to group products.
	 *
	 * @since APFS 5.0.5
	 *
	 * @param  string  $permalink  The permalink of the product belonging to the group
	 * @param  WP_Post $the_post   The WP_Post object
	 *
	 * @return string modified string with the query arg present
	 * @see   WC_Subscriptions_Switcher::add_switch_query_arg_post_link
	 */
	public static function add_switch_query_arg_post_link( $permalink, $the_post ) {

		if ( ! is_main_query() || ! is_product() || 'product' !== $the_post->post_type ) {
			return $permalink;
		}

		// If the actions are equal, then we are not in a grouped product.
		if ( did_action( 'woocommerce_grouped_product_list_before_quantity' ) === did_action( 'woocommerce_grouped_product_list_after_quantity' ) ) {
			return $permalink;
		}

		$product_type = WC_Product_Factory::get_product_type( $the_post->ID );

		if ( 'variable' === $product_type ) {
			// Ideally, we should have used WC_Subscriptions_Switcher::add_switch_query_args() but it's a protected method.
			$permalink = WC_Subscriptions_Switcher::add_switch_query_arg_grouped( $permalink );
		}

		return $permalink;
	}

	/**
	 * Filters the add to cart text for products during a switch request.
	 * No need to call self::is_switch_request() here, as the filter is only added when there is a switch request.
	 *
	 * @since APFS 5.0.5
	 *
	 * @param  string $add_to_cart_text The product's default add to cart text.
	 *
	 * @return string 'Switch subscription' during a switch, or the default add to cart text if switch args aren't present.
	 */
	public static function filter_add_to_cart_text( $add_to_cart_text ) {
		return _x( 'Switch subscription', 'add to cart button text while switching a subscription', 'woocommerce-subscriptions' );
	}
}
