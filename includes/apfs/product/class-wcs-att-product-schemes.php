<?php
/**
 * WCS_ATT_Product_Schemes API
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API for working with the subscription schemes of subscription-enabled product objects.
 *
 * @class    WCS_ATT_Product_Schemes
 * @version  5.0.2
 */
class WCS_ATT_Product_Schemes {

	/*
	|--------------------------------------------------------------------------
	| Conditionals
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determines if the product can be purchased on a recurring basis.
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @param  string     $context  Context/origin of schemes.
	 * @return boolean               Result of check.
	 */
	public static function has_subscription_schemes( $product, $context = 'any' ) {
		return count( self::get_subscription_schemes( $product, $context ) ) > 0;
	}

	/**
	 * Determines if the product is purchasable on a recurring basis only.
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @return boolean               Result of check.
	 */
	public static function has_forced_subscription_scheme( $product ) {

		if ( ! self::has_subscription_schemes( $product ) ) {
			return false;
		}

		$forced = WCS_ATT_Product::get_runtime_meta( $product, 'has_forced_subscription' );

		if ( '' === $forced ) {

			// Defined by parent?
			if ( $parent = WCS_ATT_Product::get_runtime_meta( $product, 'parent_product' ) ) {

				if ( self::has_forced_subscription_scheme( $parent ) ) {
					$forced = 'yes';
				}

				// Otherwise, fall back to DB.
			} else {

				// Only products with local plans can be force-sold on subscription.
				// Allow force subscription to work with global plans.
				// Check _wcsatt_force_subscription meta regardless of whether product has local/global schemes.
				$forced = $product->get_meta( '_wcsatt_force_subscription', true );

				// Attempt to get meta from parent if undefined on variation.
				if ( '' === $forced && $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					$forced = is_object( $parent ) ? $parent->get_meta( '_wcsatt_force_subscription', true ) : '';
				}
			}

			WCS_ATT_Product::set_runtime_meta( $product, 'has_forced_subscription', $forced );
		}

		return apply_filters( 'wcsatt_force_subscription', 'yes' === $forced, $product );
	}

	/**
	 * Determines if the product is purchasable on a recurring basis only, and a single plan is available.
	 *
	 * @since  APFS 2.4.0
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @return boolean               Result of check.
	 */
	public static function has_single_forced_subscription_scheme( $product ) {
		return self::has_forced_subscription_scheme( $product ) && ( 1 === count( self::get_subscription_schemes( $product ) ) );
	}

	/**
	 * Determines if the product is currently set to be purchased on a recurring basis.
	 *
	 * @param  WC_Product $product  Product object to check.
	 * @return boolean               Result of check.
	 */
	public static function has_active_subscription_scheme( $product ) {
		$active_scheme_key = self::get_subscription_scheme( $product );
		return ! empty( $active_scheme_key );
	}

