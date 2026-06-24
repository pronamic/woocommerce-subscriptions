<?php
/**
 * Functional price calculator: extracts subscription data and computes prices.
 *
 * Does NOT handle string rendering — that is Price_String_Renderer's job.
 * This separation allows different callers to render the same Price_Context
 * in different ways (product page, cart, APFS, REST API, etc.).
 *
 * Context-specific public methods:
 *   - calculate_product_price() — product page display (implemented now)
 *   - calculate_cart_price()    — cart/checkout totals (future)
 *   - calculate_switch_price()  — subscription switching/proration (future)
 *
 * @package WooCommerce Subscriptions
 */

namespace Automattic\WooCommerce_Subscriptions\Internal\Pricing;

use WC_Product;
use WC_Subscriptions_Product;
use WC_Subscriptions_Synchroniser;

/**
 * Stateless price calculator for subscription products.
 *
 * @internal This class may be modified, moved or removed in future releases.
 * @since 8.5.0
 */
class Price_Calculator {

	/**
	 * Request-scoped cache for calculated Price_Context objects.
	 *
	 * @var array<string, Price_Context>
	 */
	private static $cache = array();

	/**
	 * Calculate a Price_Context for product page display.
	 *
	 * Extracts subscription data from product meta (firing all existing
	 * getter filter hooks), resolves sync state, applies tax adjustments,
	 * and returns a fully populated Price_Context with numeric values.
	 *
	 * Supported $options:
	 *   'tax_display'  (string|false) Tax display mode: 'incl', 'excl',
	 *                                 'include_tax', 'exclude_tax', or false
	 *                                 to skip tax adjustment.
	 *                                 Default: woocommerce_tax_display_shop option.
	 *
	 * @param WC_Product $product The subscription product.
	 * @param array      $options Calculation options (see above).
	 * @since 8.5.0
	 *
	 * @param mixed      $plan    Subscription plan object (future APFS use).
	 *                            Null for existing subscription product types.
	 * @return Price_Context Fully calculated context.
	 */
	public static function calculate_product_price( $product, $options = array(), $plan = null ) {
		$options = wp_parse_args(
			$options,
			array(
				'tax_display' => get_option( 'woocommerce_tax_display_shop' ),
			)
		);

		$price_context = self::extract( $product, $plan );

		// Build cache key from product ID + extracted data + options.
		// Product ID is needed because two products with identical subscription
		// meta but different tax classes would otherwise produce the same key.
		$cache_key = $product->get_id() . '::' . md5( wp_json_encode( $price_context->to_array() ) ) . '::' . md5( wp_json_encode( $options ) );

		if ( isset( self::$cache[ $cache_key ] ) ) {
			return clone self::$cache[ $cache_key ];
		}

		self::apply_tax( $product, $price_context, $options['tax_display'] );

		self::$cache[ $cache_key ] = clone $price_context;

		return $price_context;
	}

	/**
	 * Clear the request-scoped cache. Useful for testing.
	 *
	 * @since 8.5.0
	 */
	public static function clear_cache() {
		self::$cache = array();
	}

