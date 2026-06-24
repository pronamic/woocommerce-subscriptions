<?php
/**
 * Validation class for subscription scheme properties.
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    9.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_ATT_Validation {
	/**
	 * Maximum allowed trial lengths for each period.
	 *
	 * @var array
	 */
	const MAX_TRIAL_LENGTHS = array(
		'day'   => 90,
		'week'  => 52,
		'month' => 24,
		'year'  => 5,
	);

	/**
	 * Valid trial period values.
	 *
	 * @var array
	 */
	const VALID_PERIODS = array( 'day', 'week', 'month', 'year' );

	/**
	 * Validate trial length based on period.
	 *
	 * @param int    $length Trial length value.
	 * @param string $period Trial period (day/week/month/year).
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_trial_length( $length, $period ) {
		// Validate period first.
		if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
			return new WP_Error(
				'invalid_trial_period',
				/* translators: %s: the invalid period value */
				sprintf( __( 'Invalid trial period: %s. Must be one of: day, week, month, year.', 'woocommerce-subscriptions' ), $period )
			);
		}

		// Ensure length is numeric.
		if ( ! is_numeric( $length ) ) {
			return new WP_Error(
				'invalid_trial_length_type',
				__( 'Trial length must be a number.', 'woocommerce-subscriptions' )
			);
		}

		$length = (int) $length;

		// Length must be non-negative.
		if ( $length < 0 ) {
			return new WP_Error(
				'negative_trial_length',
				__( 'Trial length cannot be negative.', 'woocommerce-subscriptions' )
			);
		}

		// Check maximum length for period.
		$max_length = self::MAX_TRIAL_LENGTHS[ $period ];
		if ( $length > $max_length ) {
			return new WP_Error(
				'trial_length_exceeds_maximum',
				/* translators: 1: the maximum allowed value, 2: the period */
				sprintf( __( 'Trial length cannot exceed %1$d %2$ss.', 'woocommerce-subscriptions' ), $max_length, $period )
			);
		}

		return true;
	}

	/**
	 * Validate signup fee.
	 *
	 * @param float|string $fee Signup fee value.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_signup_fee( $fee ) {
		// Allow empty values (treated as 0).
		if ( '' === $fee || null === $fee ) {
			return true;
		}

		// Ensure fee is numeric.
		if ( ! is_numeric( $fee ) ) {
			return new WP_Error(
				'invalid_signup_fee_type',
				__( 'Signup fee must be a number.', 'woocommerce-subscriptions' )
			);
		}

		$fee = (float) $fee;

		// Fee must be non-negative.
		if ( $fee < 0 ) {
			return new WP_Error(
				'negative_signup_fee',
				__( 'Signup fee cannot be negative.', 'woocommerce-subscriptions' )
			);
		}

		return true;
	}

	/**
	 * Get the maximum trial length for a given period.
	 *
	 * @param string $period Trial period (day/week/month/year).
	 *
	 * @return int|null Maximum length or null if invalid period.
	 */
	public static function get_max_trial_length( $period ) {
		if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
			return null;
		}

		return self::MAX_TRIAL_LENGTHS[ $period ];
	}

	/**
	 * Check if a value is valid (returns true if validation passes).
	 *
	 * Helper method to simplify validation checks.
	 *
	 * @param mixed $result The result of a validation method.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid( $result ) {
		return true === $result;
	}

	/**
	 * Get error message from validation result.
	 *
	 * @param mixed $result The result of a validation method.
	 *
	 * @return string Error message or empty string if valid.
	 */
	public static function get_error_message( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}

		return '';
	}

	/**
	 * Validate subscription period.
	 *
	 * @param string $period Subscription period value.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_period( $period ) {
		if ( empty( $period ) ) {
			return new WP_Error(
				'empty_period',
				__( 'Subscription period is required.', 'woocommerce-subscriptions' )
			);
		}

		if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
			return new WP_Error(
				'invalid_period',
				__( 'Invalid subscription period. Must be day, week, month, or year.', 'woocommerce-subscriptions' )
			);
		}

		return true;
	}

	/**
	 * Validate subscription period interval.
	 *
	 * @param int $interval Subscription period interval value.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_interval( $interval ) {
		if ( ! is_numeric( $interval ) ) {
			return new WP_Error(
				'invalid_interval_type',
				__( 'Subscription interval must be a number.', 'woocommerce-subscriptions' )
			);
		}

		$interval = (int) $interval;

		if ( $interval <= 0 ) {
			return new WP_Error(
				'invalid_interval',
				__( 'Subscription interval must be greater than 0.', 'woocommerce-subscriptions' )
			);
		}

		return true;
	}

	/**
	 * Validate subscription trial period.
	 *
	 * @param string $period Trial period value.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_trial_period( $period ) {
		if ( empty( $period ) ) {
			return new WP_Error(
				'empty_trial_period',
				__( 'Trial period is required.', 'woocommerce-subscriptions' )
			);
		}

		if ( ! in_array( $period, self::VALID_PERIODS, true ) ) {
			return new WP_Error(
				'invalid_trial_period',
				/* translators: %s: the invalid period value */
				sprintf( __( 'Invalid trial period: %s. Must be one of: day, week, month, year.', 'woocommerce-subscriptions' ), $period )
			);
		}

		return true;
	}

	/**
	 * Maximum subscription duration in periods for 10 years.
	 *
	 * @var array
	 */
	const MAX_LENGTH_BY_PERIOD = array(
		'day'   => 3650,
		'week'  => 520,
		'month' => 120,
		'year'  => 10,
	);

	/**
	 * Validate subscription length.
	 *
	 * @param int    $length Subscription length value (number of intervals).
	 * @param string $period Optional. Billing period to validate max duration against.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_length( $length, $period = '' ) {
		if ( ! is_numeric( $length ) ) {
			return new WP_Error(
				'invalid_length_type',
				__( 'Subscription length must be a number.', 'woocommerce-subscriptions' )
			);
		}

		$length = (int) $length;

		// Length must be non-negative (0 means never expire).
		if ( $length < 0 ) {
			return new WP_Error(
				'negative_length',
				__( 'Subscription length cannot be negative.', 'woocommerce-subscriptions' )
			);
		}

		// Validate that the total duration does not exceed 10 years.
		if ( $length > 0 && $period && isset( self::MAX_LENGTH_BY_PERIOD[ $period ] ) ) {
			$max_length = self::MAX_LENGTH_BY_PERIOD[ $period ];
			if ( $length > $max_length ) {
				return new WP_Error(
					'length_exceeds_maximum',
					__( 'The total subscription duration cannot exceed 10 years.', 'woocommerce-subscriptions' )
				);
			}
		}

		return true;
	}

	/**
	 * Validate subscription payment sync date based on billing period.
	 *
	 * Accepts:
	 *  - 0 (integer)             — "do not align", valid for any period.
	 *  - integer 1-7             — week period (1 = Monday, 7 = Sunday).
	 *  - integer 1-28            — month period (capped at 28 so the day exists in every month).
	 *  - array {day, month}      — year period; validated with checkdate() against a non-leap year
	 *                              so that Feb 29 is never accepted.
	 *
	 * @param mixed  $sync_date Sync date: 0, int, or array {day, month}.
	 * @param string $period    Subscription billing period (day/week/month/year).
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_sync_date( $sync_date, $period ) {
		// 0 means "do not align" — always valid. Use is_numeric to guard against non-numeric strings (e.g. 'monday') that absint() would silently coerce to 0.
		if ( ! is_array( $sync_date ) && is_numeric( $sync_date ) && 0 === absint( $sync_date ) ) {
			return true;
		}

		// Day period does not support payment sync.
		if ( 'day' === $period ) {
			return new WP_Error(
				'sync_date_not_supported',
				__( 'Payment sync is not supported for daily billing periods.', 'woocommerce-subscriptions' )
			);
		}

		if ( 'week' === $period ) {
			if ( ! is_numeric( $sync_date ) ) {
				return new WP_Error(
					'invalid_sync_date_type',
					__( 'Weekly sync date must be a number.', 'woocommerce-subscriptions' )
				);
			}

			$day = absint( $sync_date );

			if ( $day < 1 || $day > 7 ) {
				return new WP_Error(
					'sync_date_out_of_range',
					__( 'Weekly sync date must be between 1 (Monday) and 7 (Sunday).', 'woocommerce-subscriptions' )
				);
			}

			return true;
		}

		if ( 'month' === $period ) {
			if ( ! is_numeric( $sync_date ) ) {
				return new WP_Error(
					'invalid_sync_date_type',
					__( 'Monthly sync date must be a number.', 'woocommerce-subscriptions' )
				);
			}

			$day = absint( $sync_date );

			if ( $day < 1 || $day > 28 ) {
				return new WP_Error(
					'sync_date_out_of_range',
					__( 'Monthly sync date must be between 1 and 28.', 'woocommerce-subscriptions' )
				);
			}

			return true;
		}

		if ( 'year' === $period ) {
			if ( ! is_array( $sync_date ) ) {
				return new WP_Error(
					'invalid_sync_date_type',
					__( 'Yearly sync date must be an object with day and month.', 'woocommerce-subscriptions' )
				);
			}

			if ( empty( $sync_date['day'] ) || empty( $sync_date['month'] ) ) {
				return new WP_Error(
					'incomplete_sync_date',
					__( 'Yearly sync date requires both day and month.', 'woocommerce-subscriptions' )
				);
			}

			$month = absint( $sync_date['month'] );
			$day   = absint( $sync_date['day'] );

			// Use a non-leap year so Feb 29 is never accepted; mirrors the JS day-options logic.
			if ( ! checkdate( $month, $day, 2001 ) ) {
				return new WP_Error(
					'invalid_sync_date',
					__( 'Invalid sync date: the day does not exist in the selected month.', 'woocommerce-subscriptions' )
				);
			}

			return true;
		}

		return true;
	}

	/**
	 * Validate subscription discount percentage.
	 *
	 * @param float|string $discount Discount percentage value.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_percentage_discount( $discount ) {
		// Allow empty values (treated as 0).
		if ( '' === $discount || null === $discount ) {
			return true;
		}

		// Ensure discount is numeric.
		if ( ! is_numeric( $discount ) ) {
			return new WP_Error(
				'invalid_discount_type',
				__( 'Subscription discount must be a number.', 'woocommerce-subscriptions' )
			);
		}

		$discount = (float) $discount;

		// Discount must be between 0 and 100.
		if ( $discount < 0 || $discount > 100 ) {
			return new WP_Error(
				'invalid_discount_range',
				__( 'Please enter positive subscription discount values, between 0-100.', 'woocommerce-subscriptions' )
			);
		}

		return true;
	}

	/**
	 * Validate a fixed monetary discount amount.
	 *
	 * Unlike percentage discounts, fixed amounts have no upper bound.
	 *
	 * @param float|string $discount Fixed discount monetary value.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_fixed_discount( $discount ) {
		// Allow empty values (treated as 0).
		if ( '' === $discount || null === $discount ) {
			return true;
		}

		// Ensure discount is numeric.
		if ( ! is_numeric( $discount ) ) {
			return new WP_Error(
				'invalid_discount_type',
				__( 'Subscription discount must be a number.', 'woocommerce-subscriptions' )
			);
		}

		$discount = (float) $discount;

		// Fixed discount must be non-negative.
		if ( $discount < 0 ) {
			return new WP_Error(
				'invalid_discount_range',
				__( 'Please enter a positive subscription discount value.', 'woocommerce-subscriptions' )
			);
		}

		return true;
	}

	/**
	 * Validate override pricing (regular and sale prices).
	 *
	 * @param string|float $regular_price Regular price value.
	 * @param string|float $sale_price    Sale price value.
	 *
	 * @return array Array of validation errors (empty if valid).
	 */
	public static function validate_override_pricing( $regular_price, $sale_price ) {
		$errors = array();

		// Regular price is required for override mode.
		if ( '' === $regular_price || ! is_numeric( $regular_price ) || $regular_price < 0 ) {
			$errors['subscription_regular_price'] = __( 'Regular price is required when overriding product price.', 'woocommerce-subscriptions' );
		}

		// Validate sale price if provided.
		if ( '' !== $sale_price ) {
			if ( ! is_numeric( $sale_price ) || $sale_price < 0 ) {
				$errors['subscription_sale_price'] = __( 'Sale price cannot be negative.', 'woocommerce-subscriptions' );
			} elseif ( is_numeric( $regular_price ) && $sale_price >= $regular_price ) {
				$errors['subscription_sale_price'] = __( 'Sale price must be less than regular price.', 'woocommerce-subscriptions' );
			}
		}

		return $errors;
	}

	/**
	 * Validate plan data before saving.
	 *
	 * @param array $plan_data Plan data to validate.
	 *
	 * @return array Array of validation errors (empty if valid).
	 */
	public static function validate_plan_data( $plan_data ) {
		$errors = array();

		// Validate subscription period (required).
		if ( isset( $plan_data['subscription_period'] ) ) {
			$validation_result = self::validate_period( $plan_data['subscription_period'] );

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_period'] = $validation_result->get_error_message();
			}
		}

		// Validate subscription interval (required).
		if ( isset( $plan_data['subscription_period_interval'] ) ) {
			$validation_result = self::validate_interval( $plan_data['subscription_period_interval'] );

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_period_interval'] = $validation_result->get_error_message();
			}
		}

		// Validate subscription length.
		if ( isset( $plan_data['subscription_length'] ) ) {
			$period            = isset( $plan_data['subscription_period'] ) ? $plan_data['subscription_period'] : '';
			$validation_result = self::validate_length( $plan_data['subscription_length'], $period );

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_length'] = $validation_result->get_error_message();
			}
		}

		// Validate trial period (only if trial length > 0 or if period is explicitly provided and not empty).
		$trial_length = isset( $plan_data['subscription_trial_length'] ) ? $plan_data['subscription_trial_length'] : 0;
		$trial_period = isset( $plan_data['subscription_trial_period'] ) ? $plan_data['subscription_trial_period'] : '';

		if ( $trial_length > 0 || ( ! empty( $trial_period ) ) ) {
			$validation_result = self::validate_trial_period( $trial_period );

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_trial_period'] = $validation_result->get_error_message();
			}
		}

		// Validate trial length.
		if ( isset( $plan_data['subscription_trial_length'] ) && $plan_data['subscription_trial_length'] > 0 ) {
			$trial_period      = isset( $plan_data['subscription_trial_period'] ) ? $plan_data['subscription_trial_period'] : 'day';
			$validation_result = self::validate_trial_length(
				$plan_data['subscription_trial_length'],
				$trial_period
			);

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_trial_length'] = $validation_result->get_error_message();
			}
		}

		// Validate signup fee.
		if ( isset( $plan_data['subscription_signup_fee'] ) ) {
			$validation_result = self::validate_signup_fee( $plan_data['subscription_signup_fee'] );

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_signup_fee'] = $validation_result->get_error_message();
			}
		}

		// Validate sync date (if provided and billing period is known).
		if ( isset( $plan_data['subscription_payment_sync_date'] ) && ! empty( $plan_data['subscription_period'] ) ) {
			$validation_result = self::validate_sync_date(
				$plan_data['subscription_payment_sync_date'],
				$plan_data['subscription_period']
			);

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_payment_sync_date'] = $validation_result->get_error_message();
			}
		}

		// Validate pricing based on method (if pricing method is set).
		if ( isset( $plan_data['subscription_pricing_method'] ) ) {
			if ( WCS_ATT_Scheme::MODE_OVERRIDE === $plan_data['subscription_pricing_method'] ) {
				$regular_price = isset( $plan_data['subscription_regular_price'] ) ? $plan_data['subscription_regular_price'] : '';
				$sale_price    = isset( $plan_data['subscription_sale_price'] ) ? $plan_data['subscription_sale_price'] : '';

				// Validate override pricing.
				$pricing_errors = self::validate_override_pricing( $regular_price, $sale_price );
				$errors         = array_merge( $errors, $pricing_errors );
			} elseif ( WCS_ATT_Scheme::MODE_INHERIT === $plan_data['subscription_pricing_method'] || WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $plan_data['subscription_pricing_method'] ) {
				// Validate discount for inherit mode (percentage) and fixed_discount mode (monetary amount).
				if ( isset( $plan_data['subscription_discount'] ) ) {
					if ( WCS_ATT_Scheme::MODE_FIXED_DISCOUNT === $plan_data['subscription_pricing_method'] ) {
						$validation_result = self::validate_fixed_discount( $plan_data['subscription_discount'] );
					} else {
						$validation_result = self::validate_percentage_discount( $plan_data['subscription_discount'] );
					}

					if ( is_wp_error( $validation_result ) ) {
						$errors['subscription_discount'] = $validation_result->get_error_message();
					}
				}
			}
		} elseif ( isset( $plan_data['subscription_discount'] ) ) {
			// For storewide plans (no pricing method), validate discount directly.
			$validation_result = self::validate_percentage_discount( $plan_data['subscription_discount'] );

			if ( is_wp_error( $validation_result ) ) {
				$errors['subscription_discount'] = $validation_result->get_error_message();
			}
		}

		return $errors;
	}
}
