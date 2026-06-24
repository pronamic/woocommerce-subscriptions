<?php
/**
 * WCS_ATT_Product_Data
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 5.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product data parallel structure for storing product properties.
 *
 * @class    WCS_ATT_Product_Data
 * @version  5.0.1
 */
class WCS_ATT_Product_Data {

	/**
	 * @var WCS_ATT_Product_Data - the single instance of the class.
	 */
	protected static $_instance = null;

	/**
	 * @var array - the instance's data.
	 */
	protected $data = array();

	/**
	 * Main WCS_ATT_Product_Data Instance.
	 *
	 * Ensures only one instance of WCS_ATT_Product_Data is loaded or can be loaded.
	 *
	 * @static
	 * @return WCS_ATT_Product_Data - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Overriding the constructor with a private one prevents calling it directly.
	 */
	private function __construct() {
		// Nothing is needed here.
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Foul!', 'woocommerce-subscriptions' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since APFS 1.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Foul!', 'woocommerce-subscriptions' ), '1.0.0' );
	}

	/**
	 * Gets product data.
	 *
	 * @param WC_Product  $product
	 * @param string      $key
	 * @param null|string $default
	 *
	 * @return string
	 */
	public function get( $product, $key, $default = null ) {

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $default;
		}

		$identifier = spl_object_hash( $product ) . $product->get_id();

		if ( ! isset( $this->data[ $identifier ] ) ) {
			return $default;
		}

		if ( ! isset( $this->data[ $identifier ][ $key ] ) ) {
			return $default;
		}

		return $this->data[ $identifier ][ $key ];
	}

	/**
	 * Sets product data.
	 *
	 * @param WC_Product $product
	 * @param string     $key
	 * @param string     $value
	 */
	public function set( $product, $key, $value ) {

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return;
		}

		$identifier = spl_object_hash( $product ) . $product->get_id();

		if ( ! isset( $this->data[ $identifier ] ) ) {
			$this->data[ $identifier ] = array();
		}

		$this->data[ $identifier ][ $key ] = $value;
	}

	/**
	 * Deletes product data.
	 *
	 * @param WC_Product $product
	 * @param string     $key
	 *
	 * @return boolean
	 */
	public function delete( $product, $key ) {

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return false;
		}

		$identifier = spl_object_hash( $product ) . $product->get_id();

		if ( ! isset( $this->data[ $identifier ] ) ) {
			return false;
		}

		if ( ! isset( $this->data[ $identifier ][ $key ] ) ) {
			return false;
		}

		unset( $this->data[ $identifier ][ $key ] );

		return true;
	}
}