	/**
	 * Returns the regular price to use as the cap when displaying a fixed discount.
	 *
	 * Variable products have no single regular price; use the highest variation regular
	 * price so that "save up to $X" is capped against the most expensive variant.
	 *
	 * @param  WC_Product $product  Product object.
	 * @return float
	 * @since 8.5.0
	 */
	public static function get_regular_price_for_discount_cap( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			// @phpstan-ignore method.notFound
			return (float) $product->get_variation_regular_price( 'max' );
		}
		if ( $product->is_type( 'composite' ) && method_exists( $product, 'get_composite_price' ) ) {
			// @phpstan-ignore method.notFound
			return (float) $product->get_composite_price( 'min' );
		}
		if ( $product->is_type( 'bundle' ) && method_exists( $product, 'get_bundle_price' ) ) {
			// @phpstan-ignore method.notFound
			return (float) $product->get_bundle_price( 'min' );
		}
		return (float) $product->get_regular_price();
	}

	/**
	 * ------------------------------------------------
	 * Private implementation methods
	 * ------------------------------------------------
	 */

	/**
	 * Extract raw price data from a subscription product into a Price_Context.
	 *
	 * Shared building block for all calculate_* methods. Calls existing
	 * WC_Subscriptions_Product static getters which fire their filter hooks.
	 *
	 * @param WC_Product $product The subscription product.
	 * @param mixed      $plan    Subscription plan object, or null.
	 * @return Price_Context Populated with raw subscription data.
	 */
	private static function extract( $product, $plan = null ) {
		$price_context = new Price_Context();

		if ( $plan instanceof \WCS_ATT_Scheme ) {
			// Plan-based: read subscription parameters from scheme object.
			$price_context->billing_period      = $plan->get_period();
			$price_context->billing_interval    = (int) $plan->get_interval();
			$price_context->subscription_length = (int) $plan->get_length();
			$price_context->trial_length        = (int) $plan->get_trial_length();
			$price_context->trial_period        = $plan->get_trial_period();
			$price_context->base_sign_up_fee    = $plan->get_signup_fee();

			// Resolve recurring price from scheme pricing mode.
			$raw_prices = array(
				'price'         => $product->get_price( 'edit' ),
				'regular_price' => $product->get_regular_price( 'edit' ),
				'sale_price'    => $product->get_sale_price( 'edit' ),
			);

			if ( $plan->has_price_filter() ) {
				$resolved                            = $plan->get_prices( $raw_prices );
				$price_context->base_recurring_price = (float) $resolved['price'];
			} else {
				$price_context->base_recurring_price = (float) $raw_prices['price'];
			}

			// Sync state from scheme.
			if ( $plan->is_synced() ) {
				$price_context->is_synced              = true;
				$price_context->payment_day            = $plan->get_sync_date();
				$price_context->first_billing_behavior = \WC_Subscriptions_Synchroniser::resolve_billing_behavior( \WCS_ATT_Sync::is_first_payment_prorated( $product, $plan ) );
			}
		} else {
			// Native subscription: read via existing static getters.
			$price_context->base_recurring_price = (float) WC_Subscriptions_Product::get_price( $product );
			$price_context->billing_interval     = (int) WC_Subscriptions_Product::get_interval( $product );
			$price_context->billing_period       = WC_Subscriptions_Product::get_period( $product );
			$price_context->subscription_length  = (int) WC_Subscriptions_Product::get_length( $product );
			$price_context->trial_length         = (int) WC_Subscriptions_Product::get_trial_length( $product );
			$price_context->trial_period         = WC_Subscriptions_Product::get_trial_period( $product );
			$price_context->base_sign_up_fee     = (float) WC_Subscriptions_Product::get_sign_up_fee( $product );

			// Sync state.
			if ( WC_Subscriptions_Synchroniser::is_product_synced( $product )
				&& in_array( $price_context->billing_period, array( 'week', 'month', 'year' ), true ) ) {

				$price_context->is_synced              = true;
				$price_context->payment_day            = WC_Subscriptions_Synchroniser::get_products_payment_day( $product );
				$price_context->first_billing_behavior = WC_Subscriptions_Synchroniser::resolve_billing_behavior( WC_Subscriptions_Synchroniser::is_product_prorated( $product ) );
			}
		}

		// Default empty billing period to 'month' (matches existing behavior).
		if ( empty( $price_context->billing_period ) ) {
			$price_context->billing_period = 'month';
		}

		return $price_context;
	}

	/**
	 * Apply tax adjustments to base values on a Price_Context.
	 *
	 * Populates $recurring_price, $sign_up_fee, and $initial_amount
	 * with tax-adjusted numeric values.
	 *
	 * @param WC_Product    $product       The product (needed for tax class context).
	 * @param Price_Context $price_context Raw context from extract().
	 * @param string|false  $tax_display   Tax display mode.
	 */
	private static function apply_tax( $product, Price_Context $price_context, $tax_display ) {
		if ( $tax_display ) {
			$exclude = in_array( $tax_display, array( 'exclude_tax', 'excl' ), true );

			// Use wc_get_price_* directly instead of the wcs_get_price_* wrappers.
			//
			// The wcs_* wrappers call wp_parse_args() with 'price' => $product->get_price()
			// as the default, which triggers the woocommerce_product_get_price filter and
			// causes an infinite loop when this method is called from within a price filter
			// callback (e.g. woocommerce_subscriptions_cart_get_price).
			//
			// This is safe because both wcs_get_price_including_tax() and wcs_get_price_excluding_tax()
			// are thin wrappers around the wc_* equivalents — they only add the default 'price' arg,
			// which we already supply explicitly here.
			$args                           = array(
				'qty'   => 1,
				'price' => $price_context->base_recurring_price,
			);
			$price_context->recurring_price = $exclude
				? wc_get_price_excluding_tax( $product, $args )
				: wc_get_price_including_tax( $product, $args );

			$args['price']              = $price_context->base_sign_up_fee;
			$price_context->sign_up_fee = $exclude
				? wc_get_price_excluding_tax( $product, $args )
				: wc_get_price_including_tax( $product, $args );
		} else {
			// No tax adjustment — use base values.
			$price_context->recurring_price = $price_context->base_recurring_price;
			$price_context->sign_up_fee     = $price_context->base_sign_up_fee;
		}

		// Determine initial amount for synced subscriptions with upfront payment.
		// For native subscriptions, pass the product to preserve filters and static cache.
		// For plan-based (APFS), pass the Price_Context which holds the scheme's sync data.
		if ( $price_context->is_synced ) {
			$sync_source             = WC_Subscriptions_Synchroniser::is_product_synced( $product )
				? $product
				: $price_context;
			$first_payment_timestamp = WC_Subscriptions_Synchroniser::calculate_first_payment_date( $sync_source, 'timestamp' );

			if ( WC_Subscriptions_Synchroniser::is_payment_upfront( $sync_source )
				&& ! WC_Subscriptions_Synchroniser::is_today( $first_payment_timestamp ) ) {
				$price_context->initial_amount = $price_context->recurring_price;
			}
		}
	}
}
