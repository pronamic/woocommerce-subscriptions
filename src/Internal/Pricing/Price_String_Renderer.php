<?php
/**
 * Renders subscription price strings from Price_Context data.
 *
 * Handles all formatting branches: standard period, sync dates
 * (week/month/year), upfront payment prefix, length suffix,
 * trial period, sign-up fee.
 *
 * @package WooCommerce Subscriptions
 */

namespace Automattic\WooCommerce_Subscriptions\Internal\Pricing;

use WC_Subscriptions_Synchroniser;

/**
 * Stateless renderer for subscription price strings.
 *
 * @internal This class may be modified, moved or removed in future releases.
 * @since 8.5.0
 */
class Price_String_Renderer {

	/**
	 * Render subscription price string from a calculated Price_Context.
	 *
	 * Supported $render_options:
	 *   'tax_calculation'     (string|false) Tax display mode. When false, recurring
	 *                                        price is formatted with wc_price(). When
	 *                                        truthy, numeric value is used as-is.
	 *   'subscription_price'  (bool)         Show the price amount. Default: true.
	 *   'subscription_period' (bool)         Show the billing period. Default: true.
	 *   'subscription_length' (bool)         Show the subscription length. Default: true.
	 *   'sign_up_fee'         (bool)         Show the sign-up fee. Default: true.
	 *   'trial_length'        (bool)         Show the trial period. Default: true.
	 *   'price'               (string|null)  Pre-formatted price HTML override.
	 *                                        Bypasses default price formatting.
	 *                                        Default: not set.
	 *
	 * @since 8.5.0
	 *
	 * @param Price_Context $price_context  Calculated context from Price_Calculator.
	 * @param array         $render_options Rendering options (see above).
	 * @return string Subscription price HTML string.
	 */
	public static function render( Price_Context $price_context, $render_options = array() ) {
		global $wp_locale;

		$render_options = wp_parse_args(
			$render_options,
			array(
				'tax_calculation'     => get_option( 'woocommerce_tax_display_shop' ),
				'subscription_price'  => true,
				'subscription_period' => true,
				'subscription_length' => true,
				'sign_up_fee'         => true,
				'trial_length'        => true,
			)
		);

		// Resolve the formatted price string.
		$price = self::resolve_price( $price_context, $render_options );

		// Resolve the formatted sign-up fee.
		$sign_up_fee = self::resolve_sign_up_fee( $price_context, $render_options );

		$billing_interval    = $price_context->billing_interval;
		$billing_period      = $price_context->billing_period;
		$subscription_length = $price_context->subscription_length;
		$trial_length        = $price_context->trial_length;
		$trial_period        = $price_context->trial_period;

		$include_length = $render_options['subscription_length'] && 0 !== $subscription_length;

		if ( $include_length ) {
			$ranges = wcs_get_subscription_ranges( $billing_period );
		}

		// Build the opening: price + subscription-details span.
		$price .= ' <span class="subscription-details">';

		$subscription_string = '';

		if ( $render_options['subscription_price'] && $render_options['subscription_period'] ) {
			if ( $include_length && $subscription_length === $billing_interval ) {
				// Only for one billing period: show "$5 for 3 months" instead of "$5 every 3 months for 3 months".
				$subscription_string = $price;
			} elseif ( $price_context->is_synced && in_array( $billing_period, array( 'week', 'month', 'year' ), true ) ) {
				$subscription_string = self::render_synced( $price, $price_context, $wp_locale );
			} else {
				$subscription_string = sprintf(
					// translators: 1$: recurring amount, 2$: subscription period (e.g. "month" or "3 months") (e.g. "$15 / month" or "$15 every 2nd month").
					_n( '%1$s / %2$s', '%1$s every %2$s', $billing_interval, 'woocommerce-subscriptions' ),
					$price,
					wcs_get_subscription_period_strings( $billing_interval, $billing_period )
				);
			}
		} elseif ( $render_options['subscription_price'] ) {
			$subscription_string = $price;
		} elseif ( $render_options['subscription_period'] ) {
			$subscription_string = '<span class="subscription-details">' . sprintf(
				// translators: billing period (e.g. "every week").
				__( 'every %s', 'woocommerce-subscriptions' ),
				wcs_get_subscription_period_strings( $billing_interval, $billing_period )
			);
		} else {
			$subscription_string = '<span class="subscription-details">';
		}

		// Add the length to the end.
		if ( $include_length ) {
			// translators: 1$: subscription string, 2$: length (e.g. "4 years").
			$subscription_string = sprintf( __( '%1$s for %2$s', 'woocommerce-subscriptions' ), $subscription_string, $ranges[ $subscription_length ] );
		}

		if ( $render_options['trial_length'] && 0 !== $trial_length ) {
			$trial_string = wcs_get_subscription_trial_period_strings( $trial_length, $trial_period );
			// translators: 1$: subscription string, 2$: trial length (e.g. "with 4 months free trial").
			$subscription_string = sprintf( __( '%1$s with %2$s free trial', 'woocommerce-subscriptions' ), $subscription_string, $trial_string );
		}

		if ( $render_options['sign_up_fee'] && $price_context->base_sign_up_fee > 0 ) {
			// translators: 1$: subscription string, 2$: signup fee price (e.g. "and a $30 sign-up fee").
			$subscription_string = sprintf( __( '%1$s and a %2$s sign-up fee', 'woocommerce-subscriptions' ), $subscription_string, $sign_up_fee );
		}

		$subscription_string .= '</span>';

		return $subscription_string;
	}

