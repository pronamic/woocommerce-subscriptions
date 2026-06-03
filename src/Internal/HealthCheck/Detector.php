<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\RenewalSnapshot;
use WCS_Payment_Tokens;
use WC_Subscription;

/**
 * Detects subscriptions that are silently broken — stuck on manual while
 * holding a valid payment token (Supports-auto-renewal signal), or whose
 * next-payment schedule is missing or stale without a matching renewal
 * order (Missing-renewal signal).
 *
 * The Detector runs in two stages per signal:
 *
 *  1. `candidate_ids()` pulls a cheap SQL-side shortlist. Dispatches to a
 *     signal-specific private helper — keyset-paginated so we can batch
 *     across a large store. HPOS + CPT branches per shortlist.
 *  2. `classify_ids()` then runs the richer per-subscription analysis on
 *     the shortlist. Rows that survive come back with a signal payload the
 *     admin surface consumes.
 *
 * The split keeps the hot SQL path minimal and lets us run the richer
 * per-subscription analysis only on the shortlist, which is typically two
 * or three orders of magnitude smaller than the full active-sub set.
 *
 * Both public entry points accept a trailing `$signal_type` parameter that
 * defaults to the original Supports-auto-renewal path. Callers on the
 * missing-renewal chain pass
 * `CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL` explicitly.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Detector {

	/**
	 * Per-request cache of prefetched renewal data, keyed by subscription
	 * id. Populated by `prefetch_renewals_by_sub()` so repeated calls
	 * for the same IDs (e.g. warm-up batch then per-row classify) skip
	 * the DB round-trip.
	 *
	 * @var array<int, RenewalSnapshot>
	 */
	private array $renewals_cache = array();

	/**
	 * SQL-side base candidate IDs for the requested signal. Keyset-paginated
	 * on the subscription id.
	 *
	 * Dispatches on `$signal_type` to a signal-specific private helper:
	 *  - Supports-auto-renewal: manual-renewal flag on, gateway set.
	 *  - Missing-renewal: active/on-hold, missing-or-past next-payment
	 *    schedule. See `missing_renewal_candidate_ids()` for the second
	 *    shortlist.
	 *
	 * The `id > %d` cursor is a classic keyset — cheaper than OFFSET on
	 * large tables and immune to "rows shifted between pages" bugs because
	 * new subscriptions are appended with a larger id.
	 *
	 * @param int    $after_id    Keyset cursor: only return subscriptions
	 *                            with `id > $after_id`. Pass 0 for the
	 *                            first page.
	 * @param int    $limit       Maximum rows per page.
	 * @param string $signal_type Which signal to run the shortlist for.
	 *                            Defaults to Supports-auto-renewal so
	 *                            already-queued SCAN_BATCH actions keep
	 *                            hitting the original path after a deploy.
	 *
	 * @return int[] Subscription ids, ascending. May be shorter than
	 *               `$limit` when the tail of the table is reached.
	 */
	public function candidate_ids( int $after_id, int $limit, string $signal_type = CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL ): array {
		if ( CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL === $signal_type ) {
			return $this->missing_renewal_candidate_ids( $after_id, $limit );
		}

		return $this->supports_auto_renewal_candidate_ids( $after_id, $limit );
	}

	/**
	 * Supports-auto-renewal SQL shortlist: manual-renewal flag on, payment
	 * method set. HPOS + CPT branches.
	 *
	 * @param int $after_id Keyset cursor.
	 * @param int $limit    Max rows.
	 *
	 * @return int[]
	 */
	private function supports_auto_renewal_candidate_ids( int $after_id, int $limit ): array {
		global $wpdb;

		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			// HPOS: `payment_method` lives on the orders table itself as a
			// dedicated column, so we only need a single join (for the
			// requires_manual_renewal meta).
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT o.id
				 FROM {$wpdb->prefix}wc_orders o
				 JOIN {$wpdb->prefix}wc_orders_meta mr
				   ON o.id = mr.order_id
				   AND mr.meta_key = '_requires_manual_renewal'
				   AND mr.meta_value = 'true'
				 WHERE o.type = 'shop_subscription'
				   AND o.status IN ('wc-active','wc-on-hold','wc-pending-cancel')
				   AND o.payment_method <> ''
				   AND o.id > %d
				 ORDER BY o.id ASC
				 LIMIT %d",
				$after_id,
				$limit
			);
		} else {
			// CPT: `_payment_method` is a postmeta row, so we join twice —
			// once for the manual-renewal flag, once for the gateway id.
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} mr
				   ON p.ID = mr.post_id
				   AND mr.meta_key = '_requires_manual_renewal'
				   AND mr.meta_value = 'true'
				 JOIN {$wpdb->postmeta} pm
				   ON p.ID = pm.post_id
				   AND pm.meta_key = '_payment_method'
				   AND pm.meta_value <> ''
				 WHERE p.post_type = 'shop_subscription'
				   AND p.post_status IN ('wc-active','wc-on-hold','wc-pending-cancel')
				   AND p.ID > %d
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$after_id,
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql was built via $wpdb->prepare() in the branch above.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

		return empty( $ids ) ? array() : $ids;
	}

	/**
	 * Apply the PHP-side classifier to a list of subscription ids.
	 *
	 * Each subscription is run through a short pipeline of exclusion checks
	 * and, if it survives, returned with the signals + details payload the
	 * admin surface consumes. Callers pass in the shortlist from
	 * `candidate_ids()` (or any arbitrary id list, for targeted rescans).
	 *
	 * The returned array is **keyed by subscription id**, not numerically
	 * indexed, so admin code and stores can look up a classification
	 * directly by id without re-scanning the list.
	 *
	 * @param int[]  $subscription_ids Subscription ids to classify. An empty
	 *                                 array short-circuits to an empty
	 *                                 result.
	 * @param string $signal_type      Which signal pipeline to run. Defaults
	 *                                 to Supports-auto-renewal so already-
	 *                                 queued SCAN_BATCH actions keep hitting
	 *                                 the original path after a deploy.
	 *
	 * @return array<int, array{signals: string[], details: array<string, mixed>}>
	 */
	public function classify_ids( array $subscription_ids, string $signal_type = CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL ): array {
		if ( empty( $subscription_ids ) ) {
			return array();
		}

		// Single batched scan into the `_subscription_renewal` postmeta /
		// wc_orders_meta bucket — the structural bottleneck that no per-sub
		// rewrite can avoid. Both classifiers read from $this->renewals_cache.
		$this->prefetch_renewals_by_sub( array_map( 'intval', $subscription_ids ) );
		$out = array();

		foreach ( $subscription_ids as $sub_id ) {
			$id           = (int) $sub_id;
			$subscription = wcs_get_subscription( $id );
			if ( ! $subscription instanceof WC_Subscription ) {
				continue;
			}

			switch ( $signal_type ) {
				case CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL:
					$result = $this->classify_missing_renewal_one( $subscription );
					break;
				case CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL:
					$result = $this->classify_supports_auto_renewal_one( $subscription );
					break;
				default:
					$result = null;
					break;
			}

			if ( null !== $result ) {
				$out[ $id ] = $result;
			}
		}

		return $out;
	}

	/**
	 * Classify a single subscription against all signal types.
	 *
	 * @param WC_Subscription $subscription Subscription to classify.
	 *
	 * @return array<string, array{signals: string[], details: array<string, mixed>}|null>
	 *         Keyed by signal type. Value is the classification array
	 *         or null when the subscription doesn't match the signal.
	 */
	public function classify_all_signals( WC_Subscription $subscription ): array {
		$this->prefetch_renewals_by_sub( array( (int) $subscription->get_id() ) );

		return array(
			CandidateStore::SIGNAL_TYPE_MISSING_RENEWAL => $this->classify_missing_renewal_one( $subscription ),
			CandidateStore::SIGNAL_TYPE_SUPPORTS_AUTO_RENEWAL => $this->classify_supports_auto_renewal_one( $subscription ),
		);
	}

	/**
	 * Per-batch prefetch of every renewal order linked to any sub in the
	 * input list. Returns a map keyed by `sub_id`, each value carrying the
	 * latest renewal (id + status) and the full timestamp list (consumed
	 * by the missing-renewal classifier's window check).
	 *
	 * Pipeline:
	 *  1. `read_renewal_ids_from_order_meta()` reads WCS's
	 *     `_subscription_renewal_order_ids_cache` postmeta — bounded by
	 *     sub count, not total store renewals.
	 *  2. `bucket_scan_renewal_ids()` is a one-shot fallback for subs
	 *     whose cache row is missing entirely (legacy data, manual DB
	 *     intervention, partial migrations) — mirrors WCS's own lazy
	 *     rebuild so missing cache doesn't surface false positives in
	 *     the missing-renewal classifier.
	 *  3. Per-sub renewal IDs are capped to the top 10 by ID DESC, then
	 *     accumulated into chunks of up to 1 000 IDs.
	 *     `resolve_latest_renewal_chunk()` calls `fetch_renewal_data()`
	 *     for each chunk and picks the single latest renewal per sub
	 *     via `(date_gmt DESC, id DESC)`.
	 *
	 * Subs with no renewals are stored as null sentinels so subsequent
	 * lookups skip the DB. Classifiers read directly from
	 * `$this->renewals_cache[ $sub_id ] ?? null`.
	 *
	 * @param int[] $sub_ids Subscription ids to fetch renewals for.
	 */
	private function prefetch_renewals_by_sub( array $sub_ids ): void {
		if ( empty( $sub_ids ) ) {
			return;
		}

		$sub_ids = array_map( 'intval', $sub_ids );

		// Check if all requested IDs are already cached.
		$cache    = &$this->renewals_cache;
		$uncached = array_filter(
			$sub_ids,
			static function ( $id ) use ( &$cache ) {
				return ! array_key_exists( $id, $cache );
			}
		);

		if ( empty( $uncached ) ) {
			return;
		}

		// Get "Sub ID => Renewal ID" map
		$renewal_ids_by_sub = $this->read_renewal_ids_from_order_meta( $uncached );
		$subs_without_cache = array_filter(
			$uncached,
			static function ( $id ) use ( $renewal_ids_by_sub ) {
				return ! array_key_exists( $id, $renewal_ids_by_sub );
			}
		);
		if ( ! empty( $subs_without_cache ) ) {
			$renewal_ids_by_sub += $this->bucket_scan_renewal_ids( $subs_without_cache );
		}

		// Resolve the latest renewal per subscription in chunks to keep
		// the IN() clause bounded. Each sub contributes at most its top
		// 10 renewal IDs (by ID DESC); chunks are flushed to
		// fetch_renewal_data() once the accumulated count reaches the
		// threshold.
		$max_candidates_per_sub = 10;
		$chunk_threshold        = 1000;
		$by_sub                 = array();
		$chunk_ids              = array();
		$chunk_subs             = array();

		foreach ( $renewal_ids_by_sub as $subscription_id => $renewal_ids ) {
			if ( empty( $renewal_ids ) ) {
				continue;
			}

			rsort( $renewal_ids, SORT_NUMERIC );
			$top_ids                        = array_slice( $renewal_ids, 0, $max_candidates_per_sub );
			$chunk_subs[ $subscription_id ] = $top_ids;
			foreach ( $top_ids as $rid ) {
				$chunk_ids[ $rid ] = true;
			}

			if ( count( $chunk_ids ) >= $chunk_threshold ) {
				$this->resolve_latest_renewal_chunk( $chunk_subs, $chunk_ids, $by_sub );
				$chunk_ids  = array();
				$chunk_subs = array();
			}
		}

		if ( ! empty( $chunk_ids ) ) {
			$this->resolve_latest_renewal_chunk( $chunk_subs, $chunk_ids, $by_sub );
		}

		// Store null sentinels for subscriptions confirmed to have no
		// renewals so subsequent calls don't re-query them.
		foreach ( $uncached as $id ) {
			if ( ! isset( $by_sub[ $id ] ) ) {
				$by_sub[ $id ] = null;
			}
		}

		$this->store_renewals_in_cache( $by_sub );
	}

	/**
	 * Merge fetched renewal data into the per-request cache.
	 *
	 * @param array<int, RenewalSnapshot|null> $renewals Keyed by subscription id.
	 */
	private function store_renewals_in_cache( array $renewals ): void {
		$this->renewals_cache += $renewals;
	}

	/**
	 * Fetch renewal data for a chunk of renewal IDs and pick the latest
	 * renewal per subscription using `(date_gmt DESC, id DESC)`.
	 *
	 * Results are merged into the `$by_sub` map passed by reference.
	 *
	 * @param array<int, int[]>       $chunk_subs Sub_id => candidate renewal ids for this chunk.
	 * @param array<int, true>        $chunk_ids  Flat set of all renewal ids in the chunk.
	 * @param array<int, array>       &$by_sub    Accumulated results, keyed by sub_id.
	 */
	private function resolve_latest_renewal_chunk( array $chunk_subs, array $chunk_ids, array &$by_sub ): void {
		$renewals_data = $this->fetch_renewal_data( array_keys( $chunk_ids ) );

		foreach ( $chunk_subs as $subscription_id => $renewal_ids ) {
			$latest_id     = 0;
			$latest_status = '';
			$latest_ts     = 0;

			foreach ( $renewal_ids as $renewal_id ) {
				if ( ! isset( $renewals_data[ $renewal_id ] ) ) {
					continue;
				}
				$data = $renewals_data[ $renewal_id ];
				if ( $data['date_gmt'] > $latest_ts || ( $data['date_gmt'] === $latest_ts && $renewal_id > $latest_id ) ) {
					$latest_ts     = $data['date_gmt'];
					$latest_id     = $renewal_id;
					$latest_status = $data['status'];
				}
			}

			if ( 0 === $latest_id ) {
				continue;
			}

			$by_sub[ $subscription_id ] = new RenewalSnapshot( $latest_id, $latest_status, $latest_ts );
		}
	}

	/**
	 * Read the per-sub renewal id list from WCS's
	 * `_subscription_renewal_order_ids_cache` postmeta.
	 *
	 * Subs whose cache row exists return their (possibly empty) list;
	 * subs with no cache row at all are absent from the result, signalling
	 * the caller to fall back to a bucket scan. Subs with an empty cache
	 * value (`a:0:{}`) are kept with `[]` because WCS sets that explicitly
	 * to mean "confirmed zero renewals" — bypassing the fallback there
	 * preserves correctness without churning the bucket index.
	 *
	 * @param int[] $sub_ids Subscription ids to look up.
	 *
	 * @return array<int, int[]> Map of sub_id => renewal_ids.
	 */
	private function read_renewal_ids_from_order_meta( array $sub_ids ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $sub_ids ), '%d' ) );
		$cache_table  = wcs_is_custom_order_tables_usage_enabled() ? "{$wpdb->prefix}wc_orders_meta" : $wpdb->postmeta;
		$cache_col    = wcs_is_custom_order_tables_usage_enabled() ? 'order_id' : 'post_id';
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic IN-list; placeholders built from %d only.
				"SELECT {$cache_col} AS sub_id, meta_value AS cached FROM {$cache_table} WHERE meta_key = '_subscription_renewal_order_ids_cache' AND {$cache_col} IN ({$placeholders})",
				...$sub_ids
			),
			ARRAY_A
		);

		// `$wpdb->get_results()` returns null (not []) on query failure
		// — DB unavailable, table missing, query timeout, etc. Without
		// this guard the foreach below silently no-ops on PHP 7.4 and
		// emits a generic "argument must be of type array, null given"
		// warning on PHP 8+ that support has no realistic chance of
		// correlating with a Health Check scan run. Surface the SQL
		// error in the WC log so it shows up alongside other
		// `wcs-health-check` source entries, and bail with an empty
		// map so the classifier sees "no renewals" rather than
		// faulting.
		if ( null === $rows ) {
			wc_get_logger()->warning(
				'Health Check: renewal-prefetch (cache rows) query failed — ' . $wpdb->last_error,
				array( 'source' => 'wcs-health-check' )
			);
			return array();
		}

		$renewal_ids_by_sub = array();
		foreach ( $rows as $row ) {
			$subscription_id                        = (int) $row['sub_id'];
			$cached_ids                             = maybe_unserialize( $row['cached'] );
			$renewal_ids_by_sub[ $subscription_id ] = is_array( $cached_ids )
				? array_map( 'intval', array_values( $cached_ids ) )
				: array();
		}

		return $renewal_ids_by_sub;
	}

	/**
	 * Fallback: scan the `_subscription_renewal` bucket for subs whose
	 * cache row was missing. One bounded `IN(...)` query against the
	 * meta table, mirroring the same lookup WCS does internally on
	 * cache miss.
	 *
	 * `_subscription_renewal` meta is stored on each renewal order with
	 * `meta_value = parent sub id`, so we filter by `meta_value` (the
	 * sub id) and read the row's order_id / post_id as the renewal id.
	 *
	 * @param int[] $sub_ids Subscriptions whose cache row was missing.
	 *
	 * @return array<int, int[]> Map of sub_id => renewal_ids.
	 */
	private function bucket_scan_renewal_ids( array $sub_ids ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $sub_ids ), '%s' ) );
		$values       = array_map( 'strval', $sub_ids );
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic IN-list; placeholders built from %s only.
					"SELECT meta_value AS sub_id, order_id AS renewal_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_subscription_renewal' AND meta_value IN ({$placeholders})",
					...$values
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic IN-list; placeholders built from %s only.
					"SELECT meta_value AS sub_id, post_id AS renewal_id FROM {$wpdb->postmeta} WHERE meta_key = '_subscription_renewal' AND meta_value IN ({$placeholders})",
					...$values
				),
				ARRAY_A
			);
		}

		// Same null-result guard as the cache-rows / status-rows queries.
		// On failure the caller would silently see no renewals for these
		// subs and the missing-renewal classifier would surface them as
		// false positives. Log + bail with an empty map instead.
		if ( null === $rows ) {
			wc_get_logger()->warning(
				'Health Check: renewal-prefetch (bucket-scan fallback) query failed — ' . $wpdb->last_error,
				array( 'source' => 'wcs-health-check' )
			);
			return array();
		}

		$renewal_ids_by_sub = array();
		foreach ( $rows as $row ) {
			$renewal_ids_by_sub[ (int) $row['sub_id'] ][] = (int) $row['renewal_id'];
		}

		return $renewal_ids_by_sub;
	}

	/**
	 * Fetch status + date_gmt for a set of renewal orders via a single
	 * PK IN-list against `wp_posts` / `wc_orders`. Status comes back
	 * stripped of the `wc-` prefix to match `WC_Order::get_status()`.
	 *
	 * @param int[] $renewal_ids Renewal order ids.
	 *
	 * @return array<int, array{status: string, date_gmt: int}>
	 */
	private function fetch_renewal_data( array $renewal_ids ): array {
		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $renewal_ids ), '%d' ) );
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic IN-list; placeholders built from %d only.
					"SELECT id AS renewal_id, status, date_created_gmt AS date_gmt FROM {$wpdb->prefix}wc_orders WHERE id IN ({$placeholders})",
					...$renewal_ids
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- dynamic IN-list; placeholders built from %d only.
					"SELECT ID AS renewal_id, post_status AS status, post_date_gmt AS date_gmt FROM {$wpdb->posts} WHERE ID IN ({$placeholders})",
					...$renewal_ids
				),
				ARRAY_A
			);
		}

		// Same null-result guard as the cache-rows query above. A null
		// here would silently leave `$renewals_data` empty, which the
		// missing-renewal classifier reads as "no renewals on file" —
		// flipping every past-due sub into a false-positive surface.
		// Log + bail with an empty map.
		if ( null === $rows ) {
			wc_get_logger()->warning(
				'Health Check: renewal-prefetch (status rows) query failed — ' . $wpdb->last_error,
				array( 'source' => 'wcs-health-check' )
			);
			return array();
		}

		$renewals_data = array();
		foreach ( $rows as $row ) {
			$renewals_data[ (int) $row['renewal_id'] ] = array(
				'status'   => OrderUtil::remove_status_prefix( (string) $row['status'] ),
				'date_gmt' => (int) wcs_date_to_time( (string) $row['date_gmt'] ),
			);
		}

		return $renewals_data;
	}

	/**
	 * Supports-auto-renewal classifier.
	 *
	 * Returns null when the subscription should be excluded; returns the
	 * classification array otherwise. Exclusion checks are ordered from
	 * cheapest to most expensive so we bail out as early as possible.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 *
	 * @return array{signals: string[], details: array<string, mixed>}|null
	 */
	private function classify_supports_auto_renewal_one( WC_Subscription $subscription ): ?array {
		$sub_id = (int) $subscription->get_id();

		// If something between scan and classify flipped the manual flag
		// back off, the sub is no longer a victim — drop it.
		if ( ! $subscription->is_manual() ) {
			return null;
		}

		$gateway_id = (string) $subscription->get_payment_method();
		if ( '' === $gateway_id || ! $this->gateway_supports_subscriptions( $gateway_id ) ) {
			return null;
		}

		if ( ! $this->customer_has_token( $subscription, $gateway_id ) ) {
			return null;
		}

		$renewal = $this->renewals_cache[ $sub_id ] ?? null;

		return array(
			'signals' => array( 'has_token' ),
			'details' => array_merge(
				array(
					'gateway'               => $gateway_id,
					'latest_renewal_id'     => $renewal ? $renewal->id : 0,
					'latest_renewal_status' => $renewal ? $renewal->status : '',
					'latest_renewal_date'   => $renewal ? $renewal->date_gmt : 0,
				),
				$this->notes_derived_details( $subscription )
			),
		);
	}

	/**
	 * Fetch the latest 50 order notes once and derive the note-keyed
	 * diagnostic fields the candidate row carries — `renewal_preference`
	 * (opt-out / re-enable detection) and `imported_as_manual`.
	 *
	 * Both signal-type classifiers share the same shape so the
	 * list-table renderer, filter, and sort code paths can rely on the
	 * keys being present regardless of which signal flagged the row.
	 * The two underlying string matchers (`last_renewal_preference_note`,
	 * `has_imported_as_manual_note`) both consume the same notes array,
	 * so fetching once and feeding both keeps the per-row work to a
	 * single `wc_get_order_notes()` query.
	 *
	 * @param WC_Subscription $subscription Subscription whose notes to inspect.
	 *
	 * @return array{renewal_preference: string|null, imported_as_manual: bool}
	 */
	private function notes_derived_details( WC_Subscription $subscription ): array {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $subscription->get_id(),
				'limit'    => 50,
			)
		);

		return array(
			'renewal_preference' => $this->last_renewal_preference_note( $notes ),
			'imported_as_manual' => $this->has_imported_as_manual_note( $notes ),
		);
	}

	/**
	 * Classify the subscription's most recent renewal-toggle order note,
	 * returning `'opted_out'`, `'re_enabled'`, or null when no renewal-
	 * toggle note exists.
	 *
	 * WCS writes an order note whenever the per-subscription auto-renewal
	 * toggle is flipped (from both My Account and the Edit Subscription
	 * screen). The two sources:
	 *
	 *   - `WCS_My_Account_Auto_Renew_Toggle` — writes either
	 *     "Customer turned off automatic renewals via their My Account page."
	 *     or "Customer turned on automatic renewals via their My Account page."
	 *   - `WCS_Change_Payment_Method_Admin` — writes
	 *     "Admin turned on automatic renewals by changing payment method…"
	 *     when a merchant flips an existing sub from manual to auto via
	 *     the edit screen. There is no admin-side OFF variant — admins
	 *     toggling to manual via the edit screen don't emit a note.
	 *
	 * "Latest wins" — `wc_get_order_notes()` returns notes in reverse-
	 * chronological order (newest first), so the first match in iteration
	 * is the most recent renewal-toggle decision. A sub that was opted
	 * out then later re-enabled reads as `re_enabled`; a sub that was
	 * re-enabled then later opted out reads as `opted_out`.
	 *
	 * Match strategy (each note checked against both):
	 *   - Opt-out: English substring "turned off automatic renewals" OR
	 *     the `__()`-translated literal of the customer opt-out phrase.
	 *   - Re-enable: English substring "turned on automatic renewals" OR
	 *     either `__()`-translated literal (customer + admin variants).
	 *
	 * Cross-locale limitation persists — a note written under locale A
	 * and scanned under locale B is still missed. V2 should have WCS
	 * core emit a meta flag at note-write time so detection stops
	 * relying on translatable note content.
	 *
	 * @param array $notes Pre-fetched order notes for the subscription,
	 *                     newest-first (as `wc_get_order_notes()` emits).
	 *
	 * @return string|null `'opted_out'`, `'re_enabled'`, or null.
	 */
	private function last_renewal_preference_note( array $notes ): ?string {
		$off_substring = 'turned off automatic renewals';
		$on_substring  = 'turned on automatic renewals';

		// Resolve localised phrases once, outside the loop.
		$localized_off         = __( 'Customer turned off automatic renewals via their My Account page.', 'woocommerce-subscriptions' );
		$localized_on_customer = __( 'Customer turned on automatic renewals via their My Account page.', 'woocommerce-subscriptions' );

		foreach ( $notes as $note ) {
			$content = (string) $note->content;

			if (
				false !== stripos( $content, $off_substring )
				|| ( '' !== $localized_off && false !== stripos( $content, $localized_off ) )
			) {
				return 'opted_out';
			}

			if (
				false !== stripos( $content, $on_substring )
				|| ( '' !== $localized_on_customer && false !== stripos( $content, $localized_on_customer ) )
			) {
				return 're_enabled';
			}
		}

		return null;
	}

	/**
	 * Whether the subscription was brought into the store via the
	 * "import subscriptions" flow as an already-manual sub (WOOSUBS-506 /
	 * WOOSUBS-544).
	 *
	 * Imported-as-manual subs legitimately carry `_requires_manual_renewal =
	 * true` even when the merchant uses an automatic gateway elsewhere —
	 * the importer preserved the source state. Without this exclusion they
	 * surface as medium-confidence candidates.
	 *
	 * We match on either of the two note substrings produced by the
	 * importer — "Imported subscription" (the generic import confirmation)
	 * or "payment method manual" (the explicit manual-method marker).
	 * Matching either/or gives us better coverage than requiring both in
	 * the same note.
	 *
	 * Localization: the importer plugin is external — not WooCommerce
	 * Subscriptions core — so its translations use their own text domain
	 * we can't reliably read. We apply `__()` against
	 * `woocommerce-subscriptions` as a best-effort fallback anyway: if
	 * the phrase has been translated under our domain for any reason,
	 * we gain coverage; if not, `__()` returns the source and the check
	 * collapses to the English case (no regression). The pending v1
	 * localisation work on `last_renewal_preference_note()` has the
	 * same cross-locale known limitation.
	 *
	 * @param array $notes Pre-fetched order notes for the subscription
	 *                     under analysis.
	 *
	 * @return bool
	 */
	private function has_imported_as_manual_note( array $notes ): bool {
		// Resolve translations once outside the loop.
		$phrases   = array(
			'Imported subscription',
			'payment method manual',
		);
		$localized = array();
		foreach ( $phrases as $phrase ) {
			$translated = __( $phrase, 'woocommerce-subscriptions' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- deliberate runtime resolution of external-plugin phrases.
			if ( is_string( $translated ) && '' !== $translated && $translated !== $phrase ) {
				$localized[] = $translated;
			}
		}

		$needles = array_merge( $phrases, $localized );

		foreach ( $notes as $note ) {
			$content = (string) $note->content;
			foreach ( $needles as $needle ) {
				if ( false !== stripos( $content, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a payment gateway with the given id is registered AND
	 * declares support for the `subscriptions` feature.
	 *
	 * Uses the live `WC()->payment_gateways()->payment_gateways()` map so
	 * that test-registered gateways (via the `woocommerce_payment_gateways`
	 * filter) are picked up the same way production gateways are.
	 *
	 * @param string $gateway_id Gateway identifier from the sub's
	 *                           `_payment_method` meta.
	 *
	 * @return bool
	 */
	private function gateway_supports_subscriptions( string $gateway_id ): bool {
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways[ $gateway_id ] ) ) {
			return false;
		}

		$gateway = $gateways[ $gateway_id ];
		return method_exists( $gateway, 'supports' ) && $gateway->supports( 'subscriptions' );
	}

	/**
	 * Whether the customer on the subscription has at least one saved
	 * payment token for the given gateway.
	 *
	 * A subscription "looks automatic" in the detection sense only when
	 * there is a token the gateway could have charged — otherwise the
	 * merchant would have been forced into manual billing whether or not
	 * a bug flipped the flag, so the fix wouldn't actually restore
	 * auto-renewal.
	 *
	 * @param WC_Subscription $subscription Subscription under analysis.
	 * @param string          $gateway_id   Gateway id to look up tokens for.
	 *
	 * @return bool
	 */
	private function customer_has_token( WC_Subscription $subscription, string $gateway_id ): bool {
		$customer_id = (int) $subscription->get_customer_id();
		if ( $customer_id <= 0 ) {
			return false;
		}

		// Route through WCS_Payment_Tokens rather than WC core so each
		// (customer, gateway) pair hits the DB at most once per request.
		// During a 200-sub scan batch where many subscriptions share a
		// customer, the WCS wrapper's static cache collapses repeated
		// lookups to a single query. Identical signature, so the swap is
		// drop-in.
		$tokens = WCS_Payment_Tokens::get_customer_tokens( $customer_id, $gateway_id );
		return ! empty( $tokens );
	}

	//
	// ───── Missing-renewal signal ────────────────────────────────────────
	//

	/**
	 * Missing-renewal SQL shortlist.
	 *
	 * Surfaces subscriptions that are active-or-on-hold AND have either:
	 *
	 *   1. No next-payment date set (NULL / '' / '0'). The natural-
	 *      expiry exclusion lives in the PHP classifier via
	 *      `calculate_date('next_payment')`, not a SQL guard on
	 *      `_schedule_end` — a fixed-length sub mid-term whose
	 *      next-payment got wiped still surfaces here.
	 *   2. A next-payment date strictly in the past, beyond the
	 *      freshness grace period (which absorbs clock skew and
	 *      normal Action Scheduler latency). The renewal-order matching
	 *      check that narrows the past-due branch down to genuinely-
	 *      stuck rows runs in the PHP classifier, because it needs the
	 *      WC_Order object API — too expensive for the SQL shortlist.
	 *
	 * The past-due freshness grace is filterable via
	 * `wcs_health_check_past_due_freshness_seconds` — defaults to 24 hours.
	 *
	 * Both predicates combine into a single WHERE clause with an OR.
	 * HPOS and CPT variants are kept in parallel. A single LEFT JOIN on
	 * `_schedule_next_payment` plus `SELECT DISTINCT` keeps the plan
	 * flat and dedups the duplicate-meta-row corruption case.
	 *
	 * @param int $after_id Keyset cursor.
	 * @param int $limit    Max rows.
	 *
	 * @return int[]
	 */
	private function missing_renewal_candidate_ids( int $after_id, int $limit ): array {
		global $wpdb;

		$freshness_seconds = $this->past_due_freshness_seconds();
		// Compute the past-due cutoff in PHP + UTC so MySQL timezone
		// config can't drift the comparison. WCS stores `_schedule_*`
		// meta values as MySQL DATETIME strings in UTC; lexicographic
		// comparison on ISO-8601 is monotonic with date order.
		$past_due_cutoff = gmdate( 'Y-m-d H:i:s', time() - $freshness_seconds );

		// SELECT DISTINCT defends against duplicate `_schedule_next_payment`
		// meta rows (data corruption, broken importers, partial migrations)
		// inflating `count($ids)` and the per-batch counters that feed it.
		// The natural-expiry exclusion lives in the PHP classifier via
		// `calculate_date('next_payment')` rather than a SQL guard on
		// `_schedule_end`, so a fixed-length sub mid-term whose
		// next-payment got wiped still surfaces here.
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT o.id
				 FROM {$wpdb->prefix}wc_orders o
				 LEFT JOIN {$wpdb->prefix}wc_orders_meta snp
				   ON o.id = snp.order_id
				   AND snp.meta_key = '_schedule_next_payment'
				 WHERE o.type = 'shop_subscription'
				   AND o.status IN ('wc-active','wc-on-hold')
				   AND o.id > %d
				   AND (
				     snp.meta_value IS NULL
				     OR snp.meta_value = ''
				     OR snp.meta_value = '0'
				     OR snp.meta_value < %s
				   )
				 ORDER BY o.id ASC
				 LIMIT %d",
				$after_id,
				$past_due_cutoff,
				$limit
			);
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} snp
				   ON p.ID = snp.post_id
				   AND snp.meta_key = '_schedule_next_payment'
				 WHERE p.post_type = 'shop_subscription'
				   AND p.post_status IN ('wc-active','wc-on-hold')
				   AND p.ID > %d
				   AND (
				     snp.meta_value IS NULL
				     OR snp.meta_value = ''
				     OR snp.meta_value = '0'
				     OR snp.meta_value < %s
				   )
				 ORDER BY p.ID ASC
				 LIMIT %d",
				$after_id,
				$past_due_cutoff,
				$limit
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql was built via $wpdb->prepare() in the branch above.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

		return empty( $ids ) ? array() : $ids;
	}

	/**
	 * Missing-renewal PHP classifier.
	 *
	 * Re-verifies the SQL shortlist against the live WC_Subscription — a
	 * sub whose status changed between scan and classify (customer paid,
	 * subscription cancelled, etc.) should not surface. For the past-due
	 * branch, it re-checks that the date is still stale under the current
	 * freshness grace and skips any subscription with a scheduled payment
	 * retry, since the retry system is still working on it.
	 *
	 * Details payload shape:
	 *   - next_payment_timestamp: int Unix timestamp, 0 if missing.
	 *   - next_payment_state: 'missing' or 'past_due'.
	 *   - cycle_period:   'day' | 'week' | 'month' | 'year' | ''.
	 *   - cycle_interval: int ≥ 1.
	 *   - billing_mode:   'auto' | 'manual'.
	 *   - latest_renewal_id / latest_renewal_status for the most recent
	 *     renewal order (if any) — shared column with the Supports-auto-
	 *     renewal signal so `render_renewal_order_status()` can use the
	 *     same stash key regardless of which tab surfaced the row.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 *
	 * @return array{signals: string[], details: array<string, mixed>}|null
	 */
	private function classify_missing_renewal_one( WC_Subscription $subscription ): ?array {
		$sub_id = (int) $subscription->get_id();

		// Re-verify status — the SQL shortlist saw active/on-hold, but
		// anything could have happened between the SQL page and this
		// classify call (manual admin action, a concurrent scan retrying
		// a stale id, etc).
		if ( ! in_array( (string) $subscription->get_status(), array( 'active', 'on-hold' ), true ) ) {
			return null;
		}

		$next_payment_ts   = (int) $subscription->get_time( 'next_payment' );
		$end_ts            = (int) $subscription->get_time( 'end' );
		$retry_ts          = (int) $subscription->get_time( 'payment_retry' );
		$now_ts            = time();
		$freshness_seconds = $this->past_due_freshness_seconds();

		$renewal = $this->renewals_cache[ $sub_id ] ?? null;

		// Determine which branch this candidate surfaced through. The
		// SQL shortlist already filtered to one of the two; the
		// classifier just distinguishes them for the details payload
		// and applies the branch-specific guard.
		if ( $next_payment_ts <= 0 ) {
			// Branch 1 — missing next-payment. Defer to WCS's own
			// calculator: `calculate_date('next_payment')` returns 0
			// exactly when there is no next cycle to schedule (final-
			// cycle natural expiry). WCS core uses the same test to
			// decide whether to clear `_schedule_next_payment` — see
			// class-wc-subscription.php:566–569.
			//
			// A bare `_schedule_end > now` guard would falsely exclude
			// fixed-length subs mid-term whose next-payment got wiped
			// by a data-corruption event; the calculator looks at
			// billing period + interval + last payment too, so it
			// distinguishes "natural expiry" from "broken schedule".
			if ( $subscription->calculate_date( 'next_payment' ) <= 0 ) {
				return null;
			}
			$state = 'missing';
		} else {
			// Branch 2 — past-due. SQL already applied the freshness
			// grace for the "is this stale" decision; the classifier
			// narrows to genuinely-stuck rows by re-checking the
			// freshness window and skipping subscriptions with a
			// scheduled payment retry.
			if ( $next_payment_ts >= $now_ts - $freshness_seconds ) {
				// Freshness grace shifted in the interval between SQL
				// scan and classify (e.g. the filter returned a larger
				// value on this tick). Drop the candidate rather than
				// surface a row whose scheduled date isn't actually
				// past-due under the current grace period.
				return null;
			}
			// If a payment retry is scheduled, the retry system is
			// still working on this subscription — don't surface it
			// as past-due to avoid the merchant triggering a duplicate
			// charge via "process missed renewal."
			if ( $retry_ts > $now_ts ) {
				return null;
			}

			$state = 'past_due';
		}

		return array(
			'signals' => array( 'missing_renewal' ),
			'details' => array_merge(
				array(
					'next_payment_timestamp' => $next_payment_ts,
					'next_payment_state'     => $state,
					'cycle_period'           => (string) $subscription->get_billing_period(),
					'cycle_interval'         => (int) $subscription->get_billing_interval(),
					'billing_mode'           => $subscription->is_manual() ? 'manual' : 'auto',
					'end_timestamp'          => $end_ts,
					'latest_renewal_id'      => $renewal ? $renewal->id : 0,
					'latest_renewal_status'  => $renewal ? $renewal->status : '',
					'latest_renewal_date'    => $renewal ? $renewal->date_gmt : 0,
					'payment_retry_date'     => $retry_ts,
				),
				$this->notes_derived_details( $subscription )
			),
		);
	}

	/**
	 * Past-due freshness grace period (seconds). A
	 * `_schedule_next_payment` value is considered stale enough to
	 * surface only after this much time has passed beyond the due
	 * date. Acts as a buffer so a sub whose due date passed less than
	 * the configured window ago does not surface yet — Action
	 * Scheduler still has time to fire. Defaults to `DAY_IN_SECONDS`.
	 *
	 * Clamped to a non-negative integer because a negative value would
	 * invert the comparison and surface every future-dated subscription.
	 *
	 * @return int
	 */
	private function past_due_freshness_seconds(): int {
		/**
		 * Filters the past-due freshness grace period applied when
		 * deciding whether a `_schedule_next_payment` value is stale
		 * enough to surface in the Missing renewals list. Values ≤ 0
		 * are clamped to 0 (no grace).
		 *
		 * @since 8.7.0
		 *
		 * @param int $seconds Default `DAY_IN_SECONDS`.
		 */
		$seconds = (int) apply_filters(
			'wcs_health_check_past_due_freshness_seconds',
			DAY_IN_SECONDS
		);

		return max( 0, $seconds );
	}
}
