<?php
/**
 * Repair missing _contains_synced_subscription post meta caused by an error in determining if a variation product was synced at subscription signup.
 * The error only affects subscriptions containing a synced variation product which were created with WC 3.0 and a Subscriptions version between 2.2.0 and 2.2.8
 *
 * @author Prospress
 * @category Admin
 * @package WooCommerce Subscriptions/Admin/Upgrades
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * @deprecated
 */
class WCS_Upgrade_2_2_9 {

	private static $cron_hook = 'wcs_repair_subscriptions_containing_synced_variations';
	private static $repaired_subscriptions_option = 'wcs_2_2_9_repaired_subscriptions';
	private static $batch_size = 30;

	/**
	 * Schedule an WP-Cron event to run in 3 minutes to repair subscription synced post meta.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.9
	 */
	public static function schedule_repair() {
		wp_schedule_single_event( gmdate( 'U' ) + ( MINUTE_IN_SECONDS * 3 ), self::$cron_hook );
	}

	/**
	 * Repair a batch of subscriptions.
	 *
	 * Subscriptions 2.2.0 included a bug which caused subscriptions which contain a synced variation product created while
	 * WC 3.0 was active, to have missing _contains_synced_subscription post meta. This was fixed to prevent new subscriptions
	 * falling victim to that bug in WCS 2.2.8 however existing subscriptions need to be repaired.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.9
	 */
	public static function repair_subscriptions_containing_synced_variations() {
		$repaired_subscriptions  = get_option( self::$repaired_subscriptions_option, array() );
		$subscriptions_to_repair = self::get_subscriptions_to_repair( $repaired_subscriptions );

		foreach ( $subscriptions_to_repair as $subscription_id ) {
			try {
				$subscription         = wcs_get_subscription( $subscription_id );
				$subscription_updated = false;

				if ( false === $subscription ) {
					throw new Exception( 'Failed to instantiate subscription object' );
				}

				foreach ( $subscription->get_items() as $item ) {
					$product_id = wcs_get_canonical_product_id( $item );

					if ( WC_Subscriptions_Synchroniser::is_product_synced( $product_id ) ) {
						update_post_meta( $subscription->get_id(), '_contains_synced_subscription', 'true' );
						self::log( sprintf( 'Subscription %d repaired for synced product ID: %d', $subscription_id, $product_id ) );
						$subscription_updated = true;
						break;
					}
				}

				if ( false === $subscription_updated ) {
					self::log( sprintf( 'Subscription %d wasn\'t repaired - no synced product found', $subscription_id ) );
				}
			} catch ( Exception $e ) {
				self::log( sprintf( '--- Exception caught repairing subscription %d - exception message: %s ---', $subscription_id, $e->getMessage() ) );
			}

			// Flag this subscription as repaired so we don't pull it into a following batch
			$repaired_subscriptions[] = $subscription_id;
			update_option( self::$repaired_subscriptions_option, $repaired_subscriptions, 'no' );
		}

		// If we've processed a full batch, schedule the next batch to be repaired
		if ( count( $subscriptions_to_repair ) === self::$batch_size ) {
			self::schedule_repair();
		} else {
			self::log( '2.2.9 repair missing _contains_synced_subscription post meta complete' );
		}
	}

	/**
	 * Get a batch of subscriptions to repair.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.9
	 * @param array $repaired_subscriptions A list of subscription post IDs to ignore.
	 * @return array A list of subscription ids which may need to be repaired.
	 */
	public static function get_subscriptions_to_repair( $repaired_subscriptions ) {
		$subscriptions_to_repair = get_posts( array(
			'post_type'      => 'shop_subscription',
			'posts_per_page' => self::$batch_size,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'post__not_in'   => $repaired_subscriptions,
			'meta_query'     => array(
				array(
					'key'     => '_contains_synced_subscription',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_order_version', // Try to narrow the focus to subscriptions created after 3.0.0 as they are the only ones affected and needing repair (tough all subscriptions instantiated after 3.0 will also have their _order_version updated)
					'value'   => '3.0.0',
					'compare' => '>=',
				),
			),
		) );

		return $subscriptions_to_repair;
	}

	/**
	 * Add a message to the wcs-upgrade-subscriptions-containing-synced-variations log
	 *
	 * @param string $message The message to be logged
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.9
	 */
	protected static function log( $message ) {
		WCS_Upgrade_Logger::add( $message, 'wcs-upgrade-subscriptions-containing-synced-variations' );
	}
}
