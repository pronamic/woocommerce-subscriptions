<?php
/**
 * Subscription Billing Schedule
 *
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Meta Boxes
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WCS_Meta_Box_Schedule
 */
class WCS_Meta_Box_Schedule {

	/**
	 * Outputs the subscription schedule metabox.
	 *
	 * @param WC_Subscription|WP_Post $subscription The subscription object to display the schedule metabox for. This will be a WP Post object on CPT stores.
	 */
	public static function output( $subscription ) {
		global $post, $the_subscription;

		if ( $subscription && is_a( $subscription, 'WC_Subscription' ) ) {
			$the_subscription = $subscription;
		} elseif ( empty( $the_subscription ) ) {
			$the_subscription = wcs_get_subscription( $post->ID );
		}

		/**
		 * Subscriptions without a start date are freshly created subscriptions.
		 * In order to display the schedule meta box we need to pre-populate the start date with the created date.
		 */
		if ( 0 === $the_subscription->get_time( 'start' ) ) {
			$the_subscription->set_start_date( $the_subscription->get_date( 'date_created' ) );
		}

		include dirname( __FILE__ ) . '/views/html-subscription-schedule.php';
	}

	/**
	 * Saves the subscription schedule meta box data.
	 *
	 * @see woocommerce_process_shop_order_meta
	 *
	 * @param int             $subscription_id The subscription ID to save the schedule for.
	 * @param WC_Subscription $subscription    The subscription object to save the schedule for.
	 */
	public static function save( $subscription_id, $subscription ) {

		if ( ! wcs_is_subscription( $subscription_id ) ) {
			return;
		}

		if ( empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( wc_clean( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		if ( $subscription instanceof WP_Post ) {
			$subscription = wcs_get_subscription( $subscription->ID );
		}

		if ( isset( $_POST['_billing_interval'] ) ) {
			$subscription->set_billing_interval( wc_clean( wp_unslash( $_POST['_billing_interval'] ) ) );
		}

		if ( ! empty( $_POST['_billing_period'] ) ) {
			$subscription->set_billing_period( wc_clean( wp_unslash( $_POST['_billing_period'] ) ) );
		}

		$dates         = array();
		$invalid_dates = array();

		foreach ( wcs_get_subscription_date_types() as $date_type => $date_label ) {
			$date_key = wcs_normalise_date_type_key( $date_type );

			if ( 'last_order_date_created' === $date_key ) {
				continue;
			}

			$utc_timestamp_key = $date_type . '_timestamp_utc';

			// A subscription needs a created date, even if it wasn't set or is empty
			if ( 'date_created' === $date_key && empty( $_POST[ $utc_timestamp_key ] ) ) {
				$datetime = time();
			} elseif ( isset( $_POST[ $utc_timestamp_key ] ) ) {
				$datetime = wc_clean( wp_unslash( $_POST[ $utc_timestamp_key ] ) );
			} else { // No date to set
				continue;
			}

			$timestamp = wcs_date_to_time( $datetime );

			if ( null !== $timestamp ) {
				$dates[ $date_key ] = $timestamp;
			} else {
				$invalid_dates[ $date_key ] = $datetime;
			}
		}

		try {
			$subscription->update_dates( $dates, 'gmt' );

			// Clear the posts cache for non-HPOS stores.
			if ( ! wcs_is_custom_order_tables_usage_enabled() ) {
				wp_cache_delete( $subscription_id, 'posts' );
			}

			$subscription->save();

			if ( ! empty( $invalid_dates ) ) {
				$subscription_date_types = wcs_get_subscription_date_types();
				$invalid_dates_labels    = array_map(
					function ( $date_type ) use ( $subscription_date_types ) {
						// Fallback to the date type key in case there is no translation string.
						return isset( $subscription_date_types[ $date_type ] ) ? $subscription_date_types[ $date_type ] : $date_type;
					},
					array_keys( $invalid_dates )
				);

				$warning_message = sprintf(
					// translators: 1$ is a comma-separated list of invalid dates fields like "Start Date", "Next Payment", 2$-3$: opening and closing <strong> tags.
					__( 'Some subscription dates could not be updated because they contain invalid values: %2$s%1$s%3$s. Please correct these dates and save the changes.', 'woocommerce-subscriptions' ),
					esc_html( implode( ', ', $invalid_dates_labels ) ),
					'<strong>',
					'</strong>'
				);

				wc_get_logger()->warning(
					$warning_message,
					array(
						'subscription_id' => $subscription_id,
						'invalid_dates'   => $invalid_dates,
					)
				);

				wcs_add_admin_notice(
					$warning_message,
					'error', // There is no warning level for admin notices, so using error level.
					get_current_user_id(),
					get_current_screen()->id
				);
			}
		} catch ( \Throwable $e ) {
			// Log the error.
			wc_get_logger()->error(
				sprintf(
					'Error updating subscription #%d: %s',
					$subscription_id,
					$e->getMessage(),
				),
				array(
					'stack_trace' => $e->getTraceAsString(),
				)
			);

			// Display an admin notice.
			wcs_add_admin_notice( $e->getMessage(), 'error' );
		}
	}
}
