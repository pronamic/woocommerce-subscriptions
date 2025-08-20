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
	 * Tracks whether the cache should be updated after generating report data.
	 *
	 * @var bool
	 */
	private static $should_update_cache = false;

	/**
	 * Cached report results for performance optimization.
	 *
	 *
	 * @var array
	 */
	private static $cached_report_results = array();

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
	 *
	 * @see WCS_Report_Cache_Manager::update_cache() - This method is called by the cache manager to update the cache.
	 *
	 * @param array $args The arguments for the report.
	 * @return object The report data.
	 */
	public static function get_data( $args = array() ) {
		$default_args = array(
			'no_cache' => false,
		);

		$args = apply_filters( 'wcs_reports_subscription_dashboard_args', $args );
		$args = wp_parse_args( $args, $default_args );

		self::init_cache();

		// Use current month as default date range.
		$start_date = $args['start_date'] ?? date( 'Y-m-01', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date,WordPress.DateTime.CurrentTimeTimestamp.Requested -- Keep default date values for backward compatibility.
		$end_date   = $args['end_date'] ?? date( 'Y-m-d', strtotime( '+1 DAY', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date,WordPress.DateTime.CurrentTimeTimestamp.Requested -- Keep default date values for backward compatibility.

		$report_data                  = new stdClass();
		$report_data->signup_count    = self::fetch_signup_count( $start_date, $end_date, $args['no_cache'] );
		$report_data->signup_revenue  = self::fetch_signup_revenue( $start_date, $end_date, $args['no_cache'] );
		$report_data->renewal_count   = self::fetch_renewal_count( $start_date, $end_date, $args['no_cache'] );
		$report_data->renewal_revenue = self::fetch_renewal_revenue( $start_date, $end_date, $args['no_cache'] );
		$report_data->cancel_count    = self::fetch_cancel_count( $start_date, $end_date, $args['no_cache'] );

		if ( self::$should_update_cache ) {
			set_transient( strtolower( __CLASS__ ), self::$cached_report_results, HOUR_IN_SECONDS );
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
				echo wp_kses_post( sprintf( _n( '%2$s%1$s cancellation%3$s subscription cancellations this month', '%2$s%1$s cancellations%3$s subscription cancellations this month', $report_data->cancel_count, 'woocommerce-subscriptions' ), $report_data->cancel_count, '<strong>', '</strong>' ) );
				?>
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
	 * @see WCS_Report_Cache_Manager::update_cache() - This method is called by the cache manager before updating the cache.
	 *
	 * @since 3.0.10
	 */
	public static function clear_cache() {
		delete_transient( strtolower( __CLASS__ ) );
		self::$should_update_cache   = false;
		self::$cached_report_results = array();
	}

	/**
	 * Fetch the signup count for the dashboard.
	 *
	 * @param string $start_date The start date.
	 * @param string $end_date The end date.
	 * @param bool   $force_cache_update Whether to force update the cache.
	 * @return int The signup count.
	 */
	private static function fetch_signup_count( $start_date, $end_date, $force_cache_update = false ) {
		global $wpdb;

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT COUNT(DISTINCT wcsubs.ID) AS count
					FROM {$wpdb->prefix}wc_orders AS wcsubs
					INNER JOIN {$wpdb->prefix}wc_orders AS wcorder
						ON wcsubs.parent_order_id = wcorder.ID
					WHERE wcorder.type IN ( 'shop_order' )
						AND wcsubs.type IN ( 'shop_subscription' )
						AND wcorder.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
						AND wcorder.date_created_gmt >= %s
						AND wcorder.date_created_gmt < %s",
				$start_date,
				$end_date
			);
		} else {
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
				$start_date,
				$end_date
			);
		}

		$query_hash = md5( $query );

		if ( $force_cache_update || ! isset( self::$cached_report_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			/**
			 * Filter the query for the signup count.
			 *
			 * @param string $query The query to execute.
			 * @return string The filtered query.
			 *
			 * @since 3.0.10
			 */
			$query         = apply_filters( 'woocommerce_subscription_dashboard_status_widget_signup_query', $query );
			$query_results = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			self::cache_report_results( $query_hash, $query_results );
		}

		return self::$cached_report_results[ $query_hash ];
	}

	/**
	 * Fetch the signup revenue for the dashboard.
	 *
	 * @param string $start_date The start date.
	 * @param string $end_date The end date.
	 * @param bool   $force_cache_update Whether to force update the cache.
	 * @return float The signup revenue.
	 */
	private static function fetch_signup_revenue( $start_date, $end_date, $force_cache_update = false ) {
		global $wpdb;

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT SUM(parent_orders.total_amount)
				FROM {$wpdb->prefix}wc_orders AS subscripitons
				INNER JOIN {$wpdb->prefix}wc_orders AS parent_orders
					ON subscripitons.parent_order_id = parent_orders.ID
				WHERE parent_orders.type IN ( 'shop_order' )
					AND subscripitons.type IN ( 'shop_subscription' )
					AND parent_orders.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
					AND parent_orders.date_created_gmt >= %s
					AND parent_orders.date_created_gmt < %s					
				",
				$start_date,
				$end_date
			);
		} else {
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
				$start_date,
				$end_date
			);
		}

		$query_hash = md5( $query );

		if ( $force_cache_update || ! isset( self::$cached_report_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			/**
			 * Filter the query for the signup revenue.
			 *
			 * @param string $query The query to execute.
			 * @return string The filtered query.
			 *
			 * @since 3.0.10
			 */
			$query         = apply_filters( 'woocommerce_subscription_dashboard_status_widget_signup_revenue_query', $query );
			$query_results = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			self::cache_report_results( $query_hash, $query_results );
		}

		return self::$cached_report_results[ $query_hash ];
	}

	/**
	 * Fetch the renewal count for the dashboard.
	 *
	 * @param string $start_date The start date.
	 * @param string $end_date The end date.
	 * @param bool   $force_cache_update Whether to force update the cache.
	 * @return int The renewal count.
	 */
	private static function fetch_renewal_count( $start_date, $end_date, $force_cache_update = false ) {
		global $wpdb;

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT COUNT(DISTINCT wcorder.ID) AS count
					FROM {$wpdb->prefix}wc_orders AS wcorder
					INNER JOIN {$wpdb->prefix}wc_orders_meta AS meta__subscription_renewal
						ON (
							wcorder.id = meta__subscription_renewal.order_id
							AND
							meta__subscription_renewal.meta_key = '_subscription_renewal'
						)
					WHERE wcorder.type IN ( 'shop_order' )
						AND wcorder.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
						AND wcorder.date_created_gmt >= %s
						AND wcorder.date_created_gmt < %s",
				$start_date,
				$end_date
			);
		} else {
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
				$start_date,
				$end_date
			);
		}

		$query_hash = md5( $query );

		if ( $force_cache_update || ! isset( self::$cached_report_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			/**
			 * Filter the query for the renewal count.
			 *
			 * @param string $query The query to execute.
			 * @return string The filtered query.
			 *
			 * @since 3.0.10
			 */
			$query         = apply_filters( 'woocommerce_subscription_dashboard_status_widget_renewal_query', $query );
			$query_results = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			self::cache_report_results( $query_hash, $query_results );
		}

		return self::$cached_report_results[ $query_hash ];
	}

	/**
	 * Fetch the renewal revenue for the dashboard.
	 *
	 * @param string $start_date The start date.
	 * @param string $end_date The end date.
	 * @param bool   $force_cache_update Whether to force update the cache.
	 * @return float The renewal revenue.
	 */
	private static function fetch_renewal_revenue( $start_date, $end_date, $force_cache_update = false ) {
		global $wpdb;

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT SUM(wcorder.total_amount)
				FROM {$wpdb->prefix}wc_orders AS wcorder
				INNER JOIN {$wpdb->prefix}wc_orders_meta AS meta__subscription_renewal
					ON (
						wcorder.id = meta__subscription_renewal.order_id
						AND
						meta__subscription_renewal.meta_key = '_subscription_renewal'
					)
				WHERE wcorder.type IN ( 'shop_order' )
					AND wcorder.status IN ( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' )
					AND wcorder.date_created_gmt >= %s
					AND wcorder.date_created_gmt < %s",
				$start_date,
				$end_date
			);
		} else {
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
				$start_date,
				$end_date
			);
		}

		$query_hash = md5( $query );

		if ( $force_cache_update || ! isset( self::$cached_report_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			/**
			 * Filter the query for the renewal revenue.
			 *
			 * @param string $query The query to execute.
			 * @return string The filtered query.
			 *
			 * @since 3.0.10
			 */
			$query         = apply_filters( 'woocommerce_subscription_dashboard_status_widget_renewal_revenue_query', $query );
			$query_results = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			self::cache_report_results( $query_hash, $query_results );
		}

		return self::$cached_report_results[ $query_hash ];
	}

	/**
	 * Fetch the cancellation count for the dashboard.
	 *
	 * @param string $start_date The start date.
	 * @param string $end_date The end date.
	 * @param bool   $force_cache_update Whether to force update the cache.
	 * @return int The cancellation count.
	 */
	private static function fetch_cancel_count( $start_date, $end_date, $force_cache_update = false ) {
		global $wpdb;

		$offset = get_option( 'gmt_offset' );

		// Use this once it is merged - wcs_get_gmt_offset_string();
		// Convert from Decimal format(eg. 11.5) to a suitable format(eg. +11:30) for CONVERT_TZ() of SQL query.
		$site_timezone = sprintf( '%+02d:%02d', (int) $offset, ( $offset - floor( $offset ) ) * 60 );

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT COUNT(DISTINCT wcsubs.ID) AS count
						FROM {$wpdb->prefix}wc_orders AS wcsubs
						JOIN {$wpdb->prefix}wc_orders_meta AS wcsmeta_cancel
							ON wcsubs.ID = wcsmeta_cancel.order_id
						AND wcsmeta_cancel.meta_key = '_schedule_cancelled'
						AND wcsubs.status NOT IN ( 'trash', 'auto-draft' )
						AND CONVERT_TZ( wcsmeta_cancel.meta_value, '+00:00', %s ) BETWEEN %s AND %s",
				$site_timezone,
				$start_date,
				$end_date
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT COUNT(DISTINCT wcsubs.ID) AS count
						FROM {$wpdb->posts} AS wcsubs
						JOIN {$wpdb->postmeta} AS wcsmeta_cancel
							ON wcsubs.ID = wcsmeta_cancel.post_id
						AND wcsmeta_cancel.meta_key = '_schedule_cancelled'
						AND wcsubs.post_status NOT IN ( 'trash', 'auto-draft' )
						AND CONVERT_TZ( wcsmeta_cancel.meta_value, '+00:00', %s ) BETWEEN %s AND %s",
				$site_timezone,
				$start_date,
				$end_date
			);
		}

		$query_hash = md5( $query );

		if ( $force_cache_update || ! isset( self::$cached_report_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			/**
			 * Filter the query for the cancellation count.
			 *
			 * @param string $query The query to execute.
			 * @return string The filtered query.
			 *
			 * @since 3.0.10
			 */
			$query         = apply_filters( 'woocommerce_subscription_dashboard_status_widget_cancellation_query', $query );
			$query_results = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			self::cache_report_results( $query_hash, $query_results );
		}

		return self::$cached_report_results[ $query_hash ];
	}

	/**
	 * Initialize cache for report results.
	 *
	 * @return void
	 */
	private static function init_cache() {
		self::$should_update_cache   = false;
		self::$cached_report_results = get_transient( strtolower( __CLASS__ ) );

		// Set a default value for cached results for PHP 8.2+ compatibility.
		if ( empty( self::$cached_report_results ) ) {
			self::$cached_report_results = array();
		}
	}

	/**
	 * Cache report results for performance optimization.
	 *
	 * @param string $query_hash   The hash of the query for caching.
	 * @param array  $report_data  The report data to cache.
	 * @return void
	 */
	private static function cache_report_results( $query_hash, $report_data ) {
		self::$cached_report_results[ $query_hash ] = $report_data;
		self::$should_update_cache                  = true;
	}
}
