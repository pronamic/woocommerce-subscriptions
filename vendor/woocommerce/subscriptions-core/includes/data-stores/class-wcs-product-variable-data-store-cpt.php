<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WCS Variable Product Data Store: Stored in CPT.
 *
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 * @author   Prospress
 */
class WCS_Product_Variable_Data_Store_CPT extends WC_Product_Variable_Data_Store_CPT {

	/**
	 * A cache of products having their min and max variation data read.
	 * Used as a circuit breaker to prevent multiple object reads causing infinite loops.
	 *
	 * @var array
	 */
	protected static $reading_min_max_variation_data = array();

	/**
	 * Method to read a product from the database.
	 *
	 * @param WC_Product_Variable_Subscription $product Product object.
	 * @throws Exception If invalid product.
	 */
	public function read( &$product ) {
		parent::read( $product );
		$this->read_min_max_variation_data( $product );
	}

	/**
	 * Read min and max variation data from post meta.
	 *
	 * @param WC_Product_Variable_Subscription $product Product object.
	 */
	protected function read_min_max_variation_data( &$product ) {
		if ( ! isset( self::$reading_min_max_variation_data[ $product->get_id() ] ) && ! $product->meta_exists( '_min_max_variation_ids_hash' ) ) {
			self::$reading_min_max_variation_data[ $product->get_id() ] = '';

			$product->set_min_and_max_variation_data();

			update_post_meta( $product->get_id(), '_min_max_variation_data', $product->get_meta( '_min_max_variation_data', true ), true );
			update_post_meta( $product->get_id(), '_min_max_variation_ids_hash', $product->get_meta( '_min_max_variation_ids_hash', true ), true );
			unset( self::$reading_min_max_variation_data[ $product->get_id() ] );
		}
	}
}
