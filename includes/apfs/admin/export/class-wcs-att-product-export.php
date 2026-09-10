<?php
/**
 * WCS_ATT_Product_Export class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.2.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce core Product Exporter support.
 *
 * @class    WCS_ATT_Product_Export
 * @version  4.0.0
 */
class WCS_ATT_Product_Export {

	/**
	 * Hook in.
	 */
	public static function init() {

		// Export Subscription schemes.
		add_filter( 'woocommerce_product_export_meta_value', array( __CLASS__, 'export_subscription_schemes' ), 10, 4 );
	}

	/**
	 * Export Subscription schemes.
	 *
	 * @param  mixed        $meta_value
	 * @param  WC_Meta_Data $meta
	 * @param  WC_Product   $product
	 * @param  array        $row
	 * @return string        $meta_value
	 */
	public static function export_subscription_schemes( $meta_value, $meta, $product, $row ) {

		if ( in_array( $meta->key, array( '_wcsatt_schemes', '_wcsatt_selected_storewide_plans' ), true ) ) {
			if ( ! empty( $meta_value ) ) {
				$meta_value = wp_json_encode( $meta_value ); // Encode: the exporter skips meta values that are not scalar.
			}
		}

		return $meta_value;
	}
}
