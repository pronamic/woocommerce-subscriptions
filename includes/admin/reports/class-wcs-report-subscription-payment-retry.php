<?php
/**
 * Subscriptions Admin Report - Subscription Events by Date
 *
 * Creates the subscription admin reports area.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 */
class WCS_Report_Subscription_Payment_Retry extends WC_Admin_Report {

	private $chart_colours = array();

	private $report_data;

	/**
	 * Get report data
	 * @return stdClass
	 */
	public function get_report_data() {

		if ( empty( $this->report_data ) ) {
			$this->query_report_data();
		}

		return $this->report_data;
	}

	/**
	 * Get all data needed for this report and store in the class
	 */
	private function query_report_data() {
		global $wpdb;

		// Convert from Decimal format(eg. 11.5) to a suitable format(eg. +11:30) for CONVERT_TZ() of SQL query.
		$offset  = get_option( 'gmt_offset' );
		$site_timezone = sprintf( '%+02d:%02d', (int) $offset, ( $offset - floor( $offset ) ) * 60 );
		$retry_date_in_local_time = $wpdb->prepare( "CONVERT_TZ(retries.date_gmt, '+00:00', %s)", $site_timezone );

		// We need to compute this on our own since 'group_by_query' from the parent class uses posts table column names.
		switch ( $this->chart_groupby ) {
			case 'day':
				$this->group_by_query = "YEAR({$retry_date_in_local_time}), MONTH({$retry_date_in_local_time}), DAY({$retry_date_in_local_time})";
				break;
			case 'month':
				$this->group_by_query = "YEAR({$retry_date_in_local_time}), MONTH({$retry_date_in_local_time})";
				break;
		}

		$this->report_data = new stdClass;

		$query_start_date = get_gmt_from_date( date( 'Y-m-d H:i:s', $this->start_date ) );
		$query_end_date   = get_gmt_from_date( date( 'Y-m-d H:i:s', wcs_strtotime_dark_knight( '+1 day', $this->end_date ) ) );

		// Get the sum of order totals for completed retries (i.e. retries which eventually succeeded in processing the failed payment)
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The $this->group_by_query clause is hard coded.
		$this->report_data->renewal_data = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT retries.retry_id) as count, MIN(retries.date_gmt) AS retry_date_gmt, MIN(%s) AS retry_date, SUM(meta_order_total.meta_value) AS renewal_totals
					FROM {$wpdb->posts} AS orders
					INNER JOIN {$wpdb->prefix}wcs_payment_retries AS retries ON ( orders.ID = retries.order_id )
					LEFT JOIN {$wpdb->postmeta} AS meta_order_total ON ( orders.ID = meta_order_total.post_id AND meta_order_total.meta_key = '_order_total' )
				WHERE retries.status = 'complete'
					AND retries.date_gmt >= %s
					AND retries.date_gmt < %s
				GROUP BY {$this->group_by_query}
				ORDER BY retry_date_gmt ASC
				",
				$retry_date_in_local_time,
				$query_start_date,
				$query_end_date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Get the counts for all retries, grouped by day or month and status
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The $this->group_by_query clause is hard coded.
		$this->report_data->retry_data = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT COUNT(DISTINCT retries.retry_id) AS count, retries.status AS status, MIN(retries.date_gmt) AS retry_date_gmt, MIN(%s) AS retry_date
					FROM {$wpdb->prefix}wcs_payment_retries AS retries
				WHERE retries.status IN ( 'complete', 'failed', 'pending' )
				AND retries.date_gmt >= %s
				AND retries.date_gmt < %s
				GROUP BY {$this->group_by_query}, status
				ORDER BY retry_date_gmt ASC
				",
				$retry_date_in_local_time,
				$query_start_date,
				$query_end_date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Total up the query data
		$this->report_data->retry_failed_count   = absint( array_sum( wp_list_pluck( wp_list_filter( $this->report_data->retry_data, array( 'status' => 'failed' ) ), 'count' ) ) );
		$this->report_data->retry_success_count  = absint( array_sum( wp_list_pluck( wp_list_filter( $this->report_data->retry_data, array( 'status' => 'complete' ) ), 'count' ) ) );
		$this->report_data->retry_pending_count  = absint( array_sum( wp_list_pluck( wp_list_filter( $this->report_data->retry_data, array( 'status' => 'pending' ) ), 'count' ) ) );

