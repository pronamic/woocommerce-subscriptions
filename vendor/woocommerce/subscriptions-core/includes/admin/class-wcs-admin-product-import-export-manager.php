<?php
/**
 * WooCommerce Subscriptions Admin Product Exporter
 *
 * A class to assist in filtering the WooCommerce core product csv importer/exporter.
 *
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v3.0.13
 */

defined( 'ABSPATH' ) || exit;

class WCS_Admin_Product_Import_Export_Manager {

	/**
	 * Attaches callbacks and initializes the class.
	 */
	public static function init() {
		add_filter( 'woocommerce_exporter_product_types', array( __CLASS__, 'register_susbcription_variation_type' ) );
		add_filter( 'woocommerce_product_export_product_query_args', array( __CLASS__, 'filter_export_query' ) );
		add_filter( 'woocommerce_product_import_process_item_data', array( __CLASS__, 'import_subscription_variations' ) );
	}

	/**
	 * Registers the subscription variation product type with the exporter.
	 *
	 * @param array $types The product type keys and labels.
	 * @return array $types
	 */
	public static function register_susbcription_variation_type( $types ) {
		$types['subscription_variation'] = __( 'Subscription variations', 'woocommerce-subscriptions' );
		return $types;
	}

	/**
	 * Filters the product export query args to separate standard variations and subscription variations.
	 *
	 * In the database subscription variations appear exactly the same as standard product variations. To
	 * enforce this distinction when exporting subscription variations, we exclude products with a standard variable product as a parent and vice versa.
	 *
	 * @param array $args The product export query args.
	 * @return array
	 */
	public static function filter_export_query( $args ) {
		if ( ! isset( $args['type'] ) || empty( $args['type'] ) || ! is_array( $args['type'] ) ) {
			return $args;
		}

		$export_subscription_variations = false;
		$export_variations              = false;

		foreach ( $args['type'] as $index => $product_type ) {
			if ( 'subscription_variation' === $product_type ) {
				$export_subscription_variations = true;

				// All variation products are exported with the 'variation' key so remove the uneeded `subscription_variation`.
				// Further filtering by product type will be handled by the query args (see below).
				unset( $args['type'][ $index ] );
			} elseif ( 'variation' === $product_type ) {
				$export_variations = true;
			}
		}

		// Exporting subscription variations but not standard variations. Exclude child variations of variable products.
		if ( $export_subscription_variations && ! $export_variations ) {
			$args['parent_exclude'] = wc_get_products(
				array(
					'type'   => 'variable',
					'limit'  => -1,
					'return' => 'ids',
				)
			);

			$args['type'][] = 'variation';
		// Exporting standard product variations but not subscription variations. Exclude child variations of subscription variable products.
		} elseif ( $export_variations && ! $export_subscription_variations ) {
			$args['parent_exclude'] = wc_get_products(
				array(
					'type'   => 'variable-subscription',
					'limit'  => -1,
					'return' => 'ids',
				)
			);
		}

		return $args;
	}

	/**
	 * Filters product import data so subcription variations are imported correctly (as variations).
	 *
	 * Subscription variations are the exact same as standard variations. What sets them apart is the fact they are linked
	 * to a variable subscription parent rather than a standard variable product. With that in mind, we need to import them just
	 * like a variation.
	 *
	 * @param array $data The product's import data.
	 * @return array $data
	 */
	public static function import_subscription_variations( $data ) {
		if ( isset( $data['type'] ) && 'subscription_variation' === $data['type'] ) {
			$data['type'] = 'variation';
		}

		return $data;
	}
}
