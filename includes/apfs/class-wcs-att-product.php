<?php
/**
 * WCS_ATT_Product API
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.0.0
 * @version  6.0.7
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API for working with subscription-enabled product objects.
 *
 * @class    WCS_ATT_Product
 * @version  6.0.7
 */
class WCS_ATT_Product {

	/**
	 * Local runtime meta store for performance.
	 *
	 * @var array
	 */
	private static $runtime_meta = array();

	/**
	 * Own implementation of 'spl_object_hash';
	 *
	 * @var integer
	 */
	private static $object_instance_count = 0;

	/**
	 * DB meta expected by WCS that needs to be added by SATT at runtime.
	 *
	 * @var array
	 */
	private static $subscription_product_type_meta_keys = array(
		'subscription_price',
		'subscription_period',
		'subscription_period_interval',
		'subscription_length',
		'subscription_trial_period',
		'subscription_trial_length',
		'subscription_sign_up_fee',
		'subscription_payment_sync_date',
		'wcs_switch_totals_calc_base_length',
	);

	/**
	 * Include Product API price and scheme components and add hooks.
	 */
	public static function init() {

		WCS_ATT_Product_Prices::init();

		self::add_hooks();
	}

	/**
	 * Hook-in.
	 */
	private static function add_hooks() {

		// Allow WCS to recognize any product as a subscription.
		add_filter( 'woocommerce_is_subscription', array( __CLASS__, 'filter_is_subscription' ), 10, 3 );

		// Make sure One-Time Shipping state is transferred from variations to parent products in the cart.
		add_filter( 'woocommerce_subscriptions_product_needs_one_time_shipping', array( __CLASS__, 'filter_needs_one_time_shipping' ), 10, 3 );

		/**
		 * 'wcsatt_use_runtime_meta_from_product'
		 *
		 * @since APFS 6.0.3
		 * @deprecated 6.1.0
		 *
		 * @param boolean False, if runtime meta should be retrieved from the product object.
		 */
		if ( apply_filters_deprecated( 'wcsatt_use_runtime_meta_from_product', array( false ), '6.1.0', '', 'Using runtime meta from the product object is deprecated.' ) ) {
			// Delete object meta in use by the application layer.
			add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'delete_runtime_meta' ) );

			// Prevent '_satt_data' from being saved in the database, when '$product->save_meta_data' is called.
			add_action( 'add_post_metadata', array( __CLASS__, 'ignore_satt_runtime_meta' ), 10, 3 );
			add_action( 'update_post_metadata', array( __CLASS__, 'ignore_satt_runtime_meta' ), 10, 3 );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Conditionals
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determines if a subscription scheme is set on the product object.
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @return boolean               Result of check.
	 */
	public static function is_subscription( $product ) {
		return WCS_ATT_Product_Schemes::has_active_subscription_scheme( $product );
	}

	/**
	 * Checks a product object to determine if it is a WCS subscription-type product.
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @return boolean               Result of check.
	 */
	public static function is_subscription_product_type( $product ) {
		return $product->is_type( array( 'subscription', 'subscription_variation', 'variable-subscription' ) );
	}

	/**
	 * Checks if a product has any existing subscription configuration.
	 *
	 * A product has subscription configuration if any of these meta keys exist:
	 * - _wcsatt_schemes_status (the authoritative mode key, set on first save)
	 * - _wcsatt_disabled (legacy: product set to "Sell one-time only")
	 * - _wcsatt_schemes (product has custom subscription plans)
	 * - _wcsatt_storewide_selection_mode (product uses storewide plans)
	 *
	 * For variations, this method can optionally check the parent product if the
	 * variation itself has no subscription configuration.
	 *
	 * @param  WC_Product $product             Product object to check.
	 * @param  bool       $check_parent        Whether to check parent product for variations (default: true).
	 * @return bool                            True if product has subscription configuration, false otherwise.
	 */
	public static function has_subscription_config( $product, $check_parent = true ) {

		if ( ! ( $product instanceof WC_Product ) ) {
			return false;
		}

		// Check if product has any subscription meta keys.
		$has_subscription_config = self::check_product_subscription_meta( $product );

		// For variations, recursively check parent product if requested and variation has no config.
		if ( ! $has_subscription_config && $check_parent && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent instanceof WC_Product ) {
				// Recursively call this method on parent, but don't check grandparent.
				$has_subscription_config = self::has_subscription_config( $parent, false );
			}
		}

