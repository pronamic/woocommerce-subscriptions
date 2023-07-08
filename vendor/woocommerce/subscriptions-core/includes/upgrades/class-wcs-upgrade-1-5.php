<?php
/**
 * Class containing functions to upgrade Subscriptions data to v1.5
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_1_5 {

	/**
	 * Set status to 'sold individually' for all existing subscription products that haven't already been updated.
	 *
	 * Subscriptions 1.5 made it possible for a product to be sold individually or in multiple quantities, whereas
	 * previously it was possible only to buy a subscription product in a single quantity.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function upgrade_products() {
		global $wpdb;

		$sql = "SELECT DISTINCT ID FROM {$wpdb->posts} as posts
			JOIN {$wpdb->postmeta} as postmeta
				ON posts.ID = postmeta.post_id
				AND (postmeta.meta_key LIKE '_subscription%')
			JOIN  {$wpdb->postmeta} AS soldindividually
				ON posts.ID = soldindividually.post_id
				AND ( soldindividually.meta_key LIKE '_sold_individually' AND soldindividually.meta_value !=  'yes' )
			WHERE posts.post_type = 'product'";

		$subscription_product_ids = $wpdb->get_results( $sql );

		foreach ( $subscription_product_ids as $product_id ) {
			update_post_meta( $product_id->ID, '_sold_individually', 'yes' );
		}

		// Update to new system to limit subscriptions by status rather than in a binary way
		$wpdb->query(
			"UPDATE $wpdb->postmeta
			SET meta_value = 'any'
			WHERE meta_key LIKE '_subscription_limit'
			AND meta_value LIKE 'yes'"
		);

		return count( $subscription_product_ids );
	}

	/**
	 * Update subscription WP-Cron tasks to Action Scheduler.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function upgrade_hooks( $number_hooks_to_upgrade ) {

		$counter = 0;

		$cron = _get_cron_array();

		foreach ( $cron as $timestamp => $actions ) {
			foreach ( $actions as $hook => $details ) {
				if ( 'scheduled_subscription_payment' == $hook || 'scheduled_subscription_expiration' == $hook || 'scheduled_subscription_end_of_prepaid_term' == $hook || 'scheduled_subscription_trial_end' == $hook || 'paypal_check_subscription_payment' == $hook ) {
					foreach ( $details as $hook_key => $values ) {

						if ( ! as_next_scheduled_action( $hook, $values['args'] ) ) {
							as_schedule_single_action( $timestamp, $hook, $values['args'] );
							unset( $cron[ $timestamp ][ $hook ][ $hook_key ] );
							$counter++;
						}

						if ( $counter >= $number_hooks_to_upgrade ) {
							break;
						}
					}

					// If there are no other jobs scheduled for this hook at this timestamp, remove the entire hook
					if ( 0 == count( $cron[ $timestamp ][ $hook ] ) ) {
						unset( $cron[ $timestamp ][ $hook ] );
					}
					if ( $counter >= $number_hooks_to_upgrade ) {
						break;
					}
				}
			}

			// If there are no actions schedued for this timestamp, remove the entire schedule
			if ( 0 == count( $cron[ $timestamp ] ) ) {
				unset( $cron[ $timestamp ] );
			}
			if ( $counter >= $number_hooks_to_upgrade ) {
				break;
			}
		}

		// Set the cron with the removed schedule
		_set_cron_array( $cron );

		return $counter;
	}

}
