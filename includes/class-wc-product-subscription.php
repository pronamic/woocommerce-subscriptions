<?php
/**
 * Subscription Product Class
 *
 * The subscription product class is an extension of the simple product class.
 *
 * @class WC_Product_Subscription
 * @package WooCommerce Subscriptions
 * @subpackage WC_Product_Subscription
 * @category Class
 * @since 1.3
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Subscription extends WC_Product_Simple {

	/**
	 * Get internal type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'subscription';
	}

	/**
	 * Auto-load in-accessible properties on demand.
	 *
	 * @param mixed $key
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
	 * Get subscription's price HTML.
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
			$text = __( 'Read more', 'woocommerce-subscriptions' );
		}

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Get the add to cart button text for the single page
	 *
	 * @return string The single product page add to cart text.
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', WC_Subscriptions_Product::get_add_to_cart_text(), $this );
	}

	/**
	 * Checks if the store manager has requested the current product be limited to one purchase
	 * per customer, and if so, checks whether the customer already has an active subscription to
	 * the product.
	 *
	 * @access public
	 * @return bool
	 */
	function is_purchasable() {
		$purchasable = WCS_Limiter::is_purchasable( parent::is_purchasable(), $this );

		return apply_filters( 'woocommerce_subscription_is_purchasable', $purchasable, $this );
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
	public function get_sign_up_fee_including_tax( $qty = 1 ) {
		wcs_deprecated_function( __METHOD__, '2.2.0', 'wcs_get_price_including_tax( $product, array( "qty" => $qty, "price" => WC_Subscriptions_Product::get_sign_up_fee( $product ) ) )' );
		return wcs_get_price_including_tax(
			$this,
			array(
				'qty'   => $qty,
				'price' => WC_Subscriptions_Product::get_sign_up_fee( $this ),
			)
		);
	}

	/**
	 * Returns the sign up fee (excluding tax) by filtering the products price used in
	 * @see WC_Product::get_price_excluding_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_excluding_tax( $qty = 1 ) {
		wcs_deprecated_function( __METHOD__, '2.2.0', 'wcs_get_price_excluding_tax( $product, array( "qty" => $qty, "price" => WC_Subscriptions_Product::get_sign_up_fee( $product ) ) )' );
		return wcs_get_price_excluding_tax(
			$this,
			array(
				'qty'   => $qty,
				'price' => WC_Subscriptions_Product::get_sign_up_fee( $this ),
			)
		);
	}
}
