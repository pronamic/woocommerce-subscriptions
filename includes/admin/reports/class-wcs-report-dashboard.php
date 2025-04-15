<?php
/**
 * Subscriptions Admin Report - Dashboard Stats
 *
 * Creates the subscription admin reports area.
 *
 * @package WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category Class
 * @author Prospress
 * @since 2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Report_Dashboard {

	/**
	 * Hook in additional reporting to WooCommerce dashboard widget
	 */
	public function __construct() {

			// Add the dashboard widget text
			add_action( 'woocommerce_after_dashboard_status_widget', __CLASS__ . '::add_stats_to_dashboard' );

			// Add any necessary scripts / styles
			add_action( 'admin_enqueue_scripts', __CLASS__ . '::dashboard_scripts' );
	}

	/**
	 * Get all data needed for this report and store in the class
	 */
	public static function get_data( $args = array() ) {
		global $wpdb;

		$default_args = array(
			'no_cache' => false,
		);

		$args         = apply_filters( 'wcs_reports_subscription_dashboard_args', $args );
		$args         = wp_parse_args( $args, $default_args );
		$offset       = get_option( 'gmt_offset' );
		$update_cache = false;

		// Use this once it is merged - wcs_get_gmt_offset_string();
		// Convert from Decimal format(eg. 11.5) to a suitable format(eg. +11:30) for CONVERT_TZ() of SQL query.
		$site_timezone = sprintf( '%+02d:%02d', (int) $offset, ( $offset - floor( $offset ) ) * 60 );

		$report_data = new stdClass;

		$cached_results = get_transient( strtolower( __CLASS__ ) );

		// Set a default value for cached results for PHP 8.2+ compatibility.
		if ( empty( $cached_results ) ) {
			$cached_results = [];
		}

		// Subscription signups this month
		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT wcsubs.ID) AS count
				FROM {$wpdb->posts} AS wcsubs
				INNER JOIN {$wpdb->posts} AS wcorder
					ON wcsubs.post_parent = wcorder.ID
				WHERE wcorder.post_type IN ( 'shop_order' )
					AND wcsubs.post_type IN ( 'shop_subscription' )
					AND wcorder.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
					AND wcorder.post_date >= %s
					AND wcorder.post_date < %s",
			date( 'Y-m-01', current_time( 'timestamp' ) ),
			date( 'Y-m-d', strtotime( '+1 DAY', current_time( 'timestamp' ) ) )
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = $wpdb->get_var( apply_filters( 'woocommerce_subscription_dashboard_status_widget_signup_query', $query ) );
			$update_cache = true;
		}

		$report_data->signup_count = $cached_results[ $query_hash ];

		// Signup revenue this month
		$query = $wpdb->prepare(
			"SELECT SUM(order_total_meta.meta_value)
				FROM {$wpdb->postmeta} AS order_total_meta
					RIGHT JOIN
					(
						SELECT DISTINCT wcorder.ID
						FROM {$wpdb->posts} AS wcsubs
						INNER JOIN {$wpdb->posts} AS wcorder
							ON wcsubs.post_parent = wcorder.ID
						WHERE wcorder.post_type IN ( 'shop_order' )
							AND wcsubs.post_type IN ( 'shop_subscription' )
							AND wcorder.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
							AND wcorder.post_date >= %s
							AND wcorder.post_date < %s
					) AS orders ON orders.ID = order_total_meta.post_id
				WHERE order_total_meta.meta_key = '_order_total'",
			date( 'Y-m-01', current_time( 'timestamp' ) ),
			date( 'Y-m-d', strtotime( '+1 DAY', current_time( 'timestamp' ) ) )
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || false === $cached_results || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = $wpdb->get_var( apply_filters( 'woocommerce_subscription_dashboard_status_widget_signup_revenue_query', $query ) );
			$update_cache = true;
		}

		$report_data->signup_revenue = $cached_results[ $query_hash ];

		// Subscription renewals this month
		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT wcorder.ID) AS count
				FROM {$wpdb->posts} AS wcorder
				INNER JOIN {$wpdb->postmeta} AS meta__subscription_renewal
					ON (
						wcorder.id = meta__subscription_renewal.post_id
						AND
						meta__subscription_renewal.meta_key = '_subscription_renewal'
					)
				WHERE wcorder.post_type IN ( 'shop_order' )
					AND wcorder.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
					AND wcorder.post_date >= %s
					AND wcorder.post_date < %s",
			date( 'Y-m-01', current_time( 'timestamp' ) ),
			date( 'Y-m-d', strtotime( '+1 DAY', current_time( 'timestamp' ) ) )
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = $wpdb->get_var( apply_filters( 'woocommerce_subscription_dashboard_status_widget_renewal_query', $query ) );
			$update_cache = true;
		}

		$report_data->renewal_count = $cached_results[ $query_hash ];

		// Renewal revenue this month
		$query = $wpdb->prepare(
			"SELECT SUM(order_total_meta.meta_value)
				FROM {$wpdb->postmeta} as order_total_meta
				RIGHT JOIN
				(
					SELECT DISTINCT wcorder.ID
					FROM {$wpdb->posts} AS wcorder
					INNER JOIN {$wpdb->postmeta} AS meta__subscription_renewal
						ON (
							wcorder.id = meta__subscription_renewal.post_id
							AND
							meta__subscription_renewal.meta_key = '_subscription_renewal'
						)
					WHERE wcorder.post_type IN ( 'shop_order' )
						AND wcorder.post_status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
						AND wcorder.post_date >= %s
						AND wcorder.post_date < %s
				) AS orders ON orders.ID = order_total_meta.post_id
				WHERE order_total_meta.meta_key = '_order_total'",
			date( 'Y-m-01', current_time( 'timestamp' ) ),
			date( 'Y-m-d', strtotime( '+1 DAY', current_time( 'timestamp' ) ) )
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = $wpdb->get_var( apply_filters( 'woocommerce_subscription_dashboard_status_widget_renewal_revenue_query', $query ) );
			$update_cache = true;
		}

		$report_data->renewal_revenue = $cached_results[ $query_hash ];

		// Cancellation count this month
		$query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT wcsubs.ID) AS count
					FROM {$wpdb->posts} AS wcsubs
					JOIN {$wpdb->postmeta} AS wcsmeta_cancel
						ON wcsubs.ID = wcsmeta_cancel.post_id
					AND wcsmeta_cancel.meta_key = '_schedule_cancelled'
					AND wcsubs.post_status NOT IN ( 'trash', 'auto-draft' )
					AND CONVERT_TZ( wcsmeta_cancel.meta_value, '+00:00', %s ) BETWEEN %s AND %s",
			$site_timezone,
			date( 'Y-m-01', current_time( 'timestamp' ) ),
			date( 'Y-m-d', strtotime( '+1 DAY', current_time( 'timestamp' ) ) )
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = $wpdb->get_var( apply_filters( 'woocommerce_subscription_dashboard_status_widget_cancellation_query', $query ) );
			$update_cache = true;
		}

		$report_data->cancel_count = $cached_results[ $query_hash ];

		if ( $update_cache ) {
			set_transient( strtolower( __CLASS__ ), $cached_results, HOUR_IN_SECONDS );
		}

		return $report_data;
	}

	/**
	 * Add the subscription specific details to the bottom of the dashboard widget
	 *
	 * @since 2.1
	 */
	public static function add_stats_to_dashboard() {
		$report_data = self::get_data();

		?>
		<li class="signup-count">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date&range=month' ) ); ?>">
				<?php
				// translators: 1$: count, 2$ and 3$ are opening and closing strong tags, respectively.
				echo wp_kses_post( sprintf( _n( '%2$s%1$s signup%3$s subscription signups this month', '%2$s%1$s signups%3$s subscription signups this month', $report_data->signup_count, 'woocommerce-subscriptions' ), $report_data->signup_count, '<strong>', '</strong>' ) );
				?>
			</a>
		</li>
		<li class="signup-revenue">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date&range=month' ) ); ?>">
				<?php
				// translators: %s: formatted amount.
				echo wp_kses_post( sprintf( __( '%s signup revenue this month', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $report_data->signup_revenue ) . '</strong>' ) );
				?>
			</a>
		</li>
		<li class="renewal-count">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date&range=month' ) ); ?>">
				<?php
				// translators: 1$: count, 2$ and 3$ are opening and closing strong tags, respectively.
				echo wp_kses_post( sprintf( _n( '%2$s%1$s renewal%3$s subscription renewals this month', '%2$s%1$s renewals%3$s subscription renewals this month', $report_data->renewal_count, 'woocommerce-subscriptions' ), $report_data->renewal_count, '<strong>', '</strong>' ) );
				?>
			</a>
		</li>
		<li class="renewal-revenue">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date&range=month' ) ); ?>">
				<?php
				// translators: %s: formatted amount.
				echo wp_kses_post( sprintf( __( '%s renewal revenue this month', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $report_data->renewal_revenue ) . '</strong>' ) );
				?>
			</a>
		</li>
		<li class="cancel-count">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-reports&tab=subscriptions&report=subscription_events_by_date&range=month' ) ); ?>">
				<?php
				// translators: 1$: count, 2$ and 3$ are opening and closing strong tags, respectively.
				echo wp_kses_post( sprintf( _n( '%2$s%1$s cancellation%3$s subscription cancellations this month', '%2$s%1$s cancellations%3$s subscription cancellations this month', $report_data->cancel_count, 'woocommerce-subscriptions' ), $report_data->cancel_count, '<strong>', '</strong>' ) ); ?>
			</a>
		</li>
		<?php

	}

	/**
	 * Add the subscription specific details to the bottom of the dashboard widget
	 *
	 * @since 2.1
	 */
	public static function dashboard_scripts() {
		wp_enqueue_style( 'wcs-dashboard-report', WC_Subscriptions_Plugin::instance()->get_plugin_directory_url( 'assets/css/dashboard.css' ), array(), WC_Subscriptions_Plugin::instance()->get_library_version() );
	}

	/**
	 * Clears the cached report data.
	 *
	 * @since 3.0.10
	 */
	public static function clear_cache() {
		delete_transient( strtolower( __CLASS__ ) );
	}
}
