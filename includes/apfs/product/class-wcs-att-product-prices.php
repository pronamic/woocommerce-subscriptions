<?php
/**
 * WCS_ATT_Product_Prices class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce_Subscriptions\Internal\Pricing\Price_Calculator;
use Automattic\WooCommerce_Subscriptions\Internal\Pricing\Price_String_Renderer;

/**
 * API for working with the prices of subscription-enabled product objects.
 *
 * @class    WCS_ATT_Product_Prices
 * @version  6.0.7
 */
class WCS_ATT_Product_Prices {

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Add price filters.
	 *
	 * @return void
	 */
	private static function add_hooks() {

		add_action( 'plugins_loaded', array( 'WCS_ATT_Product_Price_Filters', 'add' ), 99 );
	}

	/*
	|--------------------------------------------------------------------------
	| Getters
	|--------------------------------------------------------------------------
	*/

	/**
	 * Returns a string representing the details of the active subscription scheme.
	 *
	 * @param  WC_Product $product  Product object.
	 * @param  array      $include  An associative array of flags to indicate how to calculate the price and what to include - @see 'WC_Subscriptions_Product::get_price_string'.
	 * @param  array      $args     Optional args to pass into 'WC_Subscriptions_Product::get_price_string'. Use 'scheme_key' to optionally define a scheme key to use.
	 * @return string
	 */
	public static function get_price_string( $product, $args = array() ) {

		$scheme_key = isset( $args['scheme_key'] ) ? $args['scheme_key'] : '';

		$active_scheme_key = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
		$scheme_key        = '' === $scheme_key ? $active_scheme_key : $scheme_key;

		$scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $scheme_key );

		if ( $scheme ) {
			$options       = array( 'tax_display' => isset( $args['tax_calculation'] ) ? $args['tax_calculation'] : null );
			$price_context = Price_Calculator::calculate_product_price( $product, $options, $scheme );
			$price_string  = Price_String_Renderer::render( $price_context, $args );
		} else {
			// Fallback: no scheme found, use legacy path.
			$price_string = WC_Subscriptions_Product::get_price_string( $product, $args );
		}

