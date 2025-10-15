<?php
/**
 * WooCommerce Subscriptions Temporal Functions
 *
 * Functions for time values and ranges
 *
 * @author   Prospress
 * @category Core
 * @package  WooCommerce Subscriptions/Functions
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


/**
 * Return an i18n'ified associative array of all possible subscription periods.
 *
 * @param int|null $number (optional) An interval in the range 1-6
 * @param string|null $period (optional) One of day, week, month or year. If empty, all subscription ranges are returned.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscription_period_strings( $number = 1, $period = '' ) {

	// phpcs:disable Generic.Functions.FunctionCallArgumentSpacing.TooMuchSpaceAfterComma
	$translated_periods = apply_filters( 'woocommerce_subscription_periods',
		array(
			// translators: placeholder is number of days. (e.g. "Bill this every day / 4 days")
			'day'   => sprintf( _nx( 'day',   '%s days',   $number, 'Subscription billing period.', 'woocommerce-subscriptions' ), $number ), // phpcs:ignore WordPress.WP.I18n.MissingSingularPlaceholder,WordPress.WP.I18n.MismatchedPlaceholders
			// translators: placeholder is number of weeks. (e.g. "Bill this every week / 4 weeks")
			'week'  => sprintf( _nx( 'week',  '%s weeks',  $number, 'Subscription billing period.', 'woocommerce-subscriptions' ), $number ), // phpcs:ignore WordPress.WP.I18n.MissingSingularPlaceholder,WordPress.WP.I18n.MismatchedPlaceholders
			// translators: placeholder is number of months. (e.g. "Bill this every month / 4 months")
			'month' => sprintf( _nx( 'month', '%s months', $number, 'Subscription billing period.', 'woocommerce-subscriptions' ), $number ), // phpcs:ignore WordPress.WP.I18n.MissingSingularPlaceholder,WordPress.WP.I18n.MismatchedPlaceholders
			// translators: placeholder is number of years. (e.g. "Bill this every year / 4 years")
			'year'  => sprintf( _nx( 'year',  '%s years',  $number, 'Subscription billing period.', 'woocommerce-subscriptions' ), $number ), // phpcs:ignore WordPress.WP.I18n.MissingSingularPlaceholder,WordPress.WP.I18n.MismatchedPlaceholders
		),
		$number
	);
	// phpcs:enable

	return ( ! empty( $period ) ) ? $translated_periods[ $period ] : $translated_periods;
}

/**
 * Return an i18n'ified associative array of all possible subscription trial periods.
 *
 * @param int|null $number (optional) An interval in the range 1-6
 * @param string|null $period (optional) One of day, week, month or year. If empty, all subscription ranges are returned.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscription_trial_period_strings( $number = 1, $period = '' ) {

	$translated_periods = apply_filters( 'woocommerce_subscription_trial_periods',
		array(
			// translators: placeholder is a number of days.
			'day'   => sprintf( _n( '%s day', 'a %s-day', $number, 'woocommerce-subscriptions' ), $number ),
			// translators: placeholder is a number of weeks.
			'week'  => sprintf( _n( '%s week', 'a %s-week', $number, 'woocommerce-subscriptions' ), $number ),
			// translators: placeholder is a number of months.
			'month' => sprintf( _n( '%s month', 'a %s-month', $number, 'woocommerce-subscriptions' ), $number ),
			// translators: placeholder is a number of years.
			'year'  => sprintf( _n( '%s year', 'a %s-year', $number, 'woocommerce-subscriptions' ), $number ),
		),
		$number
	);

	return ( ! empty( $period ) ) ? $translated_periods[ $period ] : $translated_periods;
}

/**
 * Returns an array of subscription lengths.
 *
 * PayPal Standard Allowable Ranges
 * D – for days; allowable range is 1 to 90
 * W – for weeks; allowable range is 1 to 52
 * M – for months; allowable range is 1 to 24
 * Y – for years; allowable range is 1 to 5
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1.2
 */
