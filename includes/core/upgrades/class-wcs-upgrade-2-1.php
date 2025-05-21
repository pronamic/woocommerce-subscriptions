<?php
/**
 * Methods to upgrade subscriptions data to v2.1
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * @deprecated
 */
class WCS_Upgrade_2_1 {

	/**
	 * Set the _schedule_cancelled post meta value to store a subscription's cancellation
	 * date for those subscriptions still in the pending cancellation state, and therefore
	 * where it is possible to determine the cancellation date.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
	 */
	public static function set_cancelled_dates() {
		global $wpdb;

		$wpdb->query( 'SET SQL_BIG_SELECTS = 1;' );

		$cancelled_date_meta_key = wcs_get_date_meta_key( 'cancelled' );

		// Run two separate insert queries for pending cancellation and cancelled subscriptions. This could easily be done in one query, but we'll run it in two separate queries to minimise issues with large databases.
		foreach ( array( 'wc-pending-cancel', 'wc-cancelled' ) as $post_status ) {

			$rows_inserted = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta}(post_id, meta_key, meta_value)
					SELECT ID, %s, post_modified_gmt
						FROM {$wpdb->posts} as posts
					WHERE post_status = %s
					AND NOT EXISTS (
						SELECT null
						FROM {$wpdb->postmeta} as postmeta
						WHERE postmeta.post_id = posts.ID
						AND postmeta.meta_key = %s
					)
				",
				$cancelled_date_meta_key,
				$post_status,
				$cancelled_date_meta_key
			) );

			WCS_Upgrade_Logger::add( sprintf( 'v2.1: Set _schedule_cancelled date to post_modified_gmt column value for %d subscriptions with %s status.', $rows_inserted, $post_status ) );
		}
	}
}
