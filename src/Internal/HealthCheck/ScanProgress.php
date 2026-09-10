<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Single source of truth for the scan progress reading.
 *
 * Wraps `RunStore::get_in_flight_scan()` (is a scan running?),
 * `CircuitBreaker::get_scan_position()` (how far the run has reached) and
 * `SubscriptionCounts::all_and_covered()` (the store total, and how much of it
 * the position has passed) so
 * both `StatusTab` (server render) and the `wcs_health_check_scan_status`
 * AJAX endpoint (background poll) compute the same
 * `{ in_flight, run_id, scanned, total }` reading and format the same
 * "N of M subscriptions scanned" copy. Keeping the arithmetic + format
 * string here avoids duplicating them across the render path, the JS poll
 * response and the stats a finished run persists.
 *
 * `count_scanned()` is the arithmetic. It is what makes the numerator and
 * the denominator the same universe: both count subscriptions in the
 * store, so a finished run reports the figure the subscriptions list
 * shows rather than the far smaller shortlist total it used to
 * (WOOSUBS-1908).
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
	 * When a scan is in flight the numerator is `count_scanned()` and the denominator
	 * is the store-wide subscription total. When idle every field is zeroed and
	 * `in_flight` is false so callers can short-circuit.
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

		$run_id = (int) $in_flight_run['id'];

		try {
			$reading = self::read( $this->circuit_breaker->get_scan_position( $run_id ) );
		} catch ( HealthCheckDbException $e ) {
			// The 5s poll must keep answering while a scan is in flight even
			// when the store cannot be counted - a zeroed reading drops the
			// "N of M" fragment and leaves the "Scanning now..." headline,
			// the same fallback an empty store takes. The guard has already
			// logged the failure.
			$reading = array(
				'scanned' => 0,
				'total'   => 0,
			);
		}

		return array(
			'in_flight' => true,
			'run_id'    => $run_id,
			'scanned'   => $reading['scanned'],
			'total'     => $reading['total'],
		);
	}

	/**
	 * The `{ scanned, total }` pair for a run at `$position`, off one snapshot of
	 * the store.
	 *
	 * Every surface that reports progress goes through here - the server render,
	 * the AJAX poll, and the stats a finishing run persists - so all three read
	 * the same numerator against the same denominator, and each costs one query.
	 *
	 * Propagates `SubscriptionCounts`' failure raise untouched: a reading built
	 * on a failed count would be indistinguishable from an empty store, so
	 * there is no honest value to return. Callers that can survive a missing
	 * reading (the render and poll paths, `cancel_scan()`) catch and degrade;
	 * the worker finalise path lets it reach `handle_scan_batch()`'s catch and
	 * retries.
	 *
	 * @param array{checks_completed: int, cursor: int} $position Position from
	 *                                                            `CircuitBreaker::get_scan_position()`.
	 *
	 * @return array{scanned: int, total: int}
	 *
	 * @throws HealthCheckDbException When the store count query fails or never reaches the database.
	 */
	public static function read( array $position ): array {
		$counts = SubscriptionCounts::all_and_covered( max( (int) ( $position['cursor'] ?? 0 ), 0 ) );

		return array(
			'scanned' => self::count_scanned( $position, $counts['total'], $counts['covered'] ),
			'total'   => $counts['total'],
		);
	}

	/**
	 * Subscriptions a run has scanned, from the position it recorded.
	 *
	 * A run works through the checks in `ScheduleManager::CHECK_TYPE_CHAIN` one at a
	 * time, each a keyset walk over the whole store, so its work is
	 * `check_count() x store total`. The subscriptions it has been through are the
	 * ones every drained check covered (all of them) plus the ones at or below the
	 * running check's cursor; dividing by the number of checks turns that into a
	 * single 0-to-store-total progression rather than a count that restarts each time
	 * a check hands off.
	 *
	 * Clamped to `[0, $total]` so a position recorded against a larger store reports as
	 * complete rather than overrunning.
	 *
	 * Mid-run, the number is therefore run progress, not distinct subscriptions
	 * evaluated: at the moment the first of two checks has been past the whole
	 * store, it reads total/2, not total. Reviewed and accepted (WOOSUBS-1908):
	 * demonstrating steady progress that never overstates beats an exact
	 * distinct count - the per-check alternatives either reset to zero at the
	 * hand-off or sit at "total of total" under a "Scanning now" headline for
	 * the second half of the run. The completed-run figure is exact either way.
	 *
	 * Because both inputs are read from the store rather than accumulated, the reading
	 * tracks a store that changes under the scan - trash a hundred subscriptions
	 * mid-scan and it moves down. That is the trade the fix makes: a number that means
	 * "how far through the store this scan is" is worth more than one that only ever
	 * grows, which is what reported the shortlist size in the first place.
	 *
	 * @param array{checks_completed: int, cursor: int} $position Position from
	 *                                                            `CircuitBreaker::get_scan_position()`.
	 * @param int                                       $total    Store-wide subscription total.
	 * @param int                                       $covered  Subscriptions at or below the
	 *                                                            position's cursor, from the same
	 *                                                            snapshot as `$total`.
	 *
	 * @return int
	 */
	public static function count_scanned( array $position, int $total, int $covered ): int {
		if ( $total <= 0 ) {
			return 0;
		}

		$checks_completed = max( (int) ( $position['checks_completed'] ?? 0 ), 0 );

		$scanned = intdiv(
			$checks_completed * $total + max( $covered, 0 ),
			max( ScheduleManager::check_count(), 1 )
		);

		return min( max( $scanned, 0 ), $total );
	}

	/**
	 * Build the bold-wrapped "**N** of **M** subscriptions scanned" fragment for the
	 * LAST SCAN card / AJAX poll. Returns null when the store is empty (`$total <= 0`)
	 * so the caller can fall back to a static headline. Output is raw HTML — the caller
	 * is responsible for `wp_kses`-ing it with the `strong` allowlist.
	 *
	 * @param int $scanned Subscriptions scanned so far (already clamped by count_scanned()).
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
	 * @param int $scanned Subscriptions scanned so far (already clamped by count_scanned()).
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
