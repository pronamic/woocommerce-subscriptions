<?php
/**
 * WooCommerce Subscriptions Product Functions
 *
 * Functions for managing renewal of a subscription.
 *
 * @author Prospress
 * @category Core
 * @package WooCommerce Subscriptions/Functions
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 */

/**
 * For a given product, and optionally price/qty, work out the sign-up with tax included, based on store settings.
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @param  WC_Product $product
 * @param  array $args
 * @return float
 */
function wcs_get_price_including_tax( $product, $args = array() ) {

	$args = wp_parse_args( $args, array(
		'qty'   => 1,
		'price' => $product->get_price(),
	) );

	if ( function_exists( 'wc_get_price_including_tax' ) ) { // WC 3.0+
		$price = wc_get_price_including_tax( $product, $args );
	} else { // WC < 3.0
		$price = $product->get_price_including_tax( $args['qty'], $args['price'] );
	}

	return $price;
}

/**
 * For a given product, and optionally price/qty, work out the sign-up fee with tax excluded, based on store settings.
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @param  WC_Product $product
 * @param  array $args
 * @return float
 */
function wcs_get_price_excluding_tax( $product, $args = array() ) {

	$args = wp_parse_args( $args, array(
		'qty'   => 1,
		'price' => $product->get_price(),
	) );

	if ( function_exists( 'wc_get_price_excluding_tax' ) ) { // WC 3.0+
		$price = wc_get_price_excluding_tax( $product, $args );
	} else { // WC < 3.0
		$price = $product->get_price_excluding_tax( $args['qty'], $args['price'] );
	}

	return $price;
}

/**
 * Returns a 'from' prefix if you want to show where prices start at.
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @return string
 */
function wcs_get_price_html_from_text( $product = '' ) {

	if ( function_exists( 'wc_get_price_html_from_text' ) ) { // WC 3.0+
		$price_html_from_text = wc_get_price_html_from_text();
	} else { // WC < 3.0
		$price_html_from_text = $product->get_price_html_from_text();
	}

	return $price_html_from_text;
}

/**
 * Get an array of the prices, used to help determine min/max values.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 */
function wcs_get_variation_prices( $variation, $variable_product ) {

	return array(
		'price'         => apply_filters( 'woocommerce_variation_prices_price', WC_Subscriptions_Product::get_price( $variation ), $variation, $variable_product ),
		'regular_price' => apply_filters( 'woocommerce_variation_prices_regular_price', WC_Subscriptions_Product::get_regular_price( $variation, 'edit' ), $variation, $variable_product ),
		'sale_price'    => apply_filters( 'woocommerce_variation_prices_sale_price', WC_Subscriptions_Product::get_sale_price( $variation, 'edit' ), $variation, $variable_product ),
		'sign_up_fee'   => apply_filters( 'woocommerce_variation_prices_sign_up_fee', WC_Subscriptions_Product::get_sign_up_fee( $variation ), $variation, $variable_product ),
	);
}

/**
 * Get an array of the minimum and maximum priced variations based on subscription billing terms.
 *
 * @param array $child_variation_ids the IDs of product variation children ids
 * @return array Array containing the min and max variation prices and billing data
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 */
function wcs_get_min_max_variation_data( $variable_product, $child_variation_ids = array() ) {

	if ( empty( $child_variation_ids ) ) {
		$child_variation_ids = is_callable( array( $variable_product, 'get_visible_children' ) ) ? $variable_product->get_visible_children() : $variable_product->get_children( true );
	}

	$variations_data = array();

	foreach ( $child_variation_ids as $variation_id ) {

		if ( $variation = wc_get_product( $variation_id ) ) {

			$prices = wcs_get_variation_prices( $variation, $variable_product );

			foreach ( $prices as $price_key => $amount ) {
				if ( '' !== $amount ) {
					if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
						$prices[ $price_key ] = wcs_get_price_including_tax( $variable_product, array( 'price' => $amount ) );
					} else {
						$prices[ $price_key ] = wcs_get_price_excluding_tax( $variable_product, array( 'price' => $amount ) );
					}
				}
			}

			$variations_data[ $variation_id ] = array(
				'price'         => $prices['price'],
				'regular_price' => $prices['regular_price'],
				'sale_price'    => $prices['sale_price'],
				'subscription'  => array(
					'sign_up_fee'  => $prices['sign_up_fee'],
					'period'       => WC_Subscriptions_Product::get_period( $variation ),
					'interval'     => WC_Subscriptions_Product::get_interval( $variation ),
					'trial_length' => WC_Subscriptions_Product::get_trial_length( $variation ),
					'trial_period' => WC_Subscriptions_Product::get_trial_period( $variation ),
					'length'       => WC_Subscriptions_Product::get_length( $variation ),
				),
			);
		}
	}

	return wcs_calculate_min_max_variations( $variations_data );
}