	/*
	|--------------------------------------------------------------------------
	| Getters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Returns all subscription schemes associated with a product.
	 *
	 * @param  WC_Product $product  Product object.
	 * @param  string     $context  Context of schemes, based on origin. Values: 'local', 'global'.
	 * @return array
	 */
	public static function get_subscription_schemes( $product, $context = 'any' ) {

		if ( ! WCS_ATT_Product::supports_feature( $product, 'subscription_schemes' ) ) {
			return array();
		}

		$schemes = WCS_ATT_Product::get_runtime_meta( $product, 'subscription_schemes' );

		if ( '' === $schemes ) {

			$schemes       = array();
			$scheme_origin = 'local';

			// Defined by parent?
			if ( $parent = WCS_ATT_Product::get_runtime_meta( $product, 'parent_product' ) ) {

				$schemes = self::get_subscription_schemes( $parent, $context );

				// Otherwise, read data from DB.
			} else {

				$schemes_data = false;

				// For variations, always resolve mode from the parent product since
				// variations don't carry their own mode settings. Variations may have
				// empty meta keys (e.g. _wcsatt_schemes = '') left by WooCommerce's
				// variation save, which should not be treated as own config.
				if ( $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );

					if ( ! $parent instanceof WC_Product ) {
						// translators: %d is the variation ID.
						$message = sprintf( __( 'Could not fetch subscription plans for variation with ID %d. This indicates that this variation may be an orphaned variation. To clear orphaned variations, go to "WooCommerce > Status > Tools" and click "Delete orphaned variations".', 'woocommerce-subscriptions' ), $product->get_id() );
						WCS_ATT()->log( $message, 'info' );
						return array();
					}

					$product_mode = WCS_ATT_Product::get_subscription_scheme_mode( $parent );
				} else {
					// Get the authoritative mode for this product (with legacy fallback).
					$product_mode = WCS_ATT_Product::get_subscription_scheme_mode( $product );
				}

				if ( WCS_ATT_Scheme::MODE_OVERRIDE === $product_mode ) {
					// Override mode: use local custom schemes.
					$schemes_data = $product->get_meta( '_wcsatt_schemes', true );

					if ( empty( $schemes_data ) || ! is_array( $schemes_data ) ) {
						$schemes_data = false;
					}

					// Attempt to read schemes from parent meta if undefined on variation.
					if ( false === $schemes_data && $product->is_type( 'variation' ) ) {
						$schemes_data = is_object( $parent ) ? $parent->get_meta( '_wcsatt_schemes', true ) : array();

						if ( empty( $schemes_data ) || ! is_array( $schemes_data ) ) {
							$schemes_data = false;
						}
					}
				}

				if ( WCS_ATT_Scheme::MODE_INHERIT === $product_mode ) {
					$global_schemes_data = get_option( 'wcsatt_subscribe_to_cart_schemes', array() );

					if ( ! empty( $global_schemes_data ) && is_array( $global_schemes_data ) ) {
						$schemes_data  = $global_schemes_data;
						$scheme_origin = 'global';
					}
				}

				if ( ! empty( $schemes_data ) && is_array( $schemes_data ) ) {
					foreach ( $schemes_data as $scheme_meta ) {

						$scheme     = new WCS_ATT_Scheme(
							array(
								'data'    => $scheme_meta,
								'context' => $scheme_origin,
							)
						);
						$scheme_key = $scheme->get_key();

						if ( ! isset( $schemes[ $scheme_key ] ) ) {
							$schemes[ $scheme_key ] = $scheme;
						}
					}
				}
			}

			// Filter storewide plans if "Select specific plans" mode is active.
			// Only applies when using global schemes — storewide meta may persist
			// across mode switches but should not filter local custom schemes.
			$storewide_meta_source = $product->is_type( 'variation' ) && $parent instanceof WC_Product ? $parent : $product;
			$selection_mode        = 'global' === $scheme_origin ? $storewide_meta_source->get_meta( '_wcsatt_storewide_selection_mode', true ) : '';
			if ( 'specific' === $selection_mode ) {
				$selected_plan_ids = $storewide_meta_source->get_meta( '_wcsatt_selected_storewide_plans', true );

				if ( ! empty( $selected_plan_ids ) && is_array( $selected_plan_ids ) ) {
					// Filter schemes to only include selected plans.
					$schemes = array_filter(
						$schemes,
						function ( $scheme ) use ( $selected_plan_ids ) {
							return in_array( $scheme->get_key(), $selected_plan_ids, true );
						}
					);
				}
			}

			$schemes = apply_filters( 'wcsatt_product_subscription_schemes', $schemes, $product );

			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_schemes', $schemes );
		}

		if ( ! in_array( $context, array( 'any', 'product' ) ) ) {
			$schemes = self::filter_by_context( $schemes, $context );
		}

