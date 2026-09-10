<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * The two store-wide subscription counts the Health Check reports against,
 * and the status universe they share.
 *
 * `all()` is the denominator on the Status tab and the `All (N)` tab on the
 * candidates table. `all_and_covered()` returns it together with the numerator
 * behind it - how many subscriptions a scan's keyset walk has passed - off one
 * query. The two have to agree on which subscriptions exist or the reading is
 * nonsense, which is why the `trash` / `auto-draft` exclusion lives in one
 * place - `get_count_target()` - rather than being repeated at each query.
 *
 * Deliberately outside the `Admin` namespace. `all()` used to live on
 * `CandidatesListTable`, which extends `WP_List_Table` - a class WordPress
 * loads only for wp-admin requests. Reaching it from the Action Scheduler
 * worker (which runs under wp-cron with no admin includes) fatals with
 * `Class "WP_List_Table" not found`, and `handle_scan_batch()`'s `Throwable`
 * catch would turn that into a retry loop and a tripped circuit breaker
 * rather than a visible error.
 *
 * Direct SQL rather than `wcs_get_subscriptions( [ 'subscriptions_per_page'
 * => -1 ] )` because the latter hydrates every WC_Subscription object just to
 * count them - unbounded memory on big stores. The other near-equivalent,
 * `WC_Data_Store::load( 'subscription' )->get_subscriptions_count_by_status()`
 * (summed for store totals by `wcs_is_large_site()`), counts through
 * `wp_count_posts()`'s `counts` cache group, and a cached total can disagree
 * with the uncached snapshot `all_and_covered()` takes - the numerator and
 * denominator must come from the same instant, so both read uncached here.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class SubscriptionCounts {

	/**
	 * Total subscription count across the whole store.
	 *
	 * Matches the status filter `wcs_get_subscriptions( [ 'subscription_status'
	 * => [ 'any' ] ] )` actually applies - `trash` and `auto-draft` are excluded
	 * by the paginated fetch, so including them here would make the `All (N)`
	 * tab count drift higher than the rows a merchant can browse to.
	 *
	 * HPOS-aware: queries `wc_orders` when HPOS is enabled, falls back to
	 * `posts` otherwise.
	 *
	 * @param string $status_filter Optional single status to narrow the count to,
	 *                              so pagination matches the `wcs_get_subscriptions`
	 *                              result set. Allowlisted by the caller.
	 *
	 * @return int
	 *
	 * @throws HealthCheckDbException When the count query fails or never reaches the database.
	 */
	public static function all( string $status_filter = '' ): int {
		global $wpdb;

		$target = self::get_count_target( $status_filter );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count, get_count_target() builds the clause from a static allowlist or a prepared fragment.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$target['table']} WHERE {$target['where']}" );

		self::assert_count_succeeded(
			'' === $status_filter
				? 'store-wide subscription count'
				: "subscription count (status filter '{$status_filter}')"
		);

		return (int) $count;
	}

	/**
	 * Both figures in one pass: the store total, and how much of it a keyset
	 * cursor has passed.
	 *
	 * The scan-progress reading needs the pair together - it reports one against
	 * the other - and they share a WHERE clause, so asking separately walks the
	 * same rows twice. That matters because the pair is read on every Status tab
	 * render and on the 5s poll behind an in-flight scan, which before this
	 * feature cost a single `COUNT(*)`.
	 *
	 * Taking one snapshot also keeps the two figures consistent with each other.
	 * Read separately, a subscription trashed between them lands in the total but
	 * not the coverage, or the reverse.
	 *
	 * @param int $through_id Highest subscription id the scan has passed. Zero
	 *                        (no batch banked yet) skips the conditional sum.
	 *
	 * @return array{total: int, covered: int}
	 *
	 * @throws HealthCheckDbException When the count query fails or never reaches the database.
	 */
	public static function all_and_covered( int $through_id ): array {
		global $wpdb;

		if ( $through_id <= 0 ) {
			return array(
				'total'   => self::all(),
				'covered' => 0,
			);
		}

		$target = self::get_count_target();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/where come from get_count_target()'s static fragments; the cursor is prepared below.
		$sql = "SELECT COUNT(*) AS total, COALESCE( SUM( {$target['id_column']} <= %d ), 0 ) AS covered FROM {$target['table']} WHERE {$target['where']}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- read-only count, $sql is composed of get_count_target()'s static fragments and prepared here.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $through_id ), ARRAY_A );

		self::assert_count_succeeded( 'subscription count + coverage snapshot' );

		return array(
			'total'   => (int) ( $row['total'] ?? 0 ),
			'covered' => (int) ( $row['covered'] ?? 0 ),
		);
	}

	/**
	 * The table, id column and WHERE clause every count in this class runs
	 * against - the class's single definition of "a subscription that exists".
	 *
	 * `all()` and `all_and_covered()` are numerator and denominator of one
	 * reading, so a status-universe edit that reached only one of them would
	 * silently desync the pair. Both build their SQL from here so that edit has
	 * exactly one place to land.
	 *
	 * @param string $status_filter Optional single status replacing the
	 *                              trash/auto-draft exclusion. Allowlisted by
	 *                              the caller; normalised to the `wc-` prefix
	 *                              both datastores persist.
	 *
	 * @return array{table: string, id_column: string, where: string}
	 */
	private static function get_count_target( string $status_filter = '' ): array {
		global $wpdb;

		$hpos = wcs_is_custom_order_tables_usage_enabled();

		$table         = $hpos ? $wpdb->prefix . 'wc_orders' : $wpdb->posts;
		$id_column     = $hpos ? 'id' : 'ID';
		$type_clause   = $hpos ? "type = 'shop_subscription'" : "post_type = 'shop_subscription'";
		$status_column = $hpos ? 'status' : 'post_status';

		if ( '' !== $status_filter ) {
			$status        = 'wc-' === substr( $status_filter, 0, 3 ) ? $status_filter : 'wc-' . $status_filter;
			$status_clause = $wpdb->prepare( "{$status_column} = %s", $status ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column name is one of two literals chosen above.
		} else {
			$status_clause = "{$status_column} NOT IN ('trash', 'auto-draft')";
		}

		return array(
			'table'     => $table,
			'id_column' => $id_column,
			'where'     => "{$type_clause} AND {$status_clause}",
		);
	}

	/**
	 * Raise when the count query the caller just ran failed at the SQL layer.
	 *
	 * A failed aggregate comes back null and casts to 0, which is
	 * indistinguishable from a store with no subscriptions - so continuing
	 * with the value would hand every caller a fabricated reading: the worker
	 * would persist it into `stats_json` for the life of the run row, and the
	 * admin surfaces would present an empty store as fact. Raising keeps the
	 * counts deterministic; each caller decides what a failure costs on its
	 * own path (the batch handler retries, the render/poll/cancel paths catch
	 * and degrade to a missing number). The sibling of
	 * `Detector::assert_shortlist_query_succeeded()`, with the same contract:
	 * call immediately after the query - `wpdb::query()` flushes `last_error`
	 * before every query it executes, so a non-empty value here belongs to the
	 * query just run, never to a stale earlier failure.
	 *
	 * @param string $description Which count the caller just ran - names the
	 *                            failing query in the log line and the
	 *                            exception, since this class runs several
	 *                            (the store-wide total, the filtered total,
	 *                            the total + coverage pair) and the driver
	 *                            error alone does not always say which.
	 *
	 * @return void
	 *
	 * @throws HealthCheckDbException When the count query the caller just ran failed.
	 */
	private static function assert_count_succeeded( string $description ): void {
		global $wpdb;

		if ( empty( $wpdb->last_error ) ) {
			return;
		}

		wc_get_logger()->error(
			"Health Check: {$description} query failed - " . $wpdb->last_error,
			array( 'source' => 'wcs-health-check' )
		);

		throw new HealthCheckDbException( esc_html( "Health Check: {$description} query failed." ) );
	}
}
