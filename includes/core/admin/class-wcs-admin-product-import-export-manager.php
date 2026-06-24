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
		add_filter( 'woocommerce_product_import_pre_insert_product_object', array( __CLASS__, 'set_subscription_price_on_import' ) );
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

				// All variation products are exported with the 'variation' key so remove the unneeded `subscription_variation`.
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
	 * Filters product import data to handle subscription product types.
	 *
	 * Auto-enables legacy subscription product type settings when the CSV importer encounters
	 * subscription or variable-subscription types that are currently disabled, so the import
	 * succeeds instead of failing with "Invalid product type".
	 *
	 * Also converts subscription_variation types to variation, since subscription variations
	 * are identical to standard variations except for their parent product type.
	 *
	 * @param array $data The product's import data.
	 * @return array $data
	 */
	public static function import_subscription_variations( $data ) {
		if ( ! isset( $data['type'] ) ) {
			return $data;
		}

		$type_to_option = array(
			'subscription'          => WC_Subscriptions_Admin::$option_prefix . '_enable_simple_subscription',
			'variable-subscription' => WC_Subscriptions_Admin::$option_prefix . '_enable_variable_subscription',
		);

		if ( isset( $type_to_option[ $data['type'] ] ) ) {
			$option_name = $type_to_option[ $data['type'] ];

			if ( 'yes' !== get_option( $option_name, 'no' ) ) {
				update_option( $option_name, 'yes' );
			}
		}

		if ( 'subscription_variation' === $data['type'] ) {
			$data['type'] = 'variation';
		}

		return $data;
	}

	/**
	 * Sets the subscription price meta when importing a subscription product.
	 *
	 * During CSV imports, WooCommerce sets the regular price (`_price`) from the "Regular price" column,
	 * but subscription products also need _subscription_price to be set for proper and consistent pricing.
	 *
	 * @param WC_Product $product The product object being imported.
	 *
	 * @return WC_Product
	 */
	public static function set_subscription_price_on_import( $product ) {
		if ( ! $product instanceof WC_Product || ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			return $product;
		}

		$product->update_meta_data( '_subscription_price', $product->get_regular_price() );
		return $product;
	}
}