/**
 * Determine the minimum and maximum values for a set of structured subscription
 * price data in a form created by @see wcs_get_min_max_variation_data()
 *
 * @param array $variations_data the IDs of product variation children ids
 * @return array
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 */
function wcs_calculate_min_max_variations( $variations_data ) {

	$lowest_initial_amount             = $highest_initial_amount = $lowest_price = $highest_price = '';
	$shortest_initial_period           = $longest_initial_period = $shortest_trial_period = $longest_trial_period = $shortest_trial_length = $longest_trial_length = '';
	$longest_initial_interval          = $shortest_initial_interval = $variable_subscription_period = $variable_subscription_period_interval = '';
	$lowest_regular_price              = $highest_regular_price = $lowest_sale_price = $highest_sale_price = $max_subscription_period = $max_subscription_period_interval = '';
	$variable_subscription_sign_up_fee = $variable_subscription_trial_period = $variable_subscription_trial_length = $variable_subscription_length = $variable_subscription_sign_up_fee = $variable_subscription_trial_period = $variable_subscription_trial_length = $variable_subscription_length = '';
	$min_variation_id                  = $max_variation_id = null;

	$variations_data_prices_list        = array();
	$variations_data_sign_up_fees_list  = array();
	$variations_data_periods_list       = array();
	$variations_data_intervals_list     = array();
	$variations_data_trial_lengths_list = array();
	$variations_data_trial_periods_list = array();
	$variations_data_lengths_list       = array();

	foreach ( $variations_data as $variation_id => $variation_data ) {

		$is_max = $is_min = false;

		if ( '' === $variation_data['price'] && empty( $variation_data['subscription']['sign_up_fee'] ) ) {
			continue;
		}

		$variations_data_prices_list        = array_unique( array_merge( $variations_data_prices_list, array( $variation_data['price'] ) ) );
		$variations_data_sign_up_fees_list  = array_unique( array_merge( $variations_data_sign_up_fees_list, array( empty( $variation_data['subscription']['sign_up_fee'] ) ? 0 : $variation_data['subscription']['sign_up_fee'] ) ) );
		$variations_data_periods_list       = array_unique( array_merge( $variations_data_periods_list, array( $variation_data['subscription']['period'] ) ) );
		$variations_data_intervals_list     = array_unique( array_merge( $variations_data_intervals_list, array( $variation_data['subscription']['interval'] ) ) );
		$variations_data_trial_lengths_list = array_unique( array_merge( $variations_data_trial_lengths_list, array( empty( $variation_data['subscription']['trial_length'] ) ? 0 : $variation_data['subscription']['trial_length'] ) ) );
		$variations_data_trial_periods_list = array_unique( array_merge( $variations_data_trial_periods_list, array( $variation_data['subscription']['trial_period'] ) ) );
		$variations_data_lengths_list       = array_unique( array_merge( $variations_data_lengths_list, array( $variation_data['subscription']['length'] ) ) );

		$has_free_trial = '' !== $variation_data['subscription']['trial_length'] && $variation_data['subscription']['trial_length'] > 0;

		// Determine some recurring price flags
		$is_lowest_price     = $variation_data['price'] < $lowest_price || '' === $lowest_price;
		$is_longest_period   = wcs_get_longest_period( $variable_subscription_period, $variation_data['subscription']['period'] ) === $variation_data['subscription']['period'];
		$is_longest_interval = $variation_data['subscription']['interval'] >= $variable_subscription_period_interval || '' === $variable_subscription_period_interval;

		// Find the amount the subscriber will have to pay up-front
		if ( $has_free_trial ) {
			$initial_amount   = $variation_data['subscription']['sign_up_fee'];
			$initial_period   = $variation_data['subscription']['trial_period'];
			$initial_interval = $variation_data['subscription']['trial_length'];
		} else {
			$initial_amount   = (float) $variation_data['price'] + (float) $variation_data['subscription']['sign_up_fee'];
			$initial_period   = $variation_data['subscription']['period'];
			$initial_interval = $variation_data['subscription']['interval'];
		}

		// We have a free trial & no sign-up fee, so need to choose the longest free trial (and maybe the shortest)
		if ( $has_free_trial && 0 == $variation_data['subscription']['sign_up_fee'] ) {

			// First variation
			if ( '' === $longest_trial_period ) {

				$is_min = true;

			// If two variations have the same free trial, choose the variation with the lowest recurring price for the longest period
			} elseif ( $variable_subscription_trial_period === $variation_data['subscription']['trial_period'] && $variation_data['subscription']['trial_length'] === $variable_subscription_trial_length ) {

				// If the variation has the lowest recurring price, it's the cheapest
				if ( $is_lowest_price ) {

					$is_min = true;

				// When current variation's free trial is the same as the lowest, it's the cheaper if it has a longer billing schedule
				} elseif ( $variation_data['price'] === $lowest_price ) {

					if ( $is_longest_period && $is_longest_interval ) {

						$is_min = true;

					// Longest with a new billing period
					} elseif ( $is_longest_period && $variation_data['subscription']['period'] !== $variable_subscription_trial_period ) {

						$is_min = true;

					}
				}

			// Otherwise the cheapest variation is the one with the longer trial
			} elseif ( $variable_subscription_trial_period === $variation_data['subscription']['trial_period'] ) {

				$is_min = $variation_data['subscription']['trial_length'] > $variable_subscription_trial_length;

			// Otherwise just a longer trial period (that isn't equal to the longest period)
			} elseif ( wcs_get_longest_period( $longest_trial_period, $variation_data['subscription']['trial_period'] ) === $variation_data['subscription']['trial_period'] ) {

				$is_min = true;

			}

			if ( $is_min ) {
				$longest_trial_period = $variation_data['subscription']['trial_period'];
				$longest_trial_length = $variation_data['subscription']['trial_length'];
			}

			// If the current cheapest variation is also free, then the shortest trial period is the most expensive
			if ( 0 == $lowest_price || '' === $lowest_price ) {

				if ( '' === $shortest_trial_period ) {

					$is_max = true;

				// Need to check trial length
				} elseif ( $shortest_trial_period === $variation_data['subscription']['trial_period'] ) {

					$is_max = $variation_data['subscription']['trial_length'] < $shortest_trial_length;

				// Need to find shortest period
				} elseif ( wcs_get_shortest_period( $shortest_trial_period, $variation_data['subscription']['trial_period'] ) === $variation_data['subscription']['trial_period'] ) {

					$is_max = true;

				}

				if ( $is_max ) {
					$shortest_trial_period = $variation_data['subscription']['trial_period'];
					$shortest_trial_length = $variation_data['subscription']['trial_length'];
				}
			}
		} else {

			$longest_initial_period  = wcs_get_longest_period( $longest_initial_period, $initial_period );
			$shortest_initial_period = wcs_get_shortest_period( $shortest_initial_period, $initial_period );

			$is_lowest_initial_amount    = $initial_amount < $lowest_initial_amount || '' === $lowest_initial_amount;
			$is_longest_initial_period   = $initial_period === $longest_initial_period;
			$is_longest_initial_interval = $initial_interval >= $longest_initial_interval || '' === $longest_initial_interval;

			$is_highest_initial   = $initial_amount > $highest_initial_amount || '' === $highest_initial_amount;
			$is_shortest_period   = $initial_period === $shortest_initial_period || '' === $shortest_initial_period;
			$is_shortest_interval = $initial_interval < $shortest_initial_interval || '' === $shortest_initial_interval;

			// If we're not dealing with the lowest initial access amount, then ignore this variation
			if ( ! $is_lowest_initial_amount && $initial_amount !== $lowest_initial_amount ) {
				continue;
			}

			// If the variation has the lowest price, it's the cheapest
			if ( $is_lowest_initial_amount ) {

				$is_min = true;

			// When current variation's price is the same as the lowest, it's the cheapest only if it has a longer billing schedule
			} elseif ( $initial_amount === $lowest_initial_amount ) {

				// We need to check the recurring schedule when the sign-up fee & free trial periods are equal
				if ( $has_free_trial && $initial_period == $longest_initial_period && $initial_interval == $longest_initial_interval ) {

					// If the variation has the lowest recurring price, it's the cheapest
					if ( $is_lowest_price ) {

						$is_min = true;

					// When current variation's price is the same as the lowest, it's the cheapest only if it has a longer billing schedule
					} elseif ( $variation_data['price'] === $lowest_price ) {

						if ( $is_longest_period && $is_longest_interval ) {

							$is_min = true;

						// Longest with a new billing period
						} elseif ( $is_longest_period && $variation_data['subscription']['period'] !== $variable_subscription_period ) {

							$is_min = true;

						}
					}
				// Longest initial term is the cheapest
				} elseif ( $is_longest_initial_period && $is_longest_initial_interval ) {

					$is_min = true;

				// Longest with a new billing period
				} elseif ( $is_longest_initial_period && $initial_period !== $variable_subscription_period ) {

					$is_min = true;

				}
			}

			// If we have the highest price for the shortest period, we might have the maximum variation
			if ( $is_highest_initial && $is_shortest_period && $is_shortest_interval ) {

				$is_max = true;

			// But only if its for the shortest billing period
			} elseif ( $variation_data['price'] === $highest_price ) {

				if ( $is_shortest_period && $is_shortest_interval ) {

					$is_max = true;

				} elseif ( $is_shortest_period ) {

					$is_max = true;

				}
			}
		}

		// If it's the min subscription terms
		if ( $is_min ) {

			$min_variation_id      = $variation_id;

			$lowest_price          = $variation_data['price'];
			$lowest_regular_price  = $variation_data['regular_price'];
			$lowest_sale_price     = $variation_data['sale_price'];

			$lowest_regular_price = ( '' === $lowest_regular_price ) ? 0 : $lowest_regular_price;
			$lowest_sale_price    = ( '' === $lowest_sale_price ) ? 0 : $lowest_sale_price;

			$lowest_initial_amount    = $initial_amount;
			$longest_initial_period   = $initial_period;
			$longest_initial_interval = $initial_interval;

			$variable_subscription_sign_up_fee     = $variation_data['subscription']['sign_up_fee'];
			$variable_subscription_period          = $variation_data['subscription']['period'];
			$variable_subscription_period_interval = $variation_data['subscription']['interval'];
			$variable_subscription_trial_length    = $variation_data['subscription']['trial_length'];
			$variable_subscription_trial_period    = $variation_data['subscription']['trial_period'];
			$variable_subscription_length          = $variation_data['subscription']['length'];
		}

		if ( $is_max ) {

			$max_variation_id       = $variation_id;

			$highest_price          = $variation_data['price'];
			$highest_regular_price  = $variation_data['regular_price'];
			$highest_sale_price     = $variation_data['sale_price'];
			$highest_initial_amount = $initial_amount;

			$highest_regular_price = ( '' === $highest_regular_price ) ? 0 : $highest_regular_price;
			$highest_sale_price    = ( '' === $highest_sale_price ) ? 0 : $highest_sale_price;

			$max_subscription_period          = $variation_data['subscription']['period'];
			$max_subscription_period_interval = $variation_data['subscription']['interval'];
		}
	}

	if ( sizeof( array_unique( $variations_data_prices_list ) ) > 1 ) {
		$subscription_details_identical = false;
	} elseif ( sizeof( array_unique( $variations_data_sign_up_fees_list ) ) > 1 ) {
		$subscription_details_identical = false;
	} elseif ( sizeof( array_unique( $variations_data_periods_list ) ) > 1 ) {
		$subscription_details_identical = false;
	} elseif ( sizeof( array_unique( $variations_data_intervals_list ) ) > 1 ) {
		$subscription_details_identical = false;
	} elseif ( sizeof( array_unique( $variations_data_trial_lengths_list ) ) > 1 ) {
		$subscription_details_identical = false;
	} elseif ( sizeof( array_unique( $variations_data_trial_periods_list ) ) > 1 ) {
		$subscription_details_identical = false;
	} elseif ( sizeof( array_unique( $variations_data_lengths_list ) ) > 1 ) {
		$subscription_details_identical = false;
	} else {
		$subscription_details_identical = true;
	}

	return array(
		'min'          => array(
			'variation_id'  => $min_variation_id,
			'price'         => $lowest_price,
			'regular_price' => $lowest_regular_price,
			'sale_price'    => $lowest_sale_price,
			'period'        => $variable_subscription_period,
			'interval'      => $variable_subscription_period_interval,
		),
		'max'          => array(
			'variation_id'  => $max_variation_id,
			'price'         => $highest_price,
			'regular_price' => $highest_regular_price,
			'sale_price'    => $highest_sale_price,
			'period'        => $max_subscription_period,
			'interval'      => $max_subscription_period_interval,
		),
		'subscription' => array(
			'signup-fee'   => $variable_subscription_sign_up_fee,
			'trial_period' => $variable_subscription_trial_period,
			'trial_length' => $variable_subscription_trial_length,
			'length'       => $variable_subscription_length,
		),
		'identical'    => $subscription_details_identical,
	);
}