function wcs_get_non_cached_subscription_ranges() {

	foreach ( array( 'day', 'week', 'month', 'year' ) as $period ) {

		$subscription_lengths = array(
			_x( 'Do not stop until cancelled', 'Subscription length', 'woocommerce-subscriptions' ),
		);

		switch ( $period ) {
			case 'day':
				$subscription_lengths[] = _x( '1 day', 'Subscription lengths. e.g. "For 1 day..."', 'woocommerce-subscriptions' );
				$subscription_range = range( 2, 90 );
				break;
			case 'week':
				$subscription_lengths[] = _x( '1 week', 'Subscription lengths. e.g. "For 1 week..."', 'woocommerce-subscriptions' );
				$subscription_range = range( 2, 52 );
				break;
			case 'month':
				$subscription_lengths[] = _x( '1 month', 'Subscription lengths. e.g. "For 1 month..."', 'woocommerce-subscriptions' );
				$subscription_range = range( 2, 24 );
				break;
			case 'year':
				$subscription_lengths[] = _x( '1 year', 'Subscription lengths. e.g. "For 1 year..."', 'woocommerce-subscriptions' );
				$subscription_range = range( 2, 5 );
				break;
		}

		foreach ( $subscription_range as $number ) {
			$subscription_range[ $number ] = wcs_get_subscription_period_strings( $number, $period );
		}

		// Add the possible range to all time range
		$subscription_lengths += $subscription_range;

		$subscription_ranges[ $period ] = $subscription_lengths;
	}

	return $subscription_ranges;
}

/**
 * Retaining the API, it makes use of the transient functionality.
 *
 * @param string $subscription_period
 * @return bool|mixed
 */
function wcs_get_subscription_ranges( $subscription_period = null ) {
	static $subscription_locale_ranges = array();

	if ( ! is_string( $subscription_period ) ) {
		$subscription_period = '';
	}

	$locale = function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();

	if ( ! isset( $subscription_locale_ranges[ $locale ] ) ) {
		$subscription_locale_ranges[ $locale ] = wcs_get_non_cached_subscription_ranges();
	}

	$subscription_ranges = apply_filters( 'woocommerce_subscription_lengths', $subscription_locale_ranges[ $locale ], $subscription_period );

	if ( ! empty( $subscription_period ) ) {
		return $subscription_ranges[ $subscription_period ];
	} else {
		return $subscription_ranges;
	}
}

