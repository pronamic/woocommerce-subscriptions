<?php
/**
 * WCS_ATT_Product_Import class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 2.2.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce core Product Importer support.
 *
 * @class    WCS_ATT_Product_Import
 * @version  4.0.0
 */
class WCS_ATT_Product_Import {

	/**
	 * Hook in.
	 */
	public static function init() {

		// Parse Subscription schemes.
		add_filter( 'woocommerce_product_importer_parsed_data', array( __CLASS__, 'import_subscription_schemes' ), 10, 2 );
	}

	/**
	 * Parse Subscription schemes.
	 *
	 * @param  array                   $parsed_data
	 * @param  WC_Product_CSV_Importer $importer
	 * @return array                    $parsed_data
	 */
	public static function import_subscription_schemes( $parsed_data, $importer ) {

		if ( empty( $parsed_data['meta_data'] ) ) {
			return $parsed_data;
		}

		foreach ( $parsed_data['meta_data'] as $meta_data_index => $meta_data ) {
			if ( in_array( $meta_data['key'], array( '_wcsatt_schemes', '_wcsatt_selected_storewide_plans' ), true ) ) {
				if ( ! empty( $meta_data['value'] ) ) {
					$meta_data['value']                           = json_decode( $meta_data['value'], true );
					$parsed_data['meta_data'][ $meta_data_index ] = $meta_data;
				}
			}
		}

		return $parsed_data;
	}
}
