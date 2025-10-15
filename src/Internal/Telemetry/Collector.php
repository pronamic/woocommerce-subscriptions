<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Telemetry;

use Automattic\WooCommerce_Subscriptions\Internal\Utilities\Scheduled_Actions;

/**
 * Handles the collection of telemetry data.
 *
 * This supplements and integrates with the data collected and sent by WC_Subscriptions_Tracker.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Collector {
	public const TRANSIENT_KEY          = 'wcs-tracker-data';
	public const TRANSIENT_LIFESPAN     = WEEK_IN_SECONDS;
	public const SCHEDULED_ACTION_HOOK  = 'wcs-collect-telemetry-data';
	public const SCHEDULED_ACTION_GROUP = 'wcs-telemetry';

	/**
	 * Sets up telemetry collection.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_filter( self::SCHEDULED_ACTION_HOOK, array( $this, 'collect_telemetry_data' ) );

		// Every 3 days, update our telemetry ready for use the next time WC Tracker calls home. Note that we use the
		// unique flag to indicate that there should only ever be one instance of this action.
		Scheduled_Actions::schedule_recurring_action(
			HOUR_IN_SECONDS,
			3 * DAY_IN_SECONDS,
			self::SCHEDULED_ACTION_HOOK,
			array(),
			self::SCHEDULED_ACTION_GROUP,
			true
		);
	}

	/**
	 * Supplies an array containing our collected telemetry data.
	 *
	 * Additionally, a 'telemetry_cache' key is added to the array, which indicates whether the data was fetched from
	 * cache or generated fresh.
	 *
	 * @return array
	 */
	public function get_telemetry_data(): array {
		// Fetch our recently cached telemetry data, if available.
		$telemetry = get_transient( self::TRANSIENT_KEY );

		// Otherwise (if it has not yet been generated, or expired) then let's fetch it now.
		if ( ! is_array( $telemetry ) || empty( $telemetry ) ) {
			$telemetry                    = $this->collect_telemetry_data();
			$telemetry['telemetry_cache'] = 'miss';
		} else {
			$telemetry['telemetry_cache'] = 'hit';
		}

		return $telemetry;
	}

	/**
	 * Captures the results of various telemetry queries, and stores them in the database (via the Options API).
	 *
	 * @return array
	 */
	public function collect_telemetry_data(): array {
		$orders        = new Orders();
		$products      = new Products();
		$subscriptions = new Subscriptions();
		$start_timer   = microtime( true );

		$telemetry = array(
			'order_trends'  => array(
				'by_order_type'      => $orders->get_aggregated_monthly_order_data( time() - YEAR_IN_SECONDS, time() ),
				'by_payment_gateway' => $orders->get_aggregated_monthly_order_data_by_payment_gateway( time() - YEAR_IN_SECONDS, time() ),
			),
			'products'      => array(
				'frequencies' => $products->get_product_frequencies(),
				'giftable'    => $products->get_active_giftable_products_count(),
			),
			'subscriptions' => array(
				'gifted'                 => $subscriptions->get_gifted_subscriptions_count(),
				'payment_methods'        => $subscriptions->get_subscriptions_by_payment_method(),
				'renewal_frequencies'    => $subscriptions->get_subscriptions_by_frequency(),
				'renewing_automatically' => $subscriptions->get_active_subscriptions_renewing_automatically(),
				'renewing_manually'      => $subscriptions->get_active_subscriptions_renewing_manually(),
				'subscriber_count'       => $subscriptions->get_subscriber_count(),
			),
		);

		$telemetry['generation_timestamp']  = time();
		$telemetry['total_generation_time'] = ( microtime( true ) - $start_timer ) * 1_000;
		set_transient( self::TRANSIENT_KEY, $telemetry, self::TRANSIENT_LIFESPAN );

		return $telemetry;
	}
}
