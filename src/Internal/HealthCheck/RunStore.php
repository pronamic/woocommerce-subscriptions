<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Persistence layer for Health Check diagnostic runs.
 *
 * A "run" represents a single scan performed by the Health Check tool.
 * Creates the row when a scan starts and transitions it to a terminal
 * state (completed / failed / cancelled) along with any associated
 * metadata.
 *
 * When a scan run completes successfully, the latest scan id is cached
 * in an autoloaded option so the admin notice can check for pending candidates
 * on every wp-admin page load without a database query.
 *
 * The schema retains `type` + `paused` status values in case a future
 * remediation flow is reintroduced, but v1 only uses `scan` runs.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class RunStore {

	private const TYPE_SCAN = 'scan';

	/**
	 * Status value: the run row is in flight - the scan started and has
	 * not yet reached a terminal state.
	 */
	public const STATUS_RUNNING = 'running';

	/**
	 * Status value: the run completed successfully.
	 */
	public const STATUS_COMPLETED = 'completed';

	/**
	 * Status value: the run terminated because of an error or the
	 * CircuitBreaker tripping. Distinct from `STATUS_CANCELLED` so the
	 * breaker's failure counters do not include merchant-initiated
	 * cancellations.
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Status value: the run was cancelled by the merchant before it
	 * completed. Soft-cancel: candidates already detected stay in the
	 * candidate table; the row carries partial-progress stats so the
	 * Status tab can render "Cancelled X seconds ago" with partial
	 * counts.
	 */
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Option key used to cache the latest completed scan's run id.
	 */
	private const LATEST_SCAN_RUN_ID_OPTION = 'wcs_health_check_latest_scan_run_id';

	/**
	 * Insert a new run row if and only if no run of the same `type` is
	 * currently `running`, returning its database id. Returns 0 when
	 * the insert was rejected (a concurrent caller already holds the
	 * per-type named lock, the pre-insert SELECT saw an in-flight run,
	 * or the INSERT itself failed).
	 *
	 * Atomicity comes from a MySQL per-type named session lock
	 * (`GET_LOCK`). Both `ScheduleManager::start_scan()` (Run now) and
	 * `ScheduleManager::handle_daily_scan()` (nightly cron) pre-check
	 * via `get_in_flight_scan()` and then call `start()`, but the check
	 * and the insert are two separate statements. Without a mutex, two
	 * callers passing the pre-check simultaneously both insert and
	 * enqueue `SCAN_BATCH` chains that write to the shared candidate
	 * table under distinct run ids. The named lock serialises the
	 * check-then-insert so only one caller wins.
	 *
	 * Callers that care about the reason for a 0 return can consult
	 * `get_in_flight_scan()`: a non-null row means a concurrent caller
	 * won; null means the INSERT itself failed.
	 *
	 * @param string $type         Run type — currently always `scan` in v1.
	 *                             The column retains capacity for `fix`/`undo`
	 *                             values if a future remediation flow ships.
	 * @param string $triggered_by Short marker describing the trigger
	 *                             (e.g. `scheduled`, `user`).
	 *
	 * @return int The newly created run id, or 0 when the insert was
	 *             rejected (conflict or SQL error).
	 */
	public function start( string $type, string $triggered_by ): int {
		global $wpdb;

		// Atomicity is implemented via a MySQL named session lock
		// rather than an `INSERT ... SELECT ... WHERE NOT EXISTS
		// (SELECT ... FROM same_table)` one-shot. The one-shot form
		// works in production but the WP test framework rewrites
		// `CREATE TABLE` to `CREATE TEMPORARY TABLE`, and MySQL
		// forbids any single statement from referencing the same
		// temporary table twice (`Can't reopen table`) — so the
		// elegant form would break CI on every test that exercises
		// a scan start. `GET_LOCK` is connection-scoped, works
		// against temp tables and regular tables alike, and releases
		// automatically on connection close — a PHP crash mid-scan
		// can't leave the lock held. The lock name is scoped to
		// `$type` so a hypothetical `fix` or `undo` run type never
		// blocks an in-flight `scan`.
		$lock_name = 'wcs_hc_start_' . $type;
		$acquired  = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
		);

		if ( '1' !== $acquired ) {
			// Another caller holds the lock — treat as conflict.
			// Callers use `get_in_flight_scan()` to distinguish
			// "another run is in flight" from a real DB error.
			return 0;
		}

		try {
			// Inside the lock, the check-then-insert pair is race-free.
			// No other caller can INSERT a `running` row of this type
			// between our SELECT and our INSERT because they are all
			// serialised on the same named lock.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM %i WHERE type = %s AND status = %s LIMIT 1',
					$wpdb->prefix . 'wcs_health_check_runs',
					$type,
					self::STATUS_RUNNING
				)
			);

			if ( null !== $existing ) {
				return 0;
			}

			$inserted = $wpdb->insert(
				$wpdb->prefix . 'wcs_health_check_runs',
				array(
					'type'         => $type,
					'started_at'   => current_time( 'mysql', true ),
					'status'       => self::STATUS_RUNNING,
					'triggered_by' => $triggered_by,
				),
				array( '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return 0;
			}

			return (int) $wpdb->insert_id;
		} finally {
			// Release the lock on every exit path so the next caller
			// doesn't wait 0s on a "try-lock" and see failure. The
			// MySQL docs guarantee the release if the connection
			// closes, so a crash here is still safe.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Mark a run as completed and persist the summary stats.
	 *
	 * For scan-type runs the latest run id is also cached in an autoloaded
	 * option so the admin notice can find it cheaply. The write is guarded
	 * by a monotonic id check: since run ids come from an auto-incrementing
	 * primary key, a higher id unambiguously represents a newer run. This
	 * prevents a delayed completion (for example a watchdog finalizing a
	 * stuck run) from overwriting the option with an older id.
	 *
	 * @param int                  $run_id The run id returned from `start()`.
	 * @param string               $type   The run type originally passed to
	 *                                     `start()`; currently always `scan`
	 *                                     in v1. Passed explicitly to avoid an
	 *                                     extra SELECT after the UPDATE.
	 * @param array<string, mixed> $stats  Summary statistics to persist as JSON.
	 */
	public function complete( int $run_id, string $type, array $stats ): void {
		global $wpdb;

		// Conditional UPDATE: only flip rows that are still `running`. Without this guard a
		// batch that is already executing when the merchant clicks Cancel (the `handle_scan_batch`
		// preflight cannot stop it - it has already passed the read) would finish its work and
		// silently overwrite the `cancelled` row back to `completed`, defeating the cancellation.
		// Mirrors the guard already in place on `cancel()` and `fail()`.
		$result = $wpdb->update(
			$wpdb->prefix . 'wcs_health_check_runs',
			array(
				'status'       => self::STATUS_COMPLETED,
				'completed_at' => current_time( 'mysql', true ),
				'stats_json'   => wp_json_encode( $stats ),
			),
			array(
				'id'     => $run_id,
				'status' => self::STATUS_RUNNING,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $result ) {
			wc_get_logger()->error(
				sprintf( 'Health Check: failed to mark run %d as completed — %s', $run_id, $wpdb->last_error ),
				array( 'source' => 'wcs-health-check' )
			);
			return;
		}

		// Zero affected rows means the row is no longer running (cancelled / failed / already
		// completed by a peer call). Do NOT promote a non-completed run into the autoloaded
		// `LATEST_SCAN_RUN_ID_OPTION`; the option must track genuinely completed scans only.
		if ( 0 === $result ) {
			return;
		}

		if ( self::TYPE_SCAN === $type ) {
			$latest_recorded_id = (int) get_option( self::LATEST_SCAN_RUN_ID_OPTION, 0 );
			if ( $run_id > $latest_recorded_id ) {
				update_option( self::LATEST_SCAN_RUN_ID_OPTION, $run_id, true );
			}
		}
	}

	/**
	 * Mark a run as failed and persist the error message.
	 *
	 * @param int    $run_id        The run id returned from `start()`.
	 * @param string $error_message Human-readable error message.
	 */
	public function fail( int $run_id, string $error_message ): void {
		global $wpdb;

		// Conditional UPDATE: same shape as `complete()` and `cancel()`. A batch already mid-
		// execution can throw and route here AFTER the merchant has cancelled; without the
		// `status = running` guard, that late-arriving `fail()` would overwrite the cancelled
		// row to `failed` and bump the circuit-breaker counter for an event the merchant
		// already intentionally aborted.
		$result = $wpdb->update(
			$wpdb->prefix . 'wcs_health_check_runs',
			array(
				'status'        => self::STATUS_FAILED,
				'completed_at'  => current_time( 'mysql', true ),
				'error_message' => $error_message,
			),
			array(
				'id'     => $run_id,
				'status' => self::STATUS_RUNNING,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $result ) {
			wc_get_logger()->error(
				sprintf( 'Health Check: failed to mark run %d as failed — %s', $run_id, $wpdb->last_error ),
				array( 'source' => 'wcs-health-check' )
			);
		}
	}

	/**
	 * Mark a running scan as cancelled by the merchant. Conditional UPDATE:
	 * the WHERE clause restricts the change to rows still in `running`, so a
	 * race against a parallel batch finalising the run (completed) or
	 * tripping the breaker (failed) loses cleanly without overwriting the
	 * terminal state.
	 *
	 * Soft cancel by design: the candidates persisted during the run stay
	 * in the candidate table, and the supplied `$stats` snapshot is
	 * persisted on the row so the Status tab can render the partial
	 * progress (e.g. "412 of ~2,300 scanned before cancel").
	 *
	 * The autoloaded `LATEST_SCAN_RUN_ID_OPTION` is intentionally NOT
	 * touched here: that option drives the SCOPE card and tracks
	 * completed runs only - a cancelled run must not promote itself to
	 * "latest".
	 *
	 * @param int                  $run_id The run id returned from `start()`.
	 * @param array<string, mixed> $stats  Partial-progress snapshot to
	 *                                     persist alongside the cancel marker.
	 *
	 * @return bool True when the row was flipped from `running` to
	 *              `cancelled`; false when the WHERE clause rejected the
	 *              UPDATE (already completed / failed / cancelled) or the
	 *              UPDATE itself errored.
	 */
	public function cancel( int $run_id, array $stats ): bool {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'wcs_health_check_runs',
			array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql', true ),
				'stats_json'   => wp_json_encode( $stats ),
			),
			array(
				'id'     => $run_id,
				'status' => self::STATUS_RUNNING,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $result ) {
			wc_get_logger()->error(
				sprintf( 'Health Check: failed to mark run %d as cancelled - %s', $run_id, $wpdb->last_error ),
				array( 'source' => 'wcs-health-check' )
			);
			return false;
		}

		return $result > 0;
	}

	/**
	 * Fetch a run row by id.
	 *
	 * @param int $run_id The run id returned from `start()`.
	 *
	 * @return array<string, mixed>|null The row as an associative array, or
	 *                                   null when no matching run exists.
	 */
	public function get( int $run_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$wpdb->prefix . 'wcs_health_check_runs',
				$run_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the ID of the most recently completed scan run, or 0 if none.
	 *
	 * This exposes the autoloaded latest-scan option to consumers (such as
	 * the admin notice) without leaking the option key itself — `RunStore`
	 * remains the single source of truth for that storage location.
	 *
	 * @return int The latest completed scan run id, or 0 when no scan has
	 *             completed yet.
	 */
	public function get_latest_scan_run_id(): int {
		return (int) get_option( self::LATEST_SCAN_RUN_ID_OPTION, 0 );
	}

	/**
	 * Returns the most recent `scan`-type run in any terminal status -
	 * `completed`, `failed`, or `cancelled` - or null when no terminal scan
	 * row exists yet.
	 *
	 * Unlike `get_latest_scan_run_id()`, which is sourced from the autoloaded
	 * `LATEST_SCAN_RUN_ID_OPTION` and tracks completed runs only, this helper
	 * surfaces the freshest terminal row regardless of how it ended. The LAST
	 * SCAN card on the Status tab uses it so a cancelled scan can render
	 * "Cancelled X seconds ago" with the partial counts persisted by
	 * `cancel()` - the completed-only option would otherwise mask the
	 * cancelled row behind the previous successfully-completed run.
	 *
	 * In-flight (`running`) rows are excluded by construction: the card is
	 * for terminal states only and surfacing a half-finished row here would
	 * read as a UI glitch on the eight-second auto-reload. Non-scan-type runs
	 * are also excluded - the card is exclusively for scan rows.
	 *
	 * Ordering is `id DESC` (auto-increment), so a delayed write cannot mask
	 * a newer row.
	 *
	 * @return array<string, mixed>|null The most recent terminal scan row,
	 *                                   or null when none exists.
	 */
	public function get_latest_terminal_run(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE type = %s AND status IN ( %s, %s, %s )
				 ORDER BY id DESC
				 LIMIT 1',
				$wpdb->prefix . 'wcs_health_check_runs',
				self::TYPE_SCAN,
				self::STATUS_COMPLETED,
				self::STATUS_FAILED,
				self::STATUS_CANCELLED
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Returns the most recent `scan`-type run that is still in `running`
	 * state, or null when no scan is currently in flight.
	 *
	 * Consumed by the Status tab to:
	 *  - Disable the "Run scan now" button while a scan is underway.
	 *  - No-op the form-post handler when a second admin clicks the
	 *    button at the same time, so the pipeline isn't enqueued twice.
	 *
	 * Only `scan` rows are consulted — a running `fix` or `undo` run
	 * lives on its own pipeline and must not block scan dispatches.
	 * Failed / completed rows are excluded so historical stuck runs
	 * can't mask the current state.
	 *
	 * Two stale-detection gates auto-fail abandoned rows:
	 *
	 *  1. Hard 24h ceiling. Even on a very large store the scan queue
	 *     shouldn't legitimately run longer than a day; anything older
	 *     is definitely stuck.
	 *  2. 15-minute "nothing happened" check. If a run has been
	 *     `running` for more than 15 minutes AND the
	 *     `CircuitBreaker::record_batch_processed` transient counter
	 *     for this run id is still 0, the Action Scheduler worker
	 *     never picked up the batch — a common dev-environment state
	 *     where WP-Cron is disabled and no admin traffic triggered
	 *     the AS queue. Without this earlier gate the Run-now button
	 *     sits in the in-flight spinner state for the full 24 hours
	 *     because every page load finds the "running" row.
	 *
	 * A run that HAS processed at least one batch is left alone
	 * regardless of age (up to the 24h ceiling) — it's making
	 * forward progress.
	 *
	 * @return array<string, mixed>|null The run row, or null when no
	 *                                   scan is running.
	 */
	public function get_in_flight_scan(): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE type = %s AND status = %s
				 ORDER BY id DESC
				 LIMIT 1',
				$wpdb->prefix . 'wcs_health_check_runs',
				self::TYPE_SCAN,
				self::STATUS_RUNNING
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$run_id     = (int) $row['id'];
		$started_at = (string) ( $row['started_at'] ?? '' );
		// `strtotime()` returns `false` on a corrupted `started_at`; the
		// implicit bool→int coercion would produce `time() - 0` ≈ 1.7B
		// seconds and trip the 24h auto-fail branch on every page load
		// for a row that's actually only seconds old. Mirror the
		// false-guard pattern used in
		// `StatusTab::human_time_since_mysql_utc` and
		// `ScheduleManager::emit_scan_completed_event` so a parse
		// failure yields age=0 (treated as fresh) instead of age=∞.
		$timestamp = $started_at ? strtotime( $started_at . ' UTC' ) : false;
		$age       = false !== $timestamp ? ( time() - $timestamp ) : 0;

		$auto_fail_reason = '';
		if ( $age > DAY_IN_SECONDS ) {
			$auto_fail_reason = sprintf(
				'Abandoned: no batch activity detected after %d hours.',
				(int) ( $age / HOUR_IN_SECONDS )
			);
		} elseif ( $age > 15 * MINUTE_IN_SECONDS && 0 === $this->batches_processed_for_run( $run_id ) ) {
			$auto_fail_reason = sprintf(
				'Abandoned: no batch processed after %d minutes — likely an Action Scheduler queue that never fired.',
				(int) ( $age / MINUTE_IN_SECONDS )
			);
		}

		if ( '' !== $auto_fail_reason ) {
			$updated = $wpdb->update(
				$wpdb->prefix . 'wcs_health_check_runs',
				array(
					'status'        => self::STATUS_FAILED,
					'completed_at'  => current_time( 'mysql', true ),
					'error_message' => $auto_fail_reason,
				),
				array(
					'id'     => $run_id,
					'status' => self::STATUS_RUNNING,
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( $updated ) {
				wc_get_logger()->warning(
					sprintf( 'Health Check: auto-failed abandoned scan run %d (age %ds) — %s', $run_id, $age, $auto_fail_reason ),
					array( 'source' => 'wcs-health-check' )
				);
			}

			return null;
		}

		return $row;
	}

	/**
	 * Per-run batch counter — mirrors
	 * `CircuitBreaker::get_total_batches_processed()` without taking a
	 * cross-class dependency. The CircuitBreaker writes this transient
	 * from `record_batch_processed()` at the end of each happy-path
	 * batch; reading it here lets `get_in_flight_scan()` distinguish a
	 * scan that's making forward progress from one whose AS worker
	 * never fired.
	 *
	 * @param int $run_id The run id to read progress for.
	 *
	 * @return int
	 */
	private function batches_processed_for_run( int $run_id ): int {
		$stored = get_transient( CircuitBreaker::BATCHES_TRANSIENT_PREFIX . $run_id );
		return is_numeric( $stored ) ? (int) $stored : 0;
	}
}
