<?php
/**
 * Subscriptions Admin Report - Subscriptions by product
 *
 * Creates the subscription admin reports area.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Admin_Reports
 * @category   Class
 * @author     Prospress
 * @since      2.1
 */
class WCS_Report_Subscription_By_Product extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => __( 'Product', 'woocommerce-subscriptions' ),
			'plural'   => __( 'Products', 'woocommerce-subscriptions' ),
			'ajax'     => false,
		) );
	}

	/**
	 * No subscription products found text.
	 */
	public function no_items() {
		esc_html_e( 'No products found.', 'woocommerce-subscriptions' );
	}

	/**
	 * Output the report.
	 */
	public function output_report() {

		$this->prepare_items();
		echo '<div id="poststuff" class="woocommerce-reports-wide" style="width:50%; float: left; min-width: 0px;">';
		$this->display();
		echo '</div>';
		$this->product_breakdown_chart();
	}

	/**
	 * Get column value.
	 *
	 * @param object $report_item
	 * @param string $column_name
	 * @return string
	 */
	public function column_default( $report_item, $column_name ) {
		global $wpdb;

		switch ( $column_name ) {

			case 'product_name':
				// If the product is a subscription variation, use the parent product's edit post link
				if ( $report_item->parent_product_id > 0 ) {
					edit_post_link( $report_item->product_name, ' - ', null, $report_item->parent_product_id );
				} else {
					edit_post_link( $report_item->product_name, null, null, $report_item->product_id );
				}
				break;
			case 'subscription_count':
				return sprintf( '<a href="%s%d">%d</a>', admin_url( 'edit.php?post_type=shop_subscription&_wcs_product=' ), $report_item->product_id, $report_item->subscription_count );

			case 'average_recurring_total':
				$average_subscription_amount = ( 0 !== $report_item->subscription_count ? wc_price( $report_item->recurring_total / $report_item->subscription_count ) : '-' );
				return $average_subscription_amount;

			case 'average_lifetime_value':
				$average_subscription_amount = ( 0 !== $report_item->subscription_count ? wc_price( $report_item->product_total / $report_item->subscription_count ) : '-' );
				return $average_subscription_amount;

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
			'product_name'            => __( 'Subscription Product', 'woocommerce-subscriptions' ),
			// translators: %s: help tip.
			'subscription_count'      => sprintf( __( 'Subscription Count %s', 'woocommerce-subscriptions' ), wc_help_tip( __( 'The number of subscriptions that include this product as a line item and have a status other than pending or trashed.', 'woocommerce-subscriptions' ) ) ),
			// translators: %s: help tip.
			'average_recurring_total' => sprintf( __( 'Average Recurring Line Total %s', 'woocommerce-subscriptions' ), wc_help_tip( __( 'The average line total for this product on each subscription.', 'woocommerce-subscriptions' ) ) ),
			// translators: %s: help tip.
			'average_lifetime_value'  => sprintf( __( 'Average Lifetime Value %s', 'woocommerce-subscriptions' ), wc_help_tip( __( 'The average line total on all orders for this product line item.', 'woocommerce-subscriptions' ) ) ),
		);

		return $columns;
	}

	/**
	 * Prepare subscription list items.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = self::get_data();
	}

	/**
	 * Get subscription product data, either from the cache or the database.
	 */
	public static function get_data( $args = array() ) {
		global $wpdb;

		$default_args = array(
			'no_cache'     => false,
			'order_status' => apply_filters( 'woocommerce_reports_paid_order_statuses', array( 'completed', 'processing' ) ),
		);

		$args = apply_filters( 'wcs_reports_product_args', $args );
		$args = wp_parse_args( $args, $default_args );

		$query = apply_filters( 'wcs_reports_product_query',
			"SELECT product.id as product_id,
					product.post_parent as parent_product_id,
					product.post_title as product_name,
					mo.product_type,
					COUNT(subscription_line_items.subscription_id) as subscription_count,
					SUM(subscription_line_items.product_total) as recurring_total
				FROM {$wpdb->posts} AS product
				LEFT JOIN (
					SELECT tr.object_id AS product_id, t.slug AS product_type
					FROM {$wpdb->prefix}term_relationships AS tr
					INNER JOIN {$wpdb->prefix}term_taxonomy AS x
						ON ( x.taxonomy = 'product_type' AND x.term_taxonomy_id = tr.term_taxonomy_id )
					INNER JOIN {$wpdb->prefix}terms AS t
						ON t.term_id = x.term_id
				) AS mo
					ON product.id = mo.product_id
				LEFT JOIN (
					SELECT wcoitems.order_id as subscription_id, wcoimeta.meta_value as product_id, wcoimeta.order_item_id, wcoimeta2.meta_value as product_total
					FROM {$wpdb->prefix}woocommerce_order_items AS wcoitems
					INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS wcoimeta
						ON wcoimeta.order_item_id = wcoitems.order_item_id
					INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS wcoimeta2
						ON wcoimeta2.order_item_id = wcoitems.order_item_id
					WHERE wcoitems.order_item_type = 'line_item'
						AND ( wcoimeta.meta_key = '_product_id' OR wcoimeta.meta_key = '_variation_id' )
						AND wcoimeta2.meta_key = '_line_total'
				) as subscription_line_items
					ON product.id = subscription_line_items.product_id
				LEFT JOIN {$wpdb->posts} as subscriptions
					ON subscriptions.ID = subscription_line_items.subscription_id
				WHERE  product.post_status = 'publish'
					 AND ( product.post_type = 'product' OR product.post_type = 'product_variation' )
					 AND subscriptions.post_type = 'shop_subscription'
					 AND subscriptions.post_status NOT IN( 'wc-pending', 'trash' )
				GROUP BY product.id
				ORDER BY COUNT(subscription_line_items.subscription_id) DESC" );

		$cached_results = get_transient( strtolower( __CLASS__ ) );
		$query_hash     = md5( $query );

		// Set a default value for cached results for PHP 8.2+ compatibility.
		if ( empty( $cached_results ) ) {
			$cached_results = [];
		}

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = apply_filters( 'wcs_reports_product_data', $wpdb->get_results( $query, OBJECT_K ), $args );
			set_transient( strtolower( __CLASS__ ), $cached_results, WEEK_IN_SECONDS );
		}

		$report_data = $cached_results[ $query_hash ];

		// Organize subscription variations under the parent product in a tree structure
		$tree = array();
		foreach ( $report_data as $product_id => $product ) {
			if ( ! $product->parent_product_id ) {
				if ( isset( $tree[ $product_id ] ) ) {
					array_unshift( $tree[ $product_id ], $product_id );
				} else {
					$tree[ $product_id ][] = $product_id;
				}
			} else {
				$tree[ $product->parent_product_id ][] = $product_id;
			}
		}

		// Create an array with all the report data in the correct order
		$ordered_report_data = array();
		foreach ( $tree as $parent_id => $children ) {
			foreach ( $children as $child_id ) {
				$ordered_report_data[ $child_id ] = $report_data[ $child_id ];

				// When there are variations, store the variation ids.
				if ( 'variable-subscription' === $report_data[ $child_id ]->product_type ) {
					$ordered_report_data[ $child_id ]->variations = array_diff( $children, array( $parent_id ) );
				}
			}
		}

		$placeholders = implode( ',', array_fill( 0, count( $args['order_status'] ), '%s' ) );
		$statuses     = wcs_maybe_prefix_key( $args['order_status'], 'wc-' );

		// Now let's get the total revenue for each product so we can provide an average lifetime value for that product
		$query = apply_filters( 'wcs_reports_product_lifetime_value_query',
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Ignored for allowing interpolation in the IN statements.
			$wpdb->prepare(
				"SELECT wcoimeta.meta_value as product_id, SUM(wcoimeta2.meta_value) as product_total
				FROM {$wpdb->prefix}woocommerce_order_items AS wcoitems
				INNER JOIN {$wpdb->posts} AS wcorders
					ON wcoitems.order_id = wcorders.ID
					AND wcorders.post_type = 'shop_order'
					AND wcorders.post_status IN ( {$placeholders} )
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS wcoimeta
					ON wcoimeta.order_item_id = wcoitems.order_item_id
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS wcoimeta2
					ON wcoimeta2.order_item_id = wcoitems.order_item_id
				WHERE ( wcoimeta.meta_key = '_product_id' OR wcoimeta.meta_key = '_variation_id' )
					AND wcoimeta2.meta_key = '_line_total'
				GROUP BY product_id",
				$statuses
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$query_hash = md5( $query );

		if ( $args['no_cache'] || ! isset( $cached_results[ $query_hash ] ) ) {
			$wpdb->query( 'SET SESSION SQL_BIG_SELECTS=1' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- This query is prepared above.
			$cached_results[ $query_hash ] = apply_filters( 'wcs_reports_product_lifetime_value_data', $wpdb->get_results( $query, OBJECT_K ), $args );
			set_transient( strtolower( __CLASS__ ), $cached_results, WEEK_IN_SECONDS );
		}

		// Add the product total to each item
		foreach ( array_keys( $ordered_report_data ) as $product_id ) {
			$ordered_report_data[ $product_id ]->product_total = isset( $cached_results[ $query_hash ][ $product_id ] ) ? $cached_results[ $query_hash ][ $product_id ]->product_total : 0;
		}

		return $ordered_report_data;
	}

	/**
	 * Output product breakdown chart.
	 */
	public function product_breakdown_chart() {

		$chart_colors = array( '#33a02c', '#1f78b4', '#6a3d9a', '#e31a1c', '#ff7f00', '#b15928', '#a6cee3', '#b2df8a', '#fb9a99', '#ffff99', '#fdbf6f', '#cab2d6' );

		// We only will display the first 12 plans of parent products in the chart
		$products = array_slice( wp_list_filter( $this->items, array( 'parent_product_id' => 0 ) ), 0, 12 );

		// Filter top n variations corresponding to top 12 parent products
		$variations = array();
		foreach ( $products as $product ) {
			if ( ! empty( $product->variations ) ) { // Variable subscription
				foreach ( $product->variations as $variation_id ) {
					$variations[] = $this->items[ $variation_id ];
				}
			} else { // Simple subscription
				$variations[] = $product;
			}
		}
		?>
		<div class="chart-container" style="float: left; padding-top: 50px; min-width: 0px;">
			<div class="data-container" style="display: inline-block; margin-left: 30px; border: 1px solid #e5e5e5; background-color: #FFF; padding: 20px;">
				<div class="chart-placeholder product_breakdown_chart pie-chart" style="height: 200px; width: 200px; float: left;"></div>
				<div class="chart-placeholder variation_breakdown_chart pie-chart" style="height: 200px; width: 200px;"></div>
				<div class="legend-container" style="margin-left: 10px; float: left;"></div>
				<div style="clear: both;"></div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery(function(){
				jQuery.plot(
					jQuery('.chart-placeholder.variation_breakdown_chart'),
					[
					<?php
					$colorindex = -1;
					$last_parent_id = -1;
					foreach ( $variations as $product ) {
						if ( '0' === $product->parent_product_id || $last_parent_id !== $product->parent_product_id ) {
							$colorindex++;
							$last_parent_id = $product->parent_product_id;
						}
						?>
						{
							label: '<?php echo esc_js( $product->product_name ); ?>',
							data:  '<?php echo esc_js( $product->subscription_count ); ?>',
							color: '<?php echo esc_js( $chart_colors[ $colorindex ] ); ?>',
						},
						<?php

					}
					?>
					],
					{
						grid: {
							hoverable: true
						},
						series: {
							pie: {
								show: true,
								radius: 1,
								innerRadius: 0.7,
								label: {
									show: false
								}
							},
							prepend_label: true,
							enable_tooltip: true,
							append_tooltip: "<?php echo ' ' . esc_js( __( 'subscriptions', 'woocommerce-subscriptions' ) ); ?>",
						},
						legend: {
							show: false,
						}
					}
				);
				jQuery('.chart-placeholder.variation_breakdown_chart').trigger( 'resize' );
				jQuery.plot(
					jQuery('.chart-placeholder.product_breakdown_chart'),
					[
					<?php
					$i = 0;
					foreach ( $products as $product ) {
						?>
						{
							label: '<?php echo esc_js( $product->product_name ); ?>',
							data:  '<?php echo esc_js( $product->subscription_count ); ?>',
							color: '<?php echo esc_js( $chart_colors[ $i ] ); ?>'
						},
						<?php
						$i++;
					}
					?>
					],
					{
						grid: {
							hoverable: true
						},
						series: {
							pie: {
								show: true,
								radius: 0.7,
								innerRadius: 0.3,
								label: {
									show: false
								}
							},
							prepend_label: true,
							enable_tooltip: true,
							append_tooltip: "<?php echo ' ' . esc_js( __( 'subscriptions', 'woocommerce-subscriptions' ) ); ?>",
						},
						legend: {
							show: true,
							position: "ne",
							container: jQuery('.legend-container'),
						}
					}
				);
				jQuery('.chart-placeholder.product_breakdown_chart').trigger( 'resize' );
			});
		</script>
		<?php
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
