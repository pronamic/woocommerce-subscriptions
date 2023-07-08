<?php
/**
 * Adds start date metadata to subscriptions.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Upgrades
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_Repair_Start_Date_Metadata extends WCS_Background_Upgrader {

	/**
	 * Constructor
	 *
	 * @param WC_Logger_Interface $logger The WC_Logger instance.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 */
	public function __construct( WC_Logger_Interface $logger ) {
		$this->scheduled_hook = 'wcs_add_start_date_metadata';
		$this->log_handle     = 'wcs-add-start-date-metadata';
		$this->logger         = $logger;
	}

	/**
	 * Update a subscription, saving its start date as metadata.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 */
	protected function update_item( $subscription_id ) {
		try {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( false === $subscription ) {
				throw new Exception( 'Failed to instantiate subscription object' );
			}

			// Saving the subscription is enough to save the start date.
			$subscription->save();

			$this->log( sprintf( 'Subscription ID %d start date metadata added.', $subscription_id ) );
		} catch ( Exception $e ) {
			$this->log( sprintf( '--- Exception caught adding start date metadata to subscription %d - exception message: %s ---', $subscription_id, $e->getMessage() ) );
		}
	}

	/**
	 * Get a batch of subscriptions to repair.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
	 * @return array A list of subscription ids which may need to be repaired.
	 */
	protected function get_items_to_update() {
		global $wpdb;

		return $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'shop_subscription'
				AND post_status NOT IN ( 'trash', 'auto-draft' )
				AND ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_schedule_start' )
			 LIMIT 20"
		);
	}

}
