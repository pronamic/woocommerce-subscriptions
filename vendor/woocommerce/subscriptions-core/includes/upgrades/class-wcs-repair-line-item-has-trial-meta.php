<?php
/**
 * Between WCS 2.2.0 and WCS 2.6.0 subscription purchases of free trial products haven't set the `_has_trial` line item meta
 * on subscription line items.
 *
 * This script will repair that missing data by:
 *   1. Getting all subscriptions which were purchased with a free trial. Those with '_trial_period' subscription meta.
 *   2. Schedule a background job, via Action Scheduler, to repair each of those subscriptions.
 *   3. For each subscription line item that was purchased in the parent order (has not been switched or added manually), set the _has_trial line item meta.
 *
 * All line items on subscriptions with _trial_period meta, that were purchased in the parent order must have had a free trial because subscriptions are grouped in the cart by trial period and length.
 * For more details @see https://github.com/Prospress/woocommerce-subscriptions/pull/3239
 *
 * @author   WooCommerce
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Upgrades
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCS_Repair_Line_Item_Has_Trial_Meta extends WCS_Background_Repairer {

	/**
	 * Constructor
	 *
	 * @param WC_Logger_Interface $logger The WC_Logger instance.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public function __construct( WC_Logger_Interface $logger ) {
		$this->scheduled_hook = 'wcs_schedule_trial_subscription_repairs';
		$this->repair_hook    = 'wcs_free_trial_line_item_meta_repair';
		$this->log_handle     = 'wcs-repair-line-item-has-trial-meta';
		$this->logger         = $logger;
	}

	/**
	 * Get a batch of subscriptions which have or had free trials at the time of purchase.
	 *
	 * @param int $page The page number to get results from.
	 * @return array    A list of subscription ids.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	protected function get_items_to_repair( $page ) {
		$query      = new WP_Query();
		$query_args = array(
			'post_type'      => 'shop_subscription',
			'posts_per_page' => 20,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'ASC', // Get the subscriptions in ascending order by ID so any new subscriptions created after the repairs start running will be at the end and not cause issues with paging.
			'post_status'    => 'any',
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_trial_period',
					'compare' => '!=',
					'value'   => '',
				),
			),
		);

		return $query->query( $query_args );

	}

	/**
	 * Repair the line item meta for a given subscription ID.
	 *
	 * @param int $subscription_id
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public function repair_item( $subscription_id ) {
		try {
			$subscription = wcs_get_subscription( $subscription_id );

			if ( false === $subscription ) {
				throw new Exception( 'Failed to instantiate subscription object' );
			}

			$parent_order = $subscription->get_parent();

			if ( ! $parent_order ) {
				$this->log( sprintf( "Subscription ID %d doesn't have a parent order -- skipping", $subscription_id ) );
				return;
			}

			// Build an array of product IDs so we can match corresponding subscription items.
			$parent_order_product_ids = array();
			foreach ( $parent_order->get_items() as $line_item ) {
				$parent_order_product_ids[ wcs_get_canonical_product_id( $line_item ) ] = true;
			}

			// Set the has_trial meta if this subscription line item exists in the parent order,
			foreach ( $subscription->get_items() as $line_item ) {
				if ( isset( $parent_order_product_ids[ wcs_get_canonical_product_id( $line_item ) ] ) && ! $line_item->meta_exists( '_has_trial' ) ) {
					$line_item->update_meta_data( '_has_trial', 'true' );
					$line_item->save();
				}
			}

			$this->log( sprintf( 'Subscription ID %d "_has_trial" line item meta repaired.', $subscription_id ) );
		} catch ( Exception $e ) {
			$this->log( sprintf( 'ERROR: Exception caught trying to repair free trial line item meta for subscription %d - exception message: %s ---', $subscription_id, $e->getMessage() ) );
		}
	}
}
