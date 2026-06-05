<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Queue_Management;

/**
 * Shared helper for the Queue_Management features that assert an Action Scheduler claim filter (`group` or
 * `exclude-groups`) scoped to a set of group slugs.
 *
 * Action Scheduler's DBStore resolves group slugs to IDs at claim time with `create_if_not_exists = false`,
 * and throws an `InvalidArgumentException` when *none* of the requested slugs has a row in the
 * `actionscheduler_groups` table — see {@see \ActionScheduler_DBStore::claim_actions()}. Because that resolution
 * happens inside the queue runner's claim, the exception aborts the *entire* run (every action in the batch),
 * not just the scoped subset. A slug has no row until the first action is scheduled under it, so a feature that
 * asserts a `group`/`exclude-groups` filter for a group that has never been used — e.g. dedicated processing
 * enabled before any subscription scheduled action exists — would take down all background processing on the
 * store. Consulting this helper before asserting the filter avoids that; the feature simply skips this run and
 * engages on a later one, once the group exists. (Note AS only throws when *no* requested slug resolves, so the
 * filter value itself can still be the full configured set — a non-existent slug mixed in with an existing one
 * is silently ignored by AS.)
 *
 * @internal This trait may be modified, moved or removed in future releases.
 */
trait Resolves_Existing_Groups {

	/**
	 * Returns the subset of the supplied Action Scheduler group slugs that currently exist as rows in the
	 * `actionscheduler_groups` table. Empty when none exist (or none were supplied).
	 *
	 * We query the table directly rather than through an Action Scheduler API because AS exposes no public
	 * slug -> existence check ({@see \ActionScheduler_DBStore::get_group_ids()} is `protected`), and because the
	 * Queue_Management features only ever engage against the stock {@see \ActionScheduler_DBStore} — the very
	 * assumption asserted by {@see Auto_Enable::has_expected_store()}. If support for additional Action Scheduler
	 * datastores is ever added, this direct query must be revisited: another store may not back groups with this
	 * table (or any table) at all.
	 *
	 * @param string[] $slugs Group slugs to check.
	 *
	 * @return string[] The subset of $slugs that exist, re-indexed. Empty if none exist.
	 */
	protected function existing_groups( array $slugs ): array {
		$slugs = array_values( array_unique( array_filter( $slugs, 'is_string' ) ) );

		if ( empty( $slugs ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );

		// The table name is a WordPress-registered identifier and `$placeholders` is a counted run of `%s`
		// tokens bound from `$slugs` below. Querying the DBStore groups table directly is safe because the
		// feature only ever runs against the stock store (see Auto_Enable::has_expected_store()), and the
		// result must be live since a group may have been created moments ago.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$existing = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT slug FROM {$wpdb->actionscheduler_groups} WHERE slug IN ( $placeholders )",
				...$slugs
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// A failed query (DB unavailable, table missing, deadlock, ...) yields an empty result — the same shape
		// as "none of these slugs exist yet", which makes every caller stand down. That is safe, but it also
		// hides a real fault: an operator would see the routine "group absent" breadcrumbs while subscription
		// processing stays disabled. Surface the DB error here so the two cases are distinguishable in the log.
		if ( ! empty( $wpdb->last_error ) ) {
			wc_get_logger()->warning(
				sprintf( 'Queue management group existence check failed; treating all groups as absent. Database error: %s', $wpdb->last_error ),
				array( 'source' => 'woocommerce-subscriptions' )
			);
		}

		return is_array( $existing ) ? array_map( 'strval', $existing ) : array();
	}
}
