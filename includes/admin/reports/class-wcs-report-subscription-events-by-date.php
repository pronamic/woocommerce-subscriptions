<?php
/**
 * Subscriptions Admin Report - Subscription Events by Date
 *
 * Display important historical data for subscription revenue and events, like switches and cancellations.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 */
class WCS_Report_Subscription_Events_By_Date extends WC_Admin_Report {

	public $chart_colours = array();

	private $report_data;

	private $generating_report;

	/**
	 * Sets the query hash for saving the results to enable listing later.
	 *
	 * @since 2.6.0
	 * @param array $query The report query clause array.
	 * @return array $query
	 */
	public function set_query_hash( $query ) {

		if ( in_array( $this->generating_report, array( 'new_subscriptions', 'renewals', 'resubscribes', 'switches' ) ) ) {
			$this->report_data->{$this->generating_report . '_query_hash'} = md5( 'get_results' . implode( ' ', $query ) );
		}

		return $query;
	}

	/**
	 * Get report data
	 * @return array
	 */
	public function get_report_data() {

		if ( empty( $this->report_data ) ) {
			$this->get_data();
		}

		return $this->report_data;
	}

	/**
	 * Get all data needed for this report and store in the class
	 */
	public function get_data( $args = array() ) {
		global $wpdb;

		$update_cache = false;
		$default_args = array(
			'no_cache'     => false,
			'order_status' => apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ),
		);

		$args = apply_filters( 'wcs_reports_subscription_events_args', $args );
		$args = wp_parse_args( $args, $default_args );

		$query_end_date = date( 'Y-m-d', strtotime( '+1 DAY', $this->end_date ) );
		$offset         = get_option( 'gmt_offset' );

		// Convert from Decimal format(eg. 11.5) to a suitable format(eg. +11:30) for CONVERT_TZ() of SQL query.
		$site_timezone = sprintf( '%+02d:%02d', (int) $offset, ( $offset - floor( $offset ) ) * 60 );

		$this->report_data = new stdClass;

		// While generating report data via get_order_report_data(), hook in to set the query hash so we can cache the results.
		add_filter( 'woocommerce_reports_get_order_report_query', array( $this, 'set_query_hash' ) );

		$this->generating_report                   = 'new_subscriptions';
		$this->report_data->new_subscriptions_data = (array) $this->get_order_report_data(
			array(
				'data'         => array(
					'ID'        => array(
						'type'     => 'post_data',
						'function' => 'COUNT',
						'name'     => 'count',
						'distinct' => true,
					),
					'id'        => array(
						'type'     => 'post_data',
						'function' => 'GROUP_CONCAT',
						'name'     => 'subscription_ids',
						'distinct' => true,
					),
					'post_date' => array(
						'type'     => 'post_data',
						'function' => '',
						'name'     => 'post_date',
					),
				),
				'where'        => array(
					'post_status' => array(
						'key'      => 'post_status',
						'operator' => 'NOT IN',
						'value'    => array( 'trash', 'auto-draft' ),
					),
				),
				'group_by'     => $this->group_by_query,
				'order_status' => array(),
				'order_by'     => 'post_date ASC',
				'query_type'   => 'get_results',
				'filter_range' => true,
				'order_types'  => array( 'shop_subscription' ),
				'nocache'      => $args['no_cache'],
			)
		);

