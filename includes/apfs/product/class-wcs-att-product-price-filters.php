<?php
/**
 * WCS_ATT_Product_Price_Filters class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles modifications to the prices of subscription-enabled product objects.
 *
 * @class    WCS_ATT_Product_Price_Filters
 * @version  3.2.0
 */
class WCS_ATT_Product_Price_Filters {

	/**
	 * Runtime cache.
	 *
	 * @var array
	 */
	private static $filter_instance_plan_prices = array();

	/**
	 * Whether we are currently rendering WooCommerce's grouped product list.
	 *
	 * Toggled by the 'woocommerce_grouped_product_list_before' and
	 * 'woocommerce_grouped_product_list_after' action handlers below. APFS is
	 * not supported on grouped products (see WOOSUBS-1267), so price-html
	 * mutations are suppressed while this flag is set.
	 *
	 * @var bool
	 */
	private static $in_grouped_product_list = false;

	/*
	|--------------------------------------------------------------------------
	| Public Price Filters API
	|--------------------------------------------------------------------------
	*/

	/**
	 * Determine filtering context - 'inherit' or 'override'.
	 *
	 * @since  APFS 3.1.0
	 *
	 * @return string
	 */
	public static function get_price_filter_type() {

		global $wp_filter, $wp_current_filter;

		$action = end( $wp_current_filter );
		$filter = $wp_filter[ $action ];

		return ! $filter->current_priority() ? WCS_ATT_Scheme::MODE_OVERRIDE : WCS_ATT_Scheme::MODE_INHERIT;
	}

	/**
	 * Whether the current WP price filter context is the 'override' pipeline.
	 * MODE_INHERIT and MODE_FIXED_DISCOUNT both run in the non-override pipeline.
	 *
	 * @return bool
	 */
	private static function is_processing_override_filter() {
		return WCS_ATT_Scheme::MODE_OVERRIDE === self::get_price_filter_type();
	}

	/**
	 * Whether the pricing mode inherits or adjusts the product's base price (inherit or fixed_discount).
	 *
	 * @param  string $pricing_mode  WCS_ATT_Scheme pricing mode constant.
	 * @return bool
	 */
	private static function is_price_inheriting_mode( $pricing_mode ) {
		return in_array( $pricing_mode, array( WCS_ATT_Scheme::MODE_INHERIT, WCS_ATT_Scheme::MODE_FIXED_DISCOUNT ), true );
	}

	/**
	 * Whether the scheme's pricing mode matches the currently-running WP filter pipeline.
	 *
	 * Price filters are hooked at two priorities: 0 (override pipeline) and 99 (inherit/fixed_discount pipeline).
	 * WP runs all priority-0 hooks before priority-99 hooks, so both fire for every filtered value.
	 * This guard ensures each scheme type is only processed by its own pipeline and skipped by the other.
	 *
	 * @param  string $pricing_mode  WCS_ATT_Scheme pricing mode constant.
	 * @return bool  True when the scheme should be processed; false when it should be skipped.
	 */
	private static function scheme_matches_current_pipeline( $pricing_mode ) {
		return self::is_processing_override_filter() === ( WCS_ATT_Scheme::MODE_OVERRIDE === $pricing_mode );
	}

	/**
	 * Allow plan prices to be filtered for this product?
	 *
	 * @since  APFS 3.1.0
	 *
	 * @param  WC_Product $product
	 * @return bool
	 */
	public static function filter_plan_prices( $product ) {

		$instance_id = WCS_ATT_Product::get_instance_id( $product );

		if ( isset( self::$filter_instance_plan_prices[ $instance_id ] ) ) {
			return self::$filter_instance_plan_prices[ $instance_id ];
		}

		self::$filter_instance_plan_prices[ $instance_id ] = apply_filters( 'wcsatt_price_filters_allowed', true, $product );

		return self::$filter_instance_plan_prices[ $instance_id ];
	}

