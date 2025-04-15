<?php
/**
 * Subscriptions Admin Report - Retention Rate
 *
 * Find the number of periods between when each subscription is created and ends or ended
 * then plot all subscriptions using this data to provide a curve of retention rates.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 */
class WCS_Report_Retention_Rate extends WC_Admin_Report {

	public $chart_colours = array();

	private $report_data;

	/**
	 * Get report data
	 *
	 * @since 2.1
	 * @return array
	 */
	public function get_report_data() {
		if ( empty( $this->report_data ) ) {
			$this->query_report_data();
		}
		return $this->report_data;
	}

	/**
	 * Get the number of periods each subscription has between sign-up and end.
	 *
	 * This function uses a new "living" and "age" terminology to refer to the time between when a subscription
	 * is created and when it ends (i.e. expires or is cancelled). The function can't use "active" because the
	 * subscription may not have been active all of that time. Instead, it may have been on-hold for part of it.
	 *
	 * @since 2.1
	 * @return null
	 */
	private function query_report_data() {
		global $wpdb;

		$this->report_data = new stdClass;

		// First, let's find the age of the longest living subscription in days
		$oldest_subscription_age_in_days = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(DATEDIFF(CAST(postmeta.meta_value AS DATETIME),posts.post_date_gmt)) as age_in_days
			 FROM {$wpdb->prefix}posts posts
				LEFT JOIN {$wpdb->prefix}postmeta postmeta ON posts.ID = postmeta.post_id
			 WHERE posts.post_type = 'shop_subscription'
				AND postmeta.meta_key = %s
				AND postmeta.meta_value <> '0'
			 ORDER BY age_in_days DESC
			 LIMIT 1",
			wcs_get_date_meta_key( 'end' )
		) );

		// Now determine what interval to use based on that length
		if ( $oldest_subscription_age_in_days > 365 ) {
			$this->report_data->interval_period = 'month';
		} elseif ( $oldest_subscription_age_in_days > 182 ) {
			$this->report_data->interval_period = 'week';
		} else {
			$this->report_data->interval_period = 'day';
		}

		// Use the number of days in the chosen interval period to determine how many periods between each start/end date
		$days_in_interval_period = wcs_get_days_in_cycle( $this->report_data->interval_period, 1 );

		// Find the number of these periods in the longest living subscription
		$oldest_subscription_age = floor( $oldest_subscription_age_in_days / $days_in_interval_period );

		// Now get all subscriptions, not just those that have ended, and find out how long they have lived (or if they haven't ended yet, consider them as being alive for one period longer than the longest living subsription)
		$subscription_ages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					IF(COALESCE(cancelled_date.meta_value,end_date.meta_value) <> '0',CEIL(DATEDIFF(CAST(COALESCE(cancelled_date.meta_value,end_date.meta_value) AS DATETIME),posts.post_date_gmt)/%d),%d) as periods_active,
					COUNT(posts.ID) as count
				FROM {$wpdb->prefix}posts posts
					LEFT JOIN {$wpdb->prefix}postmeta cancelled_date
						ON posts.ID = cancelled_date.post_id
						AND cancelled_date.meta_key = %s
						AND cancelled_date.meta_value <> '0'
					LEFT JOIN {$wpdb->prefix}postmeta end_date
						ON posts.ID = end_date.post_id
						AND end_date.meta_key = %s
				WHERE posts.post_type = 'shop_subscription'
					AND posts.post_status NOT IN( 'wc-pending', 'trash' )
				GROUP BY periods_active
				ORDER BY periods_active ASC",
				$days_in_interval_period,
				( $oldest_subscription_age + 1 ), // Consider living subscriptions as being alive for one period longer than the longest living subscription
				wcs_get_date_meta_key( 'cancelled' ), // If a subscription has a cancelled date, use that to determine a more accurate lifetime
				wcs_get_date_meta_key( 'end' ) // Otherwise, we want to use the end date for subscriptions that have expired
			),
			OBJECT_K
		);


		$this->report_data->total_subscriptions  = $this->report_data->unended_subscriptions = absint( array_sum( wp_list_pluck( $subscription_ages, 'count' ) ) );
		$this->report_data->living_subscriptions = array();

		// At day zero, no subscriptions have ended
		$this->report_data->living_subscriptions[0] = $this->report_data->total_subscriptions;

		// Fill out the report data to provide a smooth curve
		for ( $i = 0; $i <= $oldest_subscription_age; $i++ ) {

			// We want to push the the array keys ahead by one to make sure out the 0 index represents the total subscriptions
			$periods_after_sign_up = $i + 1;

			// Only reduce the number of living subscriptions when we have a new number for a given period as that indicates a new set of subscriptions have ended
			if ( isset( $subscription_ages[ $i ] ) ) {
				$this->report_data->living_subscriptions[ $periods_after_sign_up ] = $this->report_data->living_subscriptions[ $i ] - $subscription_ages[ $i ]->count;
				$this->report_data->unended_subscriptions                         -= $subscription_ages[ $i ]->count;
			} else {
				$this->report_data->living_subscriptions[ $periods_after_sign_up ] = $this->report_data->living_subscriptions[ $i ];
			}
		}
	}

	/**
	 * Output the report
	 *
	 * Use a custom report as we don't need the date filters provided by the WooCommerce html-report-by-date.php template.
	 *
	 * @since 2.1
	 * @return null
	 */
	public function output_report() {
		include( WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'includes/admin/views/html-report-by-period.php' ) );
	}

	/**
	 * Output the HTML and JavaScript to plot the chart
	 *
	 * @since 2.1
	 * @return null
	 */
	public function get_main_chart() {

		$this->get_report_data();

		$data_to_plot = array();

		foreach ( $this->report_data->living_subscriptions as $periods_since_sign_up => $living_subscription_count ) {
			$data_to_plot[] = array(
				absint( $periods_since_sign_up ),
				absint( $living_subscription_count ),
			);
		}

		switch ( $this->report_data->interval_period ) {
			case 'day':
				$x_axes_label = _x( 'Number of days after sign-up', 'X axis label on retention rate graph', 'woocommerce-subscriptions' );
				break;
			case 'week':
				$x_axes_label = _x( 'Number of weeks after sign-up', 'X axis label on retention rate graph', 'woocommerce-subscriptions' );
				break;
			case 'month':
				$x_axes_label = _x( 'Number of months after sign-up', 'X axis label on retention rate graph', 'woocommerce-subscriptions' );
				break;
		}

		?>
		<div class="chart-container" id="woocommerce_subscriptions_retention_chart">
			<div class="chart-placeholder main"></div>
		</div>
		<script type="text/javascript">

			var main_chart;

			jQuery(function(){
				var subscription_lifespans = JSON.parse( '<?php echo json_encode( $data_to_plot ); ?>' ),
					unended_subscriptions  = <?php echo esc_js( $this->report_data->unended_subscriptions ); ?>;

				var drawGraph = function( highlight ) {

					var series = [
						{
							data: subscription_lifespans,
							color: '#5da5da',
							points: { show: true, radius: 4, lineWidth: 3, fillColor: '#efefef', fill: true },
							lines: { show: true, lineWidth: 4, fill: false },
							shadowSize: 0
						},
					];

					main_chart = jQuery.plot(
						jQuery('.chart-placeholder.main'),
						series,
						{
							legend: {
								show: false
							},
							axisLabels: {
								show: true
							},
							grid: {
								color: '#aaa',
								borderColor: 'transparent',
								borderWidth: 0,
								hoverable: true,
								markings: [ {
									xaxis: { from: 1, to: 1 },
									yaxis: { from: 1, to: 1 },
									color: "#ccc"
								} ]
							},
							xaxes: [ {
								color: '#aaa',
								position: "bottom",
								tickDecimals: 0,
								axisLabel: "<?php echo esc_js( $x_axes_label ); ?>",
								axisLabelPadding: 18,
								font: {
									color: "#aaa"
								}
							} ],
							yaxes: [ {
								min: unended_subscriptions - 1, // exaggerate change by only plotting between total subscription count and unended count
								minTickSize: 1,
								tickDecimals: 0,
								color: '#d4d9dc',
								axisLabel: "<?php echo esc_js( __( 'Unended Subscription Count', 'woocommerce-subscriptions' ) ); ?>",
								axisLabelPadding: 18,
								font: {
									color: "#aaa"
								}
							} ],
						}
					);

					jQuery('.chart-placeholder').trigger( 'resize' );
				}

				drawGraph();
			});
		</script>
		<?php
	}
}
