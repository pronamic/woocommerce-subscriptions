<?php
/**
 * Repair subscriptions that have missing address indexes.
 *
 * Post WooCommerce Subscriptions 2.3 address indexes are used when searching via the admin subscriptions table.
 * Subscriptions created prior to WC 3.0 won't have those meta keys set and so this repair script will generate them.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Upgrades
 * @version  2.3.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_Repair_Subscription_Address_Indexes extends WCS_Background_Upgrader {

	/**
	 * Constructor
	 *
	 * @param WC_Logger_Interface $logger The WC_Logger instance.
	 *
	 * @since 2.3.0
	 */
	public function __construct( WC_Logger_Interface $logger ) {
		$this->scheduled_hook = 'wcs_add_missing_subscription_address_indexes';
		$this->log_handle     = 'wcs-add-subscription-address-indexes';
		$this->logger         = $logger;
	}

	/**
	 * Update a subscription, setting its address indexes.
	 *
	 * @since 2.3.0
	 */
	protected function update_item( $subscription_id ) {
		try {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( false === $subscription ) {
				throw new Exception( 'Failed to instantiate subscription object' );
			}

			// Saving the subscription sets the address indexes if they don't exist.
			$subscription->save();

			$this->log( sprintf( 'Subscription ID %d address index(es) added.', $subscription_id ) );
		} catch ( Exception $e ) {
			$this->log( sprintf( '--- Exception caught repairing subscription %d - exception message: %s ---', $subscription_id, $e->getMessage() ) );
		}
	}

	/**
	 * Get a batch of subscriptions which need address indexes.
	 *
	 * @since 2.3.0
	 * @return array A list of subscription ids which need address indexes.
	 */
	protected function get_items_to_update() {
		return get_posts( array(
			'post_type'      => 'shop_subscription',
			'posts_per_page' => 20,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_billing_address_index',
					'compare' => 'NOT EXISTS',
				),
			),
		) );
	}
}