		$this->report_data->renewal_total_count  = absint( array_sum( wp_list_pluck( $this->report_data->renewal_data, 'count' ) ) );
		$this->report_data->renewal_total_amount = array_sum( wp_list_pluck( $this->report_data->renewal_data, 'renewal_totals' ) );
	}

	/**
	 * Get the legend for the main chart sidebar
	 * @return array
	 */
	public function get_chart_legend() {
		$legend = array();
		$data   = $this->get_report_data();

		$legend[] = array(
			// translators: %s: formatted amount.
			'title'            => sprintf( __( '%s renewal revenue recovered', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $data->renewal_total_amount ) . '</strong>' ),
			'placeholder'      => __( 'The total amount of revenue, including tax and shipping, recovered with the failed payment retry system for renewal orders with a failed payment.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['renewal_total'],
			'highlight_series' => 3,
		);

		$legend[] = array(
			// translators: %s: renewal count.
			'title'       => sprintf( __( '%s renewal orders', 'woocommerce-subscriptions' ), '<strong>' . $data->renewal_total_count . '</strong>' ),
			'placeholder' => __( 'The number of renewal orders which had a failed payment use the retry system.', 'woocommerce-subscriptions' ),
			'color'       => $this->chart_colours['renewal_count'],
		);

		$legend[] = array(
			// translators: %s: retry count.
			'title'            => sprintf( __( '%s retry attempts succeeded', 'woocommerce-subscriptions' ), '<strong>' . $data->retry_success_count . '</strong>' ),
			'placeholder'      => __( 'The number of renewal payment retries for this period which were able to process the payment which had previously failed one or more times.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['retry_success_count'],
			'highlight_series' => 0,
		);

		$legend[] = array(
			// translators: %s: retry count.
			'title'            => sprintf( __( '%s retry attempts failed', 'woocommerce-subscriptions' ), '<strong>' . $data->retry_failed_count . '</strong>' ),
			'placeholder'      => __( 'The number of renewal payment retries for this period which did not result in a successful payment.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['retry_failure_count'],
			'highlight_series' => 1,
		);

		$legend[] = array(
			// translators: %s: retry count.
			'title'            => sprintf( __( '%s retry attempts pending', 'woocommerce-subscriptions' ), '<strong>' . $data->retry_pending_count . '</strong>' ),
			'placeholder'      => __( 'The number of renewal payment retries not yet processed.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['retry_pending_count'],
			'highlight_series' => 2,
		);

		return $legend;
	}

	/**
	 * Output the report
	 */
	public function output_report() {

		$ranges = array(
			'year'       => __( 'Year', 'woocommerce-subscriptions' ),
			'last_month' => __( 'Last Month', 'woocommerce-subscriptions' ),
			'month'      => __( 'This Month', 'woocommerce-subscriptions' ),
			'7day'       => __( 'Last 7 Days', 'woocommerce-subscriptions' ),
		);

		$this->chart_colours = array(
			'retry_success_count' => '#5cc488',
			'retry_failure_count' => '#e74c3c',
			'retry_pending_count' => '#dbe1e3',
			'renewal_count'       => '#b1d4ea',
			'renewal_total'       => '#3498db',
		);

		$current_range = ! empty( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7day';

		if ( ! in_array( $current_range, array( 'custom', 'year', 'last_month', 'month', '7day' ) ) ) {
			$current_range = '7day';
		}

		$this->calculate_current_range( $current_range );

		include( WC()->plugin_path() . '/includes/admin/views/html-report-by-date.php' );
	}

	/**
	 * Output an export link
	 */
	public function get_export_button() {
		$current_range = ! empty( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7day';
		?>
		<a
			href="#"
			download="report-<?php echo esc_attr( $current_range ); ?>-<?php echo esc_attr( date_i18n( 'Y-m-d', current_time( 'timestamp' ) ) ); ?>.csv"
			class="export_csv"
			data-export="chart"
			data-xaxes="<?php esc_attr_e( 'Date', 'woocommerce-subscriptions' ); ?>"
			data-exclude_series="2"
			data-groupby="<?php echo esc_attr( $this->chart_groupby ); ?>"
		>
			<?php esc_attr_e( 'Export CSV', 'woocommerce-subscriptions' ); ?>
		</a>
		<?php
	}

	/**
	 * Get the main chart
	 *
	 * @return void
	 */
	public function get_main_chart() {
		global $wp_locale;

		// Prepare data for report
		$retry_count         = $this->prepare_chart_data( $this->report_data->retry_data, 'retry_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$retry_success_count = $this->prepare_chart_data( wp_list_filter( $this->report_data->retry_data, array( 'status' => 'complete' ) ), 'retry_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$retry_failure_count = $this->prepare_chart_data( wp_list_filter( $this->report_data->retry_data, array( 'status' => 'failed' ) ), 'retry_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$retry_pending_count = $this->prepare_chart_data( wp_list_filter( $this->report_data->retry_data, array( 'status' => 'pending' ) ), 'retry_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );

		$renewal_count       = $this->prepare_chart_data( $this->report_data->renewal_data, 'retry_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$renewal_amount      = $this->prepare_chart_data( $this->report_data->renewal_data, 'retry_date', 'renewal_totals', $this->chart_interval, $this->start_date, $this->chart_groupby );

		// Encode in json format
		$chart_data = array(
			'retry_count'         => array_values( $retry_count ),
			'retry_success_count' => array_values( $retry_success_count ),
			'retry_failure_count' => array_values( $retry_failure_count ),
			'retry_pending_count' => array_values( $retry_pending_count ),
			'renewal_count'       => array_values( $renewal_count ),
			'renewal_amount'      => array_map( array( $this, 'round_chart_totals' ), array_values( $renewal_amount ) ),
		);

		$timeformat = ( $this->chart_groupby == 'day' ? '%d %b' : '%b' );

		?>
		<div id="woocommerce_subscriptions_payment_retry_chart" class="chart-container">
			<div class="chart-placeholder main"></div>
		</div>
		<script type="text/javascript">

			var main_chart;

			jQuery(function(){
				var chart_data = JSON.parse( '<?php echo json_encode( $chart_data ); ?>' );

				var drawGraph = function( highlight ) {
					var series = [
						{
							label: "<?php echo esc_js( __( 'Successful retries', 'woocommerce-subscriptions' ) ) ?>",
							data: chart_data.retry_success_count,
							color: '<?php echo esc_js( $this->chart_colours['retry_success_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['retry_success_count'] ); ?>',
								order: 1,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.33,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Failed retries', 'woocommerce-subscriptions' ) ) ?>",
							data: chart_data.retry_failure_count,
							color: '<?php echo esc_js( $this->chart_colours['retry_failure_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['retry_failure_count'] ); ?>',
								order: 2,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.33,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Pending retries', 'woocommerce-subscriptions' ) ) ?>",
							data: chart_data.retry_pending_count,
							color: '<?php echo esc_js( $this->chart_colours['retry_pending_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['retry_pending_count'] ); ?>',
								order: 3,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.33,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Recovered Renewal Revenue', 'woocommerce-subscriptions' ) ) ?>",
							data: chart_data.renewal_amount,
							yaxis: 2,
							color: '<?php echo esc_js( $this->chart_colours['renewal_total'] ); ?>',
							points: {
								show: true,
								radius: 6,
								lineWidth: 4,
								fillColor: '#fff',
								fill: true
							},
							lines: {
								show: true,
								lineWidth: 5,
								fill: false
							},
							shadowSize: 0,
							<?php echo wp_kses_post( $this->get_currency_tooltip() ); ?>
						},
					];

					if ( highlight !== 'undefined' && series[ highlight ] ) {
						highlight_series = series[ highlight ];

						highlight_series.color = '#9c5d90';

						if ( highlight_series.bars ) {
							highlight_series.bars.fillColor = '#9c5d90';
						}

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
								timeformat: "<?php echo esc_js( $timeformat ) ?>",
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
									font: { color: "#aaa" }
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
									font: { color: "#aaa" }
								}
							],
							stack: true,
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
	 * Round our totals correctly.
	 * @param  string $amount
	 * @return string
	 */
	private function round_chart_totals( $amount ) {
		if ( is_array( $amount ) ) {
			return array( $amount[0], wc_format_decimal( $amount[1], wc_get_price_decimals() ) );
		} else {
			return wc_format_decimal( $amount, wc_get_price_decimals() );
		}
	}
}