	/**
	 * Add price filters. Filtering early allows us to override "raw" prices as safely as possible.
	 * This allows 3p code to apply discounts or other transformations on overridden prices.
	 * The catch: Any price filters added by 3p code with a priority earlier than 0 will be rendered ineffective.
	 *
	 * @param  string $context  Filtering context. Values: 'price', 'price_html', ''.
	 * @return void
	 */
	public static function add( $context = '' ) {

		if ( in_array( $context, array( 'price', '' ) ) ) {

			// 'Override' context.
			add_filter( 'woocommerce_variation_prices', array( __CLASS__, 'filter_variation_prices' ), 0, 2 );
			add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 0, 2 );
			add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 0, 2 );
			add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 0, 2 );
			add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 0, 2 );
			add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 0, 2 );
			add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 0, 2 );

			// 'Inherit' context.
			add_filter( 'woocommerce_variation_prices', array( __CLASS__, 'filter_variation_prices' ), 99, 2 );
			add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
			add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 99, 2 );
			add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
			add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
			add_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 99, 2 );
			add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );

			add_filter( 'woocommerce_subscriptions_product_price', array( __CLASS__, 'filter_subscription_price' ), 99, 2 );
			add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'filter_variation_prices_hash' ), 0, 2 );
			add_filter( 'woocommerce_available_variation', array( __CLASS__, 'filter_variation_data' ), 0, 3 );

			add_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_is_on_sale' ), 99, 2 );

			/**
			 * Action 'wcsatt_add_price_filters'.
			 */
			do_action( 'wcsatt_add_price_filters' );

		}

		if ( in_array( $context, array( 'price_html', '' ) ) ) {

			add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 9999, 2 );

			// Suppress APFS price-html mutations inside WooCommerce's grouped product list.
			// Grouped products are not supported by APFS (see WOOSUBS-1267).
			add_action( 'woocommerce_grouped_product_list_before', array( __CLASS__, 'before_grouped_product_list' ) );
			add_action( 'woocommerce_grouped_product_list_after', array( __CLASS__, 'after_grouped_product_list' ) );

			/**
			* Action 'wcsatt_add_price_html_filters'.
			*/
			do_action( 'wcsatt_add_price_html_filters' );
		}
	}

	/**
	 * Remove price filters.
	 *
	 * @param  string $context  Filtering context. Values: 'price', 'price_html', ''.
	 * @return void
	 */
	public static function remove( $context = '' ) {

		if ( in_array( $context, array( 'price', '' ) ) ) {

			remove_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 0, 2 );
			remove_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 0, 2 );
			remove_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 0, 2 );
			remove_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 0, 2 );
			remove_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 0, 2 );
			remove_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 0, 2 );
			remove_filter( 'woocommerce_subscriptions_product_price', array( __CLASS__, 'filter_price' ), 0, 2 );
			remove_filter( 'woocommerce_variation_prices', array( __CLASS__, 'filter_variation_prices' ), 0, 2 );

			remove_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
			remove_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
			remove_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 99, 2 );
			remove_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'filter_price' ), 99, 2 );
			remove_filter( 'woocommerce_product_variation_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 99, 2 );
			remove_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 99, 2 );
			remove_filter( 'woocommerce_subscriptions_product_price', array( __CLASS__, 'filter_price' ), 99, 2 );
			remove_filter( 'woocommerce_variation_prices', array( __CLASS__, 'filter_variation_prices' ), 99, 2 );

			remove_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'filter_variation_prices_hash' ), 0, 2 );
			remove_filter( 'woocommerce_available_variation', array( __CLASS__, 'filter_variation_data' ), 0, 3 );

			remove_filter( 'woocommerce_product_is_on_sale', array( __CLASS__, 'filter_is_on_sale' ), 99, 2 );

			/**
			 * Action 'wcsatt_remove_price_filters'.
			 */
			do_action( 'wcsatt_remove_price_filters' );
		}

		if ( in_array( $context, array( 'price_html', '' ) ) ) {

			remove_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 9999, 2 );

			remove_action( 'woocommerce_grouped_product_list_before', array( __CLASS__, 'before_grouped_product_list' ) );
			remove_action( 'woocommerce_grouped_product_list_after', array( __CLASS__, 'after_grouped_product_list' ) );

			/**
			 * Action 'wcsatt_remove_price_html_filters'.
			 */
			do_action( 'wcsatt_remove_price_html_filters' );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Filters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Filter html price based on the subscription scheme that is activated on the object.
	 *
	 * @param  string     $price_html
	 * @param  WC_Product $product
	 * @return string
	 */
	public static function filter_price_html( $price_html, $product ) {

		// Leave native WooCommerce output alone while rendering a grouped product's child list.
		// APFS is not supported on grouped products (see WOOSUBS-1267), so neither the
		// "subscribe to save..." promo suffix nor forced-subscription recurring-price strings
		// should appear on a grouped product page.
		if ( self::$in_grouped_product_list ) {
			return $price_html;
		}

		if ( $price_html && WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			$args = array( 'price' => $price_html );

			// On single product pages, include sync details (e.g. "on the 15th of each month")
			// so the displayed price matches the legacy subscription product behaviour.
			// The default 'catalog' context suppresses sync details for compact shop listings.
			if ( is_product() ) {
				$args['include_sync_details'] = true;
			}

			$price_html = WCS_ATT_Product_Prices::get_price_html( $product, '', $args );
		}

		return $price_html;
	}

	/**
	 * Mark the start of WooCommerce's grouped product list rendering so that
	 * 'filter_price_html' can bail and leave native output untouched.
	 *
	 * @return void
	 */
	public static function before_grouped_product_list() {
		self::$in_grouped_product_list = true;
	}

	/**
	 * Mark the end of WooCommerce's grouped product list rendering.
	 *
	 * @return void
	 */
	public static function after_grouped_product_list() {
		self::$in_grouped_product_list = false;
	}

	/**
	 * Filter variation data based on the subscription scheme that is activated on the parent.
	 *
	 * @param  array                $variation_data
	 * @param  WC_Product_Variable  $product
	 * @param  WC_Product_Variation $variation
	 * @return array
	 */
	public static function filter_variation_data( $variation_data, $product, $variation ) {

		WCS_ATT_Product_Schemes::set_subscription_schemes( $variation, null );
		WCS_ATT_Product::set_runtime_meta( $variation, 'parent_product', $product );

		$is_bundled = class_exists( 'WC_Bundles' ) && did_action( 'woocommerce_bundled_product_price_filters_added' ) > did_action( 'woocommerce_bundled_product_price_filters_removed' );

		if ( $product->is_type( 'variable-subscription' ) ) {
			return $variation_data;
		}

		if ( ! WCS_ATT_Product_Schemes::has_subscription_schemes( $variation ) && ! $is_bundled ) {
			return $variation_data;
		}

		$variation_schemes            = WCS_ATT_Product_Schemes::get_subscription_schemes( $variation );
		$product_scheme               = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
		$variation_scheme             = WCS_ATT_Product_Schemes::get_subscription_scheme( $variation );
		$product_has_forced_sub       = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product );
		$variation_has_forced_sub     = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $variation );
		$variation_data_update_needed = $is_bundled;

		// Copy "Force Subscription" state from parent.
		if ( $product_has_forced_sub !== $variation_has_forced_sub ) {
			WCS_ATT_Product_Schemes::set_forced_subscription_scheme( $variation, $product_has_forced_sub );
			$variation_has_forced_sub     = $product_has_forced_sub;
			$variation_data_update_needed = true;
		}

		// Set active product scheme on child.
		if ( ! empty( $variation_schemes ) && $product_scheme !== $variation_scheme ) {

			if ( in_array( $product_scheme, array_keys( $variation_schemes ) ) ) {
				$variation_data_update_needed = true;
			} elseif ( false === $product_scheme && false === $variation_has_forced_sub ) {
				$variation_data_update_needed = true;
			} elseif ( $variation_has_forced_sub ) {
				$variation_data_update_needed = true;
				$product_scheme               = WCS_ATT_Product_Schemes::get_default_subscription_scheme( $variation );
			}
		}

		if ( $variation_data_update_needed ) {

			WCS_ATT_Product_Schemes::set_subscription_scheme( $variation, $product_scheme );

			$variation_data['display_price']         = wc_get_price_to_display( $variation );
			$variation_data['display_regular_price'] = wc_get_price_to_display( $variation, array( 'price' => $variation->get_regular_price() ) );
			$variation_data['price_html']            = $variation_data['price_html'] ? '<span class="price">' . $variation->get_price_html() . '</span>' : '';
		}

		return $variation_data;
	}

	/**
	 * Filter variation prices hash to load different prices depending on the scheme that's active on the object.
	 *
	 * @param  array               $hash
	 * @param  WC_Product_Variable $product
	 * @return array
	 */
	public static function filter_variation_prices_hash( $hash, $product ) {

		$active_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );

		if ( ! empty( $active_scheme ) ) {
			$hash[] = $active_scheme ? $active_scheme : '0';
		}

		return $hash;
	}

	/**
	 * Filter get_variation_prices() calls to take price filters into account.
	 * We could as well have used 'woocommerce_variation_prices_{regular_/sale_}price' filters.
	 * This is a bit slower but makes code simpler when there are no variation-level schemes.
	 *
	 * @param  array               $raw_prices
	 * @param  WC_Product_Variable $product
	 * @return array
	 */
	public static function filter_variation_prices( $raw_prices, $product ) {

		$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object' );

		if ( ! empty( $subscription_scheme ) && $subscription_scheme->has_price_filter() ) {

			$pricing_mode = $subscription_scheme->get_pricing_mode();

			if ( ! self::scheme_matches_current_pipeline( $pricing_mode ) ) {
				return $raw_prices;
			}

			if ( ! self::filter_plan_prices( $product ) ) {
				return $raw_prices;
			}

			$prices         = array();
			$regular_prices = array();
			$sale_prices    = array();

			$variation_ids = array_keys( $raw_prices['price'] );

			foreach ( $variation_ids as $variation_id ) {

				$overridden_prices = $subscription_scheme->get_prices(
					array(
						'price'         => $raw_prices['price'][ $variation_id ],
						'sale_price'    => $raw_prices['sale_price'][ $variation_id ],
						'regular_price' => $raw_prices['regular_price'][ $variation_id ],
					)
				);

				$prices[ $variation_id ]         = $overridden_prices['price'];
				$sale_prices[ $variation_id ]    = $overridden_prices['sale_price'];
				$regular_prices[ $variation_id ] = $overridden_prices['regular_price'];
			}

			asort( $prices );
			asort( $sale_prices );
			asort( $regular_prices );

			$raw_prices = array(
				'price'         => $prices,
				'sale_price'    => $sale_prices,
				'regular_price' => $regular_prices,
			);
		}

		return $raw_prices;
	}

	/**
	 * Filter get_price() calls to take scheme price overrides into account.
	 *
	 * @param  double     $price
	 * @param  WC_Product $product
	 * @return double
	 */
	public static function filter_price( $price, $product ) {

		if ( WCS_ATT_Product::is_subscription( $product ) ) {

			$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object' );

			if ( ! empty( $subscription_scheme ) && $subscription_scheme->has_price_filter() ) {

				$pricing_mode = $subscription_scheme->get_pricing_mode();

				if ( ! self::scheme_matches_current_pipeline( $pricing_mode ) ) {
					return $price;
				}

				if ( WCS_ATT_Scheme::MODE_OVERRIDE === $pricing_mode ) {

					$price = WCS_ATT_Product_Prices::get_price( $product, '', 'edit' );

				} elseif ( self::is_price_inheriting_mode( $pricing_mode ) ) {

					if ( ! self::filter_plan_prices( $product ) ) {
						return $price;
					}

					$overridden_prices = $subscription_scheme->get_prices(
						array(
							'price'         => $price,
							'sale_price'    => '',
							'regular_price' => $product->get_regular_price(),
							'offset_price'  => WCS_ATT_Product::get_runtime_meta( $product, 'price_offset' ),
						)
					);

					$price = $overridden_prices['price'];
				}
			}

			if ( '' === $price && $product->is_type( array( 'bundle', 'composite' ) ) && $product->contains( 'priced_individually' ) ) {
				$price = (float) $price;
			}
		}

		return $price;
	}

	/**
	 * Filter get_regular_price() calls to take scheme price overrides into account.
	 *
	 * @param  double     $price
	 * @param  WC_Product $product
	 * @return double
	 */
	public static function filter_regular_price( $regular_price, $product ) {

		if ( WCS_ATT_Product::is_subscription( $product ) ) {

			$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object' );

			if ( ! empty( $subscription_scheme ) && $subscription_scheme->has_price_filter() ) {

				$pricing_mode = $subscription_scheme->get_pricing_mode();

				if ( ! self::scheme_matches_current_pipeline( $pricing_mode ) ) {
					return $regular_price;
				}

				if ( WCS_ATT_Scheme::MODE_OVERRIDE === $pricing_mode ) {

					$regular_price = WCS_ATT_Product_Prices::get_regular_price( $product, '', 'edit' );

				} elseif ( WCS_ATT_Scheme::MODE_INHERIT === $pricing_mode ) {

					if ( ! self::filter_plan_prices( $product ) ) {
						return $regular_price;
					}

					if ( $subscription_scheme->get_discount() > 0 && ! apply_filters( 'wcsatt_discount_from_regular', false ) ) {

						self::remove( 'price' );
						$sale_price = $product->get_sale_price();
						$is_on_sale = $sale_price && $product->is_on_sale();
						self::add( 'price' );

						if ( $is_on_sale ) {
							$regular_price = $sale_price;
						}
					}
				}
			}
		}
		return $regular_price;
	}

	/**
	 * Filter get_sale_price() calls to take scheme price overrides into account.
	 *
	 * @param  double     $sale_price
	 * @param  WC_Product $product
	 * @return double
	 */
	public static function filter_sale_price( $sale_price, $product ) {

		if ( WCS_ATT_Product::is_subscription( $product ) ) {

			$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object' );

			if ( ! empty( $subscription_scheme ) && $subscription_scheme->has_price_filter() ) {

				$pricing_mode = $subscription_scheme->get_pricing_mode();

				if ( ! self::scheme_matches_current_pipeline( $pricing_mode ) ) {
					return $sale_price;
				}

				if ( WCS_ATT_Scheme::MODE_OVERRIDE === $pricing_mode ) {

					$sale_price = WCS_ATT_Product_Prices::get_sale_price( $product, '', 'edit' );

				} elseif ( self::is_price_inheriting_mode( $pricing_mode ) ) {

					if ( ! self::filter_plan_prices( $product ) ) {
						return $sale_price;
					}

					$overridden_prices = $subscription_scheme->get_prices(
						array(
							'price'         => $sale_price,
							'sale_price'    => '',
							'regular_price' => $product->get_regular_price(),
							'offset_price'  => WCS_ATT_Product::get_runtime_meta( $product, 'price_offset' ),
						)
					);

					$sale_price = $overridden_prices['sale_price'];
				}
			}
		}

		return $sale_price;
	}

	/**
	 * Filter WC_Subscriptions_Product::get_price() calls.
	 *
	 * @since  APFS 3.1.0
	 *
	 * @param  double     $price
	 * @param  WC_Product $product
	 * @return double
	 */
	public static function filter_subscription_price( $price, $product ) {

		if ( WCS_ATT_Product::is_subscription( $product ) && WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
			$price = $product->get_price();
		}

		return $price;
	}

	/**
	 * Filter WC_Product::is_on_sale() calls.
	 *
	 * @since  APFS 3.1.29
	 *
	 * @param  bool       $is_on_sale
	 * @param  WC_Product $product
	 * @return bool
	 */
	public static function filter_is_on_sale( $is_on_sale, $product ) {

		if ( WCS_ATT_Product::is_subscription( $product ) ) {

			$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object' );

			if ( ! empty( $subscription_scheme ) && $subscription_scheme->has_price_filter() ) {

				$pricing_mode = $subscription_scheme->get_pricing_mode();

				if ( ! self::scheme_matches_current_pipeline( $pricing_mode ) ) {
					return $is_on_sale;
				}

				if ( 'inherit' === $pricing_mode ) {

					if ( ! self::filter_plan_prices( $product ) ) {
						return $is_on_sale;
					}

					if ( $subscription_scheme->get_discount() > 0 ) {
						$is_on_sale = true;
					}
				}
			}
		}

		return $is_on_sale;
	}
}