/**
 * Return an i18n'ified associative array of all possible subscription periods.
 *
 * @param int|null $interval (optional) An interval in the range 1-6
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscription_period_interval_strings( $interval = null ) {

	$intervals = array( 1 => _x( 'every', 'period interval (eg "$10 _every_ 2 weeks")', 'woocommerce-subscriptions' ) );

	foreach ( range( 2, 6 ) as $i ) {
		// translators: period interval, placeholder is ordinal (eg "$10 every _2nd/3rd/4th_", etc)
		$intervals[ $i ] = sprintf( _x( 'every %s', 'period interval with ordinal number (e.g. "every 2nd"', 'woocommerce-subscriptions' ), wcs_append_numeral_suffix( $i ) );
	}

	$intervals = apply_filters( 'woocommerce_subscription_period_interval_strings', $intervals );

	if ( empty( $interval ) ) {
		return $intervals;
	} else {
		return $intervals[ $interval ];
	}
}

/**
 * Return an i18n'ified associative array of all time periods allowed for subscriptions.
 *
 * @param string|null $form (Optional) Either 'singular' for singular trial periods or 'plural'.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_available_time_periods( $form = 'singular' ) {

	$number = ( 'singular' === $form ) ? 1 : 2;

	// phpcs:disable Generic.Functions.FunctionCallArgumentSpacing.TooMuchSpaceAfterComma
	$translated_periods = apply_filters( 'woocommerce_subscription_available_time_periods',
		array(
			'day'   => _nx( 'day',   'days',   $number, 'Used in the trial period dropdown. Number is in text field. 0, 2+ will need plural, 1 will need singular.', 'woocommerce-subscriptions' ),
			'week'  => _nx( 'week',  'weeks',  $number, 'Used in the trial period dropdown. Number is in text field. 0, 2+ will need plural, 1 will need singular.', 'woocommerce-subscriptions' ),
			'month' => _nx( 'month', 'months', $number, 'Used in the trial period dropdown. Number is in text field. 0, 2+ will need plural, 1 will need singular.', 'woocommerce-subscriptions' ),
			'year'  => _nx( 'year',  'years',  $number, 'Used in the trial period dropdown. Number is in text field. 0, 2+ will need plural, 1 will need singular.', 'woocommerce-subscriptions' ),
		)
	);
	// phpcs:enable

	return $translated_periods;
}

/**
 * Returns an array of allowed trial period lengths.
 *
 * @param string|null $subscription_period (optional) One of day, week, month or year. If empty, all subscription trial period lengths are returned.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscription_trial_lengths( $subscription_period = '' ) {

	$all_trial_periods = wcs_get_subscription_ranges();

	foreach ( $all_trial_periods as $period => $trial_periods ) {
		$all_trial_periods[ $period ][0] = _x( 'no', 'no trial period', 'woocommerce-subscriptions' );
	}

	if ( ! empty( $subscription_period ) ) {
		return $all_trial_periods[ $subscription_period ];
	} else {
		return $all_trial_periods;
	}
}

/**
 * Convenience wrapper for adding "{n} {periods}" to a timestamp (e.g. 2 months or 5 days).
 *
 * @param int    $number_of_periods  The number of periods to add to the timestamp
 * @param string $period             One of day, week, month or year.
 * @param int    $from_timestamp     A Unix timestamp to add the time too.
 * @param string $timezone_behaviour Optional. If the $from_timestamp parameter should be offset to the site time or not, either 'offset_site_time' or 'no_offset'. Default 'no_offset'.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_add_time( $number_of_periods, $period, $from_timestamp, $timezone_behaviour = 'no_offset' ) {

	if ( $number_of_periods > 0 ) {
		if ( 'month' == $period ) {
			$next_timestamp = wcs_add_months( $from_timestamp, $number_of_periods, $timezone_behaviour );
		} else {
			$next_timestamp = wcs_strtotime_dark_knight( "+ {$number_of_periods} {$period}", $from_timestamp );
		}
	} else {
		$next_timestamp = $from_timestamp;
	}

	return $next_timestamp;
}

/**
 * Workaround the last day of month quirk in PHP's strtotime function.
 *
 * Adding +1 month to the last day of the month can yield unexpected results with strtotime().
 * For example:
 * - 30 Jan 2013 + 1 month = 3rd March 2013
 * - 28 Feb 2013 + 1 month = 28th March 2013
 *
 * What humans usually want is for the date to continue on the last day of the month.
 *
 * @param int $from_timestamp        A Unix timestamp to add the months too.
 * @param int $months_to_add         The number of months to add to the timestamp.
 * @param string $timezone_behaviour Optional. If the $from_timestamp parameter should be offset to the site time or not, either 'offset_site_time' or 'no_offset'. Default 'no_offset'.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_add_months( $from_timestamp, $months_to_add, $timezone_behaviour = 'no_offset' ) {

	if ( 'offset_site_time' === $timezone_behaviour ) {
		$from_timestamp += wc_timezone_offset();
	}

	$first_day_of_month = gmdate( 'Y-m', $from_timestamp ) . '-1';
	$days_in_next_month = gmdate( 't', wcs_strtotime_dark_knight( "+ {$months_to_add} month", wcs_date_to_time( $first_day_of_month ) ) );
	$next_timestamp = 0;

	// Payment is on the last day of the month OR number of days in next billing month is less than the the day of this month (i.e. current billing date is 30th January, next billing date can't be 30th February)
	if ( gmdate( 'd m Y', $from_timestamp ) === gmdate( 't m Y', $from_timestamp ) || gmdate( 'd', $from_timestamp ) > $days_in_next_month ) {
		for ( $i = 1; $i <= $months_to_add; $i++ ) {
			$next_month = wcs_add_time( 3, 'days', $from_timestamp, $timezone_behaviour ); // Add 3 days to make sure we get to the next month, even when it's the 29th day of a month with 31 days
			$next_timestamp = $from_timestamp = wcs_date_to_time( gmdate( 'Y-m-t H:i:s', $next_month ) ); // NB the "t" to get last day of next month
		}
	} else { // Safe to just add a month
		$next_timestamp = wcs_strtotime_dark_knight( "+ {$months_to_add} month", $from_timestamp );
	}

	if ( 'offset_site_time' === $timezone_behaviour ) {
		$next_timestamp -= wc_timezone_offset();
	}

	return $next_timestamp;
}

/**
 * Estimate how many days, weeks, months or years there are between now and a given
 * date in the future. Estimates the minimum total of periods.
 *
 * @param int $start_timestamp A Unix timestamp
 * @param int $end_timestamp A Unix timestamp at some time in the future
 * @param string $unit_of_time A unit of time, either day, week month or year.
 * @param string $rounding_method A rounding method, either ceil (default) or floor for anything else
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_estimate_periods_between( $start_timestamp, $end_timestamp, $unit_of_time = 'month', $rounding_method = 'ceil' ) {

	if ( $end_timestamp <= $start_timestamp ) {

		$periods_until = 0;

	} elseif ( 'month' == $unit_of_time ) {

		// Calculate the number of times this day will occur until we'll be in a time after the given timestamp
		$timestamp = $start_timestamp;

		if ( 'ceil' == $rounding_method ) {
			for ( $periods_until = 0; $timestamp < $end_timestamp; $periods_until++ ) {
				$timestamp = wcs_add_months( $timestamp, 1 );
			}
		} else {
			for ( $periods_until = -1; $timestamp <= $end_timestamp; $periods_until++ ) {
				$timestamp = wcs_add_months( $timestamp, 1 );
			}
		}
	} else {

		$seconds_until_timestamp = $end_timestamp - $start_timestamp;

		$denominator = 0;

		switch ( $unit_of_time ) {

			case 'day':
				$denominator = DAY_IN_SECONDS;
				break;

			case 'week':
				$denominator = WEEK_IN_SECONDS;
				break;

			case 'year':
				$denominator = YEAR_IN_SECONDS;
				// we need to adjust this because YEAR_IN_SECONDS assumes a 365 day year. See notes on wcs_number_of_leap_days
				$seconds_until_timestamp = $seconds_until_timestamp - wcs_number_of_leap_days( $start_timestamp, $end_timestamp ) * DAY_IN_SECONDS;
				break;
		}

		$periods_until = ( 'ceil' == $rounding_method ) ? ceil( $seconds_until_timestamp / $denominator ) : floor( $seconds_until_timestamp / $denominator );
	}

	return $periods_until;
}

/**
 * Utility function to find out how many leap days are there between two given dates. The reason we need this is because
 * the constant YEAR_IN_SECONDS assumes a 365 year, which means some of the calculations are going to be off by a day.
 * This has caused problems where if there's a leap year, wcs_estimate_periods_between would return 2 years instead of
 * 1, making certain payments wildly inaccurate.
 *
 * @param int $start_timestamp A unix timestamp
 * @param int $end_timestamp A unix timestamp
 *
 * @return int number of leap days between the start and end timstamps
 */
