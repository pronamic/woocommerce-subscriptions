<?php
/**
 * Tracker for Subscriptions usage.
 *
 * @class     WC_Subscriptions_Tracker
 * @version   1.0.0 - Migrated from WooCommerce Subscriptions v2.6.4
 * @package   WooCommerce Subscriptions/Classes
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce_Subscriptions\Internal\Telemetry\Collector;

class WC_Subscriptions_Tracker {
	/**
	 * Handles the collection of additional pieces of data.
	 */
	private static Collector $telemetry_collector;

	/**
	 * Initialize the Tracker.
	 */
	public static function init() {
		// Only add data if Tracker enabled
		if ( 'yes' === get_option( 'woocommerce_allow_tracking', 'no' ) ) {
			self::$telemetry_collector = new Collector();
			self::$telemetry_collector->setup();
			add_filter( 'woocommerce_tracker_data', [ __CLASS__, 'add_subscriptions_tracking_data' ], 10, 1 );
		}
	}

	/**
	 * Adds Subscriptions data to the WC tracked data.
	 *
	 * @param array $data
	 * @return array all the tracking data.
	 */
	public static function add_subscriptions_tracking_data( $data ) {
		$data['extensions']['wc_subscriptions']['settings']            = self::get_subscriptions_options();
		$data['extensions']['wc_subscriptions']['subscriptions']       = self::get_subscriptions();
		$data['extensions']['wc_subscriptions']['subscription_orders'] = self::get_subscription_orders();

		// Insert any additional telemetry that we have been collecting.
		$additional_telemetry                   = self::$telemetry_collector->get_telemetry_data();
		$data['extensions']['wc_subscriptions'] = array_merge_recursive( $data['extensions']['wc_subscriptions'], $additional_telemetry );

		return $data;
	}

	/**
	 * Gets the tracked Subscriptions options data.
	 *
	 * @return array Subscriptions options data.
	 */
	private static function get_subscriptions_options() {
		$customer_notifications_offset = get_option( WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string, [] );

		return [
			// Staging and live site
			'wc_subscriptions_staging'             => WCS_Staging::is_duplicate_site() ? 'staging' : 'live',
			'wc_subscriptions_live_url'            => esc_url( WCS_Staging::get_site_url_from_source( 'subscriptions_install' ) ),

			// Button text
			// Add to Cart Button Text
			'add_to_cart_button_text'              => get_option( WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text' ),
			// Place Order Button Text
			'order_button_text'                    => get_option( WC_Subscriptions_Admin::$option_prefix . '_order_button_text' ),

			// Roles
			// Subscriber Default Role
			'subscriber_role'                      => get_option( WC_Subscriptions_Admin::$option_prefix . '_subscriber_role' ),
			// Inactive Subscriber Role
			'cancelled_role'                       => get_option( WC_Subscriptions_Admin::$option_prefix . '_cancelled_role' ),

			// Renewals
			// Accept Manual Renewals
			'accept_manual_renewals'               => get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals' ),
			// Turn off Automatic Payments
			'turn_off_automatic_payments'          => 'no' === get_option( WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals' ) ? 'none' : get_option( WC_Subscriptions_Admin::$option_prefix . '_turn_off_automatic_payments', 'none' ),
			// Auto Renewal Toggle
			'enable_auto_renewal_toggle'           => get_option( WC_Subscriptions_Admin::$option_prefix . '_enable_auto_renewal_toggle' ),

			// Early renewal
			// Accept Early Renewal Payments
			'enable_early_renewal'                 => get_option( WC_Subscriptions_Admin::$option_prefix . '_enable_early_renewal' ),
			// Accept Early Renewal Payments via a Modal
			'enable_early_renewal_via_modal'       => 'no' === get_option( WC_Subscriptions_Admin::$option_prefix . '_enable_early_renewal' ) ? 'none' : get_option( WC_Subscriptions_Admin::$option_prefix . '_enable_early_renewal_via_modal', 'none' ),

			// Switching
			// Between Subscription Variations and Between Grouped Subscriptions are condensed into this setting.
			'allow_switching'                      => get_option( WC_Subscriptions_Admin::$option_prefix . '_allow_switching' ),
			// Prorate Recurring Payment
			'apportion_recurring_price'            => get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_recurring_price', 'none' ),
			// Prorate Sign up Fee
			'apportion_sign_up_fee'                => get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee', 'none' ),
			// Prorate Subscription Length
			'apportion_length'                     => get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'none' ),
			// Switch Button Text
			'switch_button_text'                   => get_option( WC_Subscriptions_Admin::$option_prefix . '_switch_button_text', 'none' ),

			// Gifting
			// Enable gifting
			'gifting_enable_gifting'               => get_option( WC_Subscriptions_Admin::$option_prefix . '_gifting_enable_gifting' ),
			'gifting_default_option'               => get_option( WC_Subscriptions_Admin::$option_prefix . '_gifting_default_option' ),
			// Gifting Checkbox Text
			'gifting_gifting_checkbox_text'        => get_option( WC_Subscriptions_Admin::$option_prefix . '_gifting_gifting_checkbox_text' ),
			// Downloadable Products
			'gifting_downloadable_products'        => get_option( WC_Subscriptions_Admin::$option_prefix . '_gifting_downloadable_products' ),

			// Synchronization
			// Synchronise renewals
			'sync_payments'                        => get_option( WC_Subscriptions_Admin::$option_prefix . '_sync_payments' ),
			// Prorate First Renewal
			'prorate_synced_payments'              => $prorate_synced_payments = ( 'no' === get_option( WC_Subscriptions_Admin::$option_prefix . '_sync_payments' ) ? 'none' : get_option( WC_Subscriptions_Admin::$option_prefix . '_prorate_synced_payments', 'none' ) ),
			// Sign-up grace period
			'days_no_fee'                          => 'recurring' === $prorate_synced_payments ? get_option( WC_Subscriptions_Admin::$option_prefix . '_days_no_fee', 'none' ) : 'none',

			// Miscellaneous
			// Customer Suspensions
			'max_customer_suspensions'             => get_option( WC_Subscriptions_Admin::$option_prefix . '_max_customer_suspensions' ),
			// Mixed Checkout
			'multiple_purchase'                    => get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase' ),
			// $0 Initial Checkout
			'allow_zero_initial_order_without_payment_method' => get_option( WC_Subscriptions_Admin::$option_prefix . '_zero_initial_payment_requires_payment' ),
			// Drip Downloadable Content
			'drip_downloadable_content_on_renewal' => get_option( WC_Subscriptions_Admin::$option_prefix . '_drip_downloadable_content_on_renewal' ),
			// Retry Failed Payments
			'enable_retry'                         => get_option( WC_Subscriptions_Admin::$option_prefix . '_enable_retry' ),

			// Notifications
			// Enable Reminders
			'enable_notification_reminders'        => get_option( WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$switch_setting_string ),
			// Reminder Timing
			'customer_notifications_offset_number' => $customer_notifications_offset['number'] ?? 'none',
			'customer_notifications_offset_unit'   => $customer_notifications_offset['unit'] ?? 'none',
		];
	}

	/**
	 * Gets the combined subscription dates, count, and totals data.
	 *
	 * @return array
	 */
	private static function get_subscriptions() {
		$subscription_dates  = self::get_subscription_dates();
		$subscription_counts = self::get_subscription_counts();

		return array_merge( $subscription_dates, $subscription_counts );
	}

	/**
	 * Gets subscription counts.
	 *
	 * @return array Subscription count by status. Keys are subscription status slugs, values are subscription counts (string).
	 */
	private static function get_subscription_counts() {
		$subscription_counts = [];
		$count_by_status     = WC_Data_Store::load( 'subscription' )->get_subscriptions_count_by_status();
		foreach ( wcs_get_subscription_statuses() as $status_slug => $status_name ) {
			$subscription_counts[ $status_slug ] = $count_by_status[ $status_slug ] ?? 0;
		}
		// Ensure all values are strings.
		$subscription_counts = array_map( 'strval', $subscription_counts );
		return $subscription_counts;
	}

	/**
	 * Gets subscription order counts and totals.
	 *
	 * @return array Subscription order counts and totals by type (initial, switch, renewal, resubscribe). Values are returned as strings.
	 */
	private static function get_subscription_orders() {
		$order_totals = array();

		// Get the subtotal and count for all subscription types in one query
		$counts_and_totals = self::get_order_count_and_total_by_meta_key();

		foreach ( $counts_and_totals as $type => $data ) {
			$order_totals[ $type . '_gross' ] = $data['total'];
			$order_totals[ $type . '_count' ] = $data['count'];
		}

		// Get initial orders (orders without switch, renewal, or resubscribe meta keys).
		$count_and_total = self::get_initial_order_count_and_total();

		$order_totals['initial_gross'] = $count_and_total['total'];
		$order_totals['initial_count'] = $count_and_total['count'];

		// Ensure all values are strings.
		$order_totals = array_map( 'strval', $order_totals );

		return $order_totals;
	}

	/**
	 * Gets order count and total for subscription-related orders.
	 *
	 * @return array Array with counts and totals for switch, renewal, and resubscribe orders.
	 */
	private static function get_order_count_and_total_by_meta_key() {
		global $wpdb;

		$order_statuses = array( 'wc-completed', 'wc-refunded' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			// HPOS: Use wc_orders and wc_orders_meta tables.
			$orders_table = $wpdb->prefix . 'wc_orders';
			$meta_table   = $wpdb->prefix . 'wc_orders_meta';

			$query = $wpdb->prepare(
				"SELECT
					order_relation.meta_key as 'type',
					SUM( orders.total_amount ) AS 'total',
					COUNT( orders.id ) as 'count'
				FROM {$orders_table} AS orders
					RIGHT JOIN {$meta_table} AS order_relation ON order_relation.order_id = orders.id
				WHERE order_relation.meta_key IN ( '_subscription_switch', '_subscription_renewal', '_subscription_resubscribe' )
					AND orders.status in (%s, %s)
					AND orders.type = 'shop_order'
				GROUP BY order_relation.meta_key",
				$order_statuses
			);
		} else {
			// CPT: Use posts and postmeta tables.
			$query = $wpdb->prepare(
				"SELECT
					order_relation.meta_key as 'type',
					SUM( order_total.meta_value ) AS 'total',
					COUNT( orders.ID ) as 'count'
				FROM {$wpdb->prefix}posts AS orders
					RIGHT JOIN {$wpdb->prefix}postmeta AS order_relation ON order_relation.post_id = orders.ID
					RIGHT JOIN {$wpdb->prefix}postmeta AS order_total ON order_total.post_id = orders.ID
				WHERE order_relation.meta_key IN ( '_subscription_switch', '_subscription_renewal', '_subscription_resubscribe' )
					AND orders.post_status in (%s, %s)
					AND order_total.meta_key = '_order_total'
				GROUP BY order_relation.meta_key",
				$order_statuses
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$totals = array(
			'switch'      => array(
				'count' => 0,
				'total' => 0.0,
			),
			'renewal'     => array(
				'count' => 0,
				'total' => 0.0,
			),
			'resubscribe' => array(
				'count' => 0,
				'total' => 0.0,
			),
		);

		if ( $results ) {
			foreach ( $results as $result ) {
				$type = str_replace( '_subscription_', '', $result['type'] );
				if ( isset( $totals[ $type ] ) ) {
					$totals[ $type ] = array(
						'count' => (int) $result['count'],
						'total' => (float) $result['total'],
					);
				}
			}
		}

		// Log if any type has no data
		foreach ( $totals as $type => $data ) {
			if ( 0 === $data['count'] && 0.0 === $data['total'] ) {
				wc_get_logger()->warning( "WC_Subscriptions_Tracker::get_order_count_and_total_by_meta_key() returned 0 count and total for {$type} orders" );
			}
		}

		return $totals;
	}

	/**
	 * Gets count and total for initial orders (orders without subscription relation meta keys).
	 *
	 * @return array Array with 'count' and 'total' keys.
	 */
	private static function get_initial_order_count_and_total() {
		global $wpdb;

		$order_statuses = array( 'wc-completed', 'wc-refunded' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			// HPOS: Use wc_orders table
			$orders_table = $wpdb->prefix . 'wc_orders';

			$query = $wpdb->prepare(
				"SELECT
				SUM( orders.total_amount ) AS 'total', COUNT( DISTINCT orders.id ) as 'count'
					FROM {$orders_table} AS orders
						RIGHT JOIN {$orders_table} AS subscriptions ON subscriptions.parent_order_id = orders.id
					WHERE orders.status in ( %s, %s )
						AND subscriptions.type = 'shop_subscription'
						AND orders.type = 'shop_order'",
				$order_statuses
			);
		} else {
			// CPT: Use posts and postmeta tables.
			$query = $wpdb->prepare(
				"SELECT
				SUM( order_total.meta_value ) AS 'total', COUNT( * ) as 'count'
					FROM {$wpdb->posts} AS orders
						RIGHT JOIN {$wpdb->posts} AS subscriptions ON subscriptions.post_parent = orders.ID
						RIGHT JOIN {$wpdb->postmeta} AS order_total ON order_total.post_id = orders.ID
					WHERE orders.post_status in ( %s, %s )
						AND subscriptions.post_type = 'shop_subscription'
						AND orders.post_type = 'shop_order'
						AND order_total.meta_key = '_order_total'",
				$order_statuses
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$result = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$totals = array(
			'count' => (int) ( $result ? $result['count'] : 0 ),
			'total' => (float) ( $result ? $result['total'] : 0 ),
		);

		if ( 0 === $totals['count'] && 0.0 === $totals['total'] ) {
			wc_get_logger()->warning( 'WC_Subscriptions_Tracker::get_initial_order_count_and_total() returned 0 count and total' );
		}

		return $totals;
	}

	/**
	 * Gets first and last subscription created dates.
	 *
	 * @return array 'first' and 'last' created subscription dates as a string in the date format 'Y-m-d H:i:s' or '-'.
	 */
	private static function get_subscription_dates() {
		// Ignore subscriptions with status 'trash'.
		$first = wcs_get_subscriptions(
			[
				'subscriptions_per_page' => 1,
				'orderby'                => 'date',
				'order'                  => 'ASC',
				'subscription_status'    => [ 'active', 'on-hold', 'pending', 'cancelled', 'expired' ],
			]
		);
		$last  = wcs_get_subscriptions(
			[
				'subscriptions_per_page' => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'subscription_status'    => [ 'active', 'on-hold', 'pending', 'cancelled', 'expired' ],
			]
		);

		// Return each date in 'Y-m-d H:i:s' format or '-' if no subscriptions found.
		$min_max = [
			'first' => count( $first ) ? array_shift( $first )->get_date( 'date_created' ) : '-',
			'last'  => count( $last ) ? array_shift( $last )->get_date( 'date_created' ) : '-',
		];

		return $min_max;
	}
}
