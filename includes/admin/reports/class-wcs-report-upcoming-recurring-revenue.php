<?php
/**
 * Subscriptions Admin Report - Upcoming Recurring Revenue
 *
 * Display the renewal order count and revenue that will be processed for all currently active subscriptions
 * for a given period of time in the future.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 */
class WCS_Report_Upcoming_Recurring_Revenue extends WC_Admin_Report {

	public $chart_colours = array();

	public $order_ids_recurring_totals = null;

	public $average_sales = 0;

	/**
	 * Get the legend for the main chart sidebar
	 * @return array
	 */
	public function get_chart_legend() {

		$this->order_ids_recurring_totals = $this->get_data();

		$total_renewal_revenue = 0;
		$total_renewal_count = 0;

		foreach ( $this->order_ids_recurring_totals as $r ) {

			$subscription_ids    = explode( ',', $r->subscription_ids );
			$billing_intervals   = explode( ',', $r->billing_intervals );
			$billing_periods     = explode( ',', $r->billing_periods );
			$scheduled_ends      = explode( ',', $r->scheduled_ends );
			$subscription_totals = explode( ',', $r->subscription_totals );

			// Loop through each returned subscription ID and check if there are any more renewals in this period.
			foreach ( $subscription_ids as $key => $subscription_id ) {

				$next_payment_timestamp = strtotime( $r->scheduled_date );

				//Remove the time part of the end date, if there is one
				if ( '0' !== $scheduled_ends[ $key ] ) {
					$scheduled_ends[ $key ] = date( 'Y-m-d', strtotime( $scheduled_ends[ $key ] ) );
				}

				if ( ! isset( $billing_intervals[ $key ] ) || ! isset( $billing_periods[ $key ] ) || ! in_array( $billing_periods[ $key ], array_keys( wcs_get_subscription_period_strings() ), true ) ) {
					continue;
				}

				// Keep calculating all the new payments until we hit the end date of the search
				do {

					$next_payment_timestamp = wcs_add_time( $billing_intervals[ $key ], $billing_periods[ $key ], $next_payment_timestamp );

					// If there are more renewals add them to the existing object or create a new one
					if ( $next_payment_timestamp <= $this->end_date && isset( $scheduled_ends[ $key ] ) && ( 0 == $scheduled_ends[ $key ] || $next_payment_timestamp < strtotime( $scheduled_ends[ $key ] ) ) ) {
						$update_key = date( 'Y-m-d', $next_payment_timestamp );

						if ( $next_payment_timestamp >= $this->start_date ) {

							if ( ! isset( $this->order_ids_recurring_totals[ $update_key ] ) ) {
								$this->order_ids_recurring_totals[ $update_key ] = new stdClass();
								$this->order_ids_recurring_totals[ $update_key ]->scheduled_date = $update_key;
								$this->order_ids_recurring_totals[ $update_key ]->recurring_total = 0;
								$this->order_ids_recurring_totals[ $update_key ]->total_renewals = 0;
							}
							$this->order_ids_recurring_totals[ $update_key ]->total_renewals  += 1;
							$this->order_ids_recurring_totals[ $update_key ]->recurring_total += $subscription_totals[ $key ];
						}
					}
				} while ( $next_payment_timestamp > 0 && $next_payment_timestamp <= $this->end_date
					&& isset( $scheduled_ends[ $key ] )
					&& ( 0 == $scheduled_ends[ $key ] || $next_payment_timestamp < strtotime( $scheduled_ends[ $key ] ) ) );
			}
		}

		// Sum up the total revenue and total renewal count separately to avoid adding up multiple times.
		foreach ( $this->order_ids_recurring_totals as $r ) {
			if ( strtotime( $r->scheduled_date ) >= $this->start_date && strtotime( $r->scheduled_date ) <= $this->end_date ) {

				$total_renewal_revenue += $r->recurring_total;
				$total_renewal_count   += $r->total_renewals;
			}
		}

		$legend = array();

		$this->average_sales = ( 0 != $total_renewal_count ? $total_renewal_revenue / $total_renewal_count : 0 );

		$legend[] = array(
			// translators: %s: formatted amount.
			'title'            => sprintf( __( '%s renewal income in this period', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $total_renewal_revenue ) . '</strong>' ),
			'placeholder'      => __( 'The sum of all the upcoming renewal orders, including items, fees, tax and shipping, for currently active subscriptions.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['renewals_amount'],
			'highlight_series' => 1,
		);
		$legend[] = array(
			// translators: %s: renewal count.
			'title'            => sprintf( __( '%s renewal orders', 'woocommerce-subscriptions' ), '<strong>' . $total_renewal_count . '</strong>' ),
			'placeholder'      => __( 'The number of upcoming renewal orders, for currently active subscriptions.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['renewals_count'],
			'highlight_series' => 0,
		);
		$legend[] = array(
			// translators: %s: formatted amount.
			'title' => sprintf( __( '%s average renewal amount', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $this->average_sales ) . '</strong>' ),
			'color' => $this->chart_colours['renewals_average'],
		);

		return $legend;
	}

	/**
	 * Get report data.
	 *
	 * @see WCS_Report_Cache_Manager::update_cache() - This method is called by the cache manager to update the cache.
	 *
	 * @param array $args The arguments for the report.
	 * @return stdClass[] - Upcoming renewal data grouped by scheduled date.
	 */
	public function get_data( $args = array() ) {
		global $wpdb;

		$default_args = array(
			'no_cache' => false,
		);

		$args = apply_filters( 'wcs_reports_upcoming_recurring_revenue_args', $args );
		$args = wp_parse_args( $args, $default_args );

		// Query based on whole days, not minutes/hours so that we can cache the query for at least 24 hours
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The $this->group_by_query clause is hard coded.
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT
					DATE(meta_next_payment.meta_value) as scheduled_date,
					SUM(subscriptions.total_amount) as recurring_total,
					COUNT(subscriptions.total_amount) as total_renewals,
					group_concat(subscriptions.ID) as subscription_ids,
					group_concat(meta_billing_interval.meta_value) as billing_intervals,
					group_concat(meta_billing_period.meta_value) as billing_periods,
					group_concat(meta_schedule_end.meta_value) as scheduled_ends,
					group_concat(subscriptions.total_amount) as subscription_totals
						FROM {$wpdb->prefix}wc_orders subscriptions
					LEFT JOIN {$wpdb->prefix}wc_orders_meta meta_next_payment
						ON subscriptions.ID = meta_next_payment.order_id
					LEFT JOIN {$wpdb->prefix}wc_orders_meta meta_billing_interval
						ON subscriptions.ID = meta_billing_interval.order_id
					LEFT JOIN {$wpdb->prefix}wc_orders_meta meta_billing_period
						ON subscriptions.ID = meta_billing_period.order_id
					LEFT JOIN {$wpdb->prefix}wc_orders_meta meta_schedule_end
						ON subscriptions.ID = meta_schedule_end.order_id
				WHERE subscriptions.type = 'shop_subscription'
					AND subscriptions.status = 'wc-active'
					AND meta_next_payment.meta_key = '_schedule_next_payment'
					AND ( ( meta_next_payment.meta_value < %s AND meta_schedule_end.meta_value = 0 ) OR ( meta_schedule_end.meta_value > %s AND meta_next_payment.meta_value < %s ) )
					AND meta_billing_interval.meta_key = '_billing_interval'
					AND meta_billing_period.meta_key = '_billing_period'
					AND meta_schedule_end.meta_key = '_schedule_end'
				GROUP BY {$this->group_by_query}
				ORDER BY meta_next_payment.meta_value ASC",
				date( 'Y-m-d', strtotime( '+1 DAY', $this->end_date ) ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Keep date formatting from original report.
				date( 'Y-m-d', $this->start_date ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Keep date formatting from original report.
				date( 'Y-m-d', strtotime( '+1 DAY', $this->end_date ) ) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Keep date formatting from original report.
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT
					DATE(meta_next_payment.meta_value) as scheduled_date,
					SUM(meta_order_total.meta_value) as recurring_total,
					COUNT(meta_order_total.meta_value) as total_renewals,
					group_concat(posts.ID) as subscription_ids,
					group_concat(meta_billing_interval.meta_value) as billing_intervals,
					group_concat(meta_billing_period.meta_value) as billing_periods,
					group_concat(meta_schedule_end.meta_value) as scheduled_ends,
					group_concat(meta_order_total.meta_value) as subscription_totals
						FROM {$wpdb->prefix}posts posts
					LEFT JOIN {$wpdb->prefix}postmeta meta_next_payment
						ON posts.ID = meta_next_payment.post_id
					LEFT JOIN {$wpdb->prefix}postmeta meta_order_total
						ON posts.ID = meta_order_total.post_id
					LEFT JOIN {$wpdb->prefix}postmeta meta_billing_interval
						ON posts.ID = meta_billing_interval.post_id
					LEFT JOIN {$wpdb->prefix}postmeta meta_billing_period
						ON posts.ID = meta_billing_period.post_id
					LEFT JOIN {$wpdb->prefix}postmeta meta_schedule_end
						ON posts.ID = meta_schedule_end.post_id
				WHERE posts.post_type = 'shop_subscription'
					AND posts.post_status = 'wc-active'
					AND meta_order_total.meta_key = '_order_total'
					AND meta_next_payment.meta_key = '_schedule_next_payment'
					AND ( ( meta_next_payment.meta_value < %s AND meta_schedule_end.meta_value = 0 ) OR ( meta_schedule_end.meta_value > %s AND meta_next_payment.meta_value < %s ) )
					AND meta_billing_interval.meta_key = '_billing_interval'
					AND meta_billing_period.meta_key = '_billing_period'
					AND meta_schedule_end.meta_key = '_schedule_end'
				GROUP BY {$this->group_by_query}
				ORDER BY meta_next_payment.meta_value ASC",
				date( 'Y-m-d', strtotime( '+1 DAY', $this->end_date ) ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Keep date formatting from original report.
				date( 'Y-m-d', $this->start_date ), // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Keep date formatting from original report.
				date( 'Y-m-d', strtotime( '+1 DAY', $this->end_date ) ) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Keep date formatting from original report.
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$cached_results = get_transient( strtolower( get_class( $this ) ) );
		$query_hash     = md5( $query );

		// Set a default value for cached results for PHP 8.2+ compatibility.
		if ( empty( $cached_results ) ) {
			$cached_results = array();
		}

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			$results = $wpdb->get_results( $query, OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			/**
			 * Filter the upcoming recurring revenue data.
			 *
			 * @param array $results The upcoming recurring revenue data.
			 * @param array $args The arguments for the report.
			 * @since 2.1.0
			 */
			$results = apply_filters( 'wcs_reports_upcoming_recurring_revenue_data', $results, $args );

			$cached_results[ $query_hash ] = $results;
			set_transient( strtolower( get_class( $this ) ), $cached_results, WEEK_IN_SECONDS );
		}

		return $cached_results[ $query_hash ];
	}

	/**
	 * Output the report
	 */
	public function output_report() {

		$ranges = array(
			'year'       => __( 'Next 12 Months', 'woocommerce-subscriptions' ),
			'month'      => __( 'Next 30 Days', 'woocommerce-subscriptions' ),
			'last_month' => __( 'Next Month', 'woocommerce-subscriptions' ), // misnomer to match historical reports keys, handy for caching
			'7day'       => __( 'Next 7 Days', 'woocommerce-subscriptions' ),
		);

		$this->chart_colours = array(
			'renewals_amount'  => '#1abc9c',
			'renewals_count'   => '#e67e22',
			'renewals_average' => '#d4d9dc',
		);

		$current_range = $this->get_current_range();

		$this->calculate_current_range( $current_range );

		include( WC()->plugin_path() . '/includes/admin/views/html-report-by-date.php' );

	}

	/**
	 * Output an export link
	 */
	public function get_export_button() {
		?>
		<a
			href="#"
			download="report-<?php echo esc_attr( $this->get_current_range() ); ?>-<?php echo esc_attr( date_i18n( 'Y-m-d', current_time( 'timestamp' ) ) ); ?>.csv"
			class="export_csv"
			data-export="chart"
			data-xaxes="<?php esc_attr_e( 'Date', 'woocommerce-subscriptions' ); ?>"
			data-exclude_series="2"
			data-groupby="<?php echo esc_attr( $this->chart_groupby ); ?>"
		>
			<?php esc_html_e( 'Export CSV', 'woocommerce-subscriptions' ); ?>
		</a>
		<?php
	}

	/**
	 * Get the main chart
	 * @return void
	 */
	public function get_main_chart() {
		global $wp_locale;

		// Prepare data for report
		$renewal_amounts     = $this->prepare_chart_data( $this->order_ids_recurring_totals, 'scheduled_date', 'recurring_total', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$renewal_counts      = $this->prepare_chart_data( $this->order_ids_recurring_totals, 'scheduled_date', 'total_renewals', $this->chart_interval, $this->start_date, $this->chart_groupby );

		$chart_data = array(
			'renewal_amounts' => array_values( $renewal_amounts ),
			'renewal_counts'  => array_values( $renewal_counts ),
		);

		?>
		<div id="woocommerce_subscriptions_upcoming_recurring_revenue_chart" class="chart-container">
			<div class="chart-placeholder main"></div>
		</div>
		<script type="text/javascript">

			var main_chart;

			jQuery(function(){
				var order_data = JSON.parse( '<?php echo json_encode( $chart_data ); ?>' );
				var drawGraph = function( highlight ) {
					var series = [
						{
							label: "<?php echo esc_js( __( 'Renewals count', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.renewal_counts,
							yaxis: 1,
							color: '<?php echo esc_js( $this->chart_colours['renewals_count'] ); ?>',
							points: { show: true, radius: 5, lineWidth: 3, fillColor: '#fff', fill: true },
							lines: { show: true, lineWidth: 4, fill: false },
							shadowSize: 0
						},
						{
							label: "<?php echo esc_js( __( 'Renewals amount', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.renewal_amounts,
							yaxis: 2,
							color: '<?php echo esc_js( $this->chart_colours['renewals_amount'] ); ?>',
							points: { show: true, radius: 5, lineWidth: 3, fillColor: '#fff', fill: true },
							lines: { show: true, lineWidth: 4, fill: false },
							shadowSize: 0,
							prepend_tooltip: "<?php echo esc_js( get_woocommerce_currency_symbol() ); ?>"
						}
					];

					if ( highlight !== 'undefined' && series[ highlight ] ) {
						highlight_series = series[ highlight ];

						highlight_series.color = '#9c5d90';

						if ( highlight_series.bars )
							highlight_series.bars.fillColor = '#9c5d90';

						if ( highlight_series.lines ) {
							highlight_series.lines.lineWidth = 5;
						}
					}

					main_chart = jQuery.plot(
						jQuery('.chart-placeholder.main'),
						series,
						{
							legend: {
								show: false
							},
							grid: {
								color: '#aaa',
								borderColor: 'transparent',
								borderWidth: 0,
								hoverable: true
							},
							xaxes: [ {
								color: '#aaa',
								position: "bottom",
								tickColor: 'transparent',
								mode: "time",
								timeformat: "<?php echo esc_js( ( $this->chart_groupby == 'day' ? '%d %b' : '%b' ) ); ?>",
								monthNames: <?php echo json_encode( array_values( $wp_locale->month_abbrev ) ) ?>,
								tickLength: 1,
								minTickSize: [1, "<?php echo esc_js( $this->chart_groupby ); ?>"],
								font: {
									color: "#aaa"
								}
							} ],
							yaxes: [
								{
									min: 0,
									minTickSize: 1,
									tickDecimals: 0,
									color: '#d4d9dc',
									font: {
										color: "#aaa"
									}
								},
								{
									position: "right",
									min: 0,
									tickDecimals: 2,
									tickFormatter: function (tick) {
										// Localise and format axis labels
										return jQuery.wcs_format_money(tick,0);
									},
									alignTicksWithAxis: 1,
									color: 'transparent',
									font: {
										color: "#aaa"
									}
								}
							],
						}
					);

					jQuery('.chart-placeholder').trigger( 'resize' );
				}

				drawGraph();

				jQuery('.highlight_series').on( 'hover',
					function() {
						drawGraph( jQuery(this).data('series') );
					},
					function() {
						drawGraph();
					}
				);
			});
		</script>
		<?php
	}

	/**
	 * Get the current range and calculate the start and end dates
	 *
	 * @param  string $current_range
	 */
	public function calculate_current_range( $current_range ) {
		switch ( $current_range ) {
			case 'custom':
				$this->start_date = strtotime( sanitize_text_field( $_GET['start_date'] ) );
				$this->end_date   = strtotime( 'midnight', strtotime( sanitize_text_field( $_GET['end_date'] ) ) );

				if ( ! $this->end_date ) {
					$this->end_date = current_time( 'timestamp' );
				}

				$interval = 0;
				$min_date = $this->start_date;
				while ( ( $min_date = wcs_add_months( $min_date, '1' ) ) <= $this->end_date ) {
					$interval ++;
				}

				// 3 months max for day view
				if ( $interval >= 3 ) {
					$this->chart_groupby  = 'month';
				} else {
					$this->chart_groupby  = 'day';
				}
			break;
			case 'year':
				$this->start_date    = strtotime( 'now', current_time( 'timestamp' ) );
				$this->end_date      = strtotime( 'last day', strtotime( '+1 YEAR', current_time( 'timestamp' ) ) );
				$this->chart_groupby = 'month';
			break;
			case 'last_month': // misnomer to match historical reports keys, handy for caching
				$this->start_date     = strtotime( date( 'Y-m-01', wcs_add_months( current_time( 'timestamp' ), '1' ) ) );
				$this->end_date       = strtotime( date( 'Y-m-t', $this->start_date ) );
				$this->chart_groupby  = 'day';
			break;
			case 'month':
				$this->start_date    = strtotime( 'now', current_time( 'timestamp' ) );
				$this->end_date      = wcs_add_months( current_time( 'timestamp' ), '1' );
				$this->chart_groupby = 'day';
			break;
			case '7day':
				$this->start_date    = strtotime( 'now', current_time( 'timestamp' ) );
				$this->end_date      = strtotime( '+7 days', current_time( 'timestamp' ) );
				$this->chart_groupby = 'day';
			break;
		}

		// Group by
		switch ( $this->chart_groupby ) {
			case 'day':
				$this->group_by_query = 'YEAR(meta_next_payment.meta_value), MONTH(meta_next_payment.meta_value), DAY(meta_next_payment.meta_value)';
				$this->chart_interval = ceil( max( 0, ( $this->end_date - $this->start_date ) / ( 60 * 60 * 24 ) ) );
				$this->barwidth       = 60 * 60 * 24 * 1000;
			break;
			case 'month':
				$this->group_by_query = 'YEAR(meta_next_payment.meta_value), MONTH(meta_next_payment.meta_value), DAY(meta_next_payment.meta_value)';
				$this->chart_interval = 0;
				$min_date             = $this->start_date;
				while ( ( $min_date = wcs_add_months( $min_date, '1' ) ) <= $this->end_date ) {
					$this->chart_interval ++;
				}
				$this->barwidth = 60 * 60 * 24 * 7 * 4 * 1000;
			break;
		}
	}

	/**
	 * Helper function to get the report's current range
	 */
	protected function get_current_range() {
		$current_range = ! empty( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7day';

		if ( ! in_array( $current_range, array( 'custom', 'year', 'month', 'last_month', '7day' ) ) ) {
			$current_range = '7day';
		}

		return $current_range;
	}

	/**
	 * Clears the cached query results.
	 *
	 * @see WCS_Report_Cache_Manager::update_cache() - This method is called by the cache manager before updating the cache.
	 *
	 * @since 3.0.10
	 */
	public function clear_cache() {
		delete_transient( strtolower( get_class( $this ) ) );
	}
}
