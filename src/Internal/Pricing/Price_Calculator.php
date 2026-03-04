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
	private static function extract( $product, $plan = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $plan will be used during APFS consolidation.
		$price_context = new Price_Context();

		// Read via existing static getters — each fires its own filter hook.
		$price_context->base_recurring_price = (float) WC_Subscriptions_Product::get_price( $product );
		$price_context->billing_interval     = (int) WC_Subscriptions_Product::get_interval( $product );
		$price_context->billing_period       = WC_Subscriptions_Product::get_period( $product );
		$price_context->subscription_length  = (int) WC_Subscriptions_Product::get_length( $product );
		$price_context->trial_length         = (int) WC_Subscriptions_Product::get_trial_length( $product );
		$price_context->trial_period         = WC_Subscriptions_Product::get_trial_period( $product );
		$price_context->base_sign_up_fee     = (float) WC_Subscriptions_Product::get_sign_up_fee( $product );

		// Default empty billing period to 'month' (matches existing behavior).
		if ( empty( $price_context->billing_period ) ) {
			$price_context->billing_period = 'month';
		}

		// Sync state.
		if ( WC_Subscriptions_Synchroniser::is_product_synced( $product )
			&& in_array( $price_context->billing_period, array( 'week', 'month', 'year' ), true ) ) {

			$price_context->is_synced   = true;
			$price_context->payment_day = WC_Subscriptions_Synchroniser::get_products_payment_day( $product );
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
			if ( in_array( $tax_display, array( 'exclude_tax', 'excl' ), true ) ) {
				$price_context->recurring_price = wcs_get_price_excluding_tax( $product );
				$price_context->sign_up_fee     = wcs_get_price_excluding_tax(
					$product,
					array( 'price' => $price_context->base_sign_up_fee )
				);
			} else {
				$price_context->recurring_price = wcs_get_price_including_tax( $product );
				$price_context->sign_up_fee     = wcs_get_price_including_tax(
					$product,
					array( 'price' => $price_context->base_sign_up_fee )
				);
			}
		} else {
			// No tax adjustment — use base values.
			$price_context->recurring_price = $price_context->base_recurring_price;
			$price_context->sign_up_fee     = $price_context->base_sign_up_fee;
		}

		// Determine initial amount for synced subscriptions with upfront payment.
		if ( $price_context->is_synced
			&& WC_Subscriptions_Synchroniser::is_payment_upfront( $product )
			&& ! WC_Subscriptions_Synchroniser::is_today(
				WC_Subscriptions_Synchroniser::calculate_first_payment_date( $product, 'timestamp' )
			) ) {

			$price_context->initial_amount = $price_context->recurring_price;
		}
	}
}
