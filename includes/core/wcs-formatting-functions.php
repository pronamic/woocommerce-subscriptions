<?php
/**
 * WooCommerce Subscriptions Formatting
 *
 * Functions for formatting subscription data.
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Creates a subscription price string from an array of subscription details. For example, "$5 / month for 12 months".
 *
 * @param array $subscription_details A set of name => value pairs for the subscription details to include in the string. Available keys:
 *    'initial_amount': The upfront payment for the subscription, including sign up fees, as a string from the @see wc_price(). Default empty string (no initial payment)
 *    'initial_description': The word after the initial payment amount to describe the amount. Examples include "now" or "initial payment". Defaults to "up front".
 *    'recurring_amount': The amount charged per period. Default 0 (no recurring payment).
 *    'subscription_interval': How regularly the subscription payments are charged. Default 1, meaning each period e.g. per month.
 *    'subscription_period': The temporal period of the subscription. Should be one of {day|week|month|year} as used by @see wcs_get_subscription_period_strings()
 *    'subscription_length': The total number of periods the subscription should continue for. Default 0, meaning continue indefinitely.
 *    'trial_length': The total number of periods the subscription trial period should continue for.  Default 0, meaning no trial period.
 *    'trial_period': The temporal period for the subscription's trial period. Should be one of {day|week|month|year} as used by @see wcs_get_subscription_period_strings()
 *    'use_per_slash': Allow calling code to determine if they want the shorter price string using a slash for singular billing intervals, e.g. $5 / month, or the longer form, e.g. $5 every month, which is normally reserved for intervals > 1
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 * @return string The price string with translated and billing periods included
 */