		return $price_string;
	}

	/**
	 * Returns the price html associated with the active subscription scheme.
	 * You may optionally pass a scheme key to get the price html string associated with it.
	 *
	 * @param  WC_Product $product     Product object.
	 * @param  integer    $scheme_key  Scheme key or the currently active one, if undefined. Optional.
	 * @param  array      $args        Optional args to pass into 'WC_Subscriptions_Product::get_price_string'.
	 * @return string
	 */
	public static function get_price_html( $product, $scheme_key = '', $args = array() ) {

		$active_scheme_key   = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
		$scheme_key          = '' === $scheme_key ? $active_scheme_key : $scheme_key;
		$is_valid_scheme_key = ! is_null( $scheme_key ) && false !== $scheme_key;
		$is_otp_scheme       = false === $scheme_key;

		// Verify the requested scheme exists when different from the active one.
		if (
			$is_valid_scheme_key
			&& $scheme_key !== $active_scheme_key
			&& ! WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $scheme_key )
		) {
			return '';
		}

		$price_html = '';
		$context    = isset( $args['context'] ) ? $args['context'] : 'catalog';

		/**
		 * 'wcsatt_price_html_args' filter.
		 *
		 * @since  APFS 3.0.0
		 *
		 * @param  WC_Product  $product
		 * @param  integer     $scheme_key
		 * @param  array       $args
		 */
		$args = apply_filters( 'wcsatt_price_html_args', $args, $product, $scheme_key );

		// On the single product page and in catalog/shop loops the trial and sign-up fee are surfaced as dedicated
		// detail lines next to the plan selector (or deliberately hidden), so default both off here — every APFS price
		// string rendered in those contexts (catalog, single product, prompt, dropdown, options) omits the
		// "with a N-day free trial" / "and a $X sign-up fee" suffix unless a caller opts in. Every other context
		// (REST API, widgets, mini-cart, page builders, ...) keeps the suffix.
		if ( WC_Subscriptions_Product::should_omit_inline_trial_and_fee() ) {
			if ( ! isset( $args['sign_up_fee'] ) ) {
				$args['sign_up_fee'] = false;
			}
			if ( ! isset( $args['trial_length'] ) ) {
				$args['trial_length'] = false;
			}
		}

		$subscribe_options_html = ! empty( $args['subscribe_options_html'] ) ? $args['subscribe_options_html'] : _x( 'Subscribe', 'Subscribe call-to-action', 'woocommerce-subscriptions' );
		$subscribe_for_html     = ! empty( $args['subscribe_for_html'] ) ? $args['subscribe_for_html'] : _x( 'Subscribe for %s', 'Subscribe to plan', 'woocommerce-subscriptions' );
		$subscribe_from_html    = ! empty( $args['subscribe_from_html'] ) ? $args['subscribe_from_html'] : _x( 'Subscribe from %s', 'Subscribe to plans', 'woocommerce-subscriptions' );
		/* translators: %1$s: "up to" text or empty string, %2$s: the formatted discount percentage with HTML */
		$subscribe_discounted_html = ! empty( $args['subscribe_discounted_html'] ) ? $args['subscribe_discounted_html'] : _x( 'Subscribe to save %1$s%2$s', 'Subscribe to plan(s) for discount', 'woocommerce-subscriptions' );

		$html_for_text         = _x( '<span class="for">for</span> ', 'subscription "for" price string', 'woocommerce-subscriptions' );
		$html_from_text        = _x( '<span class="from">from</span> ', 'subscription "from" price string', 'woocommerce-subscriptions' );
		$html_from_text_native = wc_get_price_html_from_text();

		$include_sync_details = isset( $args['include_sync_details'] ) ? $args['include_sync_details'] : false === in_array( $context, array( 'catalog', 'prompt' ) );

		// Determine the effective scheme for display.
		// When the product is a subscription, use the active scheme.
		// When a specific scheme_key is requested (but not active), use that scheme directly.
		$display_scheme = null;

		if ( WCS_ATT_Product::is_subscription( $product ) ) {
			$display_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object' );
		} elseif ( $is_valid_scheme_key ) {
			$display_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $scheme_key );
		}

		// Scheme is set on the object or a specific subscription scheme requested?
		if ( $display_scheme && ! $is_otp_scheme ) {

			$details_html = '';

			$schemes             = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
			$price_filter_exists = WCS_ATT_Product_Schemes::price_filter_exists( $schemes );
			$active_scheme       = $display_scheme;

			// Whether to suppress sync details in the rendered price string.
			$suppress_sync = false === $include_sync_details && $active_scheme->is_synced();

			$force_discount  = isset( $args['force_discount'] ) && $args['force_discount'];
			$append_discount = isset( $args['append_discount'] ) && $args['append_discount'];
			$append_price    = isset( $args['append_price'] ) && $args['append_price'];
			$hide_price      = isset( $args['hide_price'] ) && $args['hide_price'];

			if ( ! $active_scheme->get_discount() || 'dropdown' === $context ) {
				$force_discount  = false;
				$append_discount = false;
			}

			if ( 'catalog' === $context ) {
				$hide_price     = false;
				$force_discount = false;
			}

			$hide_string_price = false;

			if ( $hide_price || $force_discount || ( false === $price_filter_exists && 'catalog' !== $context ) ) {
				$hide_string_price = true;
				$append_price      = false;
			}

			// Generating price string without price amount?
			if ( $hide_string_price || $append_price ) {

				// When appending the price to the end, generate it here.
				if ( $append_price ) {
					$price_html = empty( $args['price'] ) ? self::get_scheme_price_html_for_product( $product, $active_scheme ) : $args['price'];
				}

				$args['price']           = '';
				$args['tax_calculation'] = false;

				if ( ! $active_scheme->is_synced() ) {
					$args['subscription_price'] = false;
				}
			} else {
				$args['price'] = empty( $args['price'] ) ? self::get_scheme_price_html_for_product( $product, $active_scheme ) : $args['price'];
			}

			// Use functional Price_Calculator path instead of WC_Subscriptions_Product::get_price_string().
			$calc_options  = array( 'tax_display' => isset( $args['tax_calculation'] ) ? $args['tax_calculation'] : null );
			$price_context = Price_Calculator::calculate_product_price( $product, $calc_options, $active_scheme );

			// Suppress sync details by clearing sync state on the context.
			if ( $suppress_sync ) {
				$price_context->is_synced      = false;
				$price_context->initial_amount = 0.0;
			}

			if ( $append_price ) {
				$details_html = Price_String_Renderer::render( $price_context, $args );
			} else {
				$price_html = Price_String_Renderer::render( $price_context, $args );
			}

			// Appending a discount string?
			if ( $force_discount || $append_discount ) {

				$discount = $active_scheme->get_discount();
				if ( WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $active_scheme->get_pricing_mode() ) {
					$discount      = min( $discount, Price_Calculator::get_regular_price_for_discount_cap( $product ) );
					$discount_html = '<span class="wcsatt-sub-discount">' . wc_price( $discount ) . '</span>';
				} else {
					/* translators: %s: Discount % (Use encoded value when translating the % character. Use &#37; instead of %.) */
					$discount_html = '<span class="wcsatt-sub-discount">' . sprintf( _x( '%s&#37;', 'option discount', 'woocommerce-subscriptions' ), round( $discount, self::get_formatted_discount_precision() ) ) . '</span>';
				}
				$price_html = sprintf( _x( '%1$s &mdash; save %2$s', 'discounted option price html format', 'woocommerce-subscriptions' ), $price_html, $discount_html );
			}

			// Drop native "from" string.
			$has_variable_price = false;

			if ( false !== strpos( $price_html, $html_from_text_native ) ) {
				$price_html         = str_replace( $html_from_text_native, '', $price_html );
				$has_variable_price = true;
			}

			// Appending price at the end?
			if ( $append_price ) {

				// Add 'from' string.
				if ( $has_variable_price ) {
					$price_html = sprintf( _x( '%1$s from %2$s', 'subscription price string with price at the end', 'woocommerce-subscriptions' ), $details_html, $price_html );
				} else {
					$price_html = sprintf( _x( '%1$s for %2$s', 'subscription price string with price at the end', 'woocommerce-subscriptions' ), $details_html, $price_html );
				}
			} else {

				// Add 'from' string.
				if ( $has_variable_price ) {
					$price_html = sprintf( _x( '%1$s%2$s', 'Price range: from', 'woocommerce-subscriptions' ), 'catalog' === $context ? $html_from_text_native : $html_from_text, $price_html );
				}
			}

			// Subscription state is undefined? Construct a special price string.
		} elseif ( is_null( $scheme_key ) ) {

			$schemes         = WCS_ATT_Product_Schemes::get_subscription_schemes( $product );
			$base_scheme     = WCS_ATT_Product_Schemes::get_base_subscription_scheme( $product );
			$base_scheme_key = $base_scheme->get_key();

			// Whether to suppress sync details in the rendered price string.
			$suppress_sync = false === $include_sync_details && $base_scheme->is_synced();

			if ( $product->is_type( 'variable' ) && $product->get_variation_price( 'min' ) !== $product->get_variation_price( 'max' ) ) {
				$has_variable_price = true;
			} elseif ( $product->is_type( 'bundle' ) && $product->get_bundle_price( 'min' ) !== $product->get_bundle_price( 'max' ) ) {
				$has_variable_price = true;
			} elseif ( $product->is_type( 'composite' ) && $product->get_composite_price( 'min' ) !== $product->get_composite_price( 'max' ) ) {
				$has_variable_price = true;
			} else {
				$has_variable_price = false;
			}

			if ( WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $product ) ) {

				// Get base scheme price string via functional path.
				$base_args     = self::get_base_subscription_scheme_price_html_args( $args, $product, $base_scheme );
				$calc_options  = array( 'tax_display' => isset( $base_args['tax_calculation'] ) ? $base_args['tax_calculation'] : null );
				$price_context = Price_Calculator::calculate_product_price( $product, $calc_options, $base_scheme );

				if ( $suppress_sync ) {
					$price_context->is_synced      = false;
					$price_context->initial_amount = 0.0;
				}

				$price_html = Price_String_Renderer::render( $price_context, $base_args );
				// Drop native "from" string.
				if ( false !== strpos( $price_html, $html_from_text_native ) ) {
					$price_html         = str_replace( $html_from_text_native, '', $price_html );
					$has_variable_price = true;
				}

				// Add "from" string.
				if ( $has_variable_price || count( $schemes ) > 1 ) {

					if ( 'prompt' === $context ) {

						$price_html = sprintf( $subscribe_from_html, '<span class="price subscription-price">' . $price_html . '</span>' );

					} else {

						$add_html_from_text = true;

						if ( $product->is_type( 'variable' ) && count( $schemes ) === 1 ) {
							$add_html_from_text = false;
						}

						if ( $add_html_from_text ) {
							$price_html = sprintf( _x( '%1$s%2$s', 'Price range: from', 'woocommerce-subscriptions' ), 'catalog' === $context ? $html_from_text_native : $html_from_text, $price_html );
						}
					}

					// Merge into "subscribe" string when applicable.
				} elseif ( 'prompt' === $context ) {

					$price_html = sprintf( $subscribe_for_html, '<span class="price subscription-price">' . $price_html . '</span>' );
				}
			} else {

				// Get bare price string before scheme calculation.
				if ( 'catalog' === $context ) {
					$price_html = empty( $args['price'] ) ? self::get_price_html_unfiltered( $product ) : $args['price'];
				}

				$suffix_price_html                = '';
				$allow_discount_price_html_suffix = true;
				$apply_discount_price_html_suffix = false;
				$has_variable_discount            = false;
				$price_filter_exists              = WCS_ATT_Product_Schemes::price_filter_exists( $schemes );
				$base_scheme_discount             = $base_scheme->get_discount();

				if ( ! $price_filter_exists ) {
					$allow_discount_price_html_suffix = false;
				} elseif ( ! $base_scheme_discount ) {
					$allow_discount_price_html_suffix = false;
				} elseif ( in_array( $context, array( 'catalog', 'prompt' ) ) ) {
					if ( count( $schemes ) === 1 ) {
						$allow_discount_price_html_suffix = false;
					} elseif ( isset( $args['allow_discount'] ) && false === $args['allow_discount'] ) {
																		$allow_discount_price_html_suffix = false;
					}
				}

				// Show discount format if all schemes are of a discount pricing mode type ('inherit' or 'fixed_discount').
				if ( $allow_discount_price_html_suffix ) {

					$apply_discount_price_html_suffix = true;
					$found_discount                   = '';
					$base_scheme_pricing_mode         = $base_scheme->get_pricing_mode();

					foreach ( $schemes as $scheme ) {
						if ( $scheme->has_price_filter() ) {

							if ( ! $scheme->is_discount_mode() ) {
								// Non-discount schemes (override or no-price) break the discount suffix format.
								$apply_discount_price_html_suffix = false;
								break;

							} elseif ( $scheme->get_pricing_mode() !== $base_scheme_pricing_mode ) {
								// Mixed discount modes (e.g. inherit + fixed_discount): always variable discount.
								$has_variable_discount = true;

							} elseif ( $found_discount !== $scheme->get_discount() ) {

								if ( '' === $found_discount ) {
									$found_discount = $scheme->get_discount();
								} else {
									$has_variable_discount = true;
								}
							}
						} else {
							$has_variable_discount = true;
						}
					}

					$apply_discount_price_html_suffix = apply_filters( 'wcsatt_price_html_discount_format', $apply_discount_price_html_suffix, $product, $args );
				}

				// Using discount format?
				if ( $apply_discount_price_html_suffix ) {

					// Merge into "subscribe" string when applicable.
					$is_fixed_discount_mode = WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $base_scheme->get_pricing_mode();

					if ( $is_fixed_discount_mode ) {
						// Cap the displayed discount to the product's regular price — a fixed discount cannot exceed the product price.
						$base_scheme_discount = min( $base_scheme_discount, Price_Calculator::get_regular_price_for_discount_cap( $product ) );
					}

					// When schemes have mixed discount modes, find the scheme with the highest dollar savings
					// so that "save up to X" always shows the maximum benefit in dollar terms.
					if ( $has_variable_discount ) {
						$regular_price            = Price_Calculator::get_regular_price_for_discount_cap( $product );
						$max_dollar_savings       = 0.0;
						$max_savings_pricing_mode = '';
						$max_savings_discount     = 0.0;

						foreach ( $schemes as $scheme ) {
							if ( ! $scheme->has_price_filter() || ! $scheme->is_discount_mode() ) {
								continue;
							}
							$scheme_discount = $scheme->get_discount();
							if ( WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $scheme->get_pricing_mode() ) {
								$dollar_savings = min( (float) $scheme_discount, $regular_price );
							} else {
								$dollar_savings = $regular_price * ( (float) $scheme_discount / 100.0 );
							}
							if ( $dollar_savings > $max_dollar_savings ) {
								$max_dollar_savings       = $dollar_savings;
								$max_savings_pricing_mode = $scheme->get_pricing_mode();
								$max_savings_discount     = (float) $scheme_discount;
							}
						}

						if ( $max_dollar_savings > 0.0 ) {
							if ( WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $max_savings_pricing_mode ) {
								$base_scheme_discount   = $max_dollar_savings;
								$is_fixed_discount_mode = true;
							} else {
								// Best savings is from a percentage plan — display as percentage.
								$base_scheme_discount   = $max_savings_discount;
								$is_fixed_discount_mode = false;
							}
						}
					}

					if ( 'prompt' === $context ) {
						if ( $is_fixed_discount_mode ) {
							$discount_html = ' <span class="wcsatt-sub-discount">' . wc_price( $base_scheme_discount ) . '</span>';
						} else {
							/* translators: %s: Discount % (Use encoded value when translating the % character. Use &#37; instead of %.) */
							$discount_html = ' <span class="wcsatt-sub-discount">' . sprintf( _x( '%s&#37;', 'subscribe to save discount', 'woocommerce-subscriptions' ), round( $base_scheme_discount, self::get_formatted_discount_precision() ) ) . '</span>';
						}
						$price_html = sprintf( $subscribe_discounted_html, $has_variable_discount ? __( 'up to', 'woocommerce-subscriptions' ) : '', $discount_html );

					} else {
						if ( $is_fixed_discount_mode ) {
							$discount_html = '</small> <span class="wcsatt-sub-discount">' . wc_price( $base_scheme_discount ) . '</span><small>';
						} else {
							/* translators: %s: Discount % (Use encoded value when translating the % character. Use &#37; instead of %.) */
							$discount_html = '</small> <span class="wcsatt-sub-discount">' . sprintf( _x( '%s&#37;', 'subscribe to save discount', 'woocommerce-subscriptions' ), round( $base_scheme_discount, self::get_formatted_discount_precision() ) ) . '</span><small>';
						}
						/* translators: %1$s: "up to" text or empty string, %2$s: Discount amount with HTML (percentage or currency depending on pricing mode) */
						$suffix_price_html = sprintf( __( 'subscribe to save %1$s%2$s', 'woocommerce-subscriptions' ), $has_variable_discount ? __( 'up to', 'woocommerce-subscriptions' ) : '', $discount_html );
						/* translators: %s: subscribe to save suffix */
						$suffix = '<small class="wcsatt-sub-options">' . sprintf( _x( ' <span class="wcsatt-dash">&mdash;</span> or %s', 'subscribe to save suffix format', 'woocommerce-subscriptions' ), $suffix_price_html ) . '</small>';
					}
				} else {

					// Get base scheme price string via functional path.
					$base_args     = self::get_base_subscription_scheme_price_html_args( $args, $product, $base_scheme );
					$calc_options  = array( 'tax_display' => isset( $base_args['tax_calculation'] ) ? $base_args['tax_calculation'] : null );
					$price_context = Price_Calculator::calculate_product_price( $product, $calc_options, $base_scheme );

					if ( $suppress_sync ) {
						$price_context->is_synced      = false;
						$price_context->initial_amount = 0.0;
					}

					$base_scheme_price_html = Price_String_Renderer::render( $price_context, $base_args );

					if ( 'prompt' === $context ) {

						// Merge into "subscribe" string when applicable.

						$base_scheme_price_html = str_replace( $html_from_text_native, '', $base_scheme_price_html );

						if ( $price_filter_exists ) {

							if ( count( $schemes ) > 1 ) {
								$price_html = sprintf( $subscribe_from_html, '<span class="price subscription-price">' . $base_scheme_price_html . '</span>' );
							} else {
								$price_html = sprintf( $subscribe_for_html, '<span class="price subscription-price">' . $base_scheme_price_html . '</span>' );
							}
						} elseif ( count( $schemes ) > 1 ) {

								$price_html = sprintf( $subscribe_options_html, '<span class="no-price subscription-price">' . $base_scheme_price_html . '</span>' );
						} else {
												$price_html = sprintf( $subscribe_for_html, '<span class="price subscription-price">' . $base_scheme_price_html . '</span>' );
						}
					} else {

						if ( count( $schemes ) > 1 ) {
							$suffix_price_html = sprintf( _x( '%1$s%2$s', 'Price range: starting at', 'woocommerce-subscriptions' ), _x( '<span class="from">from</span> ', 'subscriptions "starting at" price string', 'woocommerce-subscriptions' ), str_replace( $html_from_text_native, '', $base_scheme_price_html ) );
						} elseif ( $has_variable_price ) {
							$suffix_price_html = sprintf( _x( '%1$s%2$s', 'Price range: from', 'woocommerce-subscriptions' ), _x( '<span class="from">from</span> ', 'subscription "from" price string', 'woocommerce-subscriptions' ), str_replace( $html_from_text_native, '', $base_scheme_price_html ) );
						} else {
							$suffix_price_html = $base_scheme_price_html;
						}

						if ( $price_filter_exists ) {
							$suffix = '<small class="wcsatt-sub-options">' . sprintf( _n( ' <span class="wcsatt-dash">&mdash;</span> or %s', ' <span class="wcsatt-dash">&mdash;</span> available on subscription %s', count( $schemes ), 'woocommerce-subscriptions' ), $suffix_price_html ) . '</small>';
						} else {
							$suffix = '<small class="wcsatt-sub-options">' . sprintf( _n( ' <span class="wcsatt-dash">&mdash;</span> available on subscription', ' <span class="wcsatt-dash">&mdash;</span> available on subscription', count( $schemes ), 'woocommerce-subscriptions' ), $suffix_price_html ) . '</small>';
						}
					}
				}

				if ( 'prompt' !== $context ) {

					/**
					'wcsatt_price_html_suffix' filter

					@since  APFS 3.0.0

					@param  string      $suffix
					@param  WC_Product  $product
					@param  array       $args
*/
					$suffix     = apply_filters( 'wcsatt_price_html_suffix', $suffix, $product, $args );
					$price_html = sprintf( _x( '%1$s%2$s', 'product sub options price html suffix', 'woocommerce-subscriptions' ), $price_html, $suffix );
				}
			}
		} elseif ( false === $scheme_key ) {
			$price_html = empty( $args['price'] ) ? self::get_price_html_unfiltered( $product ) : $args['price'];
		}

		return $price_html;
	}

	/**
	 * Base subscription scheme price html args.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  array      $args
	 * @param  WC_Product $product
	 * @return array
	 */
	protected static function get_base_subscription_scheme_price_html_args( $args, $product, $scheme = null ) {

		// Base price already defined?
		if ( ! empty( $args['base_price'] ) ) {
			$args['price'] = $args['base_price'];
		} elseif ( $scheme ) {
			$args['price'] = self::get_scheme_price_html_for_product( $product, $scheme );
		} else {
			$args['price'] = self::get_price_html_unfiltered( $product );
		}

		return apply_filters( 'wcsatt_undefined_scheme_price_html_args', $args, $product );
	}

	/**
	 * Returns the product's current effective price as HTML, without subscription plan suffix.
	 *
	 * Used as the 'price' argument passed to WC_Subscriptions_Product::get_price_string() when building
	 * the subscription suffix string (e.g. "available on subscription from ¥16 / year").
	 *
	 * @param  WC_Product $product  Product object.
	 * @return string
	 */
	public static function get_price_html_unfiltered( $product ) {

		WCS_ATT_Product_Price_Filters::remove( 'price_html' );
		$price_html = $product->get_price_html();
		WCS_ATT_Product_Price_Filters::add( 'price_html' );

		return $price_html;
	}

	/**
	 * Generate price HTML for a product under a specific scheme without switching.
	 *
	 * When a scheme applies a price filter (discount or override), generates
	 * strikethrough HTML (e.g., <del>$10</del><ins>$9</ins>). Otherwise returns
	 * the product's unfiltered price HTML.
	 *
	 * @param  WC_Product     $product  The product.
	 * @param  WCS_ATT_Scheme $scheme   The scheme.
	 * @return string Formatted price HTML.
	 */
	private static function get_scheme_price_html_for_product( $product, $scheme ) {

		if ( ! $scheme->has_price_filter() || ! WCS_ATT_Product_Price_Filters::filter_plan_prices( $product ) ) {
			return self::get_price_html_unfiltered( $product );
		}

		$raw_prices = array(
			'price'         => $product->get_price( 'edit' ),
			'regular_price' => $product->get_regular_price( 'edit' ),
			'sale_price'    => $product->get_sale_price( 'edit' ),
		);

		$resolved      = $scheme->get_prices( $raw_prices );
		$regular_price = $resolved['regular_price'];
		$price         = $resolved['price'];

		if ( '' === $price || '' === $regular_price ) {
			return self::get_price_html_unfiltered( $product );
		}

		$regular_price = (float) $regular_price;
		$price         = (float) $price;

		// Apply tax display.
		$tax_display = get_option( 'woocommerce_tax_display_shop' );

		if ( 'incl' === $tax_display ) {
			$regular_display = wcs_get_price_including_tax( $product, array( 'price' => $regular_price ) );
			$price_display   = wcs_get_price_including_tax( $product, array( 'price' => $price ) );
		} else {
			$regular_display = wcs_get_price_excluding_tax( $product, array( 'price' => $regular_price ) );
			$price_display   = wcs_get_price_excluding_tax( $product, array( 'price' => $price ) );
		}

		if ( $price_display < $regular_display && $price_display >= 0 ) {
			return wc_format_sale_price( $regular_display, $price_display ) . $product->get_price_suffix( $price );
		}

		return wc_price( $price_display ) . $product->get_price_suffix( $price );
	}

	/**
	 * Returns the recurring vanilla/regular/sale price.
	 *
	 * @param  WC_Product $product     Product object.
	 * @param  string     $scheme_key  Optional key to get the price of a specific scheme.
	 * @param  string     $context     Function call context.
	 * @param  string     $price_type  Price to get. Values: '', 'regular', or 'sale'.
	 * @return mixed                    The price charged charged per subscription period.
	 */
	protected static function get_product_price( $product, $scheme_key = '', $context = 'view', $price_type = '' ) {

		$price_type = $price_type && in_array( $price_type, array( 'regular', 'sale' ) ) ? $price_type : '';
		$price_prop = $price_type ? $price_type . '_price' : 'price';
		$price_fn   = 'get_' . $price_prop;

		// In 'view' context, resolve the price for the requested scheme without switching.
		if ( 'view' === $context ) {

			$active_scheme_key = WCS_ATT_Product_Schemes::get_subscription_scheme( $product );
			$scheme_key        = '' === $scheme_key ? $active_scheme_key : $scheme_key;

			if ( $scheme_key === $active_scheme_key ) {
				// Active scheme: product price filters already handle transformation.
				$price = $product->$price_fn();
			} elseif ( false === $scheme_key ) {
				// OTP: get price without APFS filters.
				WCS_ATT_Product_Price_Filters::remove( 'price' );
				$price = $product->$price_fn();
				WCS_ATT_Product_Price_Filters::add( 'price' );
			} else {
				// Different scheme: resolve through scheme object directly.
				$scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $scheme_key );

				if ( $scheme && $scheme->has_price_filter() ) {
					$raw_prices = array(
						'price'         => $product->get_price( 'edit' ),
						'regular_price' => $product->get_regular_price( 'edit' ),
						'sale_price'    => $product->get_sale_price( 'edit' ),
						'offset_price'  => WCS_ATT_Product::get_runtime_meta( $product, 'price_offset' ),
					);

					$resolved = $scheme->get_prices( $raw_prices );
					$price    = $resolved[ $price_prop ];
				} else {
					$price = $product->$price_fn( 'edit' );
				}
			}

			// In 'edit' context, just grab the raw price from the product prop, applying overrides if present.
		} else {

			$subscription_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $product, 'object', $scheme_key );
			$price               = $product->$price_fn( 'edit' );

			if ( ! empty( $subscription_scheme ) ) {

				if ( $subscription_scheme->has_price_filter() && ( WCS_ATT_Scheme::MODE_OVERRIDE === $subscription_scheme->get_pricing_mode() || $subscription_scheme->is_discount_mode() ) && WCS_ATT_Product_Price_Filters::filter_plan_prices( $product ) ) {

					$prices_array = array(
						'price'         => 'price' === $price_prop ? $price : $product->get_price( 'edit' ),
						'sale_price'    => 'sale_price' === $price_prop ? $price : $product->get_sale_price( 'edit' ),
						'regular_price' => 'regular_price' === $price_prop ? $price : $product->get_regular_price( 'edit' ),
						'offset_price'  => WCS_ATT_Product::get_runtime_meta( $product, 'price_offset' ), // See 'WCS_ATT_Integration_PAO::backup_addon_price'.
					);

					$overridden_prices = $subscription_scheme->get_prices( $prices_array );
					$price             = $overridden_prices[ $price_prop ];
				}
			}

			if ( '' === $price && 'sale_price' !== $price_prop && $product->is_type( array( 'bundle', 'composite' ) ) && $product->contains( 'priced_individually' ) ) {
				$price = (float) $price;
			}
		}

		return $price;
	}

	/**
	 * Returns the recurring price.
	 *
	 * @param  WC_Product $product     Product object.
	 * @param  string     $scheme_key  Optional key to get the price of a specific scheme.
	 * @param  string     $context     Function call context.
	 * @return mixed                    The price charged per subscription period.
	 */
	public static function get_price( $product, $scheme_key = '', $context = 'view' ) {
		return self::get_product_price( $product, $scheme_key, $context );
	}

	/**
	 * Returns the recurring regular price.
	 *
	 * @param  WC_Product $product     Product object.
	 * @param  string     $scheme_key  Optional key to get the regular price of a specific scheme.
	 * @param  string     $context     Function call context.
	 * @return mixed                    The regular price charged per subscription period.
	 */
	public static function get_regular_price( $product, $scheme_key = '', $context = 'view' ) {
		return self::get_product_price( $product, $scheme_key, $context, 'regular' );
	}

	/**
	 * Returns the recurring sale price.
	 *
	 * @param  WC_Product $product     Product object.
	 * @param  string     $scheme_key  Optional key to get the price of a specific scheme.
	 * @param  string     $context     Function call context.
	 * @return mixed                    The sale price charged per subscription period.
	 */
	public static function get_sale_price( $product, $scheme_key = '', $context = 'view' ) {
		return self::get_product_price( $product, $scheme_key, $context, 'sale' );
	}

	/**
	 * Generated formatted discount string. Used in dropdowns.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @return string|false
	 */
	public static function get_formatted_discount( $product, $scheme ) {

		$formatted_discount = '';

		if ( ! $scheme->has_price_filter() ) {
			return $formatted_discount;
		}

		if ( $discount = $scheme->get_discount() ) {

			if ( WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $scheme->get_pricing_mode() ) {
				$discount           = min( $discount, Price_Calculator::get_regular_price_for_discount_cap( $product ) );
				$formatted_discount = wp_strip_all_tags( wc_price( $discount ) );
			} else {
				$formatted_discount = sprintf( _x( '%s%%', 'dropdown option discount', 'woocommerce-subscriptions' ), round( $discount, self::get_formatted_discount_precision() ) );
			}
		} else {

			$price         = self::get_price( $product, $scheme->get_key() );
			$regular_price = self::get_regular_price( $product, $scheme->get_key() );

			if ( $regular_price > $price ) {
				$formatted_discount = sprintf( _x( '%s%%', 'dropdown option discount', 'woocommerce-subscriptions' ), round( 100 * ( $regular_price - $price ) / $regular_price, self::get_formatted_discount_precision() ) );
			}
		}

		return $formatted_discount;
	}

	/**
	 * Precision for discounts displayed in price strings.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @return int
	 */
	public static function get_formatted_discount_precision() {
		return apply_filters( 'wcsatt_formatted_discount_precision', 1 );
	}

	/**
	 * Format prices without html content. Used in dropdowns.
	 *
	 * @since  APFS 3.0.0
	 *
	 * @param  mixed $price
	 * @param  array $args
	 * @return string
	 */
	public static function get_formatted_price( $price ) {

		$original_price  = $price;
		$num_decimals    = wc_get_price_decimals();
		$decimal_sep     = wc_get_price_decimal_separator();
		$thousands_sep   = wc_get_price_thousand_separator();
		$currency_symbol = get_woocommerce_currency_symbol();
		$price_format    = get_woocommerce_price_format();

		$price = apply_filters( 'raw_woocommerce_price', floatval( $price ), $original_price );
		$price = apply_filters( 'formatted_woocommerce_price', number_format( $price, $num_decimals, $decimal_sep, $thousands_sep ), $price, $num_decimals, $decimal_sep, $thousands_sep, $original_price );

		if ( apply_filters( 'woocommerce_price_trim_zeros', false ) && $num_decimals > 0 ) {
			$price = wc_trim_zeros( $price );
		}

		return sprintf( $price_format, $currency_symbol, $price );
	}
}
