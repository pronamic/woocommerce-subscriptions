<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use DateTimeImmutable;

/**
 * Collection of safeguards and kill-switches for the Health Check tool.
 *
 * The `ScheduleManager` consults these methods before dispatching any
 * scheduled work. Each method is a pure predicate (or small side-effect) so
 * it can be tested in isolation against options, filters and constants —
 * there is no coupling to Action Scheduler here.
 *
 * Kill-switches:
 *  - Support-level filter `wcs_health_check_scans_enabled` (a12s escape hatch).
 *  - Merchant-level option
 *    `woocommerce_subscriptions_enable_health_check_nightly_scan` (toggled
 *    via WC > Settings > Subscriptions). Stored as `'yes'`/`'no'`,
 *    defaulting to `'no'` so fresh installs do not run nightly scans
 *    until the merchant opts in. The Health Check tab itself is always
 *    visible regardless of this option — the option only gates the
 *    scheduled (nightly) scan; merchants can still trigger an on-demand
 *    scan via the "Run now" button.
 * Both must be on for `can_run()` to return true.
 *
 * Run-time guards:
 *  - Store-local nightly window (default 02:00-05:00, filterable; supports
 *    wrap-around windows like [22, 3] for a 10pm-3am quiet period).
 *  - Rolling 24h ceiling on subscriptions processed (default 100k) so a
 *    runaway scan cannot hammer a huge store. Filter-overridable for
 *    support-tuned deployments.
 *  - Transient back-off checks (WP importing, WP maintenance mode) so we do
 *    not compete with critical foreground traffic. Additional store-
 *    specific probes can be layered in via the
 *    `wcs_health_check_should_back_off` filter from a mu-plugin.
 *  - Heartbeat + consecutive-failure tracking so support can see stuck or
 *    repeatedly-failing installs and the breaker can self-trip. The
 *    consecutive-failure threshold is filter-overridable.
 *
 * Concurrency model: `RunStore::start()` atomically rejects a second
 * running scan-type row, so at most one scan is in flight at a time.
 * Within that scan, SCAN_BATCH actions chain sequentially via
 * `as_schedule_single_action()` with an inter-batch delay — there is
 * never a second worker racing this one on the same run. The two
 * counter implementations split along that invariant:
 *
 *  - `record_processed()` is the rolling 24h bucket map. It does a
 *    read-modify-write through `update_option()` and is safe ONLY
 *    because of the single-writer regime above. If that assumption
 *    ever changes (parallel SCAN_BATCH workers, multi-run execution)
 *    it must move to `atomic_increment_option()`.
 *  - `record_batch_processed()` is a per-run accumulator. It delegates
 *    to `atomic_increment_transient()`, which is SQL-atomic via
 *    `INSERT ... ON DUPLICATE KEY UPDATE` - safe regardless of the
 *    single-writer invariant.
 *  - `record_scan_position()` is not a counter at all: it overwrites
 *    the run's absolute position with `set_transient()`. A batch that
 *    throws after the write and is retried rewrites the same position
 *    instead of advancing past it.
 *
 * The environmental probes used by `should_back_off()` are delegated to
 * small protected helpers (`is_wp_importing()` etc.) so tests can override
 * them via a subclass without defining global PHP constants that outlive a
 * single test method. `current_hour()` is delegated the same way so tests
 * can exercise the wrap-around branch of `within_nightly_window()` without
 * a time-freezing library.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class CircuitBreaker {

	/**
	 * Option: `'yes'` to allow scheduled scans, `'no'` (default) to stop
	 * them. Stored in the WC `'yes'/'no'` convention so it round-trips
	 * cleanly with the WC settings checkbox UI in
	 * `Bootstrap::add_settings()`. Autoloaded because `can_run()` is
	 * consulted on every scheduled dispatch, including the early
	 * no-op path. Default `'no'` (off): fresh installs require the
	 * merchant to opt in to nightly scans before the AS schedule
	 * registers.
	 */
	public const OPTION_SCHEDULE_ENABLED = 'woocommerce_subscriptions_enable_health_check_nightly_scan';

	/**
	 * Option: MySQL-format UTC datetime of the most recent successful batch.
	 */
	private const OPTION_LAST_HEARTBEAT = 'wcs_health_check_last_heartbeat';

	/**
	 * Option: count of consecutive failed batches since the last success.
	 */
	private const OPTION_CONSECUTIVE_FAILURES = 'wcs_health_check_consecutive_failures';

	/**
	 * Option: assoc array keyed by `YYYY-MM-DD-HH` (UTC) => int count of
	 * subscriptions processed during that hour. Pruned on every write so it
	 * never holds more than 24 buckets.
	 */
	private const OPTION_PROCESSED_24H = 'wcs_health_check_subs_processed_24h';

	/**
	 * Option: human-readable reason for the most recent trip. Shown in the
	 * Settings tab so the merchant knows why the feature self-disabled.
	 */
	private const OPTION_TRIP_REASON = 'wcs_health_check_trip_reason';

	/**
	 * Number of consecutive failures that cause the breaker to trip.
	 */
	public const CONSECUTIVE_FAILURE_THRESHOLD = 3;

	/**
	 * Maximum subscriptions processed in any rolling 24h window before
	 * scheduled work stops dispatching.
	 */
	public const DAILY_CEILING_SUBS = 100000;

	/**
	 * Default start/end hours (store-local) of the nightly scan window.
	 *
	 * @var array{0:int,1:int}
	 */
	private const DEFAULT_SCAN_WINDOW = array( 2, 5 );

	/**
	 * Transient key prefix for the per-run scan position. Scoped
	 * per-run-id so concurrent scans do not share state, and stored as a
	 * transient (rather than an option) so the keys expire on their own
	 * if a run dies between start and finalisation.
	 *
	 * Deliberately a different key from the `wcs_health_check_scanned_`
	 * counter this replaced: that key held a shortlist tally, not a
	 * position, so reading it would report a wrong number rather than a
	 * stale one. A scan already in flight when the plugin updates reads a
	 * missing position and shows "0 of N" until its next batch lands
	 * (~30s), and the old rows expire on their own TTL.
	 */
	private const POSITION_TRANSIENT_PREFIX = 'wcs_health_check_scan_position_';

	/**
	 * Transient key prefix for the per-run "batches processed"
	 * counter — incremented once per SCAN_BATCH invocation. Feeds the
	 * `batches_processed` stat on the Tracks scan_completed event.
	 */
	public const BATCHES_TRANSIENT_PREFIX = 'wcs_health_check_batches_';

	/**
	 * TTL for the per-run counters and position record. One day is
	 * generous - a typical scan finishes in minutes, but a stuck run
	 * shouldn't wedge them forever.
	 */
	private const RUN_STATE_TRANSIENT_TTL = DAY_IN_SECONDS;

	/**
	 * Whether the **scheduled** nightly scan may run right now.
	 *
	 * Gates the cron path only — the on-demand "Run now" button on
	 * the Status tab bypasses this entirely (the merchant explicitly
	 * asked for a scan, so options and filters must not block it).
	 *
	 * Three gates, all of which must pass for the schedule to fire:
	 *   1. Support-level `wcs_health_check_scans_enabled` filter.
	 *   2. Tripped breaker — `trip()` was called after persistent
	 *      failures; the system has indicated it can't safely run.
	 *   3. Merchant-level
	 *      `woocommerce_subscriptions_enable_health_check_nightly_scan`
	 *      option (default off).
	 *
	 * @return bool
	 */
	public function can_run(): bool {
		/**
		 * Support-level kill switch for the Health Check scheduled work.
		 * Distinct from `wcs_health_check_tool_enabled` (`Bootstrap`),
		 * which gates the entire module including the admin surface —
		 * this filter only blocks the *scheduled* path. The on-demand
		 * "Run now" button is unconditional and ignores this filter;
		 * use the tool-wide filter to remove the button itself.
		 *
		 * @since 8.7.0
		 *
		 * @param bool $enabled Whether scheduled scans may execute. Defaults to true.
		 */
		$support_enabled = (bool) apply_filters( 'wcs_health_check_scans_enabled', true );
		if ( ! $support_enabled ) {
			return false;
		}

		if ( $this->is_tripped() ) {
			return false;
		}

		return $this->is_schedule_enabled();
	}

	/**
	 * Whether the merchant has the health-check nightly scan enabled.
	 *
	 * @return bool
	 */
	public function is_schedule_enabled(): bool {
		return 'yes' === (string) get_option( self::OPTION_SCHEDULE_ENABLED, 'no' );
	}

	/**
	 * Flip the merchant schedule toggle. Used by the circuit breaker
	 * trip path (auto-disable after persistent failures).
	 *
	 * Re-enabling the schedule also clears any persisted trip reason —
	 * the merchant re-enabling is the explicit acknowledgement that
	 * clears the tripped state, so the Health Check tab's breaker-tripped
	 * notice stops rendering on the next page load.
	 *
	 * @param bool $enabled Whether scheduled scans should be enabled.
	 *
	 * @return void
	 */
	public function toggle_schedule( bool $enabled ): void {
		update_option( self::OPTION_SCHEDULE_ENABLED, $enabled ? 'yes' : 'no', true );

		if ( $enabled ) {
			delete_option( self::OPTION_TRIP_REASON );
		}
	}

	/**
	 * Whether the breaker is currently in a tripped state — the scan
	 * schedule is disabled AND a trip reason is persisted.
	 *
	 * Distinguishes a merchant-initiated pause (Pause button — schedule
	 * off, no trip reason) from a breaker-initiated pause (trip() was
	 * called — schedule off AND trip reason set). The Status tab uses
	 * this to decide whether to render the "last scan encountered an
	 * issue" notice.
	 *
	 * @return bool
	 */
	public function is_tripped(): bool {
		if ( $this->is_schedule_enabled() ) {
			return false;
		}
		$reason = (string) get_option( self::OPTION_TRIP_REASON, '' );
		return '' !== $reason;
	}

	/**
	 * Whether the current moment is inside the store-local nightly scan
	 * window.
	 *
	 * The window is `[start_hour, end_hour]` (both ints, 0-23 in the happy
	 * path; filterable to arbitrary ranges for testing). We evaluate the
	 * current hour in the store's WP timezone — not UTC — because merchants
	 * configure the window by "my store is quiet from 2am local time". A UTC
	 * comparison would drift by the store's offset.
	 *
	 * Membership semantics: when `start <= end`,
	 * `start_hour <= current_hour < end_hour`, so `[2, 5]` covers 02:00-04:59
	 * inclusive. Setting start==end yields a zero-width (always-closed)
	 * window, which is the knob tests use to force a miss deterministically.
	 *
	 * Wrap-around: when `start > end`, the window straddles midnight. For
	 * `[22, 3]` the hour is in the window when `current_hour >= 22` OR
	 * `current_hour < 3`, covering 22:00-02:59 across two calendar days.
	 *
	 * @return bool
	 */
	public function within_nightly_window(): bool {
		/**
		 * Nightly scan window bounds as `[start_hour, end_hour]` in the
		 * store's local time. See the method docblock for the
		 * wrap-around semantics when `start > end`. Defaults to `[2, 5]`
		 * (02:00–04:59 store-local).
		 *
		 * @since 8.7.0
		 *
		 * @param array{0:int,1:int} $window Two-element [start, end] hour pair.
		 */
		$window = apply_filters( 'wcs_health_check_scan_window', self::DEFAULT_SCAN_WINDOW );
		if ( ! is_array( $window ) || count( $window ) < 2 ) {
			$window = self::DEFAULT_SCAN_WINDOW;
		}

		$start = (int) $window[0];
		$end   = (int) $window[1];
		$hour  = $this->current_hour();

		// Normal (non-wrap) window: e.g. [2, 5] means 02:00-04:59.
		if ( $start <= $end ) {
			return $hour >= $start && $hour < $end;
		}

		// Wrap-around window: e.g. [22, 3] means 22:00-23:59 OR 00:00-02:59.
		// Merchants in UTC-offset regions or non-24h-operating stores often
		// pick this shape when their quiet period straddles midnight.
		return $hour >= $start || $hour < $end;
	}

	/**
	 * Whether the rolling 24h processed-subscriptions total remains under
	 * the daily ceiling.
	 *
	 * Buckets older than 24h are ignored at read-time (they will be pruned
	 * on the next `record_processed()` write, but we don't mutate state on
	 * a predicate call).
	 *
	 * @return bool
	 */
	public function under_daily_ceiling(): bool {
		/**
		 * Maximum number of subscriptions the Health Check may inspect
		 * within a rolling 24-hour window before scans are paused. A
		 * safety rail for very large stores where an unexpectedly
		 * hot scan could add material load.
		 *
		 * @since 8.7.0
		 *
		 * @param int $ceiling Defaults to `CircuitBreaker::DAILY_CEILING_SUBS`.
		 */
		$ceiling = (int) apply_filters( 'wcs_health_check_daily_ceiling_subs', self::DAILY_CEILING_SUBS );
		$buckets = $this->load_processed_buckets();
		$cutoff  = time() - DAY_IN_SECONDS;
		$total   = 0;

		foreach ( $buckets as $key => $count ) {
			if ( $this->bucket_key_to_time( $key ) < $cutoff ) {
				continue;
			}
			$total += (int) $count;
		}

		return $total < $ceiling;
	}

	/**
	 * Whether the breaker should skip the next batch for a few minutes.
	 *
	 * Built-in probes cover WordPress-level conditions only: the
	 * `WP_IMPORTING` constant and `wp_is_maintenance_mode()`. These are the
	 * only signals we can rely on without reaching into third-party APIs
	 * that may or may not exist on a given install.
	 *
	 * Custom checks — store-specific busy conditions, WC import screens,
	 * specific AJAX actions, promotional-launch overrides — can be layered
	 * via the `wcs_health_check_should_back_off` filter from a mu-plugin.
	 * The filter receives the built-in determination and this instance, so a
	 * site can either add extra true-conditions (e.g. "also back off during
	 * my BOPIS rush") or veto the built-in result (e.g. "always run even if
	 * WP_IMPORTING is set — we set it permanently during a staged migration").
	 *
	 * @return bool
	 */
	public function should_back_off(): bool {
		$built_in = $this->is_wp_importing() || $this->is_wp_maintenance();

		/**
		 * Whether the next SCAN_BATCH should be re-queued 5 minutes out
		 * instead of running now. Use to layer site-specific busy
		 * conditions on top of the built-in WP_IMPORTING +
		 * maintenance-mode probes.
		 *
		 * @since 8.7.0
		 *
		 * @param bool           $should_back_off Built-in determination.
		 * @param CircuitBreaker $breaker         The breaker instance, so filters
		 *                                        can inspect additional state.
		 */
		return (bool) apply_filters( 'wcs_health_check_should_back_off', $built_in, $this );
	}

	/**
	 * Record a heartbeat — call once per successful batch. The stored
	 * MySQL-format UTC datetime feeds `get_heartbeat_age_seconds()`,
	 * which the Tracks emitter reports on breaker trips so support can
	 * answer "how long has this install been stuck?" without opening
	 * the database.
	 */
	public function record_heartbeat(): void {
		update_option( self::OPTION_LAST_HEARTBEAT, current_time( 'mysql', true ), false );
	}

	/**
	 * Record a failed batch. Call once per caught exception.
	 */
	public function record_failure(): void {
		$this->atomic_increment_option( self::OPTION_CONSECUTIVE_FAILURES, 1 );
	}

	/**
	 * Clear the consecutive-failure counter on a successful batch.
	 */
	public function reset_consecutive_failures(): void {
		update_option( self::OPTION_CONSECUTIVE_FAILURES, 0, false );
	}

	/**
	 * Whether the breaker should trip based on the consecutive-failure
	 * counter alone.
	 *
	 * @return bool
	 */
	public function should_trip(): bool {
		/**
		 * Number of consecutive batch failures that trip the breaker
		 * and halt scheduled scans until the merchant clicks Resume.
		 *
		 * @since 8.7.0
		 *
		 * @param int $threshold Defaults to `CircuitBreaker::CONSECUTIVE_FAILURE_THRESHOLD`.
		 */
		$threshold = (int) apply_filters( 'wcs_health_check_consecutive_failure_threshold', self::CONSECUTIVE_FAILURE_THRESHOLD );
		$failures  = (int) get_option( self::OPTION_CONSECUTIVE_FAILURES, 0 );
		return $failures >= $threshold;
	}

	/**
	 * Trip the breaker: disable the merchant schedule option and persist
	 * the reason for the Settings tab UI.
	 *
	 * Also emits a WC log entry so support can correlate a trip with the
	 * upstream error that caused it.
	 *
	 * @param string $reason Human-readable rationale (e.g. the exception
	 *                       message from the batch that exceeded the
	 *                       consecutive-failure threshold).
	 */
	public function trip( string $reason ): void {
		$this->toggle_schedule( false );
		update_option( self::OPTION_TRIP_REASON, $reason, false );

		if ( function_exists( 'wc_get_logger' ) ) {
			$failures  = (int) get_option( self::OPTION_CONSECUTIVE_FAILURES, 0 );
			$heartbeat = $this->heartbeat_age_seconds();

			wc_get_logger()->warning(
				sprintf(
					'Health Check circuit breaker tripped after %d consecutive failures (heartbeat age: %s). Reason: %s',
					$failures,
					-1 === $heartbeat ? 'never' : $heartbeat . 's',
					$reason
				),
				array( 'source' => 'wcs-health-check' )
			);
		}
	}

	/**
	 * Seconds since the last recorded heartbeat, or -1 when no heartbeat has
	 * ever been recorded (or the stored value is unparseable).
	 *
	 * Used in the `trip()` log line so support can correlate a breaker trip
	 * with "did any batch ever actually run?" without opening the database.
	 *
	 * @return int
	 */
	private function heartbeat_age_seconds(): int {
		$last = (string) get_option( self::OPTION_LAST_HEARTBEAT, '' );
		if ( '' === $last ) {
			return -1;
		}

		$timestamp = strtotime( $last . ' UTC' );
		if ( false === $timestamp ) {
			return -1;
		}

		return max( 0, time() - $timestamp );
	}

	/**
	 * Increment the rolling 24h counter by `$count` processed subscriptions
	 * and prune buckets older than 24h in the same write.
	 *
	 * Pruning happens on write (not read) so the option size stays bounded
	 * without the read-path — which is consulted on every scheduled
	 * dispatch — having to touch storage.
	 *
	 * @param int $count Number of subscriptions processed in the most
	 *                   recent batch. Negative values are clamped to zero
	 *                   rather than decrementing the counter.
	 */
	public function record_processed( int $count ): void {
		if ( $count < 0 ) {
			$count = 0;
		}

		$buckets = $this->load_processed_buckets();
		$cutoff  = time() - DAY_IN_SECONDS;

		foreach ( $buckets as $key => $_ ) {
			if ( $this->bucket_key_to_time( $key ) < $cutoff ) {
				unset( $buckets[ $key ] );
			}
		}

		$current_key             = gmdate( 'Y-m-d-H', time() );
		$buckets[ $current_key ] = ( (int) ( $buckets[ $current_key ] ?? 0 ) ) + $count;

		update_option( self::OPTION_PROCESSED_24H, $buckets, false );
	}

	/**
	 * Record how far through the store a scan run has reached.
	 *
	 * The scan runs each check as its own keyset walk over the whole id
	 * space (`id > after_id ORDER BY id LIMIT N`), one check after another,
	 * so a run's position is two numbers: how many checks have drained, and
	 * how far the check now running has moved its cursor. Together they say
	 * which subscriptions the run has been through -- everything at or below
	 * the cursor has been evaluated by the running check, matched or ruled
	 * out, and every subscription has been evaluated by each drained check.
	 * `ScanProgress` turns that into the "N of M subscriptions scanned"
	 * reading on the Status tab.
	 *
	 * Deliberately an absolute write rather than an accumulator: a batch that
	 * throws after this call is retried with the same arguments, which
	 * rewrites the same position. The scanned counter this replaced was a
	 * delta and inflated on every such retry.
	 *
	 * @param int $run_id           The scan run id.
	 * @param int $checks_completed Checks whose keyset walk has drained.
	 * @param int $cursor           Highest subscription id the running check
	 *                              has passed. Zero when it has not started.
	 *
	 * @return void
	 */
	public function record_scan_position( int $run_id, int $checks_completed, int $cursor ): void {
		set_transient(
			self::POSITION_TRANSIENT_PREFIX . $run_id,
			array(
				'checks_completed' => max( $checks_completed, 0 ),
				'cursor'           => max( $cursor, 0 ),
			),
			self::RUN_STATE_TRANSIENT_TTL
		);
	}

	/**
	 * The position recorded for a run, or a zeroed position when the run has
	 * not recorded one yet (unstarted, or the transient expired under a
	 * stuck run).
	 *
	 * @param int $run_id The scan run id.
	 *
	 * @return array{checks_completed: int, cursor: int}
	 */
	public function get_scan_position( int $run_id ): array {
		$stored = get_transient( self::POSITION_TRANSIENT_PREFIX . $run_id );

		if ( ! is_array( $stored ) ) {
			return array(
				'checks_completed' => 0,
				'cursor'           => 0,
			);
		}

		return array(
			'checks_completed' => max( (int) ( $stored['checks_completed'] ?? 0 ), 0 ),
			'cursor'           => max( (int) ( $stored['cursor'] ?? 0 ), 0 ),
		);
	}

	/**
	 * Increment the per-run "batches processed" counter. Called once
	 * per SCAN_BATCH invocation so the Tracks scan_completed payload
	 * knows how many rounds of work the scan took.
	 *
	 * @param int $run_id The scan run id.
	 *
	 * @return void
	 */
	public function record_batch_processed( int $run_id ): void {
		$this->atomic_increment_transient( self::BATCHES_TRANSIENT_PREFIX . $run_id, 1 );
	}

	/**
	 * Total SCAN_BATCH invocations recorded for a run, or 0 when
	 * never recorded.
	 *
	 * @param int $run_id The scan run id.
	 *
	 * @return int
	 */
	public function get_total_batches_processed( int $run_id ): int {
		$stored = get_transient( self::BATCHES_TRANSIENT_PREFIX . $run_id );
		return false === $stored ? 0 : (int) $stored;
	}

	/**
	 * Current consecutive-failure count. Surfaced to the Tracks
	 * breaker-tripped payload.
	 *
	 * @return int
	 */
	public function get_consecutive_failures(): int {
		return (int) get_option( self::OPTION_CONSECUTIVE_FAILURES, 0 );
	}

	/**
	 * Seconds since the last recorded heartbeat, or -1 when no
	 * heartbeat has ever been recorded. Public alias of the internal
	 * helper so the Tracks emitter can pass the value through without
	 * duplicating the parsing logic.
	 *
	 * @return int
	 */
	public function get_heartbeat_age_seconds(): int {
		return $this->heartbeat_age_seconds();
	}

	/**
	 * Whether WordPress is running a .maintenance file (site-wide core
	 * update). Overridable for testing.
	 *
	 * @return bool
	 */
	protected function is_wp_maintenance(): bool {
		return function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode();
	}

	/**
	 * Whether `WP_IMPORTING` is defined and truthy. Overridable for
	 * testing — the constant cannot be un-defined between PHPUnit tests
	 * without process isolation, so the production path is covered by the
	 * default false case and the override-based true case.
	 *
	 * @return bool
	 */
	protected function is_wp_importing(): bool {
		return defined( 'WP_IMPORTING' ) && constant( 'WP_IMPORTING' );
	}

	/**
	 * The current hour of the day (0-23) in the store's WP timezone.
	 *
	 * Overridable for testing — `within_nightly_window()` consults this so a
	 * test-only subclass can pin the "now" hour to exercise the wrap-around
	 * branch deterministically, without a time-freezing library.
	 *
	 * @return int
	 */
	protected function current_hour(): int {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		return (int) $now->format( 'G' );
	}

	/**
	 * Load the processed-buckets option and defensively coerce it to an
	 * associative array.
	 *
	 * @return array<string, int>
	 */
	private function load_processed_buckets(): array {
		$buckets = get_option( self::OPTION_PROCESSED_24H, array() );
		return is_array( $buckets ) ? $buckets : array();
	}

	/**
	 * Atomically increments a numeric option row without reading through the
	 * WordPress options cache first.
	 *
	 * @param string $option_name Option name.
	 * @param int    $increment   Positive increment amount.
	 *
	 * @return void
	 */
	private function atomic_increment_option( string $option_name, int $increment ): void {
		if ( $increment <= 0 ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Atomic option increment; affected cache keys are invalidated below.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload )
				VALUES ( %s, %d, 'no' )
				ON DUPLICATE KEY UPDATE option_value = CAST( option_value AS UNSIGNED ) + %d",
				$option_name,
				$increment,
				$increment
			)
		);

		$this->invalidate_option_cache( $option_name );
	}

	/**
	 * Atomically increments a transient-backed counter and refreshes its TTL.
	 *
	 * @param string $transient Transient key without the `_transient_` prefix.
	 * @param int    $increment Positive increment amount.
	 *
	 * @return void
	 */
	private function atomic_increment_transient( string $transient, int $increment ): void {
		if ( $increment <= 0 ) {
			return;
		}

		if ( wp_using_ext_object_cache() ) {
			if ( false !== wp_cache_incr( $transient, $increment, 'transient' ) ) {
				return;
			}

			if ( wp_cache_add( $transient, $increment, 'transient', self::RUN_STATE_TRANSIENT_TTL ) ) {
				return;
			}

			wp_cache_incr( $transient, $increment, 'transient' );
			return;
		}

		$timeout_option = '_transient_timeout_' . $transient;
		$value_option   = '_transient_' . $transient;

		$this->set_option_direct( $timeout_option, time() + self::RUN_STATE_TRANSIENT_TTL );
		$this->atomic_increment_option( $value_option, $increment );
		$this->invalidate_transient_cache( $transient );
	}

	/**
	 * Writes an option row directly, inserting it when absent.
	 *
	 * @param string $option_name Option name.
	 * @param int    $value       Integer value to store.
	 *
	 * @return void
	 */
	private function set_option_direct( string $option_name, int $value ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct timeout write paired with explicit cache invalidation.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} ( option_name, option_value, autoload )
				VALUES ( %s, %d, 'no' )
				ON DUPLICATE KEY UPDATE option_value = %d",
				$option_name,
				$value,
				$value
			)
		);

		$this->invalidate_option_cache( $option_name );
	}

	/**
	 * Clears option cache entries affected by direct SQL writes.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return void
	 */
	private function invalidate_option_cache( string $option_name ): void {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Clears transient cache entries affected by direct SQL writes.
	 *
	 * @param string $transient Transient key without the `_transient_` prefix.
	 *
	 * @return void
	 */
	private function invalidate_transient_cache( string $transient ): void {
		wp_cache_delete( $transient, 'transient' );
		wp_cache_delete( $transient, 'transient_timeout' );
	}

	/**
	 * Parse a `YYYY-MM-DD-HH` bucket key back into a Unix timestamp.
	 *
	 * Unparseable keys return 0 so they fall below any sane cutoff and
	 * get pruned on the next write — safer than carrying a malformed key
	 * around.
	 *
	 * @param string $key Bucket key produced by `gmdate( 'Y-m-d-H', ... )`.
	 *
	 * @return int Unix timestamp at the top of that hour, or 0 when
	 *             parsing failed.
	 */
	private function bucket_key_to_time( string $key ): int {
		// gmdate('Y-m-d-H') emits e.g. `2026-04-21-13`. Convert to a form
		// strtotime() will grok (swap the last hyphen for a space) and
		// treat as UTC.
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})-(\d{2})$/', $key, $m ) ) {
			return 0;
		}

		$ts = strtotime( $m[1] . ' ' . $m[2] . ':00:00 UTC' );
		return false === $ts ? 0 : $ts;
	}
}