		return $schemes;
	}

	/**
	 * Get the active subscription scheme. Note that:
	 * When requesting the active scheme 'key', the function returns:
	 *
	 * - string  if a valid subscription scheme is activated on the object (subscription state defined);
	 * - false   if the product is set to be sold in a non-recurring manner (subscription state defined); or
	 * - null    if no scheme is set on the object (subscription state undefined).
	 *
	 * When requesting the active scheme, the function returns:
	 *
	 * - A WCS_ATT_Scheme instance  if a valid subscription scheme is activated on the object;
	 * - false                      if the product is set to be sold in a non-recurring manner; or
	 * - null                       otherwise.
	 *
	 * Optionally pass a specific key to get the associated scheme, if valid.
	 *
	 * Note that the return value is always validated against 'get_subscription_schemes' and 'has_forced_subscription'.
	 *
	 * @param  WC_Product $product     Product object.
	 * @param  string     $return      What to return - 'object' or 'key'. Optional.
	 * @param  string     $scheme_key  Optional key to get a specific scheme.
	 * @return string|null|false|WCS_ATT_Scheme               Subscription scheme activated on object.
	 */
	public static function get_subscription_scheme( $product, $return = 'key', $scheme_key = '' ) {

		$active_key   = WCS_ATT_Product::get_runtime_meta( $product, 'active_subscription_scheme_key' );
		$search_key   = '' === $scheme_key ? $active_key : $scheme_key;
		$schemes      = self::get_subscription_schemes( $product );
		$found_scheme = null;

		if ( ! empty( $search_key ) && is_array( $schemes ) && isset( $schemes[ $search_key ] ) ) {
			$found_scheme = $schemes[ $search_key ];
		}

		if ( 'key' === $return ) {

			// Looking for a specific scheme other than the active one?
			if ( '' !== $scheme_key ) {
				// Just return the searched key if found, or null otherwise.
				$return_value = is_null( $found_scheme ) ? null : $scheme_key;
				// Looking for the active scheme?
			} else {
				// Return the active scheme key if it points to a valid scheme...
				if ( ! empty( $active_key ) ) {
					$return_value = is_null( $found_scheme ) ? null : $active_key;
					/*
					* Return:
					*
					* - 'false' if the product is set to be sold in a non-recurring manner, or
					* - 'null' otherwise.
					*/
				} else {
					$return_value = false === $active_key && false === self::has_forced_subscription_scheme( $product ) ? false : null;
				}
			}
		} elseif ( 'object' === $return ) {

			// Looking for a specific scheme other than the active one?
			if ( '' !== $scheme_key ) {
				// Just return the scheme if found, or null otherwise.
				$return_value = $found_scheme;
				// Looking for the active scheme?
			} else {
				/*
				* Return:
				*
				* - 'false' if the product is set to be sold in a non-recurring manner,
				* - the active scheme if it exists, or
				* - 'null' otherwise.
				*/
				$return_value = false === $active_key && false === self::has_forced_subscription_scheme( $product ) ? false : $found_scheme;
			}
		}

		return $return_value;
	}

	/**
	 * Get the default subscription scheme (key).
	 *
	 * @param  WC_Product $product  Product object.
	 * @param  string     $return   What to return - 'object' or 'key'. Optional.
	 * @return string|null|false|WCS_ATT_Scheme            Default subscription scheme.
	 */
	public static function get_default_subscription_scheme( $product, $return = 'key' ) {

		if ( ! self::has_subscription_schemes( $product ) ) {
			return 'object' === $return ? null : false;
		}

		$default_scheme_key = WCS_ATT_Product::get_runtime_meta( $product, 'default_subscription_scheme_key' );

		if ( '' === $default_scheme_key ) {

			$default_scheme     = null;
			$default_scheme_key = false;
			$schemes            = self::get_subscription_schemes( $product );

			if ( self::has_forced_subscription_scheme( $product ) ) {

				$default_scheme     = current( $schemes );
				$default_scheme_key = $default_scheme->get_key();
			}

			$default_scheme_key = apply_filters( 'wcsatt_default_subscription_scheme_key', $default_scheme_key, $product );

			WCS_ATT_Product::set_runtime_meta( $product, 'default_subscription_scheme_key', $default_scheme_key );

		} else {

			$default_scheme = self::get_subscription_scheme( $product, 'object', $default_scheme_key );
		}

		return 'object' === $return ? $default_scheme : $default_scheme_key;
	}

	/**
	 * Returns the "base" subscription scheme by finding the one with the lowest recurring price.
	 * If prices are equal, no interval-based comparison is carried out:
	 * Reason: In some applications "$5 every week for 2 weeks" (=$10) might be seen as "cheaper" than "$5 every month for 3 months" (=$15), and in some the opposite.
	 * Instead of making guesswork and complex calculations, we can let scheme order be used to define the "base" scheme manually.
	 *
	 * @param  WC_Product $product
	 * @return WCS_ATT_Scheme
	 */
	public static function get_base_subscription_scheme( $product ) {

		$base_scheme = null;
		$schemes     = self::get_subscription_schemes( $product );

		if ( ! empty( $schemes ) ) {

			$price_filter_exists = self::price_filter_exists( $schemes );
			$base_scheme         = current( $schemes );

			if ( $price_filter_exists ) {

				$product_price = $product->get_price( 'edit' );

				if ( empty( $product_price ) ) {
					$product->set_price( PHP_INT_MAX );
				}

				$base_scheme_price = $product->get_price( 'edit' );

				foreach ( $schemes as $scheme ) {

					$scheme_price = WCS_ATT_Product_Prices::get_price( $product, $scheme->get_key(), 'edit' );

					if ( $scheme_price < $base_scheme_price ) {

						$base_scheme       = $scheme;
						$base_scheme_price = $scheme_price;

					} elseif ( $scheme_price === $base_scheme_price ) {

						$scheme_discount = $scheme->get_discount();

						if ( $scheme_discount && ( is_null( $base_scheme ) || $base_scheme->get_discount() < $scheme_discount ) ) {
							$base_scheme       = $scheme;
							$base_scheme_price = $scheme_price;
						}
					}
				}

				if ( empty( $product_price ) ) {
					$product->set_price( $product_price );
				}
			}
		}

		return apply_filters( 'wcsatt_get_base_scheme', $base_scheme, $product );
	}

	/**
	 * Get the posted product subscription scheme from the single-product page.
	 *
	 * @since  APFS 2.1.0
	 *
	 * @param  mixed $product_id
	 * @return string
	 */
	public static function get_posted_subscription_scheme( $product_id = '' ) {

		$posted_subscription_scheme_key = null;

		$key = ! empty( $product_id ) ? 'convert_to_sub_' . absint( $product_id ) : 'convert_to_sub';

		if ( isset( $_REQUEST[ $key ] ) ) {
			$posted_subscription_scheme_option = wc_clean( $_REQUEST[ $key ] );
			$posted_subscription_scheme_key    = self::parse_subscription_scheme_key( $posted_subscription_scheme_option );
		}

		return $posted_subscription_scheme_key;
	}

	/*
	|--------------------------------------------------------------------------
	| Setters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Associates subscription schemes with a product.
	 * Normally, you wouldn't need to use this since 'WCS_ATT_Product::get_subscription_schemes' will automagically fetch all product-level schemes.
	 * Can be used to append or otherwise modify schemes -- e.g. it is used by 'WCS_ATT_Cart::apply_subscription_schemes' to conditionally attach cart-level schemes on session load.
	 *
	 * @param  WC_Product $product  Product object.
	 * @param  array      $schemes  Schemes.
	 * @return void
	 */
	public static function set_subscription_schemes( $product, $schemes ) {
		WCS_ATT_Product::set_runtime_meta( $product, 'subscription_schemes', $schemes );
		// Reset the cached default scheme value as it depends on the 'subscription_schemes' runtime meta.
		WCS_ATT_Product::set_runtime_meta( $product, 'default_subscription_scheme_key', null );
	}

	/**
	 * Set the active subscription scheme. Key value should be:
	 *
	 * - string  to activate a subscription scheme (valid key required);
	 * - false   to indicate that the product is sold in a non-recurring manner; or
	 * - null    to indicate that the subscription state of the product is undefined.
	 *
	 * Note that the scheme set on the object may become invalid if 'set_subscription_schemes' or 'set_forced_subscription_scheme' are modified.
	 *
	 * @param  WC_Product $product  Product object.
	 * @param  string     $key      Identifier of subscription scheme to activate on object.
	 * @return boolean               Action result.
	 */
	public static function set_subscription_scheme( $product, $key ) {

		$active_scheme_key = self::get_subscription_scheme( $product );
		$schemes           = self::get_subscription_schemes( $product );
		$scheme_set        = false;

		// Sanitize $key: must be scalar to use as array offset. PHP 8.4 fatals on non-scalar keys in isset().
		if ( ! is_scalar( $key ) ) {
			$key = '';
		}

		if ( ! empty( $key ) && is_array( $schemes ) && isset( $schemes[ $key ] ) && $key !== $active_scheme_key ) {

			$scheme_to_set = $schemes[ $key ];

			// Set subscription scheme key.
			WCS_ATT_Product::set_runtime_meta( $product, 'active_subscription_scheme_key', $key );

			/*
			 * Set subscription scheme details. Required for WCS compatibility.
			 * Later on, it might be better to grab these from the active 'WCS_ATT_Scheme' object instead.
			 *
			 * Note that prices are not set directly on objects:
			 * The price strings of many product types depend on more than the values returned by the abstract class price getters.
			 * If we are going to apply filters anyway, there's no need to permanently set raw prices here.
			 */
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_period', $scheme_to_set->get_period() );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_period_interval', $scheme_to_set->get_interval() );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_length', $scheme_to_set->get_length() );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_trial_length', $scheme_to_set->get_trial_length() );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_trial_period', $scheme_to_set->get_trial_period() );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_sign_up_fee', $scheme_to_set->get_signup_fee() );

			$scheme_set = true;

		} elseif ( empty( $key ) ) {

			// Reset subscription scheme key.
			WCS_ATT_Product::set_runtime_meta( $product, 'active_subscription_scheme_key', false === $key ? false : null );

			// Reset subscription scheme details. Required for WCS compatibility.
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_period', null );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_period_interval', null );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_length', null );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_trial_length', null );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_trial_period', null );
			WCS_ATT_Product::set_runtime_meta( $product, 'subscription_sign_up_fee', null );

			$scheme_set = true;
		}

		/**
		 * Action 'wcsatt_set_product_subscription_scheme'.
		 *
		 * @param  mixed       $key
		 * @param  mixed       $active_scheme_key
		 * @param  WC_Product  $product
		 */
		do_action( 'wcsatt_set_product_subscription_scheme', $key, $active_scheme_key, $product );

		return $scheme_set;
	}

	/**
	 * Set the product as purchasable on a recurring basis only.
	 *
	 * @param  WC_Product $product                 Product object to set.
	 * @param  boolean    $is_forced_subscription  Value.
	 */
	public static function set_forced_subscription_scheme( $product, $is_forced_subscription ) {
		WCS_ATT_Product::set_runtime_meta( $product, 'has_forced_subscription', $is_forced_subscription ? 'yes' : 'no' );
		// Reset the cached default scheme value as it depends on the 'has_forced_subscription' runtime meta.
		WCS_ATT_Product::set_runtime_meta( $product, 'default_subscription_scheme_key', null );
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * Indicates whether the product price is modified by one or more subscription schemes.
	 *
	 * @param  array  $subscription_schemes
	 * @param  string $pricing_mode
	 * @return boolean
	 */
	public static function price_filter_exists( $subscription_schemes, $pricing_mode = 'any' ) {

		$price_filter_exists = false;

		foreach ( $subscription_schemes as $subscription_scheme ) {
			if ( $subscription_scheme->has_price_filter() ) {
				if ( 'any' === $pricing_mode || $subscription_scheme->get_pricing_mode() === $pricing_mode ) {
					$price_filter_exists = true;
					break;
				}
			}
		}

		return $price_filter_exists;
	}

	/**
	 * Parses a string-formatted subscription scheme key.
	 *
	 * @since  APFS 3.1.2
	 *
	 * @param  string $key
	 * @return false|string
	 */
	public static function parse_subscription_scheme_key( $key ) {
		return ! empty( $key ) ? strval( $key ) : false;
	}

	/**
	 * Stringifies a subscription scheme key.
	 *
	 * @since  APFS 3.1.2
	 *
	 * @param  string|false $key
	 * @return string
	 */
	public static function stringify_subscription_scheme_key( $key ) {
		return false === $key ? '0' : strval( $key );
	}

	/**
	 * Filter schemes by context.
	 *
	 * @param  array  $schemes
	 * @param  string $context
	 * @return array
	 */
	private static function filter_by_context( $schemes, $context ) {

		$filtered = array();

		foreach ( $schemes as $key => $scheme ) {
			if ( $context === $scheme->get_context() ) {
				$filtered[ $key ] = $scheme;
			}
		}

		return $filtered;
	}
}