		$this->generating_report         = 'renewals';
		$this->report_data->renewal_data = (array) $this->get_order_report_data(
			array(
				'data'         => array(
					'ID'                    => array(
						'type'     => 'post_data',
						'function' => 'COUNT',
						'name'     => 'count',
						'distinct' => true,
					),
					'id'                    => array(
						'type'     => 'post_data',
						'function' => 'GROUP_CONCAT',
						'name'     => 'order_ids',
						'distinct' => true,
					),
					'post_date'             => array(
						'type'     => 'post_data',
						'function' => '',
						'name'     => 'post_date',
					),
					'_subscription_renewal' => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => 'renewal_orders',
					),
					'_order_total'          => array(
						'type'      => 'meta',
						'function'  => 'SUM',
						'name'      => 'renewal_totals',
						'join_type' => 'LEFT',   // To avoid issues if there is no renewal_total meta
					),
				),
				'where'        => array(
					'post_status' => array(
						'key'      => 'post_status',
						'operator' => 'NOT IN',
						'value'    => array( 'trash', 'auto-draft' ),
					),
				),
				'group_by'     => $this->group_by_query,
				'order_status' => $args['order_status'],
				'order_by'     => 'post_date ASC',
				'query_type'   => 'get_results',
				'filter_range' => true,
				'order_types'  => wc_get_order_types( 'order-count' ),
				'nocache'      => $args['no_cache'],
			)
		);

		$this->generating_report             = 'resubscribes';
		$this->report_data->resubscribe_data = (array) $this->get_order_report_data(
			array(
				'data'         => array(
					'ID'                        => array(
						'type'     => 'post_data',
						'function' => 'COUNT',
						'name'     => 'count',
						'distinct' => true,
					),
					'id'                        => array(
						'type'     => 'post_data',
						'function' => 'GROUP_CONCAT',
						'name'     => 'order_ids',
						'distinct' => true,
					),
					'post_date'                 => array(
						'type'     => 'post_data',
						'function' => '',
						'name'     => 'post_date',
					),
					'_subscription_resubscribe' => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => 'resubscribe_orders',
					),
					'_order_total'              => array(
						'type'      => 'meta',
						'function'  => 'SUM',
						'name'      => 'resubscribe_totals',
						'join_type' => 'LEFT', // To avoid issues if there is no resubscribe_total meta
					),
				),
				'where'        => array(
					'post_status' => array(
						'key'      => 'post_status',
						'operator' => 'NOT IN',
						'value'    => array( 'trash', 'auto-draft' ),
					),
				),
				'group_by'     => $this->group_by_query,
				'order_status' => $args['order_status'],
				'order_by'     => 'post_date ASC',
				'query_type'   => 'get_results',
				'filter_range' => true,
				'order_types'  => wc_get_order_types( 'order-count' ),
				'nocache'      => $args['no_cache'],
			)
		);

		$this->generating_report        = 'switches';
		$this->report_data->switch_data = (array) $this->get_order_report_data(
			array(
				'data'         => array(
					'ID'                   => array(
						'type'     => 'post_data',
						'function' => 'COUNT',
						'name'     => 'count',
						'distinct' => true,
					),
					'id'                   => array(
						'type'     => 'post_data',
						'function' => 'GROUP_CONCAT',
						'name'     => 'order_ids',
						'distinct' => true,
					),
					'post_date'            => array(
						'type'     => 'post_data',
						'function' => '',
						'name'     => 'post_date',
					),
					'_subscription_switch' => array(
						'type'     => 'meta',
						'function' => '',
						'name'     => 'switch_orders',
					),
					'_order_total'         => array(
						'type'      => 'meta',
						'function'  => 'SUM',
						'name'      => 'switch_totals',
						'join_type' => 'LEFT',   // To avoid issues if there is no switch_total meta
					),
				),
				'where'        => array(
					'post_status' => array(
						'key'      => 'post_status',
						'operator' => 'NOT IN',
						'value'    => array( 'trash', 'auto-draft' ),
					),
				),
				'group_by'     => $this->group_by_query,
				'order_status' => $args['order_status'],
				'order_by'     => 'post_date ASC',
				'query_type'   => 'get_results',
				'filter_range' => true,
				'order_types'  => wc_get_order_types( 'order-count' ),
				'nocache'      => $args['no_cache'],
			)
		);

		// We've finished generating report data via get_order_report_data() so unhook our query hash flagging function.
		unset( $this->generating_report );
		remove_filter( 'woocommerce_reports_get_order_report_query', array( $this, 'set_query_hash' ) );

		$cached_results = get_transient( strtolower( get_class( $this ) ) );

		// Set a default value for cached results for PHP 8.2+ compatibility.
		if ( empty( $cached_results ) ) {
			$cached_results = [];
		}

		// Check if we need to update the cache with the query results from the figures generated by get_order_report_data().
		foreach ( array( 'new_subscriptions' => 'new_subscriptions', 'renewals' => 'renewal', 'resubscribes' => 'resubscribe', 'switches' => 'switch' ) as $report => $property_key ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$query_hash = $this->report_data->{"{$report}_query_hash"};
			if ( ! isset( $cached_results[ $query_hash ] ) ) {
				$cached_results[ $query_hash ] = $this->report_data->{"{$property_key}_data"};
				$update_cache = true;
			}
		}

		$statuses                 = wcs_maybe_prefix_key( $args['order_status'], 'wc-' );
		$order_types              = wc_get_order_types( 'order-count' );
		$status_placeholders      = implode( ', ', array_fill( 0, count( $args['order_status'] ), '%s' ) );
		$order_types_placeholders = implode( ', ', array_fill( 0, count( $order_types ), '%s' ) );

		/*
		 * New subscription orders
		 */
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ignored for allowing interpolation in the IN statements.
		$query = $wpdb->prepare(
			"SELECT SUM(subscriptions.count) as count,
				order_posts.post_date as post_date,
				SUM(order_total_post_meta.meta_value) as signup_totals,
				GROUP_CONCAT( DISTINCT subscriptions.ids ) as subscription_ids
			FROM {$wpdb->posts} AS order_posts
			INNER JOIN (
				SELECT COUNT(DISTINCT(subscription_posts.ID)) as count,
					subscription_posts.post_parent as order_id,
					GROUP_CONCAT( subscription_posts.ID ) as ids
					FROM {$wpdb->posts} as subscription_posts
				WHERE subscription_posts.post_type = 'shop_subscription'
					AND subscription_posts.post_date >= %s
					AND subscription_posts.post_date < %s
					AND subscription_posts.post_status NOT IN ( 'trash', 'auto-draft' )
				GROUP BY order_id
			) AS subscriptions ON subscriptions.order_id = order_posts.ID
			LEFT JOIN {$wpdb->postmeta} AS order_total_post_meta
				ON order_posts.ID = order_total_post_meta.post_id
			WHERE  order_posts.post_type IN ( {$order_types_placeholders} )
				AND order_posts.post_status IN ( {$status_placeholders} )
				AND order_posts.post_date >= %s
				AND order_posts.post_date < %s
				AND order_total_post_meta.meta_key = '_order_total'
			GROUP BY YEAR(order_posts.post_date), MONTH(order_posts.post_date), DAY(order_posts.post_date)
			ORDER BY post_date ASC",
			array_merge(
				[ date( 'Y-m-d', $this->start_date ), $query_end_date ],
				$order_types,
				$statuses,
				[ date( 'Y-m-d', $this->start_date ), $query_end_date ]
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = apply_filters( 'wcs_reports_subscription_events_sign_up_data', (array) $wpdb->get_results( $query ), $args );
			$update_cache = true;
		}

		$this->report_data->signup_data = $cached_results[ $query_hash ];

		$this->report_data->signup_orders_query_hash = $query_hash;

		/*
		 * Subscribers by date
		 */
		$query = $wpdb->prepare(
			"SELECT searchdate.Date as date, COUNT( DISTINCT wcsubs.ID) as count, GROUP_CONCAT( DISTINCT wcsubs.ID ) as subscription_ids
				FROM (
					SELECT DATE(last_thousand_days.Date) as Date
					FROM (
						SELECT DATE(%s) - INTERVAL(units.digit + (10 * tens.digit) + (100 * hundreds.digit)) DAY as Date
						FROM (
							SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2
							UNION ALL SELECT 3 UNION ALL SELECT 4
							UNION ALL SELECT 5 UNION ALL SELECT 6
							UNION ALL SELECT 7 UNION ALL SELECT 8
							UNION ALL SELECT 9
						) as units
						CROSS JOIN (
							SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2
							UNION ALL SELECT 3 UNION ALL SELECT 4
							UNION ALL SELECT 5 UNION ALL SELECT 6
							UNION ALL SELECT 7 UNION ALL SELECT 8
							UNION ALL SELECT 9
						) as tens
						CROSS JOIN (
							SELECT 0 AS digit UNION ALL SELECT 1 UNION ALL SELECT 2
							UNION ALL SELECT 3 UNION ALL SELECT 4
							UNION ALL SELECT 5 UNION ALL SELECT 6
							UNION ALL SELECT 7 UNION ALL SELECT 8
							UNION ALL SELECT 9
						) AS hundreds
					) last_thousand_days
					WHERE last_thousand_days.Date >= %s AND last_thousand_days.Date <= %s
				) searchdate,
					{$wpdb->posts} AS wcsubs,
					{$wpdb->postmeta} AS wcsmeta
					WHERE wcsubs.ID = wcsmeta.post_id AND wcsmeta.meta_key = %s
						AND DATE( wcsubs.post_date ) <= searchdate.Date
						AND wcsubs.post_type IN ( 'shop_subscription' )
						AND wcsubs.post_status NOT IN( 'auto-draft' )
						AND (
							DATE( CONVERT_TZ( wcsmeta.meta_value , '+00:00', %s ) ) >= searchdate.Date
							OR wcsmeta.meta_value = 0
							OR wcsmeta.meta_value IS NULL
						)
					GROUP BY searchdate.Date
					ORDER BY searchdate.Date ASC",
			$query_end_date,
			date( 'Y-m-d', $this->start_date ),
			$query_end_date,
			wcs_get_date_meta_key( 'end' ),
			$site_timezone
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = apply_filters( 'wcs_reports_subscription_events_subscriber_count_data', (array) $wpdb->get_results( $query ), $args );
			$update_cache = true;
		}

		$this->report_data->subscriber_counts = $cached_results[ $query_hash ];
		$this->report_data->current_subscriptions_query_hash = $query_hash;

		/*
		 * Subscription cancellations
		 */
		$query = $wpdb->prepare(
			"SELECT COUNT( DISTINCT wcsubs.ID ) as count, CONVERT_TZ( wcsmeta_cancel.meta_value, '+00:00', %s ) as cancel_date, GROUP_CONCAT( DISTINCT wcsubs.ID ) as subscription_ids
				FROM {$wpdb->posts} as wcsubs
				JOIN {$wpdb->postmeta} AS wcsmeta_cancel
					ON wcsubs.ID = wcsmeta_cancel.post_id
					AND wcsmeta_cancel.meta_key = %s
					AND wcsubs.post_status NOT IN ( 'trash', 'auto-draft' )
				GROUP BY YEAR( cancel_date ), MONTH( cancel_date ), DAY( cancel_date )
				HAVING cancel_date BETWEEN %s AND %s
				ORDER BY wcsmeta_cancel.meta_value ASC",
			$site_timezone,
			wcs_get_date_meta_key( 'cancelled' ),
			date( 'Y-m-d', $this->start_date ),
			$query_end_date
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = apply_filters( 'wcs_reports_subscription_events_cancel_count_data', (array) $wpdb->get_results( $query ), $args );
			$update_cache = true;
		}

		$this->report_data->cancel_counts = $cached_results[ $query_hash ];

		$this->report_data->cancelled_subscriptions_query_hash = $query_hash;

		/*
		 * Subscriptions ended
		 */
		$query = $wpdb->prepare(
			"SELECT COUNT( DISTINCT wcsubs.ID ) as count, CONVERT_TZ( wcsmeta_end.meta_value, '+00:00', %s ) as end_date, GROUP_CONCAT( DISTINCT wcsubs.ID ) as subscription_ids
				FROM {$wpdb->posts} as wcsubs
				JOIN {$wpdb->postmeta} AS wcsmeta_end
					ON wcsubs.ID = wcsmeta_end.post_id
						AND wcsmeta_end.meta_key = %s
						AND wcsubs.post_status NOT IN ( 'trash', 'auto-draft' )
				GROUP BY YEAR( end_date ), MONTH( end_date ), DAY( end_date )
				HAVING end_date BETWEEN %s AND %s
				ORDER BY wcsmeta_end.meta_value ASC",
			$site_timezone,
			wcs_get_date_meta_key( 'end' ),
			date( 'Y-m-d', $this->start_date ),
			$query_end_date
		);

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = apply_filters( 'wcs_reports_subscription_events_ended_count_data', (array) $wpdb->get_results( $query ), $args );
			$update_cache = true;
		}

		$this->report_data->ended_counts = $cached_results[ $query_hash ];

		$this->report_data->ended_subscriptions_query_hash = $query_hash;

		// Total up the query data
		$this->report_data->signup_orders_total_amount          = array_sum( wp_list_pluck( $this->report_data->signup_data, 'signup_totals' ) );
		$this->report_data->renewal_orders_total_amount         = array_sum( wp_list_pluck( $this->report_data->renewal_data, 'renewal_totals' ) );
		$this->report_data->resubscribe_orders_total_amount     = array_sum( wp_list_pluck( $this->report_data->resubscribe_data, 'resubscribe_totals' ) );
		$this->report_data->switch_orders_total_amount          = array_sum( wp_list_pluck( $this->report_data->switch_data, 'switch_totals' ) );
		$this->report_data->new_subscription_total_count        = absint( array_sum( wp_list_pluck( $this->report_data->new_subscriptions_data, 'count' ) ) );
		$this->report_data->signup_orders_total_count           = absint( array_sum( wp_list_pluck( $this->report_data->signup_data, 'count' ) ) );
		$this->report_data->renewal_orders_total_count          = absint( array_sum( wp_list_pluck( $this->report_data->renewal_data, 'count' ) ) );
		$this->report_data->resubscribe_orders_total_count      = absint( array_sum( wp_list_pluck( $this->report_data->resubscribe_data, 'count' ) ) );
		$this->report_data->switch_orders_total_count           = absint( array_sum( wp_list_pluck( $this->report_data->switch_data, 'count' ) ) );
		$this->report_data->total_subscriptions_cancelled       = absint( array_sum( wp_list_pluck( $this->report_data->cancel_counts, 'count' ) ) );
		$this->report_data->total_subscriptions_ended           = absint( array_sum( wp_list_pluck( $this->report_data->ended_counts, 'count' ) ) );
		$this->report_data->total_subscriptions_at_period_end   = $this->report_data->subscriber_counts ? absint( end( $this->report_data->subscriber_counts )->count ) : 0;
		$this->report_data->total_subscriptions_at_period_start = isset( $this->report_data->subscriber_counts[0]->count ) ? absint( $this->report_data->subscriber_counts[0]->count ) : 0;

		if ( $update_cache ) {
			set_transient( strtolower( get_class( $this ) ), $cached_results, WEEK_IN_SECONDS );

			// Remove this class from the list of classes WC updates on shutdown. Introduced in WC 3.7
			if ( ! wcs_is_woocommerce_pre( '3.7' ) ) {
				$class_name = strtolower( get_class( $this ) );
				unset( WC_Admin_Report::$transients_to_update[ $class_name ] );
			}
		}
	}

	/**
	 * Get the legend for the main chart sidebar
	 *
	 * @return array
	 */
	public function get_chart_legend() {
		$legend = array();
		$data   = $this->get_report_data();
		$tracks_id = 'report_subscription_events_by_date_';

		$legend[] = array(
			// translators: %s: formatted total amount.
			'title'            => sprintf( __( '%s signup revenue in this period', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $data->signup_orders_total_amount ) . '</strong>' ),
			'placeholder'      => __( 'The sum of all subscription parent orders, including other items, fees, tax and shipping.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['signup_total'],
			'highlight_series' => 8,
		);

		$legend[] = array(
			// translators: %s: formatted total amount.
			'title'            => sprintf( __( '%s renewal revenue in this period', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $data->renewal_orders_total_amount ) . '</strong>' ),
			'placeholder'      => __( 'The sum of all renewal orders including tax and shipping.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['renewal_total'],
			'highlight_series' => 10,
		);

		$legend[] = array(
			// translators: %s: formatted total amount.
			'title'            => sprintf( __( '%s resubscribe revenue in this period', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $data->resubscribe_orders_total_amount ) . '</strong>' ),
			'placeholder'      => __( 'The sum of all resubscribe orders including tax and shipping.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['resubscribe_total'],
			'highlight_series' => 9,
		);

		$legend[] = array(
			// translators: %s: formatted total amount.
			'title'            => sprintf( __( '%s switch revenue in this period', 'woocommerce-subscriptions' ), '<strong>' . wc_price( $data->switch_orders_total_amount ) . '</strong>' ),
			'placeholder'      => __( 'The sum of all switch orders including tax and shipping.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['switch_total'],
			'highlight_series' => 11,
		);

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s new subscriptions', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->new_subscription_total_count . '</span> </strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'               => 'shop_subscription',
							'_subscriptions_list_key' => $this->report_data->new_subscriptions_query_hash,
							'_report'                 => strtolower( get_class( $this ) ),
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'new">'
			),
			'placeholder'      => __( 'The number of subscriptions created during this period, either by being manually created, imported or a customer placing an order. This includes orders pending payment.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['new_count'],
			'highlight_series' => 1,
		);

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s subscription signups', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->signup_orders_total_count . '</strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'               => 'shop_subscription',
							'_subscriptions_list_key' => $this->report_data->signup_orders_query_hash,
							'_report'                 => strtolower( get_class( $this ) ),
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'signups">'
			),
			'placeholder'      => __( 'The number of subscriptions purchased in parent orders created during this period. This represents the new subscriptions created by customers placing an order via checkout.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['signup_count'],
			'highlight_series' => 2,
		);

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s subscription resubscribes', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->resubscribe_orders_total_count . '</strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'        => 'shop_order',
							'_orders_list_key' => $this->report_data->resubscribes_query_hash,
							'_report'          => strtolower( get_class( $this ) ),
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'resubscribes">'
			),
			'placeholder'      => __( 'The number of resubscribe orders processed during this period.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['resubscribe_count'],
			'highlight_series' => 3,
		);

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s subscription renewals', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->renewal_orders_total_count . '</strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'        => 'shop_order',
							'_orders_list_key' => $this->report_data->renewals_query_hash,
							'_report'          => strtolower( get_class( $this ) ),
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'renewals">'
			),
			'placeholder'      => __( 'The number of renewal orders processed during this period.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['renewal_count'],
			'highlight_series' => 4,
		);

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s subscription switches', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->switch_orders_total_count . '</strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'        => 'shop_order',
							'_orders_list_key' => $this->report_data->switches_query_hash,
							'_report'          => strtolower( get_class( $this ) ),
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'switches">'
			),
			'placeholder'      => __( 'The number of subscriptions upgraded, downgraded or cross-graded during this period.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['switch_count'],
			'highlight_series' => 0,
		);

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s subscription cancellations', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->total_subscriptions_cancelled . '</strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'               => 'shop_subscription',
							'_subscriptions_list_key' => $this->report_data->cancelled_subscriptions_query_hash,
							'_report'                 => strtolower( get_class( $this ) ),
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'cancellations">'
			),
			'placeholder'      => __( 'The number of subscriptions cancelled by the customer or store manager during this period.  The pre-paid term may not yet have ended during this period.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['cancel_count'],
			'highlight_series' => 7,
		);

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s ended subscriptions', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->total_subscriptions_ended . '</strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'               => 'shop_subscription',
							'_subscriptions_list_key' => $this->report_data->ended_subscriptions_query_hash,
							'_report'                 => strtolower( get_class( $this ) ),
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'ended">'
			),
			'placeholder'      => __( 'The number of subscriptions which have either expired or reached the end of the prepaid term if it was previously cancelled.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['ended_count'],
			'highlight_series' => 6,
		);

		// For the subscriptions count we only need to display the subscriptions included on the last day of the report period so pass the last cache key. The array keys are integers so using max() returns the last array key.
		$data_key = $this->report_data->subscriber_counts ? max( array_keys( $this->report_data->subscriber_counts ) ) : false;

		$legend[] = array(
			'title'            => sprintf(
				// translators: 2: link opening tag, 1: subscription count and closing tag.
				__( '%2$s %1$s current subscriptions', 'woocommerce-subscriptions' ),
				'<strong> <span class="woocommerce-subscriptions-count count">' . $this->report_data->total_subscriptions_at_period_end . '</strong> </a>',
				'<a href="' .
				esc_url(
					add_query_arg(
						array(
							'post_type'               => 'shop_subscription',
							'_subscriptions_list_key' => $this->report_data->current_subscriptions_query_hash,
							'_report'                 => strtolower( get_class( $this ) ),
							'_data_key'               => $data_key,
						),
						admin_url( 'edit.php' )
					)
				) . '" id="' . $tracks_id . 'current">'
			),
			'placeholder'      => __( 'The number of subscriptions during this period with an end date in the future and a status other than pending.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['subscriber_count'],
			'highlight_series' => 5,
		);

		$subscription_change_count = ( $data->total_subscriptions_at_period_end - $data->total_subscriptions_at_period_start > 0 ) ? '+' . ( $data->total_subscriptions_at_period_end - $data->total_subscriptions_at_period_start ) : ( $data->total_subscriptions_at_period_end - $data->total_subscriptions_at_period_start );

		if ( 0 === $data->total_subscriptions_at_period_start ) {
			$subscription_change_percent = '&#x221e;%'; // infinite percentage increase if the starting subs is 0.
		} elseif ( $data->total_subscriptions_at_period_end - $data->total_subscriptions_at_period_start >= 0 ) {
			$subscription_change_percent = '+' . number_format( ( ( ( $data->total_subscriptions_at_period_end - $data->total_subscriptions_at_period_start ) / $data->total_subscriptions_at_period_start ) * 100 ), 2 ) . '%';
		} else {
			$subscription_change_percent = number_format( ( ( ( $data->total_subscriptions_at_period_end - $data->total_subscriptions_at_period_start ) / $data->total_subscriptions_at_period_start ) * 100 ), 2 ) . '%';
		}

		if ( $data->total_subscriptions_at_period_end - $data->total_subscriptions_at_period_start >= 0 ) {
			// translators: %s: subscription net gain (with percentage).
			$legend_title = __( '%s net subscription gain', 'woocommerce-subscriptions' );
		} else {
			// translators: %s: subscription net loss (with percentage).
			$legend_title = __( '%s net subscription loss', 'woocommerce-subscriptions' );
		}

		$legend[] = array(
			'title'            => sprintf( $legend_title, '<strong>' . $subscription_change_count . ' <span style="font-size:65%;">(' . $subscription_change_percent . ')</span></strong>' ),
			'placeholder'      => __( 'Change in subscriptions between the start and end of the period.', 'woocommerce-subscriptions' ),
			'color'            => $this->chart_colours['subscriber_change'],
			'highlight_series' => 5,
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
			'signup_total'      => '#439ad9',
			'renewal_total'     => '#b1d4ea',
			'resubscribe_total' => '#7ab7e2',
			'switch_total'      => '#a7b7f1',
			'new_count'         => '#9adbb5',
			'signup_count'      => '#5cc488',
			'resubscribe_count' => '#449163',
			'renewal_count'     => '#b9e6cc',
			'switch_count'      => '#f1c40f',
			'cancel_count'      => '#e74c3c',
			'ended_count'       => '#f8ccc7',
			'subscriber_count'  => '#ecf0f1',
			'subscriber_change' => '#ecf0f1',
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
	 * @return string
	 */
	public function get_main_chart() {
		global $wp_locale;

		// Prepare data for report
		$signup_orders_amount      = $this->prepare_chart_data( $this->report_data->signup_data, 'post_date', 'signup_totals', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$renewal_orders_amount     = $this->prepare_chart_data( $this->report_data->renewal_data, 'post_date', 'renewal_totals', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$resubscribe_orders_amount = $this->prepare_chart_data( $this->report_data->resubscribe_data, 'post_date', 'resubscribe_totals', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$switch_orders_amount      = $this->prepare_chart_data( $this->report_data->switch_data, 'post_date', 'switch_totals', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$new_subscriptions_count   = $this->prepare_chart_data( $this->report_data->new_subscriptions_data, 'post_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$signup_orders_count       = $this->prepare_chart_data( $this->report_data->signup_data, 'post_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$renewal_orders_count      = $this->prepare_chart_data( $this->report_data->renewal_data, 'post_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$resubscribe_orders_count  = $this->prepare_chart_data( $this->report_data->resubscribe_data, 'post_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$switch_orders_count       = $this->prepare_chart_data( $this->report_data->switch_data, 'post_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$subscriber_count          = $this->prepare_chart_data_daily_average( $this->report_data->subscriber_counts, 'date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$cancel_count              = $this->prepare_chart_data( $this->report_data->cancel_counts, 'cancel_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );
		$ended_count               = $this->prepare_chart_data( $this->report_data->ended_counts, 'end_date', 'count', $this->chart_interval, $this->start_date, $this->chart_groupby );

		// Encode in json format
		$chart_data = array(
			'signup_orders_amount'      => array_map( array( $this, 'round_chart_totals' ), array_values( $signup_orders_amount ) ),
			'renewal_orders_amount'     => array_map( array( $this, 'round_chart_totals' ), array_values( $renewal_orders_amount ) ),
			'resubscribe_orders_amount' => array_map( array( $this, 'round_chart_totals' ), array_values( $resubscribe_orders_amount ) ),
			'switch_orders_amount'      => array_map( array( $this, 'round_chart_totals' ), array_values( $switch_orders_amount ) ),
			'new_subscriptions_count'   => array_values( $new_subscriptions_count ),
			'signup_orders_count'       => array_values( $signup_orders_count ),
			'renewal_orders_count'      => array_values( $renewal_orders_count ),
			'resubscribe_orders_count'  => array_values( $resubscribe_orders_count ),
			'switch_orders_count'       => array_values( $switch_orders_count ),
			'subscriber_count'          => array_values( $subscriber_count ),
			'cancel_count'              => array_values( $cancel_count ),
			'ended_count'               => array_values( $ended_count ),
		);

		$timeformat = ( $this->chart_groupby == 'day' ? '%d %b' : '%b' );

		?>
		<div class="chart-container">
			<div class="chart-placeholder main"></div>
		</div>
		<script type="text/javascript">

			var main_chart;

			jQuery(function(){
				var order_data = JSON.parse( '<?php echo json_encode( $chart_data ); ?>' );
				var drawGraph = function( highlight ) {
					var series = [
						{
							label: "<?php echo esc_js( __( 'Switched subscriptions', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.switch_orders_count,
							color: '<?php echo esc_js( $this->chart_colours['switch_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['switch_count'] ); ?>',
								order: 0,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'New Subscriptions', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.new_subscriptions_count,
							color: '<?php echo esc_js( $this->chart_colours['new_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['new_count'] ); ?>',
								order: 1,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Subscriptions signups', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.signup_orders_count,
							color: '<?php echo esc_js( $this->chart_colours['signup_count'] ); ?>',
							bars: {
								order: 1,
								fill: 0.5,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Number of resubscribes', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.resubscribe_orders_count,
							color: '<?php echo esc_js( $this->chart_colours['resubscribe_count'] ); ?>',
							bars: {
								order: 1,
								fill: 0.5,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Number of renewals', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.renewal_orders_count,
							color: '<?php echo esc_js( $this->chart_colours['renewal_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['renewal_count'] ); ?>',
								order: 2,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Subscriptions', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.subscriber_count,
							color: '<?php echo esc_js( $this->chart_colours['subscriber_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['subscriber_count'] ); ?>',
								order: 3,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Subscriptions Ended', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.ended_count,
							color: '<?php echo esc_js( $this->chart_colours['ended_count'] ); ?>',
							bars: {
								fillColor: '<?php echo esc_js( $this->chart_colours['ended_count'] ); ?>',
								order: 3,
								fill: true,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Cancellations', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.cancel_count,
							color: '<?php echo esc_js( $this->chart_colours['cancel_count'] ); ?>',
							bars: {
								order: 3,
								fill: 0.5,
								show: true,
								lineWidth: 0,
								barWidth: <?php echo esc_js( $this->barwidth ); ?> * 0.25,
								align: 'center'
							},
							shadowSize: 0,
							hoverable: false,
						},
						{
							label: "<?php echo esc_js( __( 'Signup Totals', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.signup_orders_amount,
							yaxis: 2,
							color: '<?php echo esc_js( $this->chart_colours['signup_total'] ); ?>',
							points: {
								show: true,
								radius: 5,
								lineWidth: 5,
								fillColor: '#fff',
								fill: true
							},
							lines: {
								show: true,
								lineWidth: 4,
								fill: false
							},
							shadowSize: 0,
							<?php echo wp_kses_post( $this->get_currency_tooltip() ); ?>
						},
						{
							label: "<?php echo esc_js( __( 'Resubscribe Totals', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.resubscribe_orders_amount,
							yaxis: 2,
							color: '<?php echo esc_js( $this->chart_colours['resubscribe_total'] ); ?>',
							points: {
								show: true,
								radius: 5,
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
						{
							label: "<?php echo esc_js( __( 'Renewal Totals', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.renewal_orders_amount,
							yaxis: 2,
							color: '<?php echo esc_js( $this->chart_colours['renewal_total'] ); ?>',
							points: {
								show: true,
								radius: 5,
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
						{
							label: "<?php echo esc_js( __( 'Switch Totals', 'woocommerce-subscriptions' ) ) ?>",
							data: order_data.switch_orders_amount,
							yaxis: 2,
							color: '<?php echo esc_js( $this->chart_colours['switch_total'] ); ?>',
							points: {
								show: true,
								radius: 5,
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

	/**
	 * Put data with post_date's into an array of times averaged by day
	 *
	 * If the data is grouped by day already, we can just call @see $this->prepare_chart_data() otherwise,
	 * we need to figure out how many days in each period and average the aggregate over that count.
	 *
	 * @param  array $data array of your data
	 * @param  string $date_key key for the 'date' field. e.g. 'post_date'
	 * @param  string $data_key key for the data you are charting
	 * @param  int $interval
	 * @param  string $start_date
	 * @param  string $group_by
	 * @return array
	 */
	private function prepare_chart_data_daily_average( $data, $date_key, $data_key, $interval, $start_date, $group_by ) {

		$prepared_data = array();

		if ( 'day' == $group_by ) {

			$prepared_data = $this->prepare_chart_data( $data, $date_key, $data_key, $interval, $start_date, $group_by );

		} else {

			// Ensure all days (or months) have values first in this range
			for ( $i = 0; $i <= $interval; $i ++ ) {

				$time = strtotime( date( 'Ym', strtotime( "+{$i} MONTH", $start_date ) ) . '01' ) . '000';

				if ( ! isset( $prepared_data[ $time ] ) ) {
					$prepared_data[ $time ] = array(
						esc_js( $time ),
						0,
						'count' => 0,
					);
				}
			}

			foreach ( $data as $days_data ) {

				$time = strtotime( date( 'Ym', strtotime( $days_data->$date_key ) ) . '01' ) . '000';

				if ( ! isset( $prepared_data[ $time ] ) ) {
					continue;
				}

				if ( $data_key ) {
					$prepared_data[ $time ][1] += $days_data->$data_key;
				} else {
					$prepared_data[ $time ][1] ++;
				}

				$prepared_data[ $time ]['count']++;
			}

			foreach ( $prepared_data as $time => $aggregated_data ) {
				if ( 0 === $aggregated_data['count'] ) {
					$prepared_data[ $time ][1] = 0;
				} else {
					$prepared_data[ $time ][1] = round( $prepared_data[ $time ][1] / $aggregated_data['count'] );
				}
				unset( $prepared_data[ $time ]['count'] );
			}
		}

		return $prepared_data;
	}

	/**
	 * Clears the cached report data.
	 *
	 * @since 3.0.10
	 */
	public function clear_cache() {
		delete_transient( strtolower( get_class( $this ) ) );
	}
}
