<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Telemetry;

use DateTime;

/**
 * Provides telemetry information about subscription-related orders.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Orders {
	/**
	 * Quoted and comma separated list of order statuses that are considered active.
	 *
	 * These are held directly as a string (rather than an array of statuses) because that is how they are consumed.
	 *
	 * @var string
	 */
	private string $active_order_statuses_clause;

	/**
	 * If HPOS is enabled.
	 *
	 * @var bool
	 */
	private bool $is_hpos;

	/**
	 * The full and prefixed name of the orders table.
	 *
	 * @var string
	 */
	private string $wc_orders_table;

	/**
	 * The full and prefixed name of the orders meta table.
	 *
	 * @var string
	 */
	private string $wc_orders_meta_table;

	/**
	 * Prepares Orders telemetry collection.
	 */
	public function __construct() {
		global $wpdb;

		$this->active_order_statuses_clause = "'wc-completed', 'wc-refunded'";

		$this->is_hpos              = wcs_is_custom_order_tables_usage_enabled();
		$this->wc_orders_table      = $wpdb->prefix . 'wc_orders';
		$this->wc_orders_meta_table = $wpdb->prefix . 'wc_orders_meta';
	}

	/**
	 * Gets order counts and GMV (gross) by payment gateway, segmented by monthly data.
	 *
	 * Returns aggregated metrics (count, gross) for all subscription-related orders,
	 * grouped by payment gateway and segmented by month.
	 *
	 * @param int $start_timestamp Start timestamp. Will be normalized to the beginning of the month in UTC.
	 * @param int $end_timestamp   End timestamp. Will be normalized to the end of the month in UTC.
	 *
	 * @return array Associative array with structure:
	 *   [
	 *     'stripe' => [
	 *       ['month' => '2024-01', 'count' => 45, 'gross' => 3750.25],
	 *       ['month' => '2024-02', 'count' => 50, 'gross' => 4000.00],
	 *     ],
	 *     'paypal' => [
	 *       ['month' => '2024-01', 'count' => 30, 'gross' => 2500.00],
	 *     ],
	 *   ]
	 */
	public function get_aggregated_monthly_order_data_by_payment_gateway( int $start_timestamp, int $end_timestamp ): array {
		// Normalize timestamps to full month boundaries in UTC
		[ 'start' => $start, 'end' => $end ] = $this->normalize_timestamp_range_to_month_boundaries( $start_timestamp, $end_timestamp );

		$results = $this->is_hpos
			? $this->get_hpos_monthly_order_data_by_payment_gateway( $start, $end )
			: $this->get_cpt_monthly_order_data_by_payment_gateway( $start, $end );

		return $this->format_monthly_order_data_by_payment_gateway( $results );
	}

	/**
	 * Get monthly order metrics segmented by order type within a date range.
	 *
	 * Returns aggregated metrics (count, gross, non_zero_count, quantity) for all subscription-related orders,
	 * segmented by order type (initial/renewal/switch/resubscribe) and month.
	 *
	 * @param int $start_timestamp Start timestamp for date filtering.
	 * @param int $end_timestamp   End timestamp for date filtering.
	 *
	 * @return array Associative array with structure:
	 *   [
	 *     'store_gross' => [
	 *       ['month' => '2025-01', 'gross' => 1250.50],
	 *       ['month' => '2025-02', 'gross' => 1500.75],
	 *     ],
	 *     'initial' => [
	 *       ['month' => '2025-01', 'count' => 15, 'gross' => 750.00, 'non_zero_count' => 12, 'quantity' => 20, 'non_zero_quantity' => 18],
	 *       ['month' => '2025-02', 'count' => 18, 'gross' => 900.00, 'non_zero_count' => 15, 'quantity' => 24, 'non_zero_quantity' => 22],
	 *     ],
	 *     'renewal' => [
	 *       ['month' => '2025-01', 'count' => 10, 'gross' => 500.00, 'non_zero_count' => 8],
	 *       ['month' => '2025-02', 'count' => 12, 'gross' => 600.00, 'non_zero_count' => 10],
	 *     ],
	 *     'switch' => [
	 *       ['month' => '2025-01', 'count' => 5, 'gross' => 250.00, 'non_zero_count' => 4],
	 *     ],
	 *     'resubscribe' => [
	 *       ['month' => '2025-01', 'count' => 2, 'gross' => 100.00, 'non_zero_count' => 2],
	 *     ],
	 *   ]
	 */
	public function get_aggregated_monthly_order_data( int $start_timestamp, int $end_timestamp ): array {
		// Normalize timestamps to full month boundaries in UTC
		[ 'start' => $start, 'end' => $end ] = $this->normalize_timestamp_range_to_month_boundaries( $start_timestamp, $end_timestamp );

		// Get renewal, switch, and resubscribe order data.
		$related_orders_data = $this->aggregate_monthly_order_data_by_type( $start, $end );

		$orders_data = array(
			'store_gross' => $this->aggregate_monthly_store_gmv( $start, $end ),
			'initial'     => $this->aggregate_monthly_parent_order_data( $start, $end ),
			'renewal'     => $related_orders_data['renewal'],
			'switch'      => $related_orders_data['switch'],
			'resubscribe' => $related_orders_data['resubscribe'],
		);

		return $orders_data;
	}

	/**
	 * HPOS implementation of get_aggregated_monthly_order_data_by_payment_gateway().
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array Array of database result objects with payment_method, month, count, and gross columns.
	 */
	private function get_hpos_monthly_order_data_by_payment_gateway( string $start_date, string $end_date ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						-- Group by payment method (null becomes empty string)
						COALESCE(orders.payment_method, '') as payment_method,

						-- Group by month (first day of each month)
						DATE_FORMAT(orders.date_created_gmt, '%%Y-%%m') as month,

						-- Overall totals
						COUNT(*) as count,
						SUM(orders.total_amount) as gross

					FROM %i AS orders
					WHERE orders.type = 'shop_order'

						-- Only include successful order statuses (excludes on-hold, pending, failed, etc.)
						AND orders.status IN ( $this->active_order_statuses_clause )

						-- Date range filter (last 12 months)
						AND orders.date_created_gmt >= %s
						AND orders.date_created_gmt < %s

						-- Only include orders related to subscriptions
						AND (
							-- Parent orders (orders that created subscriptions)
							orders.id IN (
								SELECT DISTINCT parent_order_id
								FROM %i AS subscriptions
								WHERE subscriptions.type = 'shop_subscription'
								AND subscriptions.parent_order_id IS NOT NULL
								AND subscriptions.parent_order_id <> 0
							)
							-- Renewal orders (recurring subscription payments)
							OR EXISTS (
								SELECT 1 FROM %i AS renewal_meta
								WHERE renewal_meta.order_id = orders.id
								AND renewal_meta.meta_key = '_subscription_renewal'
							)
							-- Switch orders (subscription plan/product changes)
							OR EXISTS (
								SELECT 1 FROM %i AS switch_meta
								WHERE switch_meta.order_id = orders.id
								AND switch_meta.meta_key = '_subscription_switch'
							)
							-- Resubscribe orders (reactivated cancelled subscriptions)
							OR EXISTS (
								SELECT 1 FROM %i AS resubscribe_meta
								WHERE resubscribe_meta.order_id = orders.id
								AND resubscribe_meta.meta_key = '_subscription_resubscribe'
							)
						)

					-- Group by payment method and month
					GROUP BY COALESCE(orders.payment_method, ''), YEAR(orders.date_created_gmt), MONTH(orders.date_created_gmt)

                    -- Sort by payment method, then by month
					ORDER BY payment_method ASC, month ASC
				",
				$this->wc_orders_table, // main FROM table
				$start_date,
				$end_date,
				$this->wc_orders_table, // WHERE parent orders subquery
				$this->wc_orders_meta_table, // WHERE renewal orders subquery
				$this->wc_orders_meta_table, // WHERE switch orders subquery
				$this->wc_orders_meta_table // WHERE resubscribe orders subquery
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * CPT implementation of get_aggregated_monthly_order_data_by_payment_gateway().
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array Array of database result objects with payment_method, month, count, and gross columns.
	 */
	private function get_cpt_monthly_order_data_by_payment_gateway( string $start_date, string $end_date ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						-- Group by payment method (null becomes empty string)
						COALESCE(payment_meta.meta_value, '') as payment_method,

						-- Group by month (first day of each month)
						DATE_FORMAT(orders.post_date_gmt, '%%Y-%%m') as month,

						-- Overall totals
						COUNT(*) as count,
						SUM(total_meta.meta_value) as gross

					FROM %i AS orders
					-- Join payment method from order meta
					LEFT JOIN %i AS payment_meta ON (
						orders.ID = payment_meta.post_id
						AND payment_meta.meta_key = '_payment_method'
					)

					-- Join order total from order meta
					LEFT JOIN %i AS total_meta ON (
						orders.ID = total_meta.post_id
						AND total_meta.meta_key = '_order_total'
					)
					WHERE orders.post_type = 'shop_order'

						-- Only include successful order statuses (excludes on-hold, pending, failed, etc.)
						AND orders.post_status IN ( $this->active_order_statuses_clause )

						-- Date range filter (last 12 months)
						AND orders.post_date_gmt >= %s
						AND orders.post_date_gmt < %s

						-- Only include orders related to subscriptions
						AND (
							-- Parent orders (orders that created subscriptions)
							orders.ID IN (
								SELECT DISTINCT post_parent
								FROM %i AS subscriptions
								WHERE subscriptions.post_type = 'shop_subscription'
								AND subscriptions.post_parent IS NOT NULL
								AND subscriptions.post_parent <> 0
							)
							-- Renewal orders (recurring subscription payments)
							OR EXISTS (
								SELECT 1 FROM %i AS renewal_meta
								WHERE renewal_meta.post_id = orders.ID
								AND renewal_meta.meta_key = '_subscription_renewal'
							)
							-- Switch orders (subscription plan/product changes)
							OR EXISTS (
								SELECT 1 FROM %i AS switch_meta
								WHERE switch_meta.post_id = orders.ID
								AND switch_meta.meta_key = '_subscription_switch'
							)
							-- Resubscribe orders (reactivated cancelled subscriptions)
							OR EXISTS (
								SELECT 1 FROM %i AS resubscribe_meta
								WHERE resubscribe_meta.post_id = orders.ID
								AND resubscribe_meta.meta_key = '_subscription_resubscribe'
							)
						)

					-- Group by payment method and month
					GROUP BY COALESCE(payment_meta.meta_value, ''), YEAR(orders.post_date_gmt), MONTH(orders.post_date_gmt)

					-- Sort by payment method, then by month
					ORDER BY payment_method ASC, month ASC
				",
				$wpdb->posts, // main FROM table
				$wpdb->postmeta, // LEFT JOIN payment_meta
				$wpdb->postmeta, // LEFT JOIN total_meta
				$start_date,
				$end_date,
				$wpdb->posts, // WHERE parent orders subquery
				$wpdb->postmeta, // WHERE renewal orders subquery
				$wpdb->postmeta, // WHERE switch orders subquery
				$wpdb->postmeta // WHERE resubscribe orders subquery
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Aggregates order data for all related order types (renewal, switch, resubscribe), segmented by month.
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array Nested array grouped by order type, with monthly data as flat arrays. Structure:
	 *   [
	 *     'renewal' => [
	 *       ['month' => '2025-01', 'count' => 10, 'gross' => 500.00, 'non_zero_count' => 8],
	 *       ['month' => '2025-02', 'count' => 12, 'gross' => 600.00, 'non_zero_count' => 10],
	 *     ],
	 *     'switch' => [
	 *       ['month' => '2025-01', 'count' => 5, 'gross' => 250.00, 'non_zero_count' => 4],
	 *     ],
	 *     'resubscribe' => [
	 *       ['month' => '2025-01', 'count' => 2, 'gross' => 100.00, 'non_zero_count' => 2],
	 *     ],
	 *   ]
	 */
	private function aggregate_monthly_order_data_by_type( string $start_date, string $end_date ): array {
		$results = $this->is_hpos
			? $this->get_hpos_aggregate_monthly_order_data_by_type( $start_date, $end_date )
			: $this->get_cpt_aggregate_monthly_order_data_by_type( $start_date, $end_date );

		return $this->format_monthly_order_data_by_type( $results );
	}

	/**
	 * Formats database results into the structure (payment_gateway => [month, metrics]).
	 *
	 * Transforms raw SQL results into a nested array structure where the first level
	 * is grouped by payment gateway, and each payment gateway contains a flat array of monthly data.
	 *
	 * @param array $results Database result objects from get_hpos_monthly_order_data_by_payment_gateway() or get_cpt_monthly_order_data_by_payment_gateway().
	 *
	 * @return array Formatted array with structure:
	 *   [
	 *     'stripe' => [
	 *       ['month' => '2024-01', 'count' => 45, 'gross' => 3750.25],
	 *       ['month' => '2024-02', 'count' => 50, 'gross' => 4000.00],
	 *     ],
	 *     'paypal' => [
	 *       ['month' => '2024-01', 'count' => 30, 'gross' => 2500.00],
	 *     ],
	 *   ]
	 */
	private function format_monthly_order_data_by_payment_gateway( array $results ): array {
		$data = array();

		// Handle empty or null results - return empty array
		if ( empty( $results ) ) {
			return $data;
		}

		foreach ( $results as $result ) {
			$payment_method = $result->payment_method ?? '';

			// Initialize array for this payment method if it doesn't exist
			if ( ! isset( $data[ $payment_method ] ) ) {
				$data[ $payment_method ] = array();
			}

			// Add monthly data to this payment method's array
			$data[ $payment_method ][] = array(
				'month' => $result->month,
				'count' => (int) $result->count,
				'gross' => round( (float) $result->gross, 2 ),
			);
		}

		return $data;
	}

	/**
	 * Formats database results into the structure (order_type => [month, metrics]).
	 *
	 * Transforms raw SQL results into a nested array structure where the first level
	 * is grouped by order type, and each order type contains a flat array of monthly data.
	 *
	 * @param array $results Database result objects from get_hpos_aggregate_monthly_order_data_by_type() or get_cpt_aggregate_monthly_order_data_by_type().
	 *
	 * @return array Formatted array with structure:
	 *   [
	 *     'renewal' => [
	 *       ['month' => '2025-01', 'count' => 10, 'gross' => 500.00, 'non_zero_count' => 8],
	 *       ['month' => '2025-02', 'count' => 12, 'gross' => 600.00, 'non_zero_count' => 10],
	 *     ],
	 *     'switch' => [
	 *       ['month' => '2025-01', 'count' => 5, 'gross' => 250.00, 'non_zero_count' => 4],
	 *     ],
	 *     'resubscribe' => [
	 *       ['month' => '2025-01', 'count' => 2, 'gross' => 100.00, 'non_zero_count' => 2],
	 *     ],
	 *   ]
	 */
	private function format_monthly_order_data_by_type( array $results ): array {
		// Initialize with empty arrays for each order type to ensure consistent structure
		$data = array(
			'renewal'     => array(),
			'switch'      => array(),
			'resubscribe' => array(),
		);

		// Handle empty or null results - return structure with empty arrays
		if ( empty( $results ) ) {
			return $data;
		}

		// Map the order type name with its meta key
		$order_type_meta_key_map = array(
			'_subscription_renewal'     => 'renewal',
			'_subscription_switch'      => 'switch',
			'_subscription_resubscribe' => 'resubscribe',
		);

		foreach ( $results as $result ) {
			// Map meta key to friendly order type name
			$order_type_name = $order_type_meta_key_map[ $result->meta_key ] ?? null;

			if ( ! $order_type_name ) {
				continue;
			}

			// Add monthly data to this order type's array
			$data[ $order_type_name ][] = $this->format_monthly_order_metrics( $result );
		}

		return $data;
	}

	/**
	 * Format a monthly order data entry with consistent type casting and rounding.
	 *
	 * Applies standard formatting to database results:
	 * - Casts count and non_zero_count to integers
	 * - Rounds gross to 2 decimal places
	 * - Optionally merges additional fields (e.g., quantity data)
	 *
	 * @param object $row Database row with month, count, gross, non_zero_count properties.
	 * @return array Formatted monthly entry.
	 */
	private function format_monthly_order_metrics( object $row ): array {
		return array(
			'month'          => $row->month,
			'count'          => (int) $row->count,
			'gross'          => round( (float) $row->gross, 2 ),
			'non_zero_count' => (int) $row->non_zero_count,
		);
	}

	/**
	 * Formats store GMV data into monthly array structure.
	 *
	 * Transforms raw SQL results into a flat array of monthly GMV entries.
	 *
	 * @param array $results Database result objects from get_hpos_store_gmv() or get_cpt_store_gmv().
	 *
	 * @return array Flat array of monthly entries with structure:
	 *   [
	 *     ['month' => '2025-01', 'gross' => 1250.50],
	 *     ['month' => '2025-02', 'gross' => 1500.75],
	 *   ]
	 */
	private function format_aggregate_monthly_store_gmv( array $results ): array {
		$formatted = array();

		// Handle empty or null results
		if ( empty( $results ) ) {
			return $formatted;
		}

		foreach ( $results as $result ) {
			$formatted[] = array(
				'month' => $result->month,
				'gross' => round( (float) $result->gross, 2 ),
			);
		}

		return $formatted;
	}

	/**
	 * HPOS implementation of aggregate_monthly_order_data_by_type().
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array|null Array of database result objects with order data, or null.
	 */
	private function get_hpos_aggregate_monthly_order_data_by_type( string $start_date, string $end_date ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						order_type_meta.meta_key,
						DATE_FORMAT(orders.date_created_gmt, '%%Y-%%m') as month,
						COUNT(*) as count,
						SUM(orders.total_amount) as gross,
						SUM(CASE WHEN orders.total_amount > 0 THEN 1 ELSE 0 END) as non_zero_count
					FROM %i AS orders

					-- Join order type meta
					INNER JOIN %i AS order_type_meta ON (
						order_type_meta.order_id = orders.id
						AND order_type_meta.meta_key IN ('_subscription_renewal', '_subscription_switch', '_subscription_resubscribe')
					)

					WHERE orders.type = 'shop_order'

						-- Only include successful order statuses
						AND orders.status IN ( $this->active_order_statuses_clause )

						-- Date range filter
						AND orders.date_created_gmt >= %s
						AND orders.date_created_gmt < %s

					GROUP BY order_type_meta.meta_key, YEAR(orders.date_created_gmt), MONTH(orders.date_created_gmt)
					ORDER BY order_type_meta.meta_key ASC, month ASC
				",
				$this->wc_orders_table, // FROM %i AS orders
				$this->wc_orders_meta_table, // INNER JOIN %i AS order_type_meta
				$start_date, // AND orders.date_created_gmt >= %s
				$end_date // AND orders.date_created_gmt < %s
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * CPT implementation of aggregate_monthly_order_data_by_type().
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array|null Array of database result objects with order data, or null if no results.
	 */
	private function get_cpt_aggregate_monthly_order_data_by_type( string $start_date, string $end_date ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						order_type_meta.meta_key,
						DATE_FORMAT(orders.post_date_gmt, '%%Y-%%m') as month,
						COUNT(*) as count,
						SUM(total_meta.meta_value) as gross,
						SUM(CASE WHEN total_meta.meta_value > 0 THEN 1 ELSE 0 END) as non_zero_count
					FROM %i AS orders

					-- Join order type meta
					INNER JOIN %i AS order_type_meta ON (
						order_type_meta.post_id = orders.ID
						AND order_type_meta.meta_key IN ('_subscription_renewal', '_subscription_switch', '_subscription_resubscribe')
					)

					-- Join order total from order meta
					LEFT JOIN %i AS total_meta ON (
						orders.ID = total_meta.post_id
						AND total_meta.meta_key = '_order_total'
					)

					WHERE orders.post_type = 'shop_order'

						-- Only include successful order statuses
						AND orders.post_status IN ( $this->active_order_statuses_clause )

						-- Date range filter
						AND orders.post_date_gmt >= %s
						AND orders.post_date_gmt < %s

					GROUP BY order_type_meta.meta_key, YEAR(orders.post_date_gmt), MONTH(orders.post_date_gmt)
					ORDER BY order_type_meta.meta_key ASC, month ASC
				",
				$wpdb->posts, // FROM %i AS orders
				$wpdb->postmeta, // INNER JOIN %i AS order_type_meta
				$wpdb->postmeta, // LEFT JOIN %i AS total_meta
				$start_date, // AND orders.post_date_gmt >= %s
				$end_date // AND orders.post_date_gmt < %s
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Aggregates parent order data (orders that created subscriptions).
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array Associative array with order data.
	 */
	private function aggregate_monthly_parent_order_data( string $start_date, string $end_date ): array {
		$results = $this->is_hpos
			? $this->get_hpos_aggregate_monthly_parent_order_data( $start_date, $end_date )
			: $this->get_cpt_aggregate_monthly_parent_order_data( $start_date, $end_date );

		return $this->format_parent_order_data( $results['order_data'], $results['quantity_data'] );
	}

	/**
	 * Formats parent order data into monthly array structure.
	 *
	 * Merges order data (count, gross, non_zero_count) with quantity data
	 * (total_quantity, non_zero_quantity) by month and applies formatting.
	 *
	 * @param array $order_data   Monthly order results from SQL with month, count, gross, non_zero_count.
	 * @param array $quantity_data Monthly quantity results from SQL with month, total_quantity, non_zero_quantity.
	 *
	 * @return array Flat array of monthly entries with structure:
	 *   [
	 *     ['month' => '2025-01', 'count' => 10, 'gross' => 500.00, 'non_zero_count' => 8, 'quantity' => 15, 'non_zero_quantity' => 12],
	 *     ['month' => '2025-02', 'count' => 12, 'gross' => 600.00, 'non_zero_count' => 10, 'quantity' => 18, 'non_zero_quantity' => 15],
	 *   ]
	 */
	private function format_parent_order_data( array $order_data, array $quantity_data ): array {
		$formatted = array();

		// Build quantity lookup by month.
		$quantity_by_month = array();
		foreach ( $quantity_data as $qty_row ) {
			$quantity_by_month[ $qty_row->month ] = array(
				'quantity'          => (int) $qty_row->total_quantity,
				'non_zero_quantity' => (int) $qty_row->non_zero_quantity,
			);
		}

		// Build final monthly array
		foreach ( $order_data as $order_row ) {
			$month = $order_row->month;

			// Get quantity data for this month (default to 0 if not found)
			$qty_info = $quantity_by_month[ $month ] ?? array(
				'quantity'          => 0,
				'non_zero_quantity' => 0,
			);

			// Format base monthly entry and add quantity fields
			$entry                      = $this->format_monthly_order_metrics( $order_row );
			$entry['quantity']          = $qty_info['quantity'];
			$entry['non_zero_quantity'] = $qty_info['non_zero_quantity'];
			$formatted[]                = $entry;
		}

		return $formatted;
	}

	/**
	 * HPOS implementation of aggregate_monthly_parent_order_data().
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array Associative array with order data.
	 */
	private function get_hpos_aggregate_monthly_parent_order_data( string $start_date, string $end_date ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		// Get order's count, gross, and non-zero count grouped by month
		$order_data = $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						DATE_FORMAT(orders.date_created_gmt, '%%Y-%%m') as month,
						COUNT(*) as count,
						SUM(orders.total_amount) as gross,
						SUM(CASE WHEN orders.total_amount > 0 THEN 1 ELSE 0 END) as non_zero_count
					FROM %i AS orders
					WHERE orders.type = 'shop_order'

						-- Only include successful order statuses
						AND orders.status IN ( $this->active_order_statuses_clause )

						-- Date range filter
						AND orders.date_created_gmt >= %s
						AND orders.date_created_gmt < %s

						-- Parent orders (orders that created subscriptions)
						AND orders.id IN (
							SELECT DISTINCT parent_order_id
							FROM %i AS subscriptions
							WHERE subscriptions.type = 'shop_subscription'
							AND subscriptions.parent_order_id IS NOT NULL
							AND subscriptions.parent_order_id <> 0
						)

					GROUP BY YEAR(orders.date_created_gmt), MONTH(orders.date_created_gmt)
					ORDER BY month ASC
				",
				$this->wc_orders_table, // FROM %i AS orders
				$start_date, // AND orders.date_created_gmt >= %s
				$end_date, // AND orders.date_created_gmt < %s
				$this->wc_orders_table // SELECT DISTINCT parent_order_id FROM %i AS subscriptions
			)
		);

		// Get item quantity counts grouped by month
		$quantity_data = $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						DATE_FORMAT(orders.date_created_gmt, '%%Y-%%m') as month,
						SUM(CAST(wcoimeta.meta_value AS DECIMAL(10,2))) as total_quantity,
						SUM( IF( orders.total_amount > 0, CAST( wcoimeta.meta_value AS DECIMAL( 10,2 ) ), 0 ) ) as non_zero_quantity
					FROM %i AS orders

					-- Join to subscriptions: Only include orders that created subscriptions (parent orders)
					INNER JOIN %i AS subscriptions ON (
						subscriptions.parent_order_id = orders.id
						AND subscriptions.type = 'shop_subscription'
					)

					-- Join to order items: Get all line items for each parent order
					INNER JOIN %i AS wcoitems ON (
						orders.id = wcoitems.order_id
						AND wcoitems.order_item_type = 'line_item'
					)

					-- Join to item metadata: Get quantity values for each line item
					INNER JOIN %i AS wcoimeta ON (
						wcoitems.order_item_id = wcoimeta.order_item_id
						AND wcoimeta.meta_key = '_qty'
					)
					WHERE orders.type = 'shop_order'

						-- Only completed transactions
						AND orders.status IN ( $this->active_order_statuses_clause )

						-- Date range filter
						AND orders.date_created_gmt >= %s
						AND orders.date_created_gmt < %s

					GROUP BY YEAR(orders.date_created_gmt), MONTH(orders.date_created_gmt)
					ORDER BY month ASC
				",
				$this->wc_orders_table, // FROM %i AS orders
				$this->wc_orders_table, // INNER JOIN %i AS subscriptions
				$wpdb->prefix . 'woocommerce_order_items', // INNER JOIN %i AS wcoitems
				$wpdb->prefix . 'woocommerce_order_itemmeta', // INNER JOIN %i AS wcoimeta
				$start_date, // AND orders.date_created_gmt >= %s
				$end_date // AND orders.date_created_gmt < %s
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Return both datasets for formatting
		return array(
			'order_data'    => $order_data,
			'quantity_data' => $quantity_data,
		);
	}

	/**
	 * CPT implementation of aggregate_monthly_parent_order_data().
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array Associative array with order data.
	 */
	private function get_cpt_aggregate_monthly_parent_order_data( string $start_date, string $end_date ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		// Get order's count, gross, and non-zero count grouped by month
		$order_data = $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						DATE_FORMAT(orders.post_date_gmt, '%%Y-%%m') as month,
						COUNT(*) as count,
						SUM(total_meta.meta_value) as gross,
						SUM(CASE WHEN total_meta.meta_value > 0 THEN 1 ELSE 0 END) as non_zero_count
					FROM %i AS orders

					-- Join order total from order meta
					LEFT JOIN %i AS total_meta ON (
						orders.ID = total_meta.post_id
						AND total_meta.meta_key = '_order_total'
					)
					WHERE orders.post_type = 'shop_order'

						-- Only include successful order statuses
						AND orders.post_status IN ( $this->active_order_statuses_clause )

						-- Date range filter
						AND orders.post_date_gmt >= %s
						AND orders.post_date_gmt < %s

						-- Parent orders (orders that created subscriptions)
						AND orders.ID IN (
							SELECT DISTINCT post_parent
							FROM %i AS subscriptions
							WHERE subscriptions.post_type = 'shop_subscription'
							AND subscriptions.post_parent IS NOT NULL
							AND subscriptions.post_parent <> 0
						)

					GROUP BY YEAR(orders.post_date_gmt), MONTH(orders.post_date_gmt)
					ORDER BY month ASC
				",
				$wpdb->posts, // FROM %i AS orders
				$wpdb->postmeta, // LEFT JOIN %i AS total_meta
				$start_date, // AND orders.post_date_gmt >= %s
				$end_date, // AND orders.post_date_gmt < %s
				$wpdb->posts // SELECT DISTINCT post_parent FROM %i AS subscriptions
			)
		);

		// Get item quantity counts grouped by month
		$quantity_data = $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						DATE_FORMAT(orders.post_date_gmt, '%%Y-%%m') as month,
						SUM(CAST(wcoimeta.meta_value AS DECIMAL(10,2))) as total_quantity,
						SUM( IF( total_meta.meta_value > 0, CAST( wcoimeta.meta_value AS DECIMAL( 10,2 ) ), 0 ) ) as non_zero_quantity
					FROM %i AS orders

					-- Join to subscriptions: Only include orders that created subscriptions (parent orders)
					INNER JOIN %i AS subscriptions ON (
						subscriptions.post_parent = orders.ID
						AND subscriptions.post_type = 'shop_subscription'
					)

					-- Join order total from order meta
					LEFT JOIN %i AS total_meta ON (
						orders.ID = total_meta.post_id
						AND total_meta.meta_key = '_order_total'
					)

					-- Join to order items: Get all line items for each parent order
					INNER JOIN %i AS wcoitems ON (
						orders.ID = wcoitems.order_id
						AND wcoitems.order_item_type = 'line_item'
					)

					-- Join to item metadata: Get quantity values for each line item
					INNER JOIN %i AS wcoimeta ON (
						wcoitems.order_item_id = wcoimeta.order_item_id
						AND wcoimeta.meta_key = '_qty'
					)
					WHERE orders.post_type = 'shop_order'

						-- Only completed transactions
						AND orders.post_status IN ( $this->active_order_statuses_clause )

						-- Date range filter
						AND orders.post_date_gmt >= %s
						AND orders.post_date_gmt < %s

					GROUP BY YEAR(orders.post_date_gmt), MONTH(orders.post_date_gmt)
					ORDER BY month ASC
				",
				$wpdb->posts, // FROM %i AS orders
				$wpdb->posts, // INNER JOIN %i AS subscriptions
				$wpdb->postmeta, // LEFT JOIN %i AS total_meta
				$wpdb->prefix . 'woocommerce_order_items', // INNER JOIN %i AS wcoitems
				$wpdb->prefix . 'woocommerce_order_itemmeta', // INNER JOIN %i AS wcoimeta
				$start_date, // AND orders.post_date_gmt >= %s
				$end_date // AND orders.post_date_gmt < %s
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Return both datasets for formatting
		return array(
			'order_data'    => $order_data,
			'quantity_data' => $quantity_data,
		);
	}

	/**
	 * Gets the store's GMV for the specified timeframe.
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array
	 */
	private function aggregate_monthly_store_gmv( string $start_date, string $end_date ): array {
		$results = $this->is_hpos
			? $this->get_hpos_aggregate_monthly_store_gmv( $start_date, $end_date )
			: $this->get_cpt_aggregate_monthly_store_gmv( $start_date, $end_date );

		return $this->format_aggregate_monthly_store_gmv( $results );
	}

	/**
	 * Gets the store's GMV for the specified timeframe (HPOS implementation).
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array
	 */
	private function get_hpos_aggregate_monthly_store_gmv( string $start_date, string $end_date ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						DATE_FORMAT(orders.date_created_gmt, '%%Y-%%m') as month,
						SUM(orders.total_amount) as gross
					FROM %i AS orders
					WHERE orders.type = 'shop_order'
						AND orders.status IN ( $this->active_order_statuses_clause )
						AND orders.date_created_gmt >= %s
						AND orders.date_created_gmt < %s
					GROUP BY YEAR(orders.date_created_gmt), MONTH(orders.date_created_gmt)
					ORDER BY month ASC
				",
				$this->wc_orders_table,
				$start_date,
				$end_date
			)
		);
		// phpcs:enable phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Gets the store's GMV for the specified timeframe (CPT implementation).
	 *
	 * @param string $start_date Start date in 'Y-m-d H:i:s' format.
	 * @param string $end_date   End date in 'Y-m-d H:i:s' format.
	 *
	 * @return array
	 */
	private function get_cpt_aggregate_monthly_store_gmv( string $start_date, string $end_date ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_order_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						DATE_FORMAT(orders.post_date_gmt, '%%Y-%%m') as month,
						SUM(order_meta.meta_value) as gross
					FROM      %i AS orders
					LEFT JOIN %i AS order_meta ON order_meta.post_id = orders.ID
					WHERE     order_meta.meta_key = '_order_total'
					          AND orders.post_status IN ( $this->active_order_statuses_clause )
					          AND orders.post_date_gmt >= %s
					          AND orders.post_date_gmt < %s
					GROUP BY YEAR(orders.post_date_gmt), MONTH(orders.post_date_gmt)
					ORDER BY month ASC
				",
				$wpdb->posts,
				$wpdb->postmeta,
				$start_date,
				$end_date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Normalizes a timestamp range to full month boundaries in UTC.
	 *
	 * Ensures the start timestamp is normalized to the first day of its month
	 * and the end timestamp is normalized to the last day of its month.
	 * This prevents partial month data that could be misleading in monthly reports.
	 *
	 * @param int $start_timestamp Start timestamp.
	 * @param int $end_timestamp   End timestamp.
	 *
	 * @return array {
	 *     'start': string,
	 *     'end':   string,
	 * }
	 */
	private function normalize_timestamp_range_to_month_boundaries( int $start_timestamp, int $end_timestamp ): array {
		// Convert timestamps to DateTime objects in UTC (@ prefix sets UTC automatically)
		$start = new DateTime( '@' . $start_timestamp );
		$end   = new DateTime( '@' . $end_timestamp );

		// Set start date to the first day of its month at 00:00:00 UTC
		$normalized_start = $start->modify( 'first day of this month 00:00:00' )->format( 'Y-m-d H:i:s' );

		// Set end date to the last day of its month at 23:59:59 UTC
		$normalized_end = $end->modify( 'last day of this month 23:59:59' )->format( 'Y-m-d H:i:s' );

		return array(
			'start' => $normalized_start,
			'end'   => $normalized_end,
		);
	}
}
