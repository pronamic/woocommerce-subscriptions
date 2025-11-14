<?php
/**
 * Subscription Product Variation Class
 *
 * The subscription product variation class extends the WC_Product_Variation product class
 * to create subscription product variations.
 *
 * @class    WC_Product_Subscription
 * @package  WooCommerce Subscriptions
 * @category Class
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v1.3
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Subscription_Variation extends WC_Product_Variation {

	/**
	 * Magic __get method for backwards compatibility. Map legacy vars to WC_Subscriptions_Product getters.
	 *
	 * @param  string $key Key name.
	 * @return mixed
	 */
	public function __get( $key ) {

		$value = wcs_product_deprecated_property_handler( $key, $this );

		// No matching property found in wcs_product_deprecated_property_handler()
		if ( is_null( $value ) ) {
			$value = parent::__get( $key );
		}

		return $value;
	}

	/**
	 * Get internal type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'subscription_variation';
	}

	/**
	 * Get variation price HTML. Prices are not inherited from parents.
	 *
	 * @return string containing the formatted price
	 */
	public function get_price_html( $price = '' ) {

		$price = parent::get_price_html( $price );

		if ( ! empty( $price ) ) {
			$price = WC_Subscriptions_Product::get_price_string( $this, array( 'price' => $price ) );
		}

		return $price;
	}

	/**
	 * Get the add to cart button text
	 *
	 * @return string
	 */
	public function add_to_cart_text() {

		if ( $this->is_purchasable() && $this->is_in_stock() ) {
			$text = WC_Subscriptions_Product::get_add_to_cart_text();
		} else {
			$text = parent::add_to_cart_text(); // translated "Read More"
		}

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Get the add to cart button text for the single page
	 *
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', WC_Subscriptions_Product::get_add_to_cart_text(), $this );
	}

	/**
	 * Checks if the variable product this variation belongs to is purchasable.
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		$purchasable = WCS_Limiter::is_purchasable( wc_get_product( $this->get_parent_id() )->is_purchasable(), $this );
		return apply_filters( 'woocommerce_subscription_variation_is_purchasable', $purchasable, $this );
	}

	/**
	 * Checks the product type to see if it is either this product's type or the parent's
	 * product type.
	 *
	 * @param mixed $type Array or string of types
	 * @return bool
	 */
	public function is_type( $type ) {
		if ( 'variation' === $type || ( is_array( $type ) && in_array( 'variation', $type, true ) ) ) {
			return true;
		} else {
			return parent::is_type( $type );
		}
	}

	/* Deprecated Functions */

	/**
	 * Return the sign-up fee for this product
	 *
	 * @return string
	 */
	public function get_sign_up_fee() {
		wcs_deprecated_function( __METHOD__, '2.2.0', 'WC_Subscriptions_Product::get_sign_up_fee( $this )' );
		return WC_Subscriptions_Product::get_sign_up_fee( $this );
	}

	/**
	 * Returns the sign up fee (including tax) by filtering the products price used in
	 * @see WC_Product::get_price_including_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_including_tax( $qty = 1, $price = '' ) {
		wcs_deprecated_function( __METHOD__, '2.2.0', 'wcs_get_price_including_tax( $product, array( "qty" => $qty, "price" => $price ) )' );

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_including_tax = parent::get_price_including_tax( $qty );

		remove_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100 );

		return $sign_up_fee_including_tax;
	}

	/**
	 * Returns the sign up fee (excluding tax) by filtering the products price used in
	 * @see WC_Product::get_price_excluding_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_excluding_tax( $qty = 1, $price = '' ) {
		wcs_deprecated_function( __METHOD__, '2.2.0', 'wcs_get_price_excluding_tax( $product, array( "qty" => $qty, "price" => $price ) )' );

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_excluding_tax = parent::get_price_excluding_tax( $qty );

		remove_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100 );

		return $sign_up_fee_excluding_tax;
	}
}
