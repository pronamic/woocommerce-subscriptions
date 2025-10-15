<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Telemetry;

use WC_Payment_Gateways;

/**
 * Provides high-level information, primarily intended for use with WC Tracker, about the number, range,
 * and associated payment methods of subscriptions.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Subscriptions {
	/**
	 * Quoted and comma separated list of subscription statuses that are considered active.
	 *
	 * These are held directly as a string (rather than an array of statuses) because that is how they are consumed, but
	 * if we find it useful in the future to convert to an array (perhaps so they can be filtered), we can make that
	 * change.
	 *
	 * @var string
	 */
	private string $active_subscription_statuses_clause;

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
	 * Prepares Subscriptions telemetry collection.
	 */
	public function __construct() {
		global $wpdb;

		$this->active_subscription_statuses_clause = "'wc-active', 'wc-pending-cancel'";
		$this->is_hpos                             = wcs_is_custom_order_tables_usage_enabled();
		$this->wc_orders_table                     = $wpdb->prefix . 'wc_orders';
		$this->wc_orders_meta_table                = $wpdb->prefix . 'wc_orders_meta';
	}

	/**
	 * Supplies the number of active subscribers. That is, the number of unique users who have at least one active
	 * subscription.
	 *
	 * @param bool $active Whether to return a count of active subscribers (true) or inactive subscribers (false). Default true.
	 *
	 * @return int
	 */
	public function get_subscriber_count( bool $active = true ): int {
		return $this->is_hpos
			? $this->get_hpos_subscriber_count( $active )
			: $this->get_cpt_subscriber_count( $active );
	}

	/**
	 * HPOS implementation of get_active_subscriber_count().
	 *
	 * @param bool $active Whether to return a count of active subscribers (true) or inactive subscribers (false). Default true.
	 *
	 * @return int
	 */
	private function get_hpos_subscriber_count( bool $active = true ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		$base_query = $wpdb->prepare(
			"
				FROM   %i
				WHERE  type = 'shop_subscription'
				       AND customer_id IS NOT NULL
				       AND status IN ( $this->active_subscription_statuses_clause )
			",
			$this->wc_orders_table
		);

		if ( $active ) {
			$query = "
				SELECT COUNT( DISTINCT customer_id )
				$base_query
			";
		} else {
			$query = $wpdb->prepare(
				"
					SELECT COUNT( DISTINCT customer_id )
					FROM   %i
					WHERE  type = 'shop_subscription'
					       AND customer_id IS NOT NULL
					       AND customer_id NOT IN (
					           SELECT DISTINCT customer_id
					           $base_query
					       )
				",
				$this->wc_orders_table
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $query );
	}

	/**
	 * CPT implementation of get_active_subscriber_count().
	 *
	 * @param bool $active Whether to return a count of active subscribers (true) or inactive subscribers (false). Default true.
	 *
	 * @return int
	 */
	private function get_cpt_subscriber_count( bool $active = true ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		$base_query = $wpdb->prepare(
			"
				FROM   %i AS orders
				JOIN   %i AS customer ON (
						   orders.ID = customer.post_id
						   AND customer.meta_key = '_customer_user'
					   )
				WHERE  orders.post_type = 'shop_subscription'
					   AND orders.post_status IN ( $this->active_subscription_statuses_clause )
			",
			$wpdb->posts,
			$wpdb->postmeta
		);

		if ( $active ) {
			$query = "
				SELECT COUNT( DISTINCT customer.meta_value ) AS customer_id
				$base_query
			";
		} else {
			$query = $wpdb->prepare(
				"
					SELECT COUNT( DISTINCT customer.meta_value ) AS customer_id
					FROM   %i AS orders
					JOIN   %i AS customer ON (
							   orders.ID = customer.post_id
							   AND customer.meta_key = '_customer_user'
						   )
					WHERE  orders.post_type = 'shop_subscription'
						   AND customer.meta_value NOT IN (
						       SELECT DISTINCT customer.meta_value
							   $base_query
						   )
				",
				$wpdb->posts,
				$wpdb->postmeta
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $query );
	}

	/**
	 * Supplies the number of active subscriptions that are set to renew automatically.
	 *
	 * @return int
	 */
	public function get_active_subscriptions_renewing_automatically(): int {
		return $this->is_hpos
			? $this->get_hpos_subscriptions_count()
			: $this->get_cpt_subscriptions_count();
	}

	/**
	 * Supplies the number of active subscriptions that are set to renew manually.
	 *
	 * @return int
	 */
	public function get_active_subscriptions_renewing_manually(): int {
		return $this->is_hpos
			? $this->get_hpos_subscriptions_count( false )
			: $this->get_cpt_subscriptions_count( false );
	}

	/**
	 * HPOS implementation for get_active_subscriptions_renewing_<automatically|manually>().
	 *
	 * @param bool $automatic_renewal If we are interested specifically in subscriptions renewing automatically (true) or manually (false). Default true.
	 *
	 * @return int
	 */
	private function get_hpos_subscriptions_count( bool $automatic_renewal = true ): int {
		global $wpdb;
		$renewal_condition = $automatic_renewal ? '<>' : '=';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"
					SELECT    COUNT( 1 ) AS order_count
					FROM      %i AS orders
					LEFT JOIN %i AS order_meta ON (
					              orders.id = order_meta.order_id
					              AND order_meta.meta_key = '_requires_manual_renewal'
					          )
					WHERE     orders.type = 'shop_subscription'
					          AND orders.status IN ( $this->active_subscription_statuses_clause )
					          AND order_meta.meta_value $renewal_condition 'true'
				",
				$this->wc_orders_table,
				$this->wc_orders_meta_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * CPT implementation for get_active_subscriptions_renewing_<automatically|manually>().
	 *
	 * @param bool $automatic_renewal If we are interested specifically in subscriptions renewing automatically (true) or manually (false). Default true.
	 *
	 * @return int
	 */
	private function get_cpt_subscriptions_count( bool $automatic_renewal = true ): int {
		global $wpdb;
		$renewal_condition = $automatic_renewal ? '<>' : '=';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"
					SELECT    COUNT( 1 ) AS order_count
					FROM      %i AS orders
					LEFT JOIN %i AS order_meta ON (
					              orders.ID = order_meta.post_id
					              AND order_meta.meta_key = '_requires_manual_renewal'

					          )
					WHERE     orders.post_type = 'shop_subscription'
					          AND orders.post_status IN ( $this->active_subscription_statuses_clause )
					          AND order_meta.meta_value $renewal_condition 'true'
				",
				$wpdb->posts,
				$wpdb->postmeta
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Returns an array of objects detailing the number of (active) subscriptions by frequency.
	 *
	 * The return value is an array of objects, with each object containing the properties period, interval and count:
	 *
	 *     [
	 *         {
	 *             period:   string,
	 *             interval: int,
	 *             count:    int
	 *         },
	 *         ...
	 *     ]
	 *
	 * @return object[]
	 */
	public function get_subscriptions_by_frequency(): array {
		$results = (array) (
			$this->is_hpos
				? $this->get_hpos_subscriptions_by_frequency()
				: $this->get_cpt_subscriptions_by_frequency()
		);

		foreach ( $results as &$result_set ) {
			$result_set->period   = (string) $result_set->period;
			$result_set->interval = (int) $result_set->interval;
			$result_set->count    = (int) $result_set->count;
		}

		return $results;
	}

	/**
	 * HPOS implementation of get_subscriptions_by_frequency().
	 */
	private function get_hpos_subscriptions_by_frequency(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT    billing_period.meta_value AS period,
							  billing_interval.meta_value AS `interval`,
							  COUNT(*) AS count
					FROM      %i AS orders
					LEFT JOIN %i AS billing_period ON (
								  orders.id = billing_period.order_id
								  AND billing_period.meta_key = '_billing_period'
							  )
					LEFT JOIN %i AS billing_interval ON (
								  orders.id = billing_interval.order_id
								  AND billing_interval.meta_key = '_billing_interval'
							  )
					WHERE     orders.type = 'shop_subscription'
					          AND orders.status IN ( $this->active_subscription_statuses_clause )
					GROUP BY  billing_period.meta_value,
							  billing_interval.meta_value
					ORDER BY  count DESC,
							  billing_period.meta_value ASC,
							  billing_interval.meta_value DESC
				",
				$this->wc_orders_table,
				$this->wc_orders_meta_table,
				$this->wc_orders_meta_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * CPT implementation of get_subscriptions_by_frequency().
	 */
	private function get_cpt_subscriptions_by_frequency(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT    billing_period.meta_value AS period,
							  billing_interval.meta_value AS `interval`,
							  COUNT(*) AS count
					FROM      %i AS orders
					LEFT JOIN %i AS billing_period ON (
								  orders.ID = billing_period.post_id
								  AND billing_period.meta_key = '_billing_period'
							  )
					LEFT JOIN %i AS billing_interval ON (
								  orders.ID = billing_interval.post_id
								  AND billing_interval.meta_key = '_billing_interval'
							  )
					WHERE     orders.post_type = 'shop_subscription'
					          AND orders.post_status IN ( $this->active_subscription_statuses_clause )
					GROUP BY  billing_period.meta_value,
							  billing_interval.meta_value
					ORDER BY  count DESC,
							  billing_period.meta_value ASC,
							  billing_interval.meta_value DESC
				",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Gets a count of subscriptions grouped by payment method.
	 *
	 * The return value is an array of objects, with each object containing the properties payment_method and order_count:
	 *
	 *     [
	 *         {
	 *             payment_method:              string,
	 *             active_subscription_count:   int,
	 *             inactive_subscription_count: int,
	 *             method_renews_off_site:      string, # 'yes'|'no'|'unknown'
	 *             manual_renewal_only:         string, # 'yes'|'no'|'unknown'
	 *         },
	 *         ...
	 *     ]
	 *
	 * Note that the method_renews_off_site property is set if the gateway reports that it supports off-site renewal
	 * payment ('gateway_scheduled_payments'). See also payment_method_renews_off_site() for caveats on this.
	 *
	 * @return object[] Array of objects containing payment_method and order_count.
	 */
	public function get_subscriptions_by_payment_method(): array {
		$results = $this->is_hpos
			? $this->get_hpos_subscriptions_by_payment_method()
			: $this->get_cpt_subscriptions_by_payment_method();

		foreach ( $results as &$result_set ) {
			$gateway_properties                      = $this->payment_method_properties( $result_set->payment_method );
			$result_set->payment_method              = (string) $result_set->payment_method;
			$result_set->active_subscription_count   = (int) $result_set->active_subscription_count;
			$result_set->inactive_subscription_count = (int) $result_set->inactive_subscription_count;
			$result_set->method_renews_off_site      = $gateway_properties['gateway_scheduled_payments'];
			$result_set->manual_renewal_only         = $gateway_properties['manual_renewal_only'];
		}

		return $results;
	}

	/**
	 * HPOS implementation of get_subscriptions_by_payment_method().
	 */
	private function get_hpos_subscriptions_by_payment_method(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						     IFNULL( payment_method, '' ) as payment_method,
						     SUM( CASE WHEN status IN ( $this->active_subscription_statuses_clause ) THEN 1 ELSE 0 END ) AS active_subscription_count,
						     SUM( CASE WHEN status NOT IN ( $this->active_subscription_statuses_clause ) THEN 1 ELSE 0 END ) AS inactive_subscription_count
					FROM     %i
					WHERE    type = 'shop_subscription'
					GROUP BY IFNULL(payment_method, '')
					ORDER BY active_subscription_count DESC,
					         inactive_subscription_count DESC;
				",
				$this->wc_orders_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * CPT implementation of get_subscriptions_by_payment_method().
	 */
	private function get_cpt_subscriptions_by_payment_method(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- property $this->active_subscription_statuses_clause is sanitized.
		return $wpdb->get_results(
			$wpdb->prepare(
				"
					SELECT
						      IFNULL(order_meta.meta_value, '') AS payment_method,
						      SUM( CASE WHEN orders.post_status IN ( $this->active_subscription_statuses_clause ) THEN 1 ELSE 0 END ) AS active_subscription_count,
						      SUM( CASE WHEN orders.post_status NOT IN ( $this->active_subscription_statuses_clause ) THEN 1 ELSE 0 END ) AS inactive_subscription_count
					FROM      %i AS orders
					LEFT JOIN %i AS order_meta ON orders.ID = order_meta.post_id
						 AND  order_meta.meta_key = '_payment_method'
					WHERE     orders.post_type = 'shop_subscription'
					GROUP BY  IFNULL(order_meta.meta_value, '')
					ORDER BY  active_subscription_count DESC,
					          inactive_subscription_count DESC;
				",
				$wpdb->posts,
				$wpdb->postmeta
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Indicates if the specified payment method supports off-site renewal payment (ie, gateway scheduled payments), and
	 * if it supports subscriptions.
	 *
	 * Note that, just because a gateways supports 'gateway_scheduled_payments', does not mean it is supported for a
	 * specific subscription.
	 *
	 * @param string $payment_method
	 *
	 * @return array {
	 *     gateway_scheduled_payments: string
	 *     manual_renewal_only:        string
	 * }
	 */
	private function payment_method_properties( string $payment_method ): array {
		// If a particular gateway is disabled/inactive, then we cannot determine what it does or does not support.
		$properties = array(
			'gateway_scheduled_payments' => 'unknown',
			'manual_renewal_only'        => 'unknown',
		);

		foreach ( WC_Payment_Gateways::instance()->payment_gateways() as $gateway ) {
			if ( $payment_method === $gateway->id ) {
				$properties['gateway_scheduled_payments'] = $gateway->supports( 'gateway_scheduled_payments' ) ? 'yes' : 'no';
				$properties['manual_renewal_only']        = $gateway->supports( 'subscriptions' ) ? 'no' : 'yes';
				break;
			}
		}

		return $properties;
	}

	/**
	 * Supplies the total count of gifted subscriptions.
	 *
	 * @return int
	 */
	public function get_gifted_subscriptions_count(): int {
		return $this->is_hpos
			? $this->get_hpos_gifted_subscriptions_count()
			: $this->get_cpt_gifted_subscriptions_count();
	}

	/**
	 * HPOS implementation of get_gifted_subscriptions_count().
	 *
	 * @return int
	 */
	private function get_hpos_gifted_subscriptions_count(): int {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"
					SELECT COUNT(DISTINCT orders.id)
					FROM %i AS orders
					INNER JOIN %i AS orders_meta ON (orders.id = orders_meta.order_id)
					WHERE orders.type = 'shop_subscription'
					AND orders.status NOT IN ( 'auto-draft', 'trash' )
					AND orders_meta.meta_key = '_recipient_user'
				",
				$this->wc_orders_table,
				$this->wc_orders_meta_table
			)
		);

		return absint( $count );
	}

	/**
	 * CPT implementation of get_gifted_subscriptions_count().
	 *
	 * @return int
	 */
	private function get_cpt_gifted_subscriptions_count(): int {
		global $wpdb;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"
					SELECT COUNT(DISTINCT posts.ID)
					FROM %i AS posts
					INNER JOIN %i AS posts_meta ON (posts.ID = posts_meta.post_id)
					WHERE posts.post_type = 'shop_subscription'
					AND posts.post_status NOT IN ( 'auto-draft', 'trash' )
					AND posts_meta.meta_key = '_recipient_user'
				",
				$wpdb->posts,
				$wpdb->postmeta,
			)
		);

		return absint( $count );
	}
}