function wcs_number_of_leap_days( $start_timestamp, $end_timestamp ) {
	if ( ! is_numeric( $start_timestamp ) || ! is_numeric( $end_timestamp ) ) {
		throw new InvalidArgumentException( 'Start or end times are not integers' );
	}
	// save the date! ;)
	$default_tz = date_default_timezone_get();
	date_default_timezone_set( 'UTC' );

	// Years to check
	$years = range( date( 'Y', $start_timestamp ), date( 'Y', $end_timestamp ) );
	$leap_years = array_filter( $years, 'wcs_is_leap_year' );
	$total_feb_29s = 0;

	if ( ! empty( $leap_years ) ) {
		// Let's get the first feb 29 in the list
		$first_feb_29 = mktime( 23, 59, 59, 2, 29, reset( $leap_years ) );
		$last_feb_29 = mktime( 0, 0, 0, 2, 29, end( $leap_years ) );

		$is_first_feb_covered = ( $first_feb_29 >= $start_timestamp ) ? 1 : 0;
		$is_last_feb_covered = ( $last_feb_29 <= $end_timestamp ) ? 1 : 0;

		if ( count( $leap_years ) > 1 ) {
			// the feb 29s are in different years
			$total_feb_29s = count( $leap_years ) - 2 + $is_first_feb_covered + $is_last_feb_covered;
		} else {
			$total_feb_29s = ( $first_feb_29 >= $start_timestamp && $last_feb_29 <= $end_timestamp ) ? 1 : 0;
		}
	}
	date_default_timezone_set( $default_tz );

	return $total_feb_29s;
}

/**
 * Filter function used in wcs_number_of_leap_days
 *
 * @param $year int A four digit year, eg 2017
 *
 * @return bool|string
 */
function wcs_is_leap_year( $year ) {
	return date( 'L', mktime( 0, 0, 0, 1, 1, $year ) );
}
/**
 * Method to try to determine the period of subscriptions if data is missing. It tries the following, in order:
 *
 * - defaults to month
 * - comes up with an array of possible values given the standard time spans (day / week / month / year)
 * - ranks them
 * - discards 0 interval values
 * - discards high deviation values
 * - tries to match with passed in interval
 * - if all else fails, sorts by interval and returns the one having the lowest interval, or the first, if equal (that should
 *   not happen though)
 *
 * @param  string  $last_date   mysql date string
 * @param  string  $second_date mysql date string
 * @param  integer $interval    potential interval
 * @return string               period string
 */