	/**
	 * Resolve the formatted price value for rendering.
	 *
	 * @param Price_Context $price_context  The price context.
	 * @param array         $render_options Render options.
	 * @return string|float Price value for use in sprintf patterns.
	 */
	private static function resolve_price( Price_Context $price_context, array $render_options ) {
		if ( isset( $render_options['price'] ) ) {
			return $render_options['price'];
		}

		if ( ! $render_options['tax_calculation'] ) {
			return wc_price( $price_context->base_recurring_price );
		}

		return $price_context->recurring_price;
	}

	/**
	 * Resolve the formatted sign-up fee for rendering.
	 *
	 * @param Price_Context $price_context  The price context.
	 * @param array         $render_options Render options.
	 * @return string Formatted sign-up fee HTML, or '0'.
	 */
	private static function resolve_sign_up_fee( Price_Context $price_context, array $render_options ) {
		if ( ! $render_options['sign_up_fee'] ) {
			return 0;
		}

		// Numeric override from legacy $include['sign_up_fee'].
		if ( is_numeric( $render_options['sign_up_fee'] ) && ! is_bool( $render_options['sign_up_fee'] ) ) {
			return wc_price( $render_options['sign_up_fee'] );
		}

		$sign_up_fee = $price_context->sign_up_fee;

		if ( is_numeric( $sign_up_fee ) ) {
			return wc_price( $sign_up_fee );
		}

		return $sign_up_fee;
	}

	/**
	 * Render the synced subscription string for week/month/year periods.
	 *
	 * @param string|float  $price         Formatted price value.
	 * @param Price_Context $price_context The price context with sync data.
	 * @param object        $wp_locale     WordPress locale for month names.
	 * @return string Synced subscription string.
	 */
	private static function render_synced( $price, Price_Context $price_context, $wp_locale ) {
		$subscription_string = '';
		$billing_interval    = $price_context->billing_interval;
		$billing_period      = $price_context->billing_period;
		$payment_day         = $price_context->payment_day;

		// Upfront payment prefix.
		if ( $price_context->initial_amount > 0 ) {
			/* translators: %1$s refers to the price. This string is meant to prefix another string below, e.g. "$5 now, and $5 on March 15th each year" */
			$subscription_string = sprintf( __( '%1$s now, and ', 'woocommerce-subscriptions' ), $price );
		}

		switch ( $billing_period ) {
			case 'week':
				$payment_day_of_week = WC_Subscriptions_Synchroniser::get_weekday( $payment_day );
				if ( 1 === $billing_interval ) {
					// translators: 1$: recurring amount string, 2$: day of the week (e.g. "$10 every Wednesday").
					$subscription_string .= sprintf( __( '%1$s every %2$s', 'woocommerce-subscriptions' ), $price, $payment_day_of_week );
				} else {
					$subscription_string .= sprintf(
						// translators: 1$: recurring amount string, 2$: period, 3$: day of the week (e.g. "$10 every 2nd week on Wednesday").
						__( '%1$s every %2$s on %3$s', 'woocommerce-subscriptions' ),
						$price,
						wcs_get_subscription_period_strings( $billing_interval, $billing_period ),
						$payment_day_of_week
					);
				}
				break;

			case 'month':
				$payment_day = (int) $payment_day;
				if ( 1 === $billing_interval ) {
					if ( $payment_day > 27 ) {
						// translators: placeholder is recurring amount.
						$subscription_string .= sprintf( __( '%s on the last day of each month', 'woocommerce-subscriptions' ), $price );
					} else {
						$subscription_string .= sprintf(
							// translators: 1$: recurring amount, 2$: day of the month (e.g. "23rd") (e.g. "$5 every 23rd of each month").
							__( '%1$s on the %2$s of each month', 'woocommerce-subscriptions' ),
							$price,
							wcs_append_numeral_suffix( $payment_day )
						);
					}
				} elseif ( $payment_day > 27 ) {
					$subscription_string .= sprintf(
						// translators: 1$: recurring amount, 2$: interval (e.g. "3rd") (e.g. "$10 on the last day of every 3rd month").
						__( '%1$s on the last day of every %2$s month', 'woocommerce-subscriptions' ),
						$price,
						wcs_append_numeral_suffix( $billing_interval )
					);
				} else {
					$subscription_string .= sprintf(
						// translators: 1$: <price> on the, 2$: <date> day of every, 3$: <interval> month (e.g. "$10 on the 23rd day of every 2nd month").
						__( '%1$s on the %2$s day of every %3$s month', 'woocommerce-subscriptions' ),
						$price,
						wcs_append_numeral_suffix( $payment_day ),
						wcs_append_numeral_suffix( $billing_interval )
					);
				}
				break;

			case 'year':
				if ( 1 === $billing_interval ) {
					$subscription_string .= sprintf(
						// translators: 1$: <price> on, 2$: <date>, 3$: <month> each year (e.g. "$15 on March 15th each year").
						__( '%1$s on %2$s %3$s each year', 'woocommerce-subscriptions' ),
						$price,
						$wp_locale->month[ $payment_day['month'] ],
						wcs_append_numeral_suffix( $payment_day['day'] )
					);
				} else {
					$subscription_string .= sprintf(
						// translators: 1$: recurring amount, 2$: month (e.g. "March"), 3$: day of the month (e.g. "23rd").
						__( '%1$s on %2$s %3$s every %4$s year', 'woocommerce-subscriptions' ),
						$price,
						$wp_locale->month[ $payment_day['month'] ],
						wcs_append_numeral_suffix( $payment_day['day'] ),
						wcs_append_numeral_suffix( $billing_interval )
					);
				}
				break;
		}

		return $subscription_string;
	}
}