		return $has_subscription_config;
	}

	/**
	 * Checks if a single product object has subscription meta keys.
	 *
	 * This is a helper method for has_subscription_config() to avoid code duplication.
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @return bool                 True if product has subscription meta keys, false otherwise.
	 */
	private static function check_product_subscription_meta( $product ) {
		return $product->meta_exists( '_wcsatt_schemes_status' )
			|| $product->meta_exists( '_wcsatt_disabled' )
			|| $product->meta_exists( '_wcsatt_schemes' )
			|| $product->meta_exists( '_wcsatt_storewide_selection_mode' );
	}

	/**
	 * Query for support of SATT features.
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @param  string     $feature  Feature.
	 * @param  array      $args     Additional arguments.
	 * @return boolean               Result.
	 */
	public static function supports_feature( $product, $feature, $args = array() ) {

		$is_feature_supported = false;

		if ( empty( $product ) || ! ( $product instanceof WC_Product ) ) {
			return $is_feature_supported;
		}

		switch ( $feature ) {

			case 'subscription_schemes':
				$supported_product_types = WCS_ATT()->get_supported_product_types();
				$is_feature_supported    = in_array( $product->get_type(), $supported_product_types );

				break;
			case 'subscription_scheme_options_product_single':
				// Grouped products are not supported for subscription plan UI.
				if ( $product->is_type( 'grouped' ) ) {
					$is_feature_supported = false;
				} else {
					$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
					$is_feature_supported = apply_filters( 'wcsatt_show_single_product_options', ! empty( $subscription_schemes ), $product );
				}

				break;
			case 'subscription_scheme_options_product_cart':
				if ( isset( $args['cart_item'] ) && isset( $args['cart_item_key'] ) ) {
					$subscription_schemes = WCS_ATT_Cart::get_subscription_schemes( $args['cart_item'] );
					$is_feature_supported = apply_filters( 'wcsatt_show_cart_item_options', ! empty( $subscription_schemes ), $args['cart_item'], $args['cart_item_key'] );
				}

				break;
			case 'subscription_scheme_switching':
				// Scheme switching allowed for all products with more than 1 subscription scheme.

				$option_value         = get_option( 'woocommerce_subscriptions_allow_switching_product_plans', 'yes' );
				$is_feature_supported = false;

				if ( 'no' !== $option_value ) {

					$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
					$is_feature_supported = count( $subscription_schemes ) > 1;
				}

				break;
			case 'subscription_content_switching':
				// Content switching for variable products with subscription plans is allowed when 'Between Subscription Variations' is enabled.

				$is_feature_supported = false;

				if ( $product->is_type( array( 'variable', 'variation' ) ) ) {

					$option_value = get_option( 'woocommerce_subscriptions_allow_switching', 'no' );

					if ( false !== strpos( $option_value, 'variable' ) ) {
						$subscription_schemes = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
						$is_feature_supported = count( $subscription_schemes );
					}
				}

				break;
			case 'subscription_management_add_to_subscription':
				$is_feature_supported = $product->is_purchasable() && self::supports_feature( $product, 'subscription_schemes' ) && false === $product->is_type( 'mix-and-match' );

				break;
			case 'subscription_management_add_to_subscription_product_single':
				$is_feature_supported = false;
				$option_value         = get_option( 'wcsatt_add_product_to_subscription', 'off' );

				if ( 'off' !== $option_value ) {
					$is_feature_supported = self::supports_feature( $product, 'subscription_management_add_to_subscription' );
				}

				if ( $is_feature_supported && 'matching_schemes' === $option_value ) {
					$is_feature_supported = ! empty( WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) );
				}

				break;
			case 'subscription_management_add_to_subscription_product_cart':
				$is_feature_supported = false;
				$option_value         = get_option( 'wcsatt_add_cart_to_subscription', 'off' );

				if ( 'off' !== $option_value ) {
					$is_feature_supported = self::supports_feature( $product, 'subscription_management_add_to_subscription' );
				}

				if ( $is_feature_supported && 'plans_only' === $option_value ) {
					$is_feature_supported = ! empty( WCS_ATT_Product_Schemes::get_subscription_schemes( $product ) );
				}

				break;
		}

		/**
		 * 'wcsatt_product_supports_feature' filter.
		 *
		 * @since  APFS 2.1.0
		 *
		 * @param  bool        $is_feature_supported
		 * @param  WC_Product  $product
		 * @param  string      $feature
		 * @param  array       $args
		 */
		return apply_filters( 'wcsatt_product_supports_feature', $is_feature_supported, $product, $feature, $args );
	}

	/*
	|--------------------------------------------------------------------------
	| Filters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Hooks onto 'woocommerce_is_subscription' to trick WCS into thinking it is dealing with a subscription-type product.
	 *
	 * @param  boolean    $is
	 * @param  int        $product_id
	 * @param  WC_Product $product
	 * @return boolean
	 */
	public static function filter_is_subscription( $is, $product_id, $product ) {

		if ( ! $product ) {
			return $is;
		}

		if ( self::is_subscription( $product ) ) {
			$is = true;
		}

		return $is;
	}

	/**
	 * Make sure One-Time Shipping state is transferred from variations to parent products in the cart.
	 *
	 * @since  APFS 2.2.0
	 *
	 * @param  boolean $needs_one_time_shipping
	 * @param  mixed   $product
	 * @param  mixed   $product
	 * @return boolean
	 */
	public static function filter_needs_one_time_shipping( $needs_one_time_shipping, $product, $variation = false ) {

		if ( ! is_object( $product ) ) {
			return $needs_one_time_shipping;
		}

		if ( is_object( $variation ) && ! $needs_one_time_shipping ) {

			if ( $applied_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $variation ) ) {
				WCS_ATT_Product_Schemes::set_subscription_scheme( $product, $applied_scheme );
				$needs_one_time_shipping = 'yes' === WC_Subscriptions_Product::get_meta_data( $product, 'subscription_one_time_shipping', 'no' );
			}
		}

		return $needs_one_time_shipping;
	}

	/**
	 * Delete object meta in use by the application layer.
	 * Note that the subscription state of a product object:
	 *
	 * 1. Cannot be persisted in the DB.
	 * 2. Is lost when the object is saved.
	 *
	 * This is intended behavior.
	 *
	 * @param  WC_Product $product
	 */
	public static function delete_runtime_meta( $product ) {

		self::$runtime_meta[ self::get_instance_id( $product ) ] = array();

		$product->delete_meta_data( '_satt_data' );

		// Don't delete any subscription product-type meta :)
		if ( ! self::is_subscription_product_type( $product ) ) {
			foreach ( self::$subscription_product_type_meta_keys as $runtime_meta_key ) {
				$product->delete_meta_data( '_' . $runtime_meta_key );
			}
		}
	}

	/**
	 * Prevent runtime meta from being saved on the product object
	 * when 'save_meta_data' is called without a subsequent 'save' call.
	 *
	 * @param null|bool $check       Whether to allow updating metadata for the given type.
	 * @param int       $object_id   ID of the object metadata is for.
	 * @param string    $meta_key    Metadata key.
	 */
	public static function ignore_satt_runtime_meta( $check, $object_id, $meta_key ) {

		if ( '_satt_data' !== $meta_key ) {
			return $check;
		}

		return 0;
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Property getter (compatibility wrapper).
	 *
	 * @param  WC_Product $product  Product object.
	 * @param  string     $key      Runtime meta key name.
	 * @return mixed
	 */
	public static function get_runtime_meta( $product, $key ) {

		$instance_id = self::get_instance_id( $product );

		if ( isset( self::$runtime_meta[ $instance_id ] ) && array_key_exists( $key, self::$runtime_meta[ $instance_id ] ) ) {
			return self::$runtime_meta[ $instance_id ][ $key ];
		}

		if ( in_array( $key, self::$subscription_product_type_meta_keys ) ) {

			$value = $product->get_meta( '_' . $key, true, 'edit' );

		} else {

			$value = '';

			/**
			 * 'wcsatt_use_runtime_meta_from_product'
			 *
			 * @since APFS 6.0.3
			 * @deprecated 6.1.0
			 *
			 * @param boolean False, if runtime meta should be retrieved from the product object.
			 */
			if ( apply_filters_deprecated( 'wcsatt_use_runtime_meta_from_product', array( false ), '6.1.0', '', 'Using runtime meta from the product object is deprecated.' ) ) {

				$data = $product->get_meta( '_satt_data', true, 'edit' );

				if ( is_array( $data ) && isset( $data[ $key ] ) ) {
					$value = $data[ $key ];
				}
			}
		}

		return $value;
	}

	/**
	 * Property setter (compatibility wrapper).
	 *
	 * @param  WC_Product $product  Product object.
	 * @param  string     $key      Runtime meta key name.
	 * @param  string     $value    Property value.
	 * @return mixed
	 */
	public static function set_runtime_meta( $product, $key, $value ) {

		if ( in_array( $key, self::$subscription_product_type_meta_keys ) ) {

			$product->add_meta_data( '_' . $key, $value, true );

			/**
			 * 'wcsatt_use_runtime_meta_from_product'
			 *
			 * @since APFS 6.0.3
			 * @deprecated 6.1.0
			 *
			 * @param boolean False, if runtime meta should be saved on the product object.
			 */
		} elseif ( apply_filters_deprecated( 'wcsatt_use_runtime_meta_from_product', array( false ), '6.1.0', '', 'Using runtime meta from the product object is deprecated.' ) ) {

			$data = $product->get_meta( '_satt_data', true, 'edit' );

			if ( empty( $data ) ) {
				$data = array();
			}

			$data[ $key ] = $value;

			$product->add_meta_data( '_satt_data', $data, true );
		}

		$instance_id = self::get_instance_id( $product );

		if ( ! isset( self::$runtime_meta[ $instance_id ] ) ) {
			self::$runtime_meta[ $instance_id ] = array();
		}

		if ( is_null( $value ) ) {
			$value = '';
		}

		self::$runtime_meta[ $instance_id ][ $key ] = $value;
	}

	/**
	 * Get unique identifier for product instances.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function get_instance_id( $product ) {
		WCS_ATT()->includes();
		$instance_id = WCS_ATT()->product_data->get( $product, 'wcsatt_instance' );

		if ( ! is_null( $instance_id ) ) {
			$instance_id = absint( $instance_id );
		} else {
			++self::$object_instance_count;
			WCS_ATT()->product_data->set( $product, 'wcsatt_instance', self::$object_instance_count );
			$instance_id = self::$object_instance_count;
		}

		return $instance_id;
	}

	/**
	 * Get the subscription scheme mode for a product.
	 *
	 * Reads the persisted `_wcsatt_schemes_status` meta key. For legacy products that
	 * don't have this key, infers the mode from which meta keys exist.
	 *
	 * Does not check parent products — only reads from the given product object.
	 *
	 * @since 9.0.0
	 *
	 * @param WC_Product $product The product to check.
	 * @return string The mode. One of the WCS_ATT_Scheme::MODE_* constants.
	 */
	public static function get_subscription_scheme_mode( $product ) {
		// Primary: read the persisted mode key.
		$mode = $product->get_meta( '_wcsatt_schemes_status', true );

		if ( WCS_ATT_Scheme::is_valid_mode( $mode ) ) {
			return $mode;
		}

		// Legacy fallback: infer mode from which meta keys exist.
		if ( 'yes' === $product->get_meta( '_wcsatt_disabled', true ) ) {
			return WCS_ATT_Scheme::MODE_DISABLE;
		}

		if ( $product->meta_exists( '_wcsatt_storewide_selection_mode' ) ) {
			return WCS_ATT_Scheme::MODE_INHERIT;
		}

		$schemes = $product->get_meta( '_wcsatt_schemes', true );
		if ( ! empty( $schemes ) && is_array( $schemes ) ) {
			return WCS_ATT_Scheme::MODE_OVERRIDE;
		}

		// The product has subscription config (e.g. an empty _wcsatt_schemes meta from import)
		// but no explicit mode signals. Default to inherit so storewide plans are used.
		// This matches the standalone plugin behavior where has_subscription_config() + no local
		// schemes would fall back to global plans.
		if ( self::has_subscription_config( $product, false ) ) {
			return WCS_ATT_Scheme::MODE_INHERIT;
		}

		// No config found — return the default.
		return self::get_default_subscription_scheme_mode();
	}

	/**
	 * Set the subscription scheme mode for a product.
	 *
	 * Persists `_wcsatt_schemes_status` as the authoritative mode key and sets
	 * `_wcsatt_disabled` for backward compatibility. All other data (custom schemes,
	 * storewide settings) is preserved in place — the mode key determines which is active.
	 *
	 * Does NOT call `$product->save()` - the caller is responsible for saving.
	 *
	 * @since 9.0.0
	 *
	 * @param WC_Product $product The product to update.
	 * @param string     $mode    The mode to set. One of the WCS_ATT_Scheme::MODE_* constants.
	 */
	public static function set_subscription_scheme_mode( $product, $mode ) {
		if ( ! WCS_ATT_Scheme::is_valid_mode( $mode ) ) {
			return;
		}

		$product->update_meta_data( '_wcsatt_schemes_status', $mode );

		// Maintain _wcsatt_disabled for backward compatibility with third-party code.
		if ( WCS_ATT_Scheme::MODE_DISABLE === $mode ) {
			$product->update_meta_data( '_wcsatt_disabled', 'yes' );
		} else {
			$product->delete_meta_data( '_wcsatt_disabled' );
		}
	}

	/**
	 * Get the default subscription scheme mode for products without existing subscription settings.
	 *
	 * This determines how new products (or products without subscriptions configuration) should
	 * handle subscription offerings by default.
	 *
	 * @since 9.0.0
	 *
	 * @return string The default mode.
	 */
	public static function get_default_subscription_scheme_mode() {
		/**
		 * Filter the default subscription scheme mode for new products.
		 *
		 * Determines how products without existing subscriptions configuration should handle subscriptions:
		 * - 'disable': Sell one-time only (default)
		 * - 'override': Add custom subscription plans
		 * - 'inherit': Use storewide subscription plans
		 *
		 * @since 9.0.0
		 *
		 * @param string $default_mode The default mode.
		 */
		return apply_filters( 'woocommerce_subscriptions_default_product_subscription_scheme_mode', WCS_ATT_Scheme::MODE_DISABLE );
	}
}
