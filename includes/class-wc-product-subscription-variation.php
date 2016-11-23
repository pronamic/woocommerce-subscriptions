<?php
/**
 * Subscription Product Variation Class
 *
 * The subscription product variation class extends the WC_Product_Variation product class
 * to create subscription product variations.
 *
 * @class 		WC_Product_Subscription
 * @package		WooCommerce Subscriptions
 * @category	Class
 * @since		1.3
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Subscription_Variation extends WC_Product_Variation {

	var $product_type;

	/**
	 * Create a simple subscription product object.
	 *
	 * @access public
	 * @param mixed $product
	 */
	public function __construct( $product, $args = array() ) {

		parent::__construct( $product, $args = array() );

		$this->parent_product_type = $this->product_type;

		$this->product_type = 'subscription_variation';

		$this->subscription_variation_level_meta_data = array(
			'subscription_price'             => 0,
			'subscription_period'            => '',
			'subscription_period_interval'   => 'day',
			'subscription_length'            => 0,
			'subscription_trial_length'      => 0,
			'subscription_trial_period'      => 'day',
			'subscription_sign_up_fee'       => 0,
			'subscription_payment_sync_date' => 0,
		);

		$this->variation_level_meta_data = array_merge( $this->variation_level_meta_data, $this->subscription_variation_level_meta_data );
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
	 * @access public
	 * @return string
	 */
	public function add_to_cart_text() {

		if ( $this->is_purchasable() && $this->is_in_stock() ) {
			$text = get_option( WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text', __( 'Sign Up Now', 'woocommerce-subscriptions' ) );
		} else {
			$text = parent::add_to_cart_text(); // translated "Read More"
		}

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * Get the add to cart button text for the single page
	 *
	 * @access public
	 * @return string
	 */
	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', self::add_to_cart_text(), $this );
	}

	/**
	 * Returns the sign up fee (including tax) by filtering the products price used in
	 * @see WC_Product::get_price_including_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_including_tax( $qty = 1, $price = '' ) {

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_including_tax = parent::get_price_including_tax( $qty, $price );

		remove_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		return $sign_up_fee_including_tax;
	}

	/**
	 * Returns the sign up fee (excluding tax) by filtering the products price used in
	 * @see WC_Product::get_price_excluding_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_excluding_tax( $qty = 1, $price = '' ) {

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_excluding_tax = parent::get_price_excluding_tax( $qty, $price );

		remove_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		return $sign_up_fee_excluding_tax;
	}

	/**
	 * Return the sign-up fee for this product
	 *
	 * @return string
	 */
	public function get_sign_up_fee() {
		return WC_Subscriptions_Product::get_sign_up_fee( $this );
	}


	/**
	 * Checks if the variable product this variation belongs to is purchasable.
	 *
	 * @access public
	 * @return bool
	 */
	function is_purchasable() {

		$purchasable = WCS_Limiter::is_purchasable( $this->parent->is_purchasable(), $this );

		return apply_filters( 'woocommerce_subscription_variation_is_purchasable', $purchasable, $this );
	}

	/**
	 * Checks the product type to see if it is either this product's type or the parent's
	 * product type.
	 *
	 * @access public
	 * @param mixed $type Array or string of types
	 * @return bool
	 */
	public function is_type( $type ) {
		if ( $this->product_type == $type || ( is_array( $type ) && in_array( $this->product_type, $type ) ) ) {
			return true;
		} elseif ( $this->parent_product_type == $type || ( is_array( $type ) && in_array( $this->parent_product_type, $type ) ) ) {
			return true;
		} else {
			return false;
		}
	}
}
