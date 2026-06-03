<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Persistence layer for Health Check candidate rows.
 *
 * Each "candidate" is a subscription the Detector surfaced as likely
 * stuck on manual while holding a valid payment token on a gateway that
 * supports automatic billing. Rows live in
 * `wcs_health_check_candidates`, scoped to the scan run that produced
 * them.
 *
 * This class is deliberately dumb: it does not decode the
 * `signal_summary` JSON for callers (they can decode themselves — the
 * Detector knows the signal shape, we don't). It just writes and reads
 * rows.
 *
 * The candidates table retains a `status` column plus `fixed_at`,
 * `errored_at`, `error_message`, and `snapshot_key` columns that were
 * originally wired to a per-row remediation pipeline. v1 ships read-
 * only so those columns stay at their defaults; leaving them in the
 * schema keeps the door open for a future fix flow without requiring
 * an ALTER TABLE migration.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class CandidateStore {

	/**
	 * Signal type: subscription is flagged for manual renewal but holds a
	 * saved token on a gateway that supports automatic renewal. The
	 * remediation-worthy cohort surfaced under the "Supports auto-renewal"
	 * tab.
	 */
	public const SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL = 'supports_auto_renewal';

	/**
	 * Signal type: subscription's next-payment schedule is missing or stale
	 * (past due without a matching renewal order). Surfaced under the
	 * "Missing renewals" tab.
	 */
	public const SIGNAL_TYPE_MISSING_RENEWAL = 'missing_renewal';

	/**
	 * Every signal type currently emitted by the Detector. Used by the
	 * Scope card + Tracks payload for the "sum across signals" count.
	 *
	 * @return array<int, string>
	 */
	public static function all_signal_types(): array {
		return array(
			self::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL,
			self::SIGNAL_TYPE_MISSING_RENEWAL,
		);
	}

	/**
	 * Insert a candidate row for this run, or replace it if one already exists
	 * for the same `(run_id, subscription_id, signal_type)` triple.
	 *
	 * Uses `$wpdb->replace()` gated by the `run_subscription` UNIQUE KEY so
	 * re-runs of the Detector within the same scan are idempotent — the
	 * second call overwrites the first rather than creating a duplicate row
	 * or erroring on the unique constraint. The signal_type dimension means
	 * the same subscription can legitimately have two rows in a single run
	 * — one per signal — without colliding.
	 *
	 * @param int                  $run_id          The run id this candidate
	 *                                              belongs to.
	 * @param int                  $subscription_id The WC subscription post id.
	 * @param array<string, mixed> $signal_data     Detector signals; stored as
	 *                                              JSON in `signal_summary`.
	 * @param string               $signal_type     Which detector signal surfaced
	 *                                              this row. Defaults to
	 *                                              Supports-auto-renewal so
	 *                                              already-queued SCAN_BATCH
	 *                                              actions from a running
	 *                                              deploy keep writing to the
	 *                                              original cohort.
	 */
	public function add( int $run_id, int $subscription_id, array $signal_data, string $signal_type = self::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL ): void {
		global $wpdb;

		// `wp_json_encode()` returns false on failure (malformed UTF-8, resources
		// in the payload, etc.). If we passed that straight through, `$wpdb`
		// would coerce it to the literal string "false" under the `%s` format
		// and the row would silently lose its signal payload. Fall back to an
		// empty JSON object and surface the problem via WC's logger so support
		// can spot it.
		$encoded_signals = wp_json_encode( $signal_data );
		if ( false === $encoded_signals ) {
			wc_get_logger()->warning(
				'Failed to JSON-encode signal data for health-check candidate',
				array(
					'source'          => 'wcs-health-check',
					'run_id'          => $run_id,
					'subscription_id' => $subscription_id,
					'json_error'      => json_last_error_msg(),
				)
			);
			$encoded_signals = '{}';
		}

		$result = $wpdb->replace(
			$wpdb->prefix . 'wcs_health_check_candidates',
			array(
				'run_id'          => $run_id,
				'subscription_id' => $subscription_id,
				'signal_type'     => $signal_type,
				'signal_summary'  => $encoded_signals,
				'status'          => 'pending',
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// `$wpdb->replace()` returns false on DB unavailability, schema
		// mismatch, or a missing table after a botched migration. The
		// scan-batch handler doesn't rescue here on purpose — a throw
		// would lose every other classified row in the same batch via
		// the broad try/catch retry. Logging gives support a breadcrumb
		// to correlate "fewer candidates than expected" reports with
		// the actual SQL failure. Mirrors the JSON-encode-failure log
		// above so both edge cases land in the same WC log channel.
		if ( false === $result ) {
			wc_get_logger()->error(
				'Failed to persist health-check candidate row',
				array(
					'source'          => 'wcs-health-check',
					'run_id'          => $run_id,
					'subscription_id' => $subscription_id,
					'signal_type'     => $signal_type,
					'db_error'        => $wpdb->last_error,
				)
			);
		}
	}

	/**
	 * Whether the (run_id, subscription_id) pair is currently a pending
	 * candidate. Used by the remediation AJAX endpoint as a defense-in-
	 * depth check that the subscription was actually flagged by this
	 * scan run before mutating it — narrows the authorization surface
	 * from "any subscription in the database" to "subscriptions the
	 * scan identified as problematic and that haven't been resolved
	 * yet."
	 *
	 * @param int $run_id          The scan run id.
	 * @param int $subscription_id The subscription id.
	 *
	 * @return bool True when a row exists with status='pending'.
	 */
	public function is_pending_candidate( int $run_id, int $subscription_id ): bool {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$wpdb->prefix}wcs_health_check_candidates WHERE run_id = %d AND subscription_id = %d AND status = 'pending'",
				$run_id,
				$subscription_id
			)
		);

		return $count > 0;
	}

	/**
	 * Mark a candidate row as fixed. Sets `status = 'fixed'` and
	 * `fixed_at` to the current UTC timestamp.
	 *
	 * The optional `$signal_type` scopes the update so a resolve action
	 * from one tab (e.g. Supports auto-renewal) only clears that
	 * tab's row, leaving any sibling rows the same subscription owns
	 * on other tabs intact. Without it, a subscription that surfaces
	 * under both signals disappears from both tabs after a single
	 * resolve click — violating the per-view independence the redesign
	 * spec asserts.
	 *
	 * @param int         $run_id          The run id.
	 * @param int         $subscription_id The subscription id.
	 * @param string|null $signal_type     Optional signal-type scope
	 *                                     (`SIGNAL_TYPE_*`). When null,
	 *                                     every signal row for the
	 *                                     (run, sub) pair is updated.
	 */
	public function mark_fixed( int $run_id, int $subscription_id, ?string $signal_type = null ): bool {
		global $wpdb;

		$where_data    = array(
			'run_id'          => $run_id,
			'subscription_id' => $subscription_id,
		);
		$where_formats = array( '%d', '%d' );
		if ( null !== $signal_type ) {
			$where_data['signal_type'] = $signal_type;
			$where_formats[]           = '%s';
		}

		$result = $wpdb->update(
			$wpdb->prefix . 'wcs_health_check_candidates',
			array(
				'status'   => 'fixed',
				'fixed_at' => current_time( 'mysql', true ),
			),
			$where_data,
			array( '%s', '%s' ),
			$where_formats
		);

		if ( false === $result ) {
			wc_get_logger()->error(
				'Health Check: failed to mark candidate as fixed',
				array(
					'source'          => 'wcs-health-check',
					'run_id'          => $run_id,
					'subscription_id' => $subscription_id,
					'signal_type'     => $signal_type,
				)
			);

			return false;
		}

		return true;
	}

	/**
	 * Every candidate row for the given run.
	 *
	 * Default ordering is insertion id ASC (chronological). Callers can pass
	 * an explicit `$orderby` column + `$order` direction for SQL-side sorting
	 * on candidate-row columns — used by the Status tab's sortable-column
	 * implementation. Only whitelisted columns and directions are honoured;
	 * anything else falls back to the default `id ASC` to avoid SQL injection
	 * via query args.
	 *
	 * @param string|null $orderby Optional column name for sort.
	 * @param string|null $order   Optional direction — 'asc' or 'desc'.
	 * @param int|null    $limit   Optional maximum row count.
	 * @param int         $offset  Optional row offset when limiting.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_by_run( int $run_id, ?string $orderby = null, ?string $order = null, ?int $limit = null, int $offset = 0 ): array {
		global $wpdb;

		$allowed_columns = array( 'id', 'subscription_id', 'created_at' );
		$column          = in_array( $orderby, $allowed_columns, true ) ? $orderby : 'id';
		$direction       = 'desc' === strtolower( (string) $order ) ? 'DESC' : 'ASC';

		$order_clause = $column . ' ' . $direction . ', id ASC';

		$query_args   = array( $run_id );
		$limit_clause = '';
		if ( null !== $limit ) {
			$limit        = max( 0, $limit );
			$offset       = max( 0, $offset );
			$limit_clause = ' LIMIT %d OFFSET %d';
			$query_args[] = $limit;
			$query_args[] = $offset;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order_clause composed from whitelist above; $limit_clause uses placeholders only.
				"SELECT * FROM {$wpdb->prefix}wcs_health_check_candidates WHERE run_id = %d AND status = 'pending' ORDER BY {$order_clause}{$limit_clause}",
				...$query_args
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count every candidate row for the given run.
	 *
	 * @param int $run_id The run id.
	 *
	 * @return int
	 */
	public function count_by_run( int $run_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$wpdb->prefix}wcs_health_check_candidates WHERE run_id = %d AND status = 'pending'",
				$run_id
			)
		);
	}

	/**
	 * Every candidate row for the given run, scoped to a single signal type.
	 *
	 * Same ordering + pagination contract as `list_by_run()`. Used by the
	 * CandidatesListTable's per-tab view paths.
	 *
	 * @param int         $run_id      The run id.
	 * @param string      $signal_type Signal type to filter on; use one of the
	 *                                 `SIGNAL_TYPE_*` constants.
	 * @param string|null $orderby     Optional column name for sort.
	 * @param string|null $order       Optional direction — 'asc' or 'desc'.
	 * @param int|null    $limit       Optional maximum row count.
	 * @param int         $offset      Optional row offset when limiting.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_by_run_and_signal( int $run_id, string $signal_type, ?string $orderby = null, ?string $order = null, ?int $limit = null, int $offset = 0 ): array {
		global $wpdb;

		$allowed_columns = array( 'id', 'subscription_id', 'created_at' );
		$column          = in_array( $orderby, $allowed_columns, true ) ? $orderby : 'id';
		$direction       = 'desc' === strtolower( (string) $order ) ? 'DESC' : 'ASC';

		$order_clause = $column . ' ' . $direction . ', id ASC';

		$query_args   = array( $run_id, $signal_type );
		$limit_clause = '';
		if ( null !== $limit ) {
			$limit        = max( 0, $limit );
			$offset       = max( 0, $offset );
			$limit_clause = ' LIMIT %d OFFSET %d';
			$query_args[] = $limit;
			$query_args[] = $offset;
		}

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $query_args carries (run_id, signal_type) plus optional (limit, offset) aligned with $limit_clause.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $order_clause composed from whitelist above; $limit_clause uses placeholders only.
				"SELECT * FROM {$wpdb->prefix}wcs_health_check_candidates WHERE run_id = %d AND signal_type = %s AND status = 'pending' ORDER BY {$order_clause}{$limit_clause}",
				...$query_args
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count pending candidates for a run, grouped by signal type, in a
	 * single query using conditional aggregation.
	 *
	 * @param int $run_id The run id.
	 *
	 * @return array{total: int, supports_auto_renewal: int, missing_renewal: int}
	 */
	public function count_by_run_grouped( int $run_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT
					COUNT(*) AS total,
					SUM( CASE WHEN signal_type = %s THEN 1 ELSE 0 END ) AS eligible,
					SUM( CASE WHEN signal_type = %s THEN 1 ELSE 0 END ) AS missing
				FROM {$wpdb->prefix}wcs_health_check_candidates
				WHERE run_id = %d AND status = 'pending'",
				self::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL,
				self::SIGNAL_TYPE_MISSING_RENEWAL,
				$run_id
			),
			ARRAY_A
		);

		return array(
			'total'                                 => (int) ( $row['total'] ?? 0 ),
			self::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL => (int) ( $row['eligible'] ?? 0 ),
			self::SIGNAL_TYPE_MISSING_RENEWAL       => (int) ( $row['missing'] ?? 0 ),
		);
	}

	/**
	 * Count candidate rows for the given run, scoped to a single signal
	 * type.
	 *
	 * @param int    $run_id      The run id.
	 * @param string $signal_type Signal type to filter on; use one of the
	 *                            `SIGNAL_TYPE_*` constants.
	 *
	 * @return int
	 */
	public function count_by_run_and_signal( int $run_id, string $signal_type ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$wpdb->prefix}wcs_health_check_candidates WHERE run_id = %d AND signal_type = %s AND status = 'pending'",
				$run_id,
				$signal_type
			)
		);
	}
}
