<?php
/**
 * Subscriptions Admin Report - Subscriptions by customer
 *
 * Creates the subscription admin reports area.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 */
class WCS_Report_Subscription_By_Customer extends WP_List_Table {
	/**
	 * Cached report results.
	 *
	 * @var array
	 */
	private static $cached_report_results = array();

	private $totals;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => __( 'Customer', 'woocommerce-subscriptions' ),
			'plural'   => __( 'Customers', 'woocommerce-subscriptions' ),
			'ajax'     => false,
		) );
	}

	/**
	 * Get the totals.
	 *
	 * @return object
	 */
	public function get_totals() {
		return $this->totals;
	}

	/**
	 * No subscription products found text.
	 */
	public function no_items() {
		esc_html_e( 'No customers found.', 'woocommerce-subscriptions' );
	}

	/**
	 * Output the report.
	 */
	public function output_report() {

		$this->prepare_items();
		echo '<div id="poststuff" class="woocommerce-reports-wide">';
		echo '	<div id="postbox-container-1" class="postbox-container" style="width: 280px;"><div class="postbox" style="padding: 10px;">';
		echo '	<h3>' . esc_html__( 'Customer Totals', 'woocommerce-subscriptions' ) . '</h3>';
		echo '	<p><strong>' . esc_html__( 'Total Subscribers', 'woocommerce-subscriptions' ) . '</strong>: ' . esc_html( $this->totals->total_customers ) . wc_help_tip( __( 'The number of unique customers with a subscription of any status other than pending or trashed.', 'woocommerce-subscriptions' ) ) . '<br />';
		echo '	<strong>' . esc_html__( 'Active Subscriptions', 'woocommerce-subscriptions' ) . '</strong>: ' . esc_html( $this->totals->active_subscriptions ) . wc_help_tip( __( 'The total number of subscriptions with a status of active or pending cancellation.', 'woocommerce-subscriptions' ) ) . '<br />';
		echo '	<strong>' . esc_html__( 'Total Subscriptions', 'woocommerce-subscriptions' ) . '</strong>: ' . esc_html( $this->totals->total_subscriptions ) . wc_help_tip( __( 'The total number of subscriptions with a status other than pending or trashed.', 'woocommerce-subscriptions' ) ) . '<br />';
		echo '	<strong>' . esc_html__( 'Total Subscription Orders', 'woocommerce-subscriptions' ) . '</strong>: ' . esc_html( $this->totals->initial_order_count + $this->totals->renewal_switch_count ) . wc_help_tip( __( 'The total number of sign-up, switch and renewal orders placed with your store with a paid status (i.e. processing or complete).', 'woocommerce-subscriptions' ) ) . '<br />';
		echo '	<strong>' . esc_html__( 'Average Lifetime Value', 'woocommerce-subscriptions' ) . '</strong>: ';
		echo wp_kses_post( wc_price( $this->totals->total_customers > 0 ? ( ( $this->totals->initial_order_total + $this->totals->renewal_switch_total ) / $this->totals->total_customers ) : 0 ) );
		echo wc_help_tip( __( 'The average value of all customers\' sign-up, switch and renewal orders.', 'woocommerce-subscriptions' ) ) . '</p>';
		echo '</div></div>';
		$this->display();
		echo '</div>';

	}

	/**
	 * Get column value.
	 *
	 * @param WP_User $user
	 * @param string $column_name
	 * @return string
	 */
	public function column_default( $user, $column_name ) {
		global $wpdb;

		switch ( $column_name ) {

			case 'customer_name':
				$user_info = get_userdata( $user->customer_id );
				return '<a href="' . get_edit_user_link( $user->customer_id ) . '">' . $user_info->user_email . '</a>';

			case 'active_subscription_count':
				return $user->active_subscriptions;

			case 'total_subscription_count':
				return sprintf( '<a href="%s%d">%d</a>', admin_url( 'edit.php?post_type=shop_subscription&_customer_user=' ), $user->customer_id, $user->total_subscriptions );

			case 'total_subscription_order_count':
				return sprintf( '<a href="%s%d">%d</a>', admin_url( 'edit.php?post_type=shop_order&_paid_subscription_orders_for_customer_user=' ), $user->customer_id, $user->initial_order_count + $user->renewal_switch_count );

			case 'customer_lifetime_value':
				return wc_price( $user->initial_order_total + $user->renewal_switch_total );

		}

		return '';
	}

	/**
	 * Get columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'customer_name'                  => __( 'Customer', 'woocommerce-subscriptions' ),
			// translators: %s: help tip.
			'active_subscription_count'      => sprintf( __( 'Active Subscriptions %s', 'woocommerce-subscriptions' ), wc_help_tip( __( 'The number of subscriptions this customer has with a status of active or pending cancellation.', 'woocommerce-subscriptions' ) ) ),
			// translators: %s: help tip.
			'total_subscription_count'       => sprintf( __( 'Total Subscriptions %s', 'woocommerce-subscriptions' ), wc_help_tip( __( 'The number of subscriptions this customer has with a status other than pending or trashed.', 'woocommerce-subscriptions' ) ) ),
			// translators: %s: help tip.
			'total_subscription_order_count' => sprintf( __( 'Total Subscription Orders %s', 'woocommerce-subscriptions' ), wc_help_tip( __( 'The number of sign-up, switch and renewal orders this customer has placed with your store with a paid status (i.e. processing or complete).', 'woocommerce-subscriptions' ) ) ),
			// translators: %s: help tip.
			'customer_lifetime_value'        => sprintf( __( 'Lifetime Value from Subscriptions %s', 'woocommerce-subscriptions' ), wc_help_tip( __( 'The total value of this customer\'s sign-up, switch and renewal orders.', 'woocommerce-subscriptions' ) ) ),
		);

		return $columns;
	}

	/**
	 * Prepare subscription list items.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$current_page          = absint( $this->get_pagenum() );
		$per_page              = absint( apply_filters( 'wcs_reports_customers_per_page', 20 ) );
		$offset                = absint( ( $current_page - 1 ) * $per_page );

		$this->totals = self::get_data();

		$active_statuses = wcs_maybe_prefix_key( apply_filters( 'wcs_reports_active_statuses', [ 'active', 'pending-cancel' ] ), 'wc-' );
		$paid_statuses   = wcs_maybe_prefix_key( apply_filters( 'woocommerce_reports_paid_order_statuses', [ 'completed', 'processing' ] ), 'wc-' );
		$query_options   = array(
			'active_statuses' => $active_statuses,
			'paid_statuses'   => $paid_statuses,
			'offset'          => $offset,
			'per_page'        => $per_page,
		);

		$this->items  = self::fetch_subscriptions_by_customer( $query_options );
		$customer_ids = wp_list_pluck( $this->items, 'customer_id' );

		$related_orders_query_options = array(
			'order_status' => $paid_statuses,
			'customer_ids' => $customer_ids,
		);

		$related_orders_totals_by_customer = self::fetch_subscriptions_related_orders_totals_by_customer( $related_orders_query_options );

		foreach ( $this->items as $index => $item ) {
			if ( isset( $related_orders_totals_by_customer[ $item->customer_id ] ) ) {
				$this->items[ $index ]->renewal_switch_total = $related_orders_totals_by_customer[ $item->customer_id ]->renewal_switch_total;
				$this->items[ $index ]->renewal_switch_count = $related_orders_totals_by_customer[ $item->customer_id ]->renewal_switch_count;
			} else {
				$this->items[ $index ]->renewal_switch_total = 0;
				$this->items[ $index ]->renewal_switch_count = 0;
			}
		}

		/**
		 * Pagination.
		 */
		$this->set_pagination_args(
			array(
				'total_items' => $this->totals->total_customers,
				'per_page'    => $per_page,
				'total_pages' => ceil( $this->totals->total_customers / $per_page ),
			)
		);
	}

	/**
	 * Gather totals for customers.
	 *
	 * @see WCS_Report_Cache_Manager::update_cache() - This method is called by the cache manager to update the cache.
	 *
	 * @param array $args The arguments for the report.
	 * @return object The totals for customers.
	 */
	public static function get_data( $args = array() ) {
		$default_args = array(
			'no_cache'     => false,
			/**
			 * Filter the order statuses considered as "paid" for the report.
			 *
			 * @param array $order_statuses The default paid order statuses: completed, processing.
			 * @return array The filtered order statuses.
			 *
			 * @since 2.1.0
			 */
			'order_status' => apply_filters( 'woocommerce_reports_paid_order_statuses', array( 'completed', 'processing' ) ),
		);

		/**
		 * Filter the arguments for the totals of subscriptions by customer report.
		 *
		 * @param array $args The arguments for the report.
		 * @return array The filtered arguments.
		 *
		 * @since 2.1.0
		 */
		$args = apply_filters( 'wcs_reports_customer_total_args', $args );
		$args = wp_parse_args( $args, $default_args );

		self::init_cache();
		$subscriptions_totals  = self::fetch_customer_subscription_totals( $args );
		$related_orders_totals = self::fetch_customer_subscription_related_orders_totals( $args );

		$subscriptions_totals->renewal_switch_total = $related_orders_totals->renewal_switch_total;
		$subscriptions_totals->renewal_switch_count = $related_orders_totals->renewal_switch_count;

		return $subscriptions_totals;
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
		self::$cached_report_results = array();
	}

	/**
	 * Fetch totals by customer for subscriptions.
	 *
	 * @param array $args The arguments for the report.
	 * @return object The totals by customer for subscriptions.
	 *
	 * @since 2.1.0
	 */
	public static function fetch_customer_subscription_totals( $args = array() ) {
		global $wpdb;

		/**
		 * Filter the active subscription statuses used for reporting.
		 *
		 * @param array $active_statuses The default active subscription statuses: active, pending-cancel.
		 * @return array The filtered active statuses.
		 *
		 * @since 2.1.0
		 */
		$active_statuses = wcs_maybe_prefix_key( apply_filters( 'wcs_reports_active_statuses', [ 'active', 'pending-cancel' ] ), 'wc-' );
		$order_statuses  = wcs_maybe_prefix_key( $args['order_status'], 'wc-' );

		$active_statuses_placeholders = implode( ',', array_fill( 0, count( $active_statuses ), '%s' ) );
		$order_statuses_placeholders  = implode( ',', array_fill( 0, count( $order_statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ignored for allowing interpolation in the IN statements.
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT COUNT( DISTINCT subscriptions.customer_id) as total_customers,
					COUNT(subscriptions.ID) as total_subscriptions,
					COALESCE( SUM(parent_orders.total_amount), 0) as initial_order_total,
					COUNT(DISTINCT parent_orders.ID) as initial_order_count,
					COALESCE(SUM(CASE
							WHEN subscriptions.status
								IN ( {$active_statuses_placeholders} ) THEN 1
							ELSE 0
							END), 0) AS active_subscriptions
				FROM {$wpdb->prefix}wc_orders subscriptions
				LEFT JOIN {$wpdb->prefix}wc_orders parent_orders
					ON parent_orders.ID = subscriptions.parent_order_id
					AND parent_orders.status IN ( {$order_statuses_placeholders} )
				WHERE subscriptions.type = 'shop_subscription'
					AND subscriptions.status NOT IN ('wc-pending', 'auto-draft', 'wc-checkout-draft', 'trash')
				", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are prepared above.
				array_merge( $active_statuses, $order_statuses )
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT COUNT( DISTINCT customer_ids.meta_value) as total_customers,
					COUNT(subscription_posts.ID) as total_subscriptions,
					COALESCE( SUM(parent_total.meta_value), 0) as initial_order_total,
					COUNT(DISTINCT parent_order.ID) as initial_order_count,
					COALESCE(SUM(CASE
							WHEN subscription_posts.post_status
								IN ( {$active_statuses_placeholders} ) THEN 1
							ELSE 0
							END), 0) AS active_subscriptions
				FROM {$wpdb->posts} subscription_posts
				INNER JOIN {$wpdb->postmeta} customer_ids
					ON customer_ids.post_id = subscription_posts.ID
					AND customer_ids.meta_key = '_customer_user'
				LEFT JOIN {$wpdb->posts} parent_order
					ON parent_order.ID = subscription_posts.post_parent
					AND parent_order.post_status IN ( {$order_statuses_placeholders} )
				LEFT JOIN {$wpdb->postmeta} parent_total
					ON parent_total.post_id = parent_order.ID
					AND parent_total.meta_key = '_order_total'
				WHERE subscription_posts.post_type = 'shop_subscription'
					AND subscription_posts.post_status NOT IN ('wc-pending', 'auto-draft', 'wc-checkout-draft', 'trash')
				", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are prepared above.
				array_merge( $active_statuses, $order_statuses )
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared.

		/**
		 * Filter the query used to fetch the customer subscription totals.
		 *
		 * @param string $query The query to fetch the customer subscription totals.
		 * @return string The filtered query.
		 *
		 * @since 2.1.0
		 */
		$query      = apply_filters( 'wcs_reports_customer_total_query', $query );
		$query_hash = md5( $query );

		// We expect that cache was initialized before calling this method.
		// Skip running the query if cache is available.
		if ( $args['no_cache'] || ! isset( self::$cached_report_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			$query_results = $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.

			/**
			 * Filter the query results for customer totals.
			 *
			 * @param object $query_results The query results.
			 * @return object The filtered query results.
			 *
			 * @since 2.1.0
			 */
			$query_results = apply_filters( 'wcs_reports_customer_total_data', $query_results );
			self::cache_report_results( $query_hash, $query_results );
		}

		return self::$cached_report_results[ $query_hash ];
	}

	/**
	 * Fetch totals by customer for related renewal and switch orders.
	 *
	 * @param array $args The arguments for the report.
	 * @return object The totals by customer for related renewal and switch orders.
	 *
	 * @since 2.1.0
	 */
	public static function fetch_customer_subscription_related_orders_totals( $args = array() ) {
		global $wpdb;

		$status_placeholders = implode( ',', array_fill( 0, count( $args['order_status'] ), '%s' ) );
		$statuses            = wcs_maybe_prefix_key( $args['order_status'], 'wc-' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Ignored for allowing interpolation in the IN statements.
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT COALESCE( SUM(renewal_orders.total_amount), 0) as renewal_switch_total,
					COUNT(DISTINCT renewal_orders.ID) as renewal_switch_count
				FROM {$wpdb->prefix}wc_orders_meta renewal_order_ids
				INNER JOIN {$wpdb->prefix}wc_orders subscriptions
					ON renewal_order_ids.meta_value = subscriptions.ID
					AND subscriptions.type = 'shop_subscription'
					AND subscriptions.status NOT IN ('wc-pending', 'auto-draft', 'wc-checkout-draft', 'trash')
				INNER JOIN {$wpdb->prefix}wc_orders renewal_orders
					ON renewal_order_ids.order_id = renewal_orders.ID
					AND renewal_orders.status IN ( {$status_placeholders} )
				WHERE renewal_order_ids.meta_key = '_subscription_renewal'
					OR renewal_order_ids.meta_key = '_subscription_switch'
				", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are prepared above.
				$statuses
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT COALESCE( SUM(renewal_switch_totals.meta_value), 0) as renewal_switch_total,
					COUNT(DISTINCT renewal_order_posts.ID) as renewal_switch_count
				FROM {$wpdb->postmeta} renewal_order_ids
				INNER JOIN {$wpdb->posts} subscription_posts
					ON renewal_order_ids.meta_value = subscription_posts.ID
					AND subscription_posts.post_type = 'shop_subscription'
					AND subscription_posts.post_status NOT IN ('wc-pending', 'auto-draft', 'wc-checkout-draft', 'trash')
				INNER JOIN {$wpdb->posts} renewal_order_posts
					ON renewal_order_ids.post_id = renewal_order_posts.ID
					AND renewal_order_posts.post_status IN ( {$status_placeholders} )
				LEFT JOIN {$wpdb->postmeta} renewal_switch_totals
					ON renewal_switch_totals.post_id = renewal_order_ids.post_id
					AND renewal_switch_totals.meta_key = '_order_total'
				WHERE renewal_order_ids.meta_key = '_subscription_renewal'
					OR renewal_order_ids.meta_key = '_subscription_switch'
				", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are prepared above.
				$statuses
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared.

		/**
		 * Filter the query used to fetch the customer subscription related orders totals.
		 *
		 * @param string $query The query to fetch the customer subscription related orders totals.
		 * @return string The filtered query.
		 *
		 * @since 2.1.0
		 */
		$query      = apply_filters( 'wcs_reports_customer_total_renewal_switch_query', $query );
		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( self::$cached_report_results[ $query_hash ] ) ) {
			// Enable big selects for reports
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			$query_results = $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.

			/**
			 * Filter the query results for customer subscription related orders totals.
			 *
			 * @param object $query_results The query results.
			 * @return object The filtered query results.
			 *
			 * @since 2.1.0
			 */
			$query_results = apply_filters( 'wcs_reports_customer_total_renewal_switch_data', $query_results );
			self::cache_report_results( $query_hash, $query_results );
		}

		return self::$cached_report_results[ $query_hash ];
	}

	/**
	 * Fetch subscriptions by customer.
	 *
	 * @param array $query_options The query options.
	 * @return array The subscriptions by customer.
	 *
	 * @since 2.1.0
	 */
	private static function fetch_subscriptions_by_customer( $query_options = array() ) {
		global $wpdb;

		$active_statuses = $query_options['active_statuses'] ?? array();
		$paid_statuses   = $query_options['paid_statuses'] ?? array();
		$offset          = $query_options['offset'] ?? 0;
		$per_page        = $query_options['per_page'] ?? 20;

		$active_statuses_placeholders = implode( ',', array_fill( 0, count( $active_statuses ), '%s' ) );
		$paid_statuses_placeholders   = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );

		// Ignored for allowing interpolation in the IN statements.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT subscriptions.customer_id as customer_id,
					COUNT(subscriptions.ID) as total_subscriptions,
					COALESCE( SUM(parent_order.total_amount), 0) as initial_order_total,
					COUNT(DISTINCT parent_order.ID) as initial_order_count,
					SUM(CASE
							WHEN subscriptions.status
								IN ( {$active_statuses_placeholders} ) THEN 1
							ELSE 0
							END) AS active_subscriptions
				FROM {$wpdb->prefix}wc_orders subscriptions
				LEFT JOIN {$wpdb->prefix}wc_orders parent_order
					ON parent_order.ID = subscriptions.parent_order_id
					AND parent_order.status IN ( {$paid_statuses_placeholders} )
				WHERE subscriptions.type = 'shop_subscription'
					AND subscriptions.status NOT IN ('wc-pending','auto-draft', 'wc-checkout-draft', 'trash')
				GROUP BY subscriptions.customer_id
				ORDER BY customer_id DESC
				LIMIT %d, %d
				",
				array_merge( $active_statuses, $paid_statuses, array( $offset, $per_page ) )
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT customer_ids.meta_value as customer_id,
					COUNT(subscription_posts.ID) as total_subscriptions,
					COALESCE( SUM(parent_total.meta_value), 0) as initial_order_total,
					COUNT(DISTINCT parent_order.ID) as initial_order_count,
					SUM(CASE
							WHEN subscription_posts.post_status
								IN ( {$active_statuses_placeholders} ) THEN 1
							ELSE 0
							END) AS active_subscriptions
				FROM {$wpdb->posts} subscription_posts
				INNER JOIN {$wpdb->postmeta} customer_ids
					ON customer_ids.post_id = subscription_posts.ID
					AND customer_ids.meta_key = '_customer_user'
				LEFT JOIN {$wpdb->posts} parent_order
					ON parent_order.ID = subscription_posts.post_parent
					AND parent_order.post_status IN ( {$paid_statuses_placeholders} )
				LEFT JOIN {$wpdb->postmeta} parent_total
					ON parent_total.post_id = parent_order.ID
					AND parent_total.meta_key = '_order_total'
				WHERE subscription_posts.post_type = 'shop_subscription'
					AND subscription_posts.post_status NOT IN ('wc-pending', 'auto-draft', 'wc-checkout-draft', 'trash')
				GROUP BY customer_ids.meta_value
				ORDER BY customer_id DESC
				LIMIT %d, %d
				",
				array_merge( $active_statuses, $paid_statuses, array( $offset, $per_page ) )
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		/**
		 * Filter the query used to fetch the subscriptions by customer.
		 *
		 * @param string $query The query to fetch the subscriptions by customer.
		 * @return string The filtered query.
		 *
		 * @since 2.1.0
		 */
		$query = apply_filters( 'wcs_reports_current_customer_query', $query );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
		return $wpdb->get_results( $query );
	}

	/**
	 * Fetch totals by customer for related renewal and switch orders.
	 *
	 * @param array $query_options The query options.
	 * @return array The totals by customer for related renewal and switch orders.
	 *
	 * @since 2.1.0
	 */
	private static function fetch_subscriptions_related_orders_totals_by_customer( $query_options = array() ) {
		global $wpdb;

		$paid_statuses = $query_options['order_status'] ?? array();
		$customer_ids  = $query_options['customer_ids'] ?? array();

		$customer_placeholders = implode( ',', array_fill( 0, count( $customer_ids ), '%s' ) );
		$status_placeholders   = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Ignored for allowing interpolation in the IN statements.
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$query = $wpdb->prepare(
				"SELECT
					renewal_orders.customer_id as customer_id,
					COALESCE( SUM(renewal_orders.total_amount), 0) as renewal_switch_total,
					COUNT(DISTINCT renewal_orders.ID) as renewal_switch_count
				FROM {$wpdb->prefix}wc_orders_meta renewal_order_ids
				INNER JOIN {$wpdb->prefix}wc_orders subscriptions
					ON renewal_order_ids.meta_value = subscriptions.ID
					AND subscriptions.type = 'shop_subscription'
					AND subscriptions.status NOT IN ('wc-pending', 'auto-draft', 'wc-checkout-draft', 'trash')
				INNER JOIN {$wpdb->prefix}wc_orders renewal_orders
					ON renewal_order_ids.order_id = renewal_orders.ID
					AND renewal_orders.status IN ( {$status_placeholders} )
					AND renewal_orders.customer_id IN ( {$customer_placeholders} )
				WHERE renewal_order_ids.meta_key = '_subscription_renewal'
					OR renewal_order_ids.meta_key = '_subscription_switch'
				GROUP BY renewal_orders.customer_id
				ORDER BY renewal_orders.customer_id
				",
				array_merge( $paid_statuses, $customer_ids )
			);
		} else {
			$query = $wpdb->prepare(
				"SELECT
					customer_ids.meta_value as customer_id,
					COALESCE( SUM(renewal_switch_totals.meta_value), 0) as renewal_switch_total,
					COUNT(DISTINCT renewal_order_posts.ID) as renewal_switch_count
				FROM {$wpdb->postmeta} renewal_order_ids
				INNER JOIN {$wpdb->posts} subscription_posts
					ON renewal_order_ids.meta_value = subscription_posts.ID
					AND subscription_posts.post_type = 'shop_subscription'
					AND subscription_posts.post_status NOT IN ('wc-pending', 'auto-draft', 'wc-checkout-draft', 'trash')
				INNER JOIN {$wpdb->postmeta} customer_ids
					ON renewal_order_ids.meta_value = customer_ids.post_id
					AND customer_ids.meta_key = '_customer_user'
					AND customer_ids.meta_value IN ( {$customer_placeholders} )
				INNER JOIN {$wpdb->posts} renewal_order_posts
					ON renewal_order_ids.post_id = renewal_order_posts.ID
					AND renewal_order_posts.post_status IN ( {$status_placeholders} )
				LEFT JOIN {$wpdb->postmeta} renewal_switch_totals
					ON renewal_switch_totals.post_id = renewal_order_ids.post_id
					AND renewal_switch_totals.meta_key = '_order_total'
				WHERE renewal_order_ids.meta_key = '_subscription_renewal'
					OR renewal_order_ids.meta_key = '_subscription_switch'
				GROUP BY customer_id
				ORDER BY customer_id
				",
				array_merge( $customer_ids, $paid_statuses )
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare.

		/**
		 * Filter the query used to fetch the totals by customer for related renewal and switch orders.
		 *
		 * @param string $query The query to fetch the totals by customer for related renewal and switch orders.
		 * @return string The filtered query.
		 *
		 * @since 2.1.0
		 */
		$query = apply_filters( 'wcs_reports_current_customer_renewal_switch_total_query', $query );

		return $wpdb->get_results( $query, OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
	}

	/**
	 * Initialize cache for report results.
	 */
	private static function init_cache() {
		self::$cached_report_results = get_transient( strtolower( __CLASS__ ) );

		// Set a default value for cached results for PHP 8.2+ compatibility.
		if ( empty( self::$cached_report_results ) ) {
			self::$cached_report_results = array();
		}
	}

	/**
	 * Cache report results.
	 *
	 * @param string $query_hash The query hash.
	 * @param array $report_data The report data.
	 */
	private static function cache_report_results( $query_hash, $report_data ) {
		self::$cached_report_results[ $query_hash ] = $report_data;
		set_transient( strtolower( __CLASS__ ), self::$cached_report_results, WEEK_IN_SECONDS );
	}
}
