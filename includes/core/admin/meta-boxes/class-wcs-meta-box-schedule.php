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
	 * @param int                     $subscription_id The subscription ID to save the schedule for.
	 * @param WC_Subscription/WP_Post $subscription    The subscription object to save the schedule for.
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

		$dates = array();

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

			$dates[ $date_key ] = gmdate( 'Y-m-d H:i:s', $datetime );
		}

		try {
			$subscription->update_dates( $dates, 'gmt' );

			// Clear the posts cache for non-HPOS stores.
			if ( ! wcs_is_custom_order_tables_usage_enabled() ) {
				wp_cache_delete( $subscription_id, 'posts' );
			}
		} catch ( Exception $e ) {
			wcs_add_admin_notice( $e->getMessage(), 'error' );
		}

		$subscription->save();
	}
}