function wcs_estimate_period_between( $last_date, $second_date, $interval = 1 ) {

	if ( ! is_int( $interval ) ) {
		$interval = 1;
	}

	$last_timestamp    = wcs_date_to_time( $last_date );
	$second_timestamp  = wcs_date_to_time( $second_date );

	$earlier_timestamp = min( $last_timestamp, $second_timestamp );
	$later_timestamp   = max( $last_timestamp, $second_timestamp );

	$days_in_month     = gmdate( 't', $earlier_timestamp );
	$difference        = absint( $last_timestamp - $second_timestamp );
	$period_in_seconds = round( $difference / $interval );
	$possible_periods  = array();

	// check for months
	$full_months = wcs_find_full_months_between( $earlier_timestamp, $later_timestamp, $interval );

	$possible_periods['month'] = array(
		'intervals'         => floor( $full_months['months'] / $interval ),
		'remainder'         => $full_months['remainder'],
		'fraction'          => $full_months['remainder'] / ( 30 * DAY_IN_SECONDS ),
		'period'            => 'month',
		'days_in_month'     => $days_in_month,
		'original_interval' => $interval,
	);

	// check for different time spans
	foreach ( array( 'year' => YEAR_IN_SECONDS, 'week' => WEEK_IN_SECONDS, 'day' => DAY_IN_SECONDS ) as $time => $seconds ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$possible_periods[ $time ] = array(
			'intervals'         => floor( $period_in_seconds / $seconds ),
			'remainder'         => $period_in_seconds % $seconds,
			'fraction'          => ( $period_in_seconds % $seconds ) / $seconds,
			'period'            => $time,
			'days_in_month'     => $days_in_month,
			'original_interval' => $interval,
		);
	}

	// filter out ones that are less than one period
	$possible_periods_zero_filtered = array_filter( $possible_periods, 'wcs_discard_zero_intervals' );
	if ( empty( $possible_periods_zero_filtered ) ) {
		// fall back if the difference is less than a day and return default 'day'
		return 'day';
	} else {
		$possible_periods = $possible_periods_zero_filtered;
	}

	// filter out ones that have too high of a deviation
	$possible_periods_no_hd = array_filter( $possible_periods, 'wcs_discard_high_deviations' );

	if ( count( $possible_periods_no_hd ) == 1 ) {
		// only one matched, let's return that as our best guess
		$possible_periods_no_hd = array_shift( $possible_periods_no_hd );
		return $possible_periods_no_hd['period'];
	} elseif ( count( $possible_periods_no_hd ) > 1 ) {
		$possible_periods = $possible_periods_no_hd;
	}

	// check for interval equality
	$possible_periods_interval_match = array_filter( $possible_periods, 'wcs_match_intervals' );

	if ( count( $possible_periods_interval_match ) == 1 ) {
		foreach ( $possible_periods_interval_match as $period_data ) {
			// only one matched the interval as our best guess
			return $period_data['period'];
		}
	} elseif ( count( $possible_periods_interval_match ) > 1 ) {
		$possible_periods = $possible_periods_interval_match;
	}

	// order by number of intervals and return the lowest

	usort( $possible_periods, 'wcs_sort_by_intervals' );

	$least_interval = array_shift( $possible_periods );

	return $least_interval['period'];
}

/**
 * Finds full months between two dates and the remaining seconds after the end of the last full month. Takes into account
 * leap years and variable number of days in months. Uses wcs_add_months
 *
 * @param  numeric $start_timestamp unix timestamp of a start date
 * @param  numeric $end_timestamp   unix timestamp of an end date
 * @return array                    with keys 'months' (integer) and 'remainder' (seconds, integer)
 */
function wcs_find_full_months_between( $start_timestamp, $end_timestamp, $interval = 1 ) {
	$number_of_months = 0;
	$remainder = 0;
	$previous_remainder = 0;
	$months_in_period = 0;
	$remainder_in_period = 0;

	while ( 0 <= $remainder ) {
		$previous_timestamp = $start_timestamp;
		$start_timestamp = wcs_add_months( $start_timestamp, 1 );
		$previous_remainder = $remainder;
		$remainder = $end_timestamp - $start_timestamp;
		$remainder_in_period += $start_timestamp - $previous_timestamp;

		if ( $remainder >= 0 ) {
			$number_of_months++;
			$months_in_period++;
		} elseif ( 0 === $previous_remainder ) {
			$previous_remainder = $end_timestamp - $previous_timestamp;
		}

		if ( $months_in_period >= $interval ) {
			$months_in_period = 0;
			$remainder_in_period = 0;
		}
	}

	$remainder_in_period += $remainder;

	$time_difference = array(
		'months'    => $number_of_months,
		'remainder' => $remainder_in_period,
	);

	return $time_difference;
}

/**
 * Used in an array_filter, removes elements where intervals are less than 0
 *
 * @param  array $array elements of an array
 * @return bool        true if at least 1 interval
 */
function wcs_discard_zero_intervals( $array ) {
	return $array['intervals'] > 0;
}