/**
 * Generates a key for grouping subscription products with the same billing schedule.
 *
 * Used in a frontend cart and checkout context to group items by a recurring cart key for use in generating recurring carts.
 * Used by the orders/<id>/subscriptions REST API endpoint to group order items into subscriptions.
 *
 * @see https://woocommerce.com/document/subscriptions/develop/multiple-subscriptions/#section-3
 *
 * @param WC_Product $product      The product to generate the key for.
 * @param int        $renewal_time The timestamp of the first renewal payment.
 *
 * @return string The subscription product grouping key.
 */
function wcs_get_subscription_grouping_key( $product, $renewal_time = 0 ) {
	$key = '';

	$renewal_time = ! empty( $renewal_time ) ? $renewal_time : WC_Subscriptions_Product::get_first_renewal_payment_time( $product );
	$interval     = WC_Subscriptions_Product::get_interval( $product );
	$period       = WC_Subscriptions_Product::get_period( $product );
	$length       = WC_Subscriptions_Product::get_length( $product );
	$trial_period = WC_Subscriptions_Product::get_trial_period( $product );
	$trial_length = WC_Subscriptions_Product::get_trial_length( $product );

	if ( $renewal_time > 0 ) {
		$key .= gmdate( 'Y_m_d_', $renewal_time );
	}

	// First start with the billing interval and period.
	switch ( $interval ) {
		case 1:
			if ( 'day' === $period ) {
				$key .= 'daily';
			} else {
				$key .= sprintf( '%sly', $period );
			}
			break;
		case 2:
			$key .= sprintf( 'every_2nd_%s', $period );
			break;
		case 3:
			$key .= sprintf( 'every_3rd_%s', $period ); // or sometimes two exceptions it would seem
			break;
		default:
			$key .= sprintf( 'every_%dth_%s', $interval, $period );
			break;
	}

	if ( $length > 0 ) {
		$key .= '_for_';
		$key .= sprintf( '%d_%s', $length, $period );

		if ( $length > 1 ) {
			$key .= 's';
		}
	}

	if ( $trial_length > 0 ) {
		$key .= sprintf( '_after_a_%d_%s_trial', $trial_length, $trial_period );
	}

	return apply_filters( 'wcs_subscription_product_grouping_key', $key, $product, $renewal_time );
}

/**
 * Get the reactivate link for a subscription product if the user already has a
 * pending cancellation subscription.
 *
 * @param int $user_id The user ID
 * @param WC_Product $product The product
 * @return string The reactivate link
 */
function wcs_get_user_reactivate_link_for_product( int $user_id, WC_Product $product ): string {
	$reactivate_link = '';

	$user_subscriptions = wcs_get_subscriptions(
		[
			'customer_id' => $user_id,
			'product_id'  => $product->get_id(),
			'status'      => 'pending-cancel',
		]
	);

	foreach ( $user_subscriptions as $subscription ) {
		if ( $subscription->can_be_updated_to( 'active' ) && ! $subscription->needs_payment() ) {
			$reactivate_link = wcs_get_users_change_status_link( $subscription->get_id(), 'active', $subscription->get_status() );
			break;
		}
	}

	return $reactivate_link;
}