function wcs_price_string( $subscription_details ) {
	global $wp_locale;

	$subscription_details = wp_parse_args(
		$subscription_details,
		array(
			'currency'                    => '',
			'initial_amount'              => '',
			'initial_description'         => _x( 'up front', 'initial payment on a subscription', 'woocommerce-subscriptions' ),
			'recurring_amount'            => '',

			// Schedule details
			'subscription_interval'       => 1,
			'subscription_period'         => '',
			'subscription_length'         => 0,
			'trial_length'                => 0,
			'trial_period'                => '',

			// Syncing details
			'is_synced'                   => false,
			'synchronised_payment_day'    => 0,

			// Params for wc_price()
			'display_excluding_tax_label' => false,

			// Params for formatting customisation
			'use_per_slash'               => true,
		)
	);

	$subscription_details['subscription_period'] = strtolower( $subscription_details['subscription_period'] );

	// Make sure prices have been through wc_price()
	if ( is_numeric( $subscription_details['initial_amount'] ) ) {
		$initial_amount_string = wc_price(
			$subscription_details['initial_amount'],
			array(
				'currency'     => $subscription_details['currency'],
				'ex_tax_label' => $subscription_details['display_excluding_tax_label'],
			)
		);
	} else {
		$initial_amount_string = $subscription_details['initial_amount'];
	}

	if ( is_numeric( $subscription_details['recurring_amount'] ) ) {
		$recurring_amount_string = wc_price(
			$subscription_details['recurring_amount'],
			array(
				'currency'     => $subscription_details['currency'],
				'ex_tax_label' => $subscription_details['display_excluding_tax_label'],
			)
		);
	} else {
		$recurring_amount_string = $subscription_details['recurring_amount'];
	}

	$subscription_period_string = wcs_get_subscription_period_strings( $subscription_details['subscription_interval'], $subscription_details['subscription_period'] );
	$subscription_ranges = wcs_get_subscription_ranges();

	if ( $subscription_details['subscription_length'] > 0 && $subscription_details['subscription_length'] == $subscription_details['subscription_interval'] ) {
		if ( ! empty( $subscription_details['initial_amount'] ) ) {
			if ( $subscription_details['subscription_interval'] == $subscription_details['subscription_length'] && 0 == $subscription_details['trial_length'] ) {
				$subscription_string = $initial_amount_string;
			} else {
				// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount string (e.g. "£10 / month" )
				$subscription_string = sprintf( __( '%1$s %2$s then %3$s', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string );
			}
		} else {
			$subscription_string = $recurring_amount_string;
		}
	} elseif ( true === $subscription_details['is_synced'] && in_array( $subscription_details['subscription_period'], array( 'week', 'month', 'year' ) ) ) {
		// Verbosity is important here to enable translation
		$payment_day = $subscription_details['synchronised_payment_day'];
		switch ( $subscription_details['subscription_period'] ) {
			case 'week':
				$payment_day_of_week = WC_Subscriptions_Synchroniser::get_weekday( $payment_day );
				if ( 1 == $subscription_details['subscription_interval'] ) {
					if ( ! empty( $subscription_details['initial_amount'] ) ) {
						// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount string, 4$: payment day of the week (e.g. "$15 up front, then $10 every Wednesday")
						$subscription_string = sprintf( __( '%1$s %2$s then %3$s every %4$s', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, $payment_day_of_week );
					} else {
						// translators: 1$: recurring amount string, 2$: day of the week (e.g. "$10 every Wednesday")
						$subscription_string = sprintf( __( '%1$s every %2$s', 'woocommerce-subscriptions' ), $recurring_amount_string, $payment_day_of_week );
					}
				} else {
					// e.g. $5 every 2 weeks on Wednesday
					if ( ! empty( $subscription_details['initial_amount'] ) ) {
						// translators: 1$: initial amount, 2$: initial description (e.g. "up front" ), 3$: recurring amount, 4$: interval (e.g. "2nd week"), 5$: day of the week (e.g. "Thursday"); (e.g. "$10 up front, then $20 every 2nd week on Wednesday")
						$subscription_string = sprintf( __( '%1$s %2$s then %3$s every %4$s on %5$s', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, wcs_get_subscription_period_strings( $subscription_details['subscription_interval'], $subscription_details['subscription_period'] ), $payment_day_of_week );
					} else {
						// translators: 1$: recurring amount string, 2$: period, 3$: day of the week (e.g. "$10 every 2nd week on Wednesday")
						$subscription_string = sprintf( __( '%1$s every %2$s on %3$s', 'woocommerce-subscriptions' ), $recurring_amount_string, wcs_get_subscription_period_strings( $subscription_details['subscription_interval'], $subscription_details['subscription_period'] ), $payment_day_of_week );
					}
				}
				break;
			case 'month':
				if ( 1 == $subscription_details['subscription_interval'] ) {
					// e.g. $15 on the 15th of each month
					if ( ! empty( $subscription_details['initial_amount'] ) ) {
						if ( $payment_day > 27 ) {
							// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount; (e.g. "$10 up front then $30 on the last day of each month")
							$subscription_string = sprintf( __( '%1$s %2$s then %3$s on the last day of each month', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string );
						} else {
							// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount, 4$: day of the month (e.g. "23rd"); (e.g. "$10 up front then $40 on the 23rd of each month")
							$subscription_string = sprintf( __( '%1$s %2$s then %3$s on the %4$s of each month', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, wcs_append_numeral_suffix( $payment_day ) );
						}
					} else {
						if ( $payment_day > 27 ) {
							// translators: placeholder is recurring amount
							$subscription_string = sprintf( __( '%s on the last day of each month', 'woocommerce-subscriptions' ), $recurring_amount_string );
						} else {
							// translators: 1$: recurring amount, 2$: day of the month (e.g. "23rd") (e.g. "$5 every 23rd of each month")
							$subscription_string = sprintf( __( '%1$s on the %2$s of each month', 'woocommerce-subscriptions' ), $recurring_amount_string, wcs_append_numeral_suffix( $payment_day ) );
						}
					}
				} else {
					// e.g. $15 on the 15th of every 3rd month
					if ( ! empty( $subscription_details['initial_amount'] ) ) {
						if ( $payment_day > 27 ) {
							// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount, 4$: interval (e.g. "3rd")
							$subscription_string = sprintf( __( '%1$s %2$s then %3$s on the last day of every %4$s month', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, wcs_append_numeral_suffix( $subscription_details['subscription_interval'] ) );
						} else {
							// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount, 4$: day of the month (e.g. "23rd"), 5$: interval (e.g. "3rd")
							$subscription_string = sprintf( __( '%1$s %2$s then %3$s on the %4$s day of every %5$s month', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, wcs_append_numeral_suffix( $payment_day ), wcs_append_numeral_suffix( $subscription_details['subscription_interval'] ) );
						}
					} else {
						if ( $payment_day > 27 ) {
							// translators: 1$: recurring amount, 2$: interval (e.g. "3rd") (e.g. "$10 on the last day of every 3rd month")
							$subscription_string = sprintf( __( '%1$s on the last day of every %2$s month', 'woocommerce-subscriptions' ), $recurring_amount_string, wcs_append_numeral_suffix( $subscription_details['subscription_interval'] ) );
						} else {
							// translators: 1$: recurring amount, 2$: day of the month (e.g. "23rd") (e.g. "$5 every 23rd of each month")
							$subscription_string = sprintf( __( '%1$s on the %2$s day of every %3$s month', 'woocommerce-subscriptions' ), $recurring_amount_string, wcs_append_numeral_suffix( $payment_day ), wcs_append_numeral_suffix( $subscription_details['subscription_interval'] ) );
						}
					}
				}
				break;
			case 'year':
				if ( 1 == $subscription_details['subscription_interval'] ) {
					// e.g. $15 on March 15th each year
					if ( ! empty( $subscription_details['initial_amount'] ) ) {
						// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount, 4$: month of year (e.g. "March"), 5$: day of the month (e.g. "23rd")
						$subscription_string = sprintf( __( '%1$s %2$s then %3$s on %4$s %5$s each year', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, $wp_locale->month[ $payment_day['month'] ], wcs_append_numeral_suffix( $payment_day['day'] ) );
					} else {
						// translators: 1$: recurring amount, 2$: month (e.g. "March"), 3$: day of the month (e.g. "23rd") (e.g. "$15 on March 15th every 3rd year")
						$subscription_string = sprintf( __( '%1$s on %2$s %3$s each year', 'woocommerce-subscriptions' ), $recurring_amount_string, $wp_locale->month[ $payment_day['month'] ], wcs_append_numeral_suffix( $payment_day['day'] ) );
					}
				} else {
					// e.g. $15 on March 15th every 3rd year
					if ( ! empty( $subscription_details['initial_amount'] ) ) {
						// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount, 4$: month (e.g. "March"), 5$: day of the month (e.g. "23rd"), 6$: interval (e.g. "3rd")
						$subscription_string = sprintf( __( '%1$s %2$s then %3$s on %4$s %5$s every %6$s year', 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, $wp_locale->month[ $payment_day['month'] ], wcs_append_numeral_suffix( $payment_day['day'] ), wcs_append_numeral_suffix( $subscription_details['subscription_interval'] ) );
					} else {
						// translators: 1$: recurring amount, 2$: month (e.g. "March"), 3$: day of the month (e.g. "23rd") (e.g. "$15 on March 15th every 3rd year")
						$subscription_string = sprintf( __( '%1$s on %2$s %3$s every %4$s year', 'woocommerce-subscriptions' ), $recurring_amount_string, $wp_locale->month[ $payment_day['month'] ], wcs_append_numeral_suffix( $payment_day['day'] ), wcs_append_numeral_suffix( $subscription_details['subscription_interval'] ) );
					}
				}
				break;
		}
	} elseif ( ! empty( $subscription_details['initial_amount'] ) ) {
		// translators: 1$: initial amount, 2$: initial description (e.g. "up front"), 3$: recurring amount, 4$: subscription period (e.g. "month" or "3 months")
		$subscription_string = sprintf( _n( '%1$s %2$s then %3$s / %4$s', '%1$s %2$s then %3$s every %4$s', $subscription_details['subscription_interval'], 'woocommerce-subscriptions' ), $initial_amount_string, $subscription_details['initial_description'], $recurring_amount_string, $subscription_period_string );
	} elseif ( ! empty( $subscription_details['recurring_amount'] ) || intval( $subscription_details['recurring_amount'] ) === 0 ) {
		if ( true === $subscription_details['use_per_slash'] ) {
			// translators: 1$: recurring amount, 2$: subscription period (e.g. "month" or "3 months") (e.g. "$15 / month" or "$15 every 2nd month")
			$subscription_string = sprintf( _n( '%1$s / %2$s', '%1$s every %2$s', $subscription_details['subscription_interval'], 'woocommerce-subscriptions' ), $recurring_amount_string, $subscription_period_string );
		} else {
			// translators: %1$: recurring amount (e.g. "$15"), %2$: subscription period (e.g. "month") (e.g. "$15 every 2nd month")
			$subscription_string = sprintf( __( '%1$s every %2$s', 'woocommerce-subscriptions' ), $recurring_amount_string, $subscription_period_string );
		}
	} else {
		$subscription_string = '';
	}

	if ( $subscription_details['subscription_length'] > 0 ) {
		// translators: 1$: subscription string (e.g. "$10 up front then $5 on March 23rd every 3rd year"), 2$: length (e.g. "4 years")
		$subscription_string = sprintf( __( '%1$s for %2$s', 'woocommerce-subscriptions' ), $subscription_string, $subscription_ranges[ $subscription_details['subscription_period'] ][ $subscription_details['subscription_length'] ] );
	}

	if ( $subscription_details['trial_length'] > 0 ) {
		$trial_length = wcs_get_subscription_trial_period_strings( $subscription_details['trial_length'], $subscription_details['trial_period'] );
		if ( ! empty( $subscription_details['initial_amount'] ) ) {
			// translators: 1$: subscription string (e.g. "$10 up front then $5 on March 23rd every 3rd year"), 2$: trial length (e.g. "3 weeks")
			$subscription_string = sprintf( __( '%1$s after %2$s free trial', 'woocommerce-subscriptions' ), $subscription_string, $trial_length );
		} else {
			// translators: 1$: trial length (e.g. "3 weeks"), 2$: subscription string (e.g. "$10 up front then $5 on March 23rd every 3rd year")
			$subscription_string = sprintf( __( '%1$s free trial then %2$s', 'woocommerce-subscriptions' ), ucfirst( $trial_length ), $subscription_string );
		}
	}

	if ( $subscription_details['display_excluding_tax_label'] && wc_tax_enabled() ) {
		$subscription_string .= ' <small>' . WC()->countries->ex_tax_or_vat() . '</small>';
	}

	return apply_filters( 'woocommerce_subscription_price_string', $subscription_string, $subscription_details );
}

/**
 * Display a human friendly time diff for a given timestamp, e.g. "in 12 hours" or "12 hours ago".
 *
 * @param int $timestamp_gmt
 * @return string A human friendly string to display for the timestamp's date
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
 */
function wcs_get_human_time_diff( $timestamp_gmt ) {

	$time_diff = $timestamp_gmt - current_time( 'timestamp', true );

	if ( $time_diff > 0 && $time_diff < WEEK_IN_SECONDS ) {
		// translators: placeholder is human time diff (e.g. "3 weeks")
		$date_to_display = sprintf( __( 'in %s', 'woocommerce-subscriptions' ), human_time_diff( current_time( 'timestamp', true ), $timestamp_gmt ) );
	} elseif ( $time_diff < 0 && absint( $time_diff ) < WEEK_IN_SECONDS ) {
		// translators: placeholder is human time diff (e.g. "3 weeks")
		$date_to_display = sprintf( __( '%s ago', 'woocommerce-subscriptions' ), human_time_diff( current_time( 'timestamp', true ), $timestamp_gmt ) );
	} else {
		$timestamp_site  = wcs_date_to_time( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp_gmt ) ) );
		$date_to_display = date_i18n( wc_date_format(), $timestamp_site ) . ' ' . date_i18n( wc_time_format(), $timestamp_site );
		// translators: placeholder is a localized date and time (e.g. "February 1, 2018 10:20 PM")
		$date_to_display = sprintf( _x( '%s', 'wcs_get_human_time_diff', 'woocommerce-subscriptions' ), $date_to_display ); // phpcs:ignore WordPress.WP.I18n.NoEmptyStrings
	}

	return $date_to_display;
}

/**
 * Works around the wp_kses() limitation of not accepting attribute names with underscores.
 *
 * @param string $content Content to filter through kses.
 * @param array $allowed_html List of allowed HTML elements.
 * @return string Filtered string of HTML.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.17
 */
function wp_kses_allow_underscores( $content, $allowed_html ) {

	/* Replace the underscore _ with a double hyphen -- in the attribute names */
	foreach ( $allowed_html as $tag => &$attributes ) {
		$attribute_names = array_keys( $attributes );
		$attribute_values = array_values( $attributes );
		$attributes = array_combine( preg_replace( '/_/', '--', $attribute_names ), $attribute_values );
	}

	/* Replace  the underscore _ with a double hyphen -- in the content as well.
	   The assumption is that such an attribute name would be followed by a = */
	$content = preg_replace( '/\b([-A-Za-z]+)_([-A-Za-z]+)=/', '$1--$2=', $content );
	$content = wp_kses( $content, $allowed_html ); // Now pass through wp_kses the attribute name with --
	return preg_replace( '/\b([-A-Za-z]+)--([-A-Za-z]+)=/', '$1_$2=', $content ); // Replace the _ back
}

/**
 * Appends the ordinal suffix to a given number.
 *
 * eg. Given 2, the function returns 2nd.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 *
 * @param string $number The number to append the ordinal suffix to.
 * @return string
 */
function wcs_append_numeral_suffix( $number ) {

	// Handle teens: if the tens digit of a number is 1, then write "th" after the number. For example: 11th, 13th, 19th, 112th, 9311th. http://en.wikipedia.org/wiki/English_numerals
	if ( strlen( $number ) > 1 && 1 == substr( $number, -2, 1 ) ) {
		// translators: placeholder is a number, this is for the teens
		$number_string = sprintf( __( '%sth', 'woocommerce-subscriptions' ), $number );
	} else { // Append relevant suffix
		switch ( substr( $number, -1 ) ) {
			case 1:
				// translators: placeholder is a number, numbers ending in 1
				$number_string = sprintf( __( '%sst', 'woocommerce-subscriptions' ), $number );
				break;
			case 2:
				// translators: placeholder is a number, numbers ending in 2
				$number_string = sprintf( __( '%snd', 'woocommerce-subscriptions' ), $number );
				break;
			case 3:
				// translators: placeholder is a number, numbers ending in 3
				$number_string = sprintf( __( '%srd', 'woocommerce-subscriptions' ), $number );
				break;
			default:
				// translators: placeholder is a number, numbers ending in 4-9, 0
				$number_string = sprintf( __( '%sth', 'woocommerce-subscriptions' ), $number );
				break;
		}
	}

	return apply_filters( 'woocommerce_numeral_suffix', $number_string, $number );
}
