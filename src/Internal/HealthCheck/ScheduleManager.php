<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Admin\CandidatesListTable;
use DateTimeImmutable;
use Throwable;

/**
 * Wires Detector + CandidateStore + RunStore + CircuitBreaker into an
 * Action Scheduler-backed scan pipeline.
 *
 * Two actions form the schedule:
 *
 *  - DAILY_SCAN — recurring cron; nightly trigger at 02:00 store-local.
 *    Starts a fresh scan run and enqueues the first batch.
 *  - SCAN_BATCH — single-action; processes 200 candidates keyset-paginated
 *    by id. Self-enqueues the next page with a 30s inter-batch delay. On
 *    an empty page it finalises the run (RunStore marks `completed_at`
 *    on the runs row) and stops enqueueing. On a back-off signal it
 *    re-enqueues itself in 5 minutes. On a thrown exception it records a
 *    failure and retries in 60s until the consecutive-failure threshold
 *    trips the breaker, at which point the run is failed.
 *
 * The original plan included FIX_BATCH / UNDO_BATCH handlers for a
 * merchant-actioned remediation flow. Those were designed and then
 * reverted pre-launch when v1 scope settled on read-only diagnostics.
 * Git history preserves the implementation; see commits 940ab26,
 * 7bde54f, edb420c.
 *
 * Action Scheduler interactions are routed through protected helpers
 * (`enqueue_async_action`, `schedule_single_action`,
 * `schedule_recurring_action`, `has_scheduled_action`). This keeps the
 * production code free of test-only branches while letting a test
 * subclass capture the calls deterministically — the same probe-override
 * pattern used by `CircuitBreakerBackOffDouble`.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class ScheduleManager {

	/**
	 * Cron action: fires once per day inside the store-local nightly
	 * window and enqueues a fresh scan run.
	 */
	public const DAILY_SCAN = 'wcs_health_check_daily_scan';

	/**
	 * Single-action: processes one keyset-paginated batch of candidates.
	 */
	public const SCAN_BATCH = 'wcs_health_check_scan_batch';

	/**
	 * Check-type argument for SCAN_BATCH actions processing the
	 * Supports-auto-renewal cohort (the Bug 1 signal). Kept as the
	 * default so already-queued actions from a running deploy keep
	 * hitting the original path after the chain landed.
	 */
	public const CHECK_TYPE_SUPPORTS_AUTO_RENEWAL = 'supports_auto_renewal';

	/**
	 * Check-type argument for SCAN_BATCH actions processing the
	 * Missing-renewal cohort. Enqueued after the Supports-auto-renewal
	 * chain empties; see `handle_scan_batch()` for the hand-off.
	 */
	public const CHECK_TYPE_MISSING_RENEWAL = 'missing_renewal';

	/**
	 * Maximum subscriptions inspected per SCAN_BATCH. Balances keyset-
	 * page efficiency against the Action Scheduler worker's per-action
	 * time budget.
	 */
	public const SCAN_BATCH_SIZE = 200;

	/**
	 * Delay between consecutive scan batches on the happy path. Gives
	 * foreground traffic a breather between the store-wide queries.
	 */
	public const INTER_BATCH_DELAY_SECONDS = 30;

	/**
	 * Delay before retrying the same batch after a caught exception —
	 * distinct from the 30s inter-batch cadence so the retry is
	 * identifiable in the AS queue.
	 */
	private const FAILURE_RETRY_DELAY_SECONDS = 60;

	/**
	 * Delay before re-queueing the same batch when the circuit breaker
	 * signalled a transient back-off.
	 */
	private const BACK_OFF_DELAY_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Action Scheduler group — isolates health-check jobs in the queue UI
	 * so support can filter on `wcs-health-check` alone.
	 */
	private const ACTION_GROUP = 'wcs-health-check';

	/**
	 * @var Detector
	 */
	private $detector;

	/**
	 * @var CandidateStore
	 */
	private $candidate_store;

	/**
	 * @var RunStore
	 */
	private $run_store;

	/**
	 * @var CircuitBreaker
	 */
	private $circuit_breaker;

	/**
	 * @var Tracks
	 */
	private $tracks;

	/**
	 * @param Detector|null       $detector        Optional; defaults to a fresh instance.
	 * @param CandidateStore|null $candidate_store Optional; defaults to a fresh instance.
	 * @param RunStore|null       $run_store       Optional; defaults to a fresh instance.
	 * @param CircuitBreaker|null $circuit_breaker Optional; defaults to a fresh instance.
	 * @param Tracks|null         $tracks          Optional; defaults to a fresh instance.
	 */
	public function __construct(
		?Detector $detector = null,
		?CandidateStore $candidate_store = null,
		?RunStore $run_store = null,
		?CircuitBreaker $circuit_breaker = null,
		?Tracks $tracks = null
	) {
		$this->detector        = $detector ?? new Detector();
		$this->candidate_store = $candidate_store ?? new CandidateStore();
		$this->run_store       = $run_store ?? new RunStore();
		$this->circuit_breaker = $circuit_breaker ?? new CircuitBreaker();
		$this->tracks          = $tracks ?? new Tracks();
	}

	/**
	 * Register the two Action Scheduler hooks.
	 *
	 * Called once per request during Bootstrap. Handler bindings are always
	 * registered so the on-demand "Run now" path keeps working regardless of
	 * the merchant's nightly-scan setting. The recurring DAILY_SCAN
	 * registration lives in `reconcile_daily_schedule()`, which Bootstrap
	 * calls separately so the schedule reflects the merchant toggle.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::DAILY_SCAN, array( $this, 'handle_daily_scan' ), 10, 0 );
		// Accepts 3 args so the check-type dimension reaches the
		// handler; already-queued 2-arg actions from a pre-chain
		// deploy still work because the third parameter defaults to
		// CHECK_TYPE_SUPPORTS_AUTO_RENEWAL.
		add_action( self::SCAN_BATCH, array( $this, 'handle_scan_batch' ), 10, 3 );
	}

	/**
	 * Reconcile the recurring DAILY_SCAN action with the merchant's
	 * nightly-scan setting. Called by Bootstrap on every request and
	 * whenever the option flips, so AS state always tracks the option:
	 *
	 *   - Setting on  → ensure a DAILY_SCAN is scheduled.
	 *   - Setting off → cancel any queued DAILY_SCAN so the "Last scan"
	 *     card stops showing a misleading "Due now" / "Next scheduled in
	 *     X" line.
	 *
	 * Defers to `action_scheduler_init` if AS is not yet ready (early
	 * plugins_loaded tick) — the same fallback the original `register()`
	 * used.
	 *
	 * @return void
	 */
	public function reconcile_daily_schedule(): void {
		if ( ! ( did_action( 'action_scheduler_init' ) || doing_action( 'action_scheduler_init' ) ) ) {
			add_action( 'action_scheduler_init', array( $this, 'reconcile_daily_schedule' ) );
			return;
		}

		if ( $this->circuit_breaker->is_schedule_enabled() ) {
			$this->ensure_daily_scan_scheduled();
		} else {
			$this->unschedule_daily_scan();
		}
	}

	/**
	 * Cancel any queued DAILY_SCAN action. Used when the merchant
	 * disables the nightly-scan setting so AS doesn't keep firing a
	 * handler whose first gate would just refuse to run.
	 *
	 * @return void
	 */
	public function unschedule_daily_scan(): void {
		// Delegate to the protected helper so the AS-availability guard + warning log + spy seam
		// live in exactly one place. Passing `array()` here is correct: DAILY_SCAN actions are
		// always enqueued with no args, so filtering for zero-arg matches the real-world shape.
		$this->unschedule_all_actions( self::DAILY_SCAN, array(), self::ACTION_GROUP );
	}

	/**
	 * Ensure a recurring DAILY_SCAN action is scheduled for tomorrow
	 * 02:00 store-local time.
	 *
	 * Idempotent — if a DAILY_SCAN action is already queued we return
	 * early so repeat Bootstrap runs do not stack schedules. The
	 * anchor moment is computed in `wp_timezone()` and converted to a
	 * UTC Unix timestamp before handing it to Action Scheduler, so a
	 * store in a non-UTC zone still fires at 02:00 wall-clock time (not
	 * 02:00 UTC).
	 *
	 * @return void
	 */
	public function ensure_daily_scan_scheduled(): void {
		if ( $this->has_scheduled_action( self::DAILY_SCAN, array(), self::ACTION_GROUP ) ) {
			return;
		}

		$this->schedule_recurring_action(
			$this->next_daily_scan_timestamp(),
			DAY_IN_SECONDS,
			self::DAILY_SCAN,
			array(),
			self::ACTION_GROUP
		);
	}

	/**
	 * Handler for the DAILY_SCAN cron tick — consults each gate in order
	 * (cheapest first), and on the happy path starts a new scan run and
	 * enqueues the first batch. Gates:
	 *
	 *   1. `CircuitBreaker::can_run()` — support filter + merchant toggle
	 *      + tripped state.
	 *   2. In-flight scan guard — skip if another scan (manual or
	 *      scheduled) is still running. `RunStore::start()` also
	 *      enforces this atomically, but the cheap read here avoids
	 *      even attempting the insert on the common-case overlap.
	 *   3. `CircuitBreaker::within_nightly_window()` — only scan during
	 *      the store-local scan window (defaults to 02:00–04:59 via
	 *      `CircuitBreaker::DEFAULT_SCAN_WINDOW = [2, 5]`; filterable
	 *      via `wcs_health_check_scan_window`).
	 *   4. `CircuitBreaker::under_daily_ceiling()` — cap on total scanned
	 *      rows per rolling 24h.
	 *
	 * Every gate miss is a silent no-op: Action Scheduler will fire the
	 * next recurring tick and the gate is re-evaluated then. We never
	 * mutate state from this handler unless we are about to enqueue.
	 *
	 * @return void
	 */
	public function handle_daily_scan(): void {
		if ( ! $this->circuit_breaker->can_run() ) {
			wc_get_logger()->debug( 'Health Check: skipping daily scan — circuit breaker blocked execution.', array( 'source' => 'wcs-health-check' ) );
			return;
		}

		// Mirror the manual path guard so cron does not overlap an in-flight scan.
		if ( null !== $this->run_store->get_in_flight_scan() ) {
			wc_get_logger()->debug( 'Health Check: skipping daily scan — another scan is already in flight.', array( 'source' => 'wcs-health-check' ) );
			return;
		}

		if ( ! $this->circuit_breaker->within_nightly_window() ) {
			wc_get_logger()->debug( 'Health Check: skipping daily scan — outside nightly scan window.', array( 'source' => 'wcs-health-check' ) );
			return;
		}

		if ( ! $this->circuit_breaker->under_daily_ceiling() ) {
			wc_get_logger()->debug( 'Health Check: skipping daily scan — daily ceiling exceeded.', array( 'source' => 'wcs-health-check' ) );
			return;
		}

		$run_id = $this->run_store->start( 'scan', 'scheduled' );

		if ( $run_id <= 0 ) {
			// `start()` rejected the insert — either a manual-scan
			// caller slipped through the race window between our
			// `get_in_flight_scan()` check above and the insert, or
			// the INSERT itself failed. The first case is legitimate
			// (another scan is already running); the second is worth
			// logging. Disambiguate by reading the in-flight state now.
			if ( null === $this->run_store->get_in_flight_scan() ) {
				wc_get_logger()->error(
					'Health Check: failed to create scan run row for scheduled scan',
					array(
						'source'   => 'wcs-health-check',
						'db_error' => $GLOBALS['wpdb']->last_error,
					)
				);
			}
			return;
		}

		$this->enqueue_async_action(
			self::SCAN_BATCH,
			array( $run_id, 0, self::CHECK_TYPE_SUPPORTS_AUTO_RENEWAL ),
			self::ACTION_GROUP
		);
	}

	/**
	 * On-demand scan entry point used by the Status tab "Run scan now"
	 * button.
	 *
	 * Unconditional with respect to options and filters — the merchant
	 * clicked the button, so we run regardless of the nightly-scan
	 * setting, the support-level scan filter, or a tripped breaker.
	 * The tool-wide kill switch (`wcs_health_check_tool_enabled` in
	 * `Bootstrap`) is the only way to remove the button itself; once
	 * the tab renders, "Run now" always proceeds.
	 *
	 * The only refusal path here is concurrency: the atomic
	 * `RunStore::start()` guard rejects a second running scan-type
	 * row, surfaced as `HealthCheckScanInFlightException`.
	 *
	 * @param string $triggered_by One of 'user' (admin click) or 'scheduled'
	 *                             (cron). Other values are accepted but
	 *                             normalised to 'scheduled' before storage.
	 *
	 * @return int The newly created scan run id.
	 *
	 * @throws HealthCheckDbException          When the scan-run INSERT fails
	 *                                         at the SQL layer.
	 * @throws HealthCheckScanInFlightException When the atomic guard rejected
	 *                                         the insert because another scan
	 *                                         of the same type is already in
	 *                                         flight.
	 */
	public function start_scan( string $triggered_by ): int {

		$triggered_by = 'user' === $triggered_by || 0 === strpos( $triggered_by, 'user:' )
			? 'user'
			: 'scheduled';

		$run_id = $this->run_store->start( 'scan', $triggered_by );

		if ( $run_id <= 0 ) {
			// `start()` rejected the insert. Two possibilities — a
			// concurrent caller won the atomic guard, or the INSERT
			// itself failed. Disambiguate so the merchant sees the
			// right notice. Both branches throw typed exceptions so
			// StatusTab::run_scan() can route them with dedicated
			// catch clauses — no exception-message string matching.
			if ( null !== $this->run_store->get_in_flight_scan() ) {
				throw new HealthCheckScanInFlightException( 'Health Check: another scan is already running.' );
			}
			wc_get_logger()->error(
				'Health Check: failed to create scan run row for manual scan',
				array(
					'source'       => 'wcs-health-check',
					'triggered_by' => $triggered_by,
					'db_error'     => $GLOBALS['wpdb']->last_error,
				)
			);
			throw new HealthCheckDbException( 'Health Check: failed to create scan run row.' );
		}

		$this->enqueue_async_action(
			self::SCAN_BATCH,
			array( $run_id, 0, self::CHECK_TYPE_SUPPORTS_AUTO_RENEWAL ),
			self::ACTION_GROUP
		);

		return $run_id;
	}

	/**
	 * Cancel an in-flight scan: clear queued batches from Action Scheduler,
	 * flip the run row to `cancelled`, and emit the Tracks event so
	 * downstream analytics know why the run terminated.
	 *
	 * Soft-cancel pipeline:
	 *  1. Read the in-flight row. No row -> nothing to cancel; return null
	 *     so the form handler can show "no scan to cancel".
	 *  2. Unschedule any queued SCAN_BATCH actions in the wcs-health-check
	 *     group. The currently-executing batch (if any) is left to finish;
	 *     its preflight in `handle_scan_batch()` reads the row's new
	 *     `cancelled` status and stops chaining further.
	 *  3. Snapshot the partial-progress counters via `collect_run_stats()`
	 *     so the Status tab can render "412 of ~2,300 scanned before
	 *     cancel" without re-querying the candidate table later.
	 *  4. Atomic UPDATE `running -> cancelled` via `RunStore::cancel()`.
	 *     The conditional WHERE prevents overwriting a row that another
	 *     path (batch finalising completed, breaker tripping failed) has
	 *     already moved out of `running`. If the UPDATE matches zero rows
	 *     (the race lost), return null so the handler routes the same
	 *     "no scan to cancel" notice rather than a misleading success.
	 *  5. Emit Tracks event and return the run id.
	 *
	 * Works for any triggered_by value - the merchant clicked the button,
	 * so the cancel proceeds regardless of whether the scan started
	 * manually or from the nightly cron.
	 *
	 * @return int|null The cancelled scan run id, or null when no scan was
	 *                  in flight or the atomic guard rejected the UPDATE.
	 */
	public function cancel_scan(): ?int {
		$run = $this->run_store->get_in_flight_scan();
		if ( null === $run ) {
			return null;
		}

		$run_id = (int) $run['id'];

		// Drop queued SCAN_BATCH actions before flipping the row so the
		// AS worker can't pick a queued batch off the heap between our
		// status write and the unschedule call. Mirrors the pattern in
		// `unschedule_daily_scan()` at line 201.
		//
		// `null` for the args parameter is load-bearing: `as_unschedule_all_actions()` treats
		// `array()` as "filter for actions with exactly these args" (i.e. zero-arg actions), which
		// would never match our SCAN_BATCH actions (they carry the 3-arg `[$run_id, $after_id,
		// $check_type]` vector). Passing `null` skips the args filter so every queued SCAN_BATCH
		// in the group is unscheduled regardless of its args.
		$this->unschedule_all_actions( self::SCAN_BATCH, null, self::ACTION_GROUP );

		// Snapshot the partial-progress counters under the semantic keys the StatusTab
		// cancelled-state renderer (`render_cancelled_partial_counts()`) reads:
		//   - `subscriptions_scanned`: how many subs the SQL filter has visited so far.
		//   - `total_subscriptions`:   store-wide subscription count snapshot at cancel time.
		//
		// `total_subscriptions` is a fresh `COUNT(*)` against the subscriptions store rather than
		// a derivation from `candidates_found` (which only counts classified candidates, NOT the
		// store total). Preserves all the keys produced by `collect_run_stats()` for forward-compat
		// (`total_scanned`, `candidates_found`, `candidates_<signal>`, `completed_at`).
		$collected = $this->collect_run_stats( $run_id );
		$stats     = array_merge(
			$collected,
			array(
				'subscriptions_scanned' => (int) ( $collected['total_scanned'] ?? 0 ),
				'total_subscriptions'   => $this->count_all_subscriptions(),
			)
		);

		if ( ! $this->run_store->cancel( $run_id, $stats ) ) {
			// Lost the race - another path moved the row out of
			// `running`. Surface as "no scan to cancel" rather than
			// pretending the cancel happened.
			return null;
		}

		$raw_triggered_by = (string) ( $run['triggered_by'] ?? '' );
		$triggered_by     = 'user' === $raw_triggered_by || 0 === strpos( $raw_triggered_by, 'user:' )
			? 'user'
			: 'scheduled';

		$this->tracks->scan_cancelled(
			array(
				'run_id'                => $run_id,
				'triggered_by'          => $triggered_by,
				'subscriptions_scanned' => (int) ( $stats['subscriptions_scanned'] ?? 0 ),
				'total_subscriptions'   => (int) ( $stats['total_subscriptions'] ?? 0 ),
			)
		);

		return $run_id;
	}


	/**
	 * Handler for the SCAN_BATCH single-action. Processes one keyset
	 * page for the given check type, persists the classifications, and
	 * enqueues the next page.
	 *
	 * Pipeline shape: serial two-chain.
	 *   1. The Supports-auto-renewal chain processes its candidate set
	 *      one keyset page at a time.
	 *   2. When its page comes back empty, the handler hands off to the
	 *      Missing-renewal chain (enqueues a SCAN_BATCH with
	 *      `$after_id = 0` and `$check_type = CHECK_TYPE_MISSING_RENEWAL`)
	 *      instead of finalising the run.
	 *   3. When the Missing-renewal chain's page is empty, the run is
	 *      finalised. One run completion point; retry/back-off/breaker
	 *      logic unchanged.
	 *
	 * Parallel chains were considered and rejected — they would require
	 * new per-run bookkeeping to know when both chains had drained, and
	 * the single-writer invariant documented on CircuitBreaker (which
	 * lets the scanned/batches counters stay non-atomic) only holds while
	 * exactly one SCAN_BATCH is in flight per run.
	 *
	 * Branches within a single batch call:
	 *  - Back-off signal: re-enqueue the SAME batch in 5 minutes.
	 *  - Empty candidate page on auto-renewal chain: enqueue the first
	 *    missing-renewal batch.
	 *  - Empty candidate page on missing-renewal chain: finalise the
	 *    run with summary stats, stamp the last-scan option, stop.
	 *  - Non-empty page: persist each classification, bump counters,
	 *    enqueue the next page with `max(ids)` as the cursor.
	 *  - Any caught exception: record failure. If under the breaker
	 *    threshold, retry the SAME batch in 60s. Otherwise trip and
	 *    fail the run.
	 *
	 * @param int    $run_id     The scan run id.
	 * @param int    $after_id   Keyset cursor — start of the next page.
	 * @param string $check_type Which check chain this batch belongs to.
	 *                           Defaults to Supports-auto-renewal so
	 *                           already-queued 2-arg actions from a
	 *                           pre-chain deploy still resolve the
	 *                           original path.
	 *
	 * @return void
	 */
	public function handle_scan_batch( int $run_id, int $after_id, string $check_type = self::CHECK_TYPE_SUPPORTS_AUTO_RENEWAL ): void {
		try {
			// Preflight: read the run row before doing any work. A run that
			// is no longer in `running` (cancelled by the merchant, already
			// completed, failed elsewhere, or missing entirely) must short-
			// circuit the entire pipeline - including the back-off retry
			// below, which would otherwise re-enqueue a cancelled chain in
			// 5 minutes.
			$run = $this->run_store->get( $run_id );
			if ( null === $run || RunStore::STATUS_RUNNING !== (string) ( $run['status'] ?? '' ) ) {
				return;
			}

			if ( $this->circuit_breaker->should_back_off() ) {
				$this->schedule_single_action(
					time() + self::BACK_OFF_DELAY_SECONDS,
					self::SCAN_BATCH,
					array( $run_id, $after_id, $check_type ),
					self::ACTION_GROUP
				);
				return;
			}

			$signal_type = self::signal_type_for_check( $check_type );
			$ids         = $this->detector->candidate_ids( $after_id, self::SCAN_BATCH_SIZE, $signal_type );

			if ( empty( $ids ) ) {
				// End of this chain. If there's a subsequent chain, hand
				// off to it; otherwise finalise the run.
				$next_check_type = self::next_check_type_after( $check_type );
				if ( null !== $next_check_type ) {
					$this->enqueue_async_action(
						self::SCAN_BATCH,
						array( $run_id, 0, $next_check_type ),
						self::ACTION_GROUP
					);
					return;
				}

				$stats = $this->collect_run_stats( $run_id );
				$this->run_store->complete( $run_id, 'scan', $stats );
				$this->emit_scan_completed_event( $run_id, $stats );
				return;
			}

			$classified = $this->detector->classify_ids( $ids, $signal_type );
			foreach ( $classified as $sub_id => $data ) {
				$this->candidate_store->add( $run_id, (int) $sub_id, $data, $signal_type );
			}

			// Accumulate the inspected-id count (ids the SQL filter
			// returned, BEFORE the classifier narrows them) into the
			// per-run counter so the Status tab can render
			// "Scanned N subscriptions" even on runs that classify
			// zero candidates.
			//
			// Counters live AFTER the persist loop because a classify
			// or persist exception lands in the Throwable catch below
			// and retries the SAME batch 60s later. With the counters
			// above persist, a retry double-counted rows — the
			// merchant-visible Scope-card scanned count and
			// batches-processed stat both inflated per retry attempt.
			// The daily-ceiling counter (record_processed) was already
			// positioned correctly; move its two siblings down to
			// match.
			$this->circuit_breaker->record_scanned( $run_id, count( $ids ) );
			$this->circuit_breaker->record_batch_processed( $run_id );
			$this->circuit_breaker->record_processed( count( $ids ) );
			$this->circuit_breaker->record_heartbeat();
			$this->circuit_breaker->reset_consecutive_failures();

			$last_id = (int) max( $ids );
			$this->schedule_single_action(
				time() + self::INTER_BATCH_DELAY_SECONDS,
				self::SCAN_BATCH,
				array( $run_id, $last_id, $check_type ),
				self::ACTION_GROUP
			);
		} catch ( Throwable $e ) {
			$this->circuit_breaker->record_failure();

			if ( $this->circuit_breaker->should_trip() ) {
				$reason = sprintf( 'Scan batch failure: %s', $e->getMessage() );
				$this->circuit_breaker->trip( $reason );
				$this->run_store->fail( $run_id, $e->getMessage() );
				$this->tracks->circuit_breaker_tripped(
					array(
						'reason'                => $reason,
						'consecutive_failures'  => $this->circuit_breaker->get_consecutive_failures(),
						'heartbeat_age_seconds' => $this->circuit_breaker->get_heartbeat_age_seconds(),
					)
				);
				return;
			}

			$this->schedule_single_action(
				time() + self::FAILURE_RETRY_DELAY_SECONDS,
				self::SCAN_BATCH,
				array( $run_id, $after_id, $check_type ),
				self::ACTION_GROUP
			);
		}
	}

	/**
	 * Map a check-type to the CandidateStore signal_type value the
	 * detector + store write under. Keeping the two constant spaces
	 * aligned means the chain's "what's this batch doing?" identity
	 * doubles as the per-row signal tag, no translation layer needed.
	 *
	 * @param string $check_type One of the `CHECK_TYPE_*` constants.
	 *
	 * @return string Signal-type string from `CandidateStore::SIGNAL_TYPE_*`.
	 */
	private static function signal_type_for_check( string $check_type ): string {
		if ( self::CHECK_TYPE_MISSING_RENEWAL === $check_type ) {
			return CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL;
		}

		return CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL;
	}

	/**
	 * Returns the next check type in the serial chain, or null when
	 * `$check_type` is the final chain in the sequence.
	 *
	 * Order is fixed at: Supports-auto-renewal → Missing-renewal → (end).
	 * Changing the sequence is a deliberate act — re-ordering interacts
	 * with the per-run rolling counters and the stats_json payload shape,
	 * neither of which would break per se, but the ordering is a
	 * product-surface contract (the Supports-auto-renewal tab badge
	 * populates before the Missing-renewals badge on a slow scan).
	 *
	 * @param string $check_type Current chain's check type.
	 *
	 * @return string|null Next chain's check type, or null if none.
	 */
	private static function next_check_type_after( string $check_type ): ?string {
		if ( self::CHECK_TYPE_SUPPORTS_AUTO_RENEWAL === $check_type ) {
			return self::CHECK_TYPE_MISSING_RENEWAL;
		}

		return null;
	}

	/**
	 * Compose the Tracks `scan_completed` payload from the completed
	 * run and hand it off to the emitter. Kept in one place so the
	 * payload contract lives next to the single call site that
	 * produces it.
	 *
	 * @param int                  $run_id Completed scan run id.
	 * @param array<string, mixed> $stats  Stats array produced by
	 *                                     `collect_run_stats()`.
	 *
	 * @return void
	 */
	private function emit_scan_completed_event( int $run_id, array $stats ): void {
		$run              = $this->run_store->get( $run_id );
		$started_at       = is_array( $run ) ? (string) ( $run['started_at'] ?? '' ) : '';
		$raw_triggered_by = is_array( $run ) ? (string) ( $run['triggered_by'] ?? '' ) : '';

		$duration_seconds = 0;
		if ( '' !== $started_at ) {
			$started_ts = strtotime( $started_at . ' UTC' );
			if ( false !== $started_ts ) {
				$duration_seconds = max( 0, time() - $started_ts );
			}
		}

		// `triggered_by` is normalised at write time; the legacy `user:<id>`
		// shape is still classified as user for old rows. Unknown/custom
		// values fall back to scheduled.
		$triggered_by = 'user' === $raw_triggered_by || 0 === strpos( $raw_triggered_by, 'user:' )
			? 'user'
			: 'scheduled';

		$payload = array(
			'run_id'            => $run_id,
			'total_scanned'     => (int) ( $stats['total_scanned'] ?? 0 ),
			'candidates_found'  => (int) ( $stats['candidates_found'] ?? 0 ),
			'duration_seconds'  => $duration_seconds,
			'batches_processed' => $this->circuit_breaker->get_total_batches_processed( $run_id ),
			'triggered_by'      => $triggered_by,
		);

		// Per-signal counts share the `candidates_{signal}` key shape
		// with stats_json so downstream analytics can correlate the
		// two without a translation table.
		foreach ( CandidateStore::all_signal_types() as $signal_type ) {
			$key             = 'candidates_' . $signal_type;
			$payload[ $key ] = (int) ( $stats[ $key ] ?? 0 );
		}

		$this->tracks->scan_completed( $payload );
	}

	/**
	 * Aggregate per-run totals from the CandidateStore for the `stats_json`
	 * column on the completed row.
	 *
	 * `candidates_found` is preserved as the naive sum across signals so
	 * downstream dashboards keyed on the single metric keep reading a
	 * meaningful number after the chain landed. Per-signal counts live
	 * alongside it under explicit keys so the Scope card arithmetic
	 * ("X items are ready for review" = sum of badge counts) can
	 * back-fill from stats_json without a live-count query.
	 *
	 * @param int $run_id The run id.
	 *
	 * @return array<string, mixed>
	 */
	private function collect_run_stats( int $run_id ): array {
		$per_signal = array();
		foreach ( CandidateStore::all_signal_types() as $signal_type ) {
			$per_signal[ 'candidates_' . $signal_type ] = $this->candidate_store->count_by_run_and_signal( $run_id, $signal_type );
		}

		return array_merge(
			array(
				'candidates_found' => $this->candidate_store->count_by_run( $run_id ),
				// Persist the per-run scanned total into stats_json so
				// the Status tab can read it after the transient-backed
				// counter expires.
				'total_scanned'    => $this->circuit_breaker->get_total_scanned( $run_id ),
				'completed_at'     => current_time( 'mysql', true ),
			),
			$per_signal
		);
	}

	/**
	 * Compute the Unix timestamp (UTC-anchored) of the next store-local
	 * 02:00 instant. Used as the first-fire timestamp for the recurring
	 * DAILY_SCAN schedule.
	 *
	 * We build the "tomorrow 02:00" moment in the site's WP timezone
	 * (`wp_timezone()`) so a store in e.g. Europe/Berlin fires at 02:00
	 * Berlin time every day, not 02:00 UTC. The `DateTimeImmutable` is
	 * then rendered as a Unix timestamp, which Action Scheduler
	 * interprets as UTC — so the round-trip preserves the intended
	 * wall-clock fire time.
	 *
	 * @return int
	 */
	private function next_daily_scan_timestamp(): int {
		$local = new DateTimeImmutable( 'tomorrow 02:00', wp_timezone() );
		return $local->getTimestamp();
	}

	/**
	 * Delegate to `as_enqueue_async_action()`. Overridable for tests so
	 * the SpyScheduleManager can record calls without a running AS
	 * worker.
	 *
	 * @param string $hook  Action hook to fire.
	 * @param array  $args  Handler args.
	 * @param string $group AS group identifier.
	 *
	 * @return void
	 */
	protected function enqueue_async_action( string $hook, array $args, string $group ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			wc_get_logger()->warning(
				sprintf(
					'Health Check: as_enqueue_async_action() is not available - skipping enqueue of "%s" in group "%s". Action Scheduler may not be loaded; the scan chain cannot advance.',
					$hook,
					$group
				),
				array( 'source' => 'wcs-health-check' )
			);
			return;
		}
		as_enqueue_async_action( $hook, $args, $group );
	}

	/**
	 * Delegate to `as_schedule_single_action()`. Overridable for tests.
	 *
	 * @param int    $timestamp Fire time as a Unix timestamp.
	 * @param string $hook      Action hook to fire.
	 * @param array  $args      Handler args.
	 * @param string $group     AS group identifier.
	 *
	 * @return void
	 */
	protected function schedule_single_action( int $timestamp, string $hook, array $args, string $group ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			wc_get_logger()->warning(
				sprintf(
					'Health Check: as_schedule_single_action() is not available - skipping schedule of "%s" in group "%s". Action Scheduler may not be loaded; the scan chain cannot advance.',
					$hook,
					$group
				),
				array( 'source' => 'wcs-health-check' )
			);
			return;
		}
		as_schedule_single_action( $timestamp, $hook, $args, $group );
	}

	/**
	 * Delegate to `as_schedule_recurring_action()`. Overridable for
	 * tests.
	 *
	 * @param int    $timestamp First fire time as a Unix timestamp.
	 * @param int    $interval  Recurrence interval in seconds.
	 * @param string $hook      Action hook to fire.
	 * @param array  $args      Handler args.
	 * @param string $group     AS group identifier.
	 *
	 * @return void
	 */
	protected function schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args, string $group ): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			wc_get_logger()->warning(
				sprintf(
					'Health Check: as_schedule_recurring_action() is not available - skipping schedule of "%s" in group "%s". Action Scheduler may not be loaded; the nightly scan will not re-arm.',
					$hook,
					$group
				),
				array( 'source' => 'wcs-health-check' )
			);
			return;
		}
		as_schedule_recurring_action( $timestamp, $interval, $hook, $args, $group );
	}

	/**
	 * Delegate to `as_next_scheduled_action()` and coerce to bool.
	 * Overridable for tests so `ensure_daily_scan_scheduled()` can be
	 * exercised without a running AS worker.
	 *
	 * @param string $hook  Action hook to look up.
	 * @param array  $args  Handler args to match.
	 * @param string $group AS group identifier.
	 *
	 * @return bool
	 */
	protected function has_scheduled_action( string $hook, array $args, string $group ): bool {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return false;
		}

		return (bool) as_next_scheduled_action( $hook, $args, $group );
	}

	/**
	 * Delegate to `as_unschedule_all_actions()`. Overridable for tests so
	 * `cancel_scan()` can be exercised without a running AS worker.
	 *
	 * `$args` accepts `null` to skip Action Scheduler's args-match filter (used by
	 * `cancel_scan()` to unschedule every queued SCAN_BATCH in the group regardless
	 * of its arg vector). Passing `array()` filters for zero-arg actions only - that
	 * shape would never match SCAN_BATCH actions, which always carry the 3-arg
	 * `[$run_id, $after_id, $check_type]` vector.
	 *
	 * @param string     $hook  Action hook to unschedule.
	 * @param array|null $args  Handler args to match, or null to skip the args filter.
	 * @param string     $group AS group identifier.
	 *
	 * @return void
	 */
	protected function unschedule_all_actions( string $hook, ?array $args, string $group ): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			wc_get_logger()->warning(
				sprintf(
					'Health Check: as_unschedule_all_actions() is not available - skipping unschedule of "%s" in group "%s". Action Scheduler may not be loaded.',
					$hook,
					$group
				),
				array( 'source' => 'wcs-health-check' )
			);
			return;
		}

		as_unschedule_all_actions( $hook, $args, $group );
	}

	/**
	 * Snapshot of the store-wide subscription total at the moment the helper is called.
	 *
	 * Used by `cancel_scan()` to persist a `total_subscriptions` figure on the cancelled run
	 * row so the StatusTab can render "N of ~M scanned before cancel" without enumerating the
	 * subscriptions store on every render. Delegates to the same
	 * `CandidatesListTable::count_all_subscriptions()` static helper the StatusTab uses for the
	 * SCOPE card so the two surfaces agree on the denominator. Overridable for tests so the
	 * cancel-scan suite can pin the value without seeding real subscription rows.
	 *
	 * @return int
	 */
	protected function count_all_subscriptions(): int {
		return CandidatesListTable::count_all_subscriptions();
	}
}
