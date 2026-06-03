<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Admin\CandidatesListTable;

/**
 * Single source of truth for the in-flight scan progress reading.
 *
 * Wraps `RunStore::get_in_flight_scan()` (is a scan running?),
 * `CircuitBreaker::get_total_scanned()` (the running numerator) and
 * `CandidatesListTable::count_all_subscriptions()` (the store-total
 * denominator) so both `StatusTab` (server render) and the
 * `wcs_health_check_scan_status` AJAX endpoint (background poll) compute
 * the same `{ in_flight, run_id, scanned, total }` reading and format the
 * same "N of M subscriptions scanned" copy. Keeping the clamp + format
 * string here avoids duplicating them across the render path and the JS
 * poll response.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class ScanProgress {

	/**
	 * @var RunStore
	 */
	private $run_store;

	/**
	 * @var CircuitBreaker
	 */
	private $circuit_breaker;

	public function __construct(
		?RunStore $run_store = null,
		?CircuitBreaker $circuit_breaker = null
	) {
		$this->run_store       = $run_store ?? new RunStore();
		$this->circuit_breaker = $circuit_breaker ?? new CircuitBreaker();
	}

	/**
	 * Read the current scan-progress state.
	 *
	 * When a scan is in flight the numerator is `CircuitBreaker::get_total_scanned()`
	 * clamped to `[0, total]` (a delete-during-scan race never produces a "210 of 200"
	 * reading), and the denominator is the store-wide subscription total. When idle
	 * every field is zeroed and `in_flight` is false so callers can short-circuit.
	 *
	 * @return array{in_flight: bool, run_id: int, scanned: int, total: int}
	 */
	public function get_status(): array {
		$in_flight_run = $this->run_store->get_in_flight_scan();

		if ( null === $in_flight_run ) {
			return array(
				'in_flight' => false,
				'run_id'    => 0,
				'scanned'   => 0,
				'total'     => 0,
			);
		}

		$run_id  = (int) $in_flight_run['id'];
		$total   = CandidatesListTable::count_all_subscriptions();
		$scanned = min( max( $this->circuit_breaker->get_total_scanned( $run_id ), 0 ), $total );

		return array(
			'in_flight' => true,
			'run_id'    => $run_id,
			'scanned'   => $scanned,
			'total'     => $total,
		);
	}

	/**
	 * Build the bold-wrapped "**N** of **M** subscriptions scanned" fragment for the
	 * LAST SCAN card / AJAX poll. Returns null when the store is empty (`$total <= 0`)
	 * so the caller can fall back to a static headline. Output is raw HTML — the caller
	 * is responsible for `wp_kses`-ing it with the `strong` allowlist.
	 *
	 * @param int $scanned Subscriptions scanned so far (already clamped by get_status()).
	 * @param int $total   Store-wide subscription total.
	 *
	 * @return string|null
	 */
	public static function format_label( int $scanned, int $total ): ?string {
		if ( $total <= 0 ) {
			return null;
		}

		return sprintf(
			/* translators: 1: bold-wrapped count of subscriptions scanned so far in the in-flight run. 2: bold-wrapped store total. */
			__( '%1$s of %2$s subscriptions scanned', 'woocommerce-subscriptions' ),
			'<strong>' . esc_html( number_format_i18n( $scanned ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
		);
	}

	/**
	 * Plain-text equivalent of {@see format_label()} for the `aria-live` region — the same
	 * i18n string without the `<strong>` markup, so screen readers announce only the copy.
	 * Returns null when the store is empty (`$total <= 0`).
	 *
	 * @param int $scanned Subscriptions scanned so far (already clamped by get_status()).
	 * @param int $total   Store-wide subscription total.
	 *
	 * @return string|null
	 */
	public static function format_text( int $scanned, int $total ): ?string {
		if ( $total <= 0 ) {
			return null;
		}

		return sprintf(
			/* translators: 1: count of subscriptions scanned so far in the in-flight run. 2: store total. */
			__( '%1$s of %2$s subscriptions scanned', 'woocommerce-subscriptions' ),
			number_format_i18n( $scanned ),
			number_format_i18n( $total )
		);
	}
}