/**
 * Used in an array_filter, discards high deviation elements.
 * - 10 days for a year (10/365th)
 * - 4 days for a month (4/(days_in_month))
 * - 1 day for week (i.e. 1/7th)
 * - 1 hour for days (i.e. 1/24th)
 *
 * @param  array $array elements of the filtered array
 * @return bool        true if value is within deviation limit
 */
function wcs_discard_high_deviations( $array ) {
	switch ( $array['period'] ) {
		case 'year':
			return $array['fraction'] < ( 10 / 365 );
			break;
		case 'month':
			return $array['fraction'] < ( 4 / $array['days_in_month'] );
			break;
		case 'week':
			return $array['fraction'] < ( 1 / 7 );
			break;
		case 'day':
			return $array['fraction'] < ( 1 / 24 );
			break;
		default:
			return false;
	}
}

/**
 * Used in an array_filter, tries to match intervals against passed in interval
 * @param  array $array elements of filtered array
 * @return bool        true if intervals match
 */
function wcs_match_intervals( $array ) {
	return $array['intervals'] == $array['original_interval'];
}

/**
 * Used in a usort, responsible for making sure the array is sorted in ascending order by intervals
 *
 * @param  array $a one element of the sorted array
 * @param  array $b different element of the sorted array
 * @return int    0 if equal, -1 if $b is larger, 1 if $a is larger
 */
function wcs_sort_by_intervals( $a, $b ) {
	if ( $a['intervals'] == $b['intervals'] ) {
		if ( $a['fraction'] == $b['fraction'] ) {
			return 0;
		}
		return ( $a['fraction'] < $b['fraction'] ) ? -1 : 1;

	}
	return ( $a['intervals'] < $b['intervals'] ) ? -1 : 1;
}

/**
 * Used in a usort, responsible for making sure the array is sorted in descending order by fraction.
 *
 * @param  array $a one element of the sorted array
 * @param  array $b different element of the sorted array
 * @return int    0 if equal, -1 if $b is larger, 1 if $a is larger
 */
function wcs_sort_by_fractions( $a, $b ) {
	if ( $a['fraction'] == $b['fraction'] ) {
		return 0;
	}
	return ( $a['fraction'] > $b['fraction'] ) ? -1 : 1;
}

/**
 * Validate whether a given datetime matches the mysql pattern of YYYY-MM-DD HH:MM:SS
 * This function will return false when the date or time is invalid (e.g. 2015-02-29 00:00:00)
 *
 * @param  string $time the mysql time string
 * @return boolean      if the string is valid
 */
function wcs_is_datetime_mysql_format( $time ) {
	if ( ! is_string( $time ) ) {
		return false;
	}

	$format = wcs_get_db_datetime_format();

	$date_object = DateTime::createFromFormat( $format, $time );

	// DateTime::createFromFormat will return false if it is an invalid date.
	return $date_object
			// We also need to check the output of the format() method against the provided string as it will sometimes return
			// the closest date. Passing `2022-02-29 01:02:03` will return `2022-03-01 01:02:03`
			&& $date_object->format( $format ) === $time
			// we check the year is greater than or equal to 1900 as mysql will not accept dates before this.
			&& (int) $date_object->format( 'Y' ) >= 1900;
}

/**
 * Check if a value is a valid timestamp.
 *
 * @param int|string $timestamp The value to check. Only integers and strings are allowed.
 * @return bool True if the value is a valid timestamp, false otherwise.
 */
function wcs_is_timestamp( $timestamp ) {
	// Only accept integers and strings
	if ( ! is_int( $timestamp ) && ! is_string( $timestamp ) ) {
		return false;
	}

	$str = (string) $timestamp;

	// Match valid timestamp patterns: integers, negative integers, or their string equivalents
	// Allows: 123, -123, '123', '-123', 0, '0'
	// Rejects: +123, 0123, 12.34, 1e10, ' 123', '123 ', scientific notation, newlines, etc.
	return (bool) preg_match( '/\A-?(?:0|[1-9]\d*)\z/', $str );
}

/**
 * Convert a date string into a timestamp without ever adding or deducting time.
 *
 * strtotime() would be handy for this purpose, but alas, if other code running on the server
 * is calling date_default_timezone_set() to change the timezone, strtotime() will assume the
 * date is in that timezone unless the timezone is specific on the string (which it isn't for
 * any MySQL formatted date) and attempt to convert it to UTC time by adding or deducting the
 * GMT/UTC offset for that timezone, so for example, when 3rd party code has set the servers
 * timezone using date_default_timezone_set( 'America/Los_Angeles' ) doing something like
 * gmdate( "Y-m-d H:i:s", strtotime( gmdate( "Y-m-d H:i:s" ) ) ) will actually add 7 hours to
 * the date even though it is a date in UTC timezone because the timezone wasn't specified.
 *
 * This makes sure the date is never converted.
 *
 * @param string $date_string A date string acceptable by DateTime() or a timestamp.
 * @return int|null Unix timestamp representation of the timestamp passed in without any changes for timezones, or null if the date string is invalid.
 */
