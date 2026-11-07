<?php
/**
 * WCS_ATT_Manage_Switch class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.1.0
 */

use Automattic\WooCommerce_Subscriptions\Internal\Products\Plan_Utils;
use Automattic\WooCommerce_Subscriptions\Internal\Utilities\Request;

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
	 * Whether WooCommerce's classic grouped template is rendering a grouped product's list of children.
	 *
	 * Toggled by the 'woocommerce_grouped_product_list_before' and '..._after' handlers below, and only while a
	 * switch is in progress.
	 *
	 * @var bool
	 */
	private static $in_grouped_product_list = false;

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

			// Send a grouped child that is sold through plans to its own page, where the plan chooser lives.
			add_action( 'woocommerce_grouped_product_list_before', array( __CLASS__, 'before_grouped_product_list' ) );
			add_action( 'woocommerce_grouped_product_list_after', array( __CLASS__, 'after_grouped_product_list' ) );

			// A second release, on the action grouped.php fires on its last line. Both actions live in the same
			// overridable template, so a theme copy that keeps one and drops the other still releases the override.
			add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'after_grouped_product_list' ) );
			add_filter( 'woocommerce_grouped_product_list_column_quantity', array( __CLASS__, 'filter_grouped_product_list_quantity_column' ), 10, 2 );

			// The same handoff for the grouped list of WooCommerce's Add to Cart + Options block, which fires none of the
			// actions above.
			add_filter( 'render_block_woocommerce/add-to-cart-with-options-grouped-product-item-selector', array( __CLASS__, 'filter_grouped_product_item_selector_block' ), 10, 3 );

			// Offer the plan the customer is already on, rather than the product's default.
			add_filter( 'wcsatt_get_default_subscription_scheme_id', array( __CLASS__, 'filter_default_subscription_scheme_id' ), 10, 4 );

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
		// The superglobal is read first because WCS_Cart_Switch writes to it at runtime, one order item at a
		// time, while it rebuilds the cart for a pending or failed switch order. Request::get_var() caches
		// filter_input_array() on its first call, so it can never see a write made during the request.
		// phpcs:disable WordPress.Security.NonceVerification -- this only answers whether a switch is in progress.
		// WC_Subscriptions_Switcher::validate_switch_request() verifies the nonce before the switch itself runs.
		if ( isset( $_GET['switch-subscription'], $_GET['item'] ) ) {
			return true;
		}

		// The switch arguments also travel as hidden form inputs, which is how they arrive whenever the add to
		// cart form posts to the product's own permalink rather than to the switch URL.
		$subscription_id = Request::get_var( 'switch-subscription' );
		$item_id         = Request::get_var( 'item' );
		// phpcs:enable WordPress.Security.NonceVerification

		return is_scalar( $subscription_id ) && is_scalar( $item_id ) && '' !== $subscription_id && '' !== $item_id;
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
				} elseif ( is_object( $product ) && $product_switched->is_type( 'variation' ) && $product->get_id() === $product_switched->get_parent_id() ) {
					// A variable product's plans are offered once for each variation, on the variable product's page.
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

		// Resolved the same way the rest of this class resolves it, so that a switch arriving in the posted
		// fields narrows the plan list exactly as one arriving in the query string does. Without this, a POST-only
		// switch would be accepted with the full plan list while 'Between Subscription Plans' is off, which is the
		// restriction this method exists to apply.
		$subscription = self::get_subscription_being_switched();

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

			/*
			 * Compare the canonical form of each key rather than the keys as written. An item bought under
			 * the standalone plugin can hold a different spelling of the same plan key than the one posted
			 * today - @see Plan_Utils::canonicalize_key() - and comparing the
			 * spellings reports a plan change that did not happen, letting a customer switch to the plan
			 * they are already on.
			 *
			 * Canonicalizing rather than resolving against a product is deliberate: this method also runs
			 * from @see WCS_ATT_Manage_Switch::is_variation_switch_valid, where the posted key describes the
			 * product being switched TO and the item belongs to the one being switched FROM. Resolving each
			 * against its own product would compare two products' spellings of one plan and call them
			 * different; resolving both against the item's product would read the posted key against a
			 * product it does not describe. The canonical form is a property of the key alone, so it is
			 * correct from either caller.
			 */
			$new_subscription_scheme_key = Plan_Utils::canonicalize_key( $posted_subscription_scheme_key );
			$old_subscription_scheme_key = Plan_Utils::canonicalize_key( WCS_ATT_Order::get_subscription_scheme( $item ) );

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
	 * Whether a grouped product's child must choose its plan on its own product page.
	 *
	 * A child sold through subscription plans cannot be switched to from the grouped page: the grouped form posts
	 * only quantities, so no plan ever reaches the switch, and WooCommerce Subscriptions rightly refuses a switch to
	 * a product that is not a subscription. Such a child chooses its plan on its own page, exactly as a variable
	 * child chooses its variation there.
	 *
	 * @since 9.4.0
	 *
	 * @param  mixed $child  The product WooCommerce is rendering a row for.
	 * @return boolean
	 */
	private static function must_choose_plan_on_product_page( $child ) {

		if ( ! $child instanceof WC_Product ) {
			return false;
		}

		// A child WooCommerce already sends to its own page keeps the link WooCommerce renders for it. In the
		// classic list that link carries the switch (add_switch_query_arg_post_link()), and a variable child has a
		// variation to choose as well as a plan. The grouped parent is excluded too, because its own 'has_options()'
		// decides whether its form takes quantities at all.
		if ( $child->is_type( array( 'grouped', 'variable', 'external' ) ) ) {
			return false;
		}

		// A native subscription product is switched to with the quantity box, which already works.
		if ( WCS_ATT_Product::is_subscription_product_type( $child ) ) {
			return false;
		}

		// A child that cannot be bought at all keeps whatever WooCommerce already shows in its place.
		if ( ! $child->is_purchasable() || ! $child->is_in_stock() ) {
			return false;
		}

		if ( ! WCS_ATT_Product_Schemes::has_subscription_schemes( $child ) ) {
			return false;
		}

		// Finally, the handoff is only an improvement if the switch travels with it. The link is built from the
		// switch query string, so when the switch reached us in the posted fields alone there is nothing to pass
		// on, and a link without it would buy a new subscription instead of switching. WooCommerce's own column
		// then stays: a quantity box that refuses the submit is at least honest about it.
		return $child->get_permalink() !== self::get_switch_url( $child );
	}

	/**
	 * A child's own page in switch mode, or its plain permalink when the request carries no switch to pass on.
	 *
	 * @since 9.4.0
	 *
	 * @param  WC_Product $child  The product to link to.
	 * @return string              The URL.
	 */
	private static function get_switch_url( $child ) {
		return WC_Subscriptions_Switcher::add_switch_query_arg_grouped( $child->get_permalink() );
	}

	/**
	 * Mark the start of WooCommerce's grouped product list and take over 'has_options()' for its children.
	 *
	 * The filter is added here, rather than with the module's other hooks, because 'has_options()' answers for every
	 * product in the request and the override must only apply to the children of the list being rendered.
	 *
	 * @since 9.4.0
	 *
	 * @return void
	 */
	public static function before_grouped_product_list() {
		self::$in_grouped_product_list = true;

		add_filter( 'woocommerce_product_has_options', array( __CLASS__, 'filter_product_has_options' ), 10, 2 );
	}

	/**
	 * Mark the end of WooCommerce's grouped product list and give 'has_options()' back.
	 *
	 * Left attached, the override answers for every product for the rest of the request, and
	 * WCS_ATT_Display_Product::prompt_plan_selection_in_catalog() reads 'has_options()' to decide whether a plan
	 * product in the related products or up-sells loop below the form offers its plan chooser or a plain add to
	 * cart button. So this also runs on 'woocommerce_after_add_to_cart_form'.
	 *
	 * @since 9.4.0
	 *
	 * @return void
	 */
	public static function after_grouped_product_list() {
		self::$in_grouped_product_list = false;

		remove_filter( 'woocommerce_product_has_options', array( __CLASS__, 'filter_product_has_options' ), 10 );
	}

	/**
	 * Report a grouped child that is sold through plans as having options, while that list is being rendered.
	 *
	 * This is the template's own test for "this child is chosen on its own page". It also keeps the child out of
	 * the count of children that take a quantity, so a group whose children are all sold through plans no longer
	 * renders a submit button that can only fail.
	 *
	 * @since 9.4.0
	 *
	 * @param  mixed $has_options  Whether the product has options. Untyped: any callback may have set it.
	 * @param  mixed $product      The product being asked about.
	 * @return boolean
	 */
	public static function filter_product_has_options( $has_options, $product ) {
		return (bool) $has_options || ( self::$in_grouped_product_list && self::must_choose_plan_on_product_page( $product ) );
	}

	/**
	 * Replace the quantity box of a grouped child that is sold through plans with a link to its own page.
	 *
	 * The link carries the switch arguments, so the child's page opens in switch mode with its plan chooser, the
	 * same destination the child's name in the row leads to.
	 *
	 * @since 9.4.0
	 *
	 * @param  mixed $column_value  The rendered contents of the row's quantity column.
	 * @param  mixed $child         The product the row is for.
	 * @return mixed
	 */
	public static function filter_grouped_product_list_quantity_column( $column_value, $child ) {

		if ( ! self::$in_grouped_product_list || ! self::must_choose_plan_on_product_page( $child ) ) {
			return $column_value;
		}

		return self::get_choose_plan_link( $child );
	}

	/**
	 * Replace the quantity box of a grouped child that is sold through plans with a link to its own page, in the
	 * grouped list of WooCommerce's Add to Cart + Options block.
	 *
	 * That block decides between a quantity box and a link for each child itself, and fires none of the classic
	 * list's actions, so the handoff is applied to what its item selector block rendered.
	 *
	 * @since 9.4.0
	 *
	 * @param  mixed $block_content  The rendered block. Untyped: any callback may have changed it.
	 * @param  mixed $parsed_block   The parsed block.
	 * @param  mixed $block          The block instance, whose context names the child the row is for.
	 * @return mixed
	 */
	public static function filter_grouped_product_item_selector_block( $block_content, $parsed_block, $block = null ) {

		if ( ! is_string( $block_content ) || '' === $block_content || ! $block instanceof WP_Block ) {
			return $block_content;
		}

		// The product WooCommerce rendered the row for, found the way WooCommerce finds it: the block's context, then
		// the global product. A block type registered without its metadata has no context.
		$child = ! empty( $block->context['postId'] ) && is_numeric( $block->context['postId'] ) ? wc_get_product( absint( $block->context['postId'] ) ) : null;

		if ( ! $child instanceof WC_Product && isset( $GLOBALS['product'] ) ) {
			$child = $GLOBALS['product'];
		}

		if ( ! self::must_choose_plan_on_product_page( $child ) ) {
			return $block_content;
		}

		$original = new WP_HTML_Tag_Processor( $block_content );
		$wrapper  = new WP_HTML_Tag_Processor( '<div class="wp-block-add-to-cart-with-options-grouped-product-item-selector wc-block-add-to-cart-with-options-grouped-product-item-selector">' );
		$wrapper->next_tag();

		// Keep what WordPress and other render filters added to the block's wrapper, such as the classes that hide the
		// block on a given screen size.
		if ( $original->next_tag() ) {
			foreach ( (array) $original->get_attribute_names_with_prefix( '' ) as $name ) {
				$wrapper->set_attribute( $name, $original->get_attribute( $name ) );
			}
		}

		return $wrapper->get_updated_html() . self::get_choose_plan_link( $child ) . '</div>';
	}

	/**
	 * A link to a grouped child's own page in switch mode, where its plan is chosen.
	 *
	 * @param  WC_Product $child  The product the row is for.
	 * @return string
	 */
	private static function get_choose_plan_link( $child ) {

		$classes = array_filter( array( 'button', wc_wp_theme_get_element_class_name( 'button' ) ) );

		// The same label the catalog button uses, so a merchant who relabelled it there sees it here. Any callback
		// can return anything, and an array or an object would be a notice or an Error in esc_html().
		$default_label = __( 'Select plan', 'woocommerce-subscriptions' );
		$label         = apply_filters( 'wcsatt_add_to_cart_text', $default_label, $child );
		$label         = is_scalar( $label ) && '' !== $label ? (string) $label : $default_label;

		return sprintf(
			'<a href="%1$s" class="%2$s" aria-label="%3$s">%4$s</a>',
			esc_url( self::get_switch_url( $child ) ),
			esc_attr( implode( ' ', $classes ) ),
			/* translators: %1$s: product name. */
			esc_attr( sprintf( __( 'Choose a subscription plan for %1$s', 'woocommerce-subscriptions' ), $child->get_name() ) ),
			esc_html( $label )
		);
	}

	/**
	 * Preselect the plan that matches the subscription being switched.
	 *
	 * A product's default plan has nothing to do with the schedule the customer is already paying on, so a yearly
	 * subscriber arriving at a switch found the monthly plan selected for them. Submitting without noticing the
	 * chooser put a downgrade in the cart, and with JavaScript off the preselected plan is the only one the page can
	 * send.
	 *
	 * The customer's own choice still wins: the display code applies this filter first and then lets a posted plan
	 * override it.
	 *
	 * @since 9.4.0
	 *
	 * @param  mixed $default_scheme_key  The product's default plan key. Untyped: any callback may have set it.
	 * @param  mixed $schemes             The plans the product offers, keyed by plan key.
	 * @param  mixed $one_time_allowed    Whether the product can also be bought outright. Unused.
	 * @param  mixed $product             The product the plans belong to.
	 * @return mixed
	 */
	public static function filter_default_subscription_scheme_id( $default_scheme_key, $schemes, $one_time_allowed, $product ) {

		if ( ! $product instanceof WC_Product || ! is_array( $schemes ) ) {
			return $default_scheme_key;
		}

		// Only while this product is the one being switched. This filter is also applied by the add-to-subscription
		// flow, which chooses its plan by its own rules.
		if ( ! self::is_switch_request_for_product( $product ) ) {
			return $default_scheme_key;
		}

		$subscription = self::get_subscription_being_switched();

		if ( ! $subscription ) {
			return $default_scheme_key;
		}

		$matching_scheme_keys = array();

		foreach ( $schemes as $scheme_key => $scheme ) {
			/*
			 * 'upcoming_renewals' is relaxed, as it is everywhere this plugin matches a plan to a subscription
			 * (see can_switch_item() above): a plan's length is its own, and rarely equals the renewals left on
			 * the subscription being switched. 'next_payment' keeps its default, so a subscription with no next
			 * payment date - on hold, or in its final period - matches nothing and keeps the product's default.
			 */
			if ( $scheme instanceof WCS_ATT_Scheme && $scheme->matches_subscription( $subscription, array( 'upcoming_renewals' => false ) ) ) {
				$matching_scheme_keys[] = $scheme_key;
			}
		}

		// Two plans can share a billing schedule and both match. Preselecting either would put pricing in front of
		// the customer that they never chose, so leave the product's default to be changed deliberately.
		if ( 1 !== count( $matching_scheme_keys ) ) {
			return $default_scheme_key;
		}

		return current( $matching_scheme_keys );
	}

	/**
	 * The subscription the request is switching, once the customer has been shown to own it.
	 *
	 * Reads the switch from the query string or the posted fields, in the same order as is_switch_request(), so
	 * every reader of the switch agrees with the predicate that decides whether one is in progress. Public so the
	 * Product Bundles / Composite integration resolves the switch the same way rather than reading the query string
	 * on its own.
	 *
	 * Deliberately uncached, although it can run many times per render. WCS_Cart_Switch rewrites
	 * $_GET['switch-subscription'] and $_GET['item'] for each order item while it rebuilds the cart for a pending or
	 * failed switch order, so a remembered result would name the wrong subscription from the second item onwards.
	 *
	 * @since 9.4.0
	 *
	 * @return WC_Subscription|null The subscription, or null when the request names none, it does not exist, or
	 *                              the current user may not switch it.
	 */
	public static function get_subscription_being_switched() {

		$subscription_id = Request::get_var( 'switch-subscription' );

		// The superglobal takes precedence, for the reason given in is_switch_request(): WCS_Cart_Switch writes to
		// it at runtime, where the cached reader cannot see it.
		// phpcs:disable WordPress.Security.NonceVerification -- this decides which plan to offer, nothing more. The
		// switch itself is authorized by WC_Subscriptions_Switcher::validate_switch_request().
		if ( isset( $_GET['switch-subscription'] ) && is_scalar( $_GET['switch-subscription'] ) ) {
			$subscription_id = wc_clean( wp_unslash( $_GET['switch-subscription'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! is_scalar( $subscription_id ) ) {
			return null;
		}

		$subscription = wcs_get_subscription( absint( $subscription_id ) );

		// Which plan is offered says what the subscription's billing schedule is, so it is only offered to the
		// customer whose subscription it is.
		if ( ! $subscription || ! current_user_can( 'switch_shop_subscription', $subscription->get_id() ) ) {
			return null;
		}

		return $subscription;
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
