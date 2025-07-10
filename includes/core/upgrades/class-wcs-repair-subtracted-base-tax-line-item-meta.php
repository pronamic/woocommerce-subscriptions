<?php
/**
 * Between WCS 3.0.10 and WCS 3.1.0 purchases outside the store's base location where prices are entered exclusive of tax, we have stored the amount of tax subtracted
 * from the product price to account for the taxes that apply to the store's base location. We stored those taxes taking into account the line item qty. In 3.0.14 we
 * fixed a bug that caused manual changes to the line item qty to not update the tax amount and so the tax amounts stored in `_subtracted_base_location_tax` line item
 * meta may be incorrect.
 *
 * This script will repair that data by:
 *   1. Getting all line items with `_subtracted_base_location_tax` meta. This meta is only present on stores and for purchases where this data is applicable
 *      (prices inclusive of tax and purchased outside the store's tax jurisdiction).
 *   2. Schedule a background job, via Action Scheduler, to repair each of those line items.
 *   3. For each line item get the tax rates that were subtracted at the time of purchase and reverse engineer the product's price from the subtotal and current tax rates.
 *      - for these sites, the line item's subtotal is the standard price - (minus) base location taxes.
 *      - therefore it's possible to add back the taxes, to get back to the base price.
 *      - this approach assumes the base location's tax rates haven't changed since the store upgraded to 3.0.10. New rates are fine, just changes to the existing rates which existed at the time.
 *   4. This repair deletes the existing '_subtracted_base_location_tax' meta, and calculates the base tax rates not including of quantity so changes to the line item quantity doesn't affect the value.
 *
 * @see https://github.com/woocommerce/woocommerce-subscriptions/pull/4039
 *
 * @author   WooCommerce
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Upgrades
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WCS_Repair_Subtracted_Base_Tax_Line_Item_Meta extends WCS_Background_Repairer {

	/**
	 * Constructor
	 *
	 * @param WC_Logger_Interface $logger The WC_Logger instance.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public function __construct( WC_Logger_Interface $logger ) {
		$this->scheduled_hook = 'wcs_schedule_subtracted_base_line_item_tax_repairs';
		$this->repair_hook    = 'wcs_subtracted_base_line_item_meta_tax_repair';
		$this->log_handle     = 'wcs-repair-subtracted-line-item-base-tax-meta';
		$this->logger         = $logger;
	}

	/**
	 * Get a batch of line items with _subtracted_base_location_tax meta to repair.
	 *
	 * @param int $page The page number to get results from. Base 1 - the first page is 1.
	 * @return array    A list of line item ids.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	protected function get_items_to_repair( $page ) {
		global $wpdb;
		$limit  = 20;
		$offset = ( $page - 1 ) * $limit;

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_item_id
				FROM {$wpdb->prefix}woocommerce_order_itemmeta
				WHERE `meta_key` = '_subtracted_base_location_tax'
				LIMIT %d, %d",
				$offset,
				$limit
			)
		);
	}

	/**
	 * Repair the line item meta for a given line item.
	 *
	 * @param int $line_item_id The ID for the line item to repair.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public function repair_item( $line_item_id ) {
		try {
			$item = WC_Order_Factory::get_order_item( $line_item_id );

			if ( ! $item ) {
				$this->log( sprintf( 'WARNING: The item (%d) could not be loaded from the database.', $line_item_id ) );
				return;
			}

			if ( ! $item->meta_exists( '_subtracted_base_location_tax' ) ) {
				// The meta might have been deleted after the repair was scheduled so there's no need to warn against this.
				return;
			}

			$current_base_location_taxes = $item->get_meta( '_subtracted_base_location_tax' );

			// Regenerate the base tax rates that applied at the time of purchase from the rate IDs.
			$base_rates = array();
			foreach ( $current_base_location_taxes as $rate_id => $tax_amount ) {
				$rate = WC_Tax::_get_tax_rate( $rate_id );

				if ( empty( $rate ) ) {
					$this->log( sprintf( 'WARNING: The line item %d (#%s) could not be repaired because the tax rate (#%d) applicable at the time of purchase no longer exists.', $line_item_id, $item->get_order_id(), $rate_id ) );
					return;
				}

				$base_rates[ $rate_id ] = array(
					'rate'     => (float) $rate['tax_rate'],
					'label'    => $rate['tax_rate_name'],
					'shipping' => $rate['tax_rate_shipping'] ? 'yes' : 'no',
					'compound' => $rate['tax_rate_compound'] ? 'yes' : 'no',
				);
			}

			// Reverse engineer the original product's base price from the base rates and the item's subtotal.
			$product_price = ( $item->get_subtotal() + array_sum( WC_Tax::calc_exclusive_tax( $item->get_subtotal(), $base_rates ) ) ) / $item->get_quantity();

			// Delete the old meta, store the new tax figures and store the full set of rates for completeness.
			$item->update_meta_data( '_subtracted_base_location_taxes', WC_Tax::calc_tax( $product_price, $base_rates, true ) );
			$item->update_meta_data( '_subtracted_base_location_rates', $base_rates );
			$item->delete_meta_data( '_subtracted_base_location_tax' );
			$item->save();

			$this->log( sprintf( 'The "_subtracted_base_location_tax" line item meta for %d (#%s) was repaired. Original product price assumed: $%s.', $line_item_id, $item->get_order_id(), $product_price ) );
		} catch ( Exception $e ) {
			$this->log( sprintf( 'ERROR: Exception caught trying to repair the subtracted base tax data for line item: %d - exception message: %s ---', $line_item_id, $e->getMessage() ) );
		}
	}
}