function wcs_date_to_time( $date_string ) {

	if ( 0 == $date_string ) {
		return 0;
	}

	try {
		if ( is_numeric( $date_string ) ) {
			$date_time = new WC_DateTime( 'now', new DateTimeZone( 'UTC' ) );
			$date_time->setTimestamp( (int) $date_string );
		} else {
			$date_time = new WC_DateTime( $date_string, new DateTimeZone( 'UTC' ) );
		}
	} catch ( \Throwable $e ) {
		return null;
	}

	return intval( $date_time->getTimestamp() );
}

/**
 * A wrapper for strtotime() designed to stand up against those who want to watch the WordPress burn.
 *
 * One day WordPress will require Harvey Dent (aka PHP 5.3) then we can use DateTime::add() instead,
 * but for now, this ensures when using strtotime() to add time to a timestamp, there are no additional
 * changes for server specific timezone additions or deductions.
 *
 * @param string $time_string A string representation of a date in any format that can be parsed by strtotime()
 * @return int Unix timestamp representation of the timestamp passed in without any changes for timezones
 */
function wcs_strtotime_dark_knight( $time_string, $from_timestamp = null ) {

	$original_timezone = date_default_timezone_get();

	// this should be UTC anyway as WordPress sets it to that, but some plugins and l33t h4xors just want to watch the world burn and set it to something else
	date_default_timezone_set( 'UTC' );

	if ( null === $from_timestamp ) {
		$next_timestamp = strtotime( $time_string );
	} else {
		$next_timestamp = strtotime( $time_string, $from_timestamp );
	}

	date_default_timezone_set( $original_timezone );

	return $next_timestamp;
}

/**
 * Find the average number of days for a given billing period and interval.
 *
 * @param  string $period a billing period: day, week, month or year.
 * @param  int $interval a billing interval
 * @return int the number of days in that billing cycle
 */
function wcs_get_days_in_cycle( $period, $interval ) {
	$days_in_cycle = 0;

	switch ( $period ) {
		case 'day':
			$days_in_cycle = $interval;
			break;
		case 'week':
			$days_in_cycle = $interval * 7;
			break;
		case 'month':
			$days_in_cycle = $interval * 30.4375; // Average days per month over 4 year period
			break;
		case 'year':
			$days_in_cycle = $interval * 365.25; // Average days per year over 4 year period
			break;
	}

	return apply_filters( 'wcs_get_days_in_cycle', $days_in_cycle, $period, $interval );
}

/**
 * Set a DateTime's timezone to the WordPress site's timezone, or a UTC offset
 * if no timezone string is available.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.2
 * @param WC_DateTime $date
 * @return WC_DateTime
 */
function wcs_set_local_timezone( WC_DateTime $date ) {

	if ( get_option( 'timezone_string' ) ) {
		$date->setTimezone( new DateTimeZone( wc_timezone_string() ) );
	} else {
		$date->set_utc_offset( wc_timezone_offset() );
	}

	return $date;
}

/* Deprecated Functions */

/**
 * Get an instance of the site's timezone.
 *
 * @return DateTimeZone Timezone object for the timezone the site is using.
 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.2
 */
function wcs_get_sites_timezone() {
	_deprecated_function( __FUNCTION__, '2.4.2' );

	$tzstring = get_option( 'timezone_string' );

	if ( empty( $tzstring ) ) {

		$gmt_offset = get_option( 'gmt_offset' );

		if ( 0 == $gmt_offset ) {

			$tzstring = 'UTC';

		} else {

			$gmt_offset *= HOUR_IN_SECONDS;
			$tzstring    = timezone_name_from_abbr( '', $gmt_offset );

			if ( false === $tzstring ) {

				$is_dst = date( 'I' );

				foreach ( timezone_abbreviations_list() as $abbr ) {

					foreach ( $abbr as $city ) {
						if ( $city['dst'] == $is_dst && $city['offset'] == $gmt_offset ) {
							$tzstring = $city['timezone_id'];
							break 2;
						}
					}
				}
			}

			if ( false === $tzstring ) {
				$tzstring = 'UTC';
			}
		}
	}

	$local_timezone = new DateTimeZone( $tzstring );

	return $local_timezone;
}

