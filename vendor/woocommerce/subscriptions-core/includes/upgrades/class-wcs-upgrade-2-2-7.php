<?php
/**
 * Repair missing end_of_prepaid_term scheduled actions caused by race conditions when saving subscription date properties post WCS 2.2.0
 *
 * @author Prospress
 * @category Admin
 * @package WooCommerce Subscriptions/Admin/Upgrades
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_2_2_7 {

	private static $cron_hook  = 'wcs_repair_end_of_prepaid_term_actions';
	private static $batch_size = 30;

	/**
	 * Schedule an WP-Cron event to run in 5 minutes to repair pending cancelled subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function schedule_end_of_prepaid_term_repair() {
		wp_schedule_single_event( gmdate( 'U' ) + ( MINUTE_IN_SECONDS * 3 ), self::$cron_hook );
	}

	/**
	 * Repair a batch of pending cancelled subscriptions.
	 *
	 * Subscriptions 2.2.0 included a race condition which causes cancelled subscriptions to not schedule
	 * end of prepaid term actions. This results in pending cancelled subscriptions not transitioning to
	 * cancelled automatically.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public static function repair_pending_cancelled_subscriptions() {
		$subscriptions_to_repair  = self::get_subscriptions_to_repair();
		$end_of_prepaid_term_hook = apply_filters( 'woocommerce_subscriptions_scheduled_action_hook', 'woocommerce_scheduled_subscription_end_of_prepaid_term', 'end' );

		// Unhook emails to prevent a bunch of Cancelled Subscription emails being sent to Admin
		remove_action( 'woocommerce_subscription_status_updated', 'WC_Subscriptions_Email::send_cancelled_email', 10 );

		foreach ( $subscriptions_to_repair as $subscription_id ) {
			try {
				$subscription = wcs_get_subscription( $subscription_id );

				if ( false === $subscription ) {
					throw new Exception( 'Failed to instantiate subscription object' );
				}

				$end_time = $subscription->get_time( 'end' );

				// End date is in the past, this was likely because the end of prepaid term hook wasn't scheduled - cancel the subscription now
				if ( gmdate( 'U' ) > $end_time ) {

					self::log( sprintf( 'Subscription %d end date is in the past - cancelling now', $subscription_id ) );

					$subscription->update_status( 'cancelled', __( 'Subscription end date in the past', 'woocommerce-subscriptions' ) );
				} else {
					$action_args      = array( 'subscription_id' => $subscription_id );
					$scheduled_action = as_next_scheduled_action( $end_of_prepaid_term_hook, $action_args );

					// If there isn't a scheduled end of prepaid term, schedule one now.
					if ( false == $scheduled_action ) {
						self::log( sprintf( 'Subscription %d missing scheduled end of prepaid term action - scheduled new action (end timestamp: %d)', $subscription_id, $end_time ) );
						as_schedule_single_action( $end_time, $end_of_prepaid_term_hook, $action_args );
					} else {
						self::log( sprintf( 'Subscription %d has a scheduled end of prepaid term action - there\'s nothing to do here', $subscription_id ) );
					}
				}

				// Set a flag so we don't pull this subscription into a following batch
				update_post_meta( $subscription_id, '_wcs_2_2_7_repaired', 'true' );

			} catch ( Exception $e ) {
				self::log( sprintf( '--- Exception caught repairing subscription %d - exception message: %s ---', $subscription_id, $e->getMessage() ) );
				update_post_meta( $subscription_id, '_wcs_2_2_7_repaired', 'false' );
			}
		}

		// Reattach the cancelled subscription emails
		add_action( 'woocommerce_subscription_status_updated', 'WC_Subscriptions_Email::send_cancelled_email', 10, 2 );

		// If we've processed a full batch, schedule the next batch to be repaired
		if ( count( $subscriptions_to_repair ) == self::$batch_size ) {
			self::schedule_end_of_prepaid_term_repair();
		} else {
			self::log( '2.2.7 repair missing end of prepaid terms complete' );
		}
	}

	/**
	 * Get a batch of pending cancelled subscriptions to repair.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 * @return array An list of subscription ids which may need to be repaired.
	 */
	public static function get_subscriptions_to_repair() {
		$subscriptions_to_repair = get_posts( array(
			'post_type'      => 'shop_subscription',
			'post_status'    => 'wc-pending-cancel',
			'posts_per_page' => self::$batch_size,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_wcs_2_2_7_repaired',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		return $subscriptions_to_repair;
	}

	/**
	 * Add a message to the wcs-upgrade-end-of-prepaid-term-repair log
	 *
	 * @param string The message to be logged
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	protected static function log( $message ) {
		WCS_Upgrade_Logger::add( $message, 'wcs-upgrade-end-of-prepaid-term-repair' );
	}
}