/**
 * Returns an array of subscription lengths.
 *
 * PayPal Standard Allowable Ranges
 * D – for days; allowable range is 1 to 90
 * W – for weeks; allowable range is 1 to 52
 * M – for months; allowable range is 1 to 24
 * Y – for years; allowable range is 1 to 5
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscription_ranges_tlc() {
	_deprecated_function( __FUNCTION__, '2.1.2', 'wcs_get_non_cached_subscription_ranges' );

	return wcs_get_non_cached_subscription_ranges();
}

/**
 * Take a date in the form of a timestamp, MySQL date/time string or DateTime object (or perhaps
 * a WC_Datetime object when WC > 3.0 is active) and create a WC_DateTime object.
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @param  string|integer|null $variable_date_type UTC timestamp, or ISO 8601 DateTime. If the DateTime string has no timezone or offset, WordPress site timezone will be assumed. Null if their is no date.
 * @return null|WC_DateTime in site's timezone
 */
function wcs_get_datetime_from( $variable_date_type ) {

	try {
		if ( empty( $variable_date_type ) ) {
			$datetime = null;
		} elseif ( is_a( $variable_date_type, 'WC_DateTime' ) ) {
			$datetime = $variable_date_type;
		} elseif ( is_numeric( $variable_date_type ) ) {
			$datetime = new WC_DateTime( "@{$variable_date_type}", new DateTimeZone( 'UTC' ) );
			$datetime->setTimezone( new DateTimeZone( wc_timezone_string() ) );
		} else {
			$datetime = new WC_DateTime( $variable_date_type, new DateTimeZone( wc_timezone_string() ) );
		}
	} catch ( Exception $e ) {
		$datetime = null;
	}

	return $datetime;
}

/**
 * Get a MySQL date/time string in UTC timezone from a WC_Datetime object.
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @param WC_DateTime $datetime
 * @return string MySQL date/time string representation of the DateTime object in UTC timezone
 */
function wcs_get_datetime_utc_string( $datetime ) {
	$date = clone $datetime; // Don't change the original date object's timezone
	$date->setTimezone( new DateTimeZone( 'UTC' ) );
	return $date->format( wcs_get_db_datetime_format() );
}

/**
 * Get the datetime format used in the database.
 *
 * @return string The datetime format used in the database. Default: Y-m-d H:i:s.
 */
function wcs_get_db_datetime_format() {
	return 'Y-m-d H:i:s';
}

/**
 * Format a date for output, a wrapper for wcs_format_datetime() introduced with WC 3.0.
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @param  WC_DateTime $date
 * @param  string $format Defaults to the wc_date_format function if not set.
 * @return string
 */
function wcs_format_datetime( $date, $format = '' ) {

	if ( function_exists( 'wc_format_datetime' ) ) { // WC 3.0+
		$formatted_datetime = wc_format_datetime( $date, $format );
	} else { // WC < 3.0
		if ( ! $format ) {
			$format = wc_date_format();
		}
		if ( ! is_a( $date, 'WC_DateTime' ) ) {
			return '';
		}

		$formatted_datetime = $date->date_i18n( $format );
	}

	return $formatted_datetime;
}

/**
 * Compares two periods and returns the longest period.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 *
 * @param string $current_period Period string. Can be 'day', 'week', 'month', 'year'.
 * @param string $new_period     Period string. Can be 'day', 'week', 'month', 'year'.
 *
 * @return string The longest period between the two provided.
 */
function wcs_get_longest_period( $current_period, $new_period ) {

	if ( empty( $current_period ) || 'year' == $new_period ) {
		$longest_period = $new_period;
	} elseif ( 'month' === $new_period && in_array( $current_period, array( 'week', 'day' ) ) ) {
		$longest_period = $new_period;
	} elseif ( 'week' === $new_period && 'day' === $current_period ) {
		$longest_period = $new_period;
	} else {
		$longest_period = $current_period;
	}

	return $longest_period;
}

/**
 * Compares two periods and returns the shortest period.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
 *
 * @param string $current_period A period string. Can be 'day', 'week', 'month', 'year'.
 * @param string $new_period     A period string. Can be 'day', 'week', 'month', 'year'.
 *
 * @return string The shortest period between the two provided.
 */
function wcs_get_shortest_period( $current_period, $new_period ) {

	if ( empty( $current_period ) || 'day' == $new_period ) {
		$shortest_period = $new_period;
	} elseif ( 'week' === $new_period && in_array( $current_period, array( 'month', 'year' ) ) ) {
		$shortest_period = $new_period;
	} elseif ( 'month' === $new_period && 'year' === $current_period ) {
		$shortest_period = $new_period;
	} else {
		$shortest_period = $current_period;
	}

	return $shortest_period;
}
