<?php
/**
 * There is "magic" in PHP, and then there is this.
 *
 * Story time: once upon a time, in a land not too far away, WooCommerce 3.0 deprecated accessing
 * all properties on objects. A conventicle of wizards known as __get(), __set() and __isset() came
 * together to make sure that properties on Subscriptions products could still be used, despite not being
 * accessible. However, a dark cloud hung over properties which were arrays. None of the conventicle
 * new of magic powerful enough to deal with such a problem. Enter Cesar, who summoned the dark arts
 * to call upon the ArrayAccess incantation.
 *
 * In other words, this class is used to access specific items on an array from within the magic methods
 * of other objects, like WC_Product_Subscription_Variation::__get() for the property formerly known as
 * $subscription_variation_level_meta_data.
 *
 * @package WooCommerce Subscriptions
 * @category Class
 * @since 2.2.0
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Array_Property_Post_Meta_Black_Magic implements ArrayAccess {

	/**
	 * Store the ID this class is being used against so that we use it for post meta calls.
	 */
	protected $product_id;

	/**
	 * Constructor
	 *
	 * @access public
	 * @param mixed $product
	 */
	public function __construct( $product_id ) {
		$this->product_id = $product_id;
	}

	/**
	 * offsetGet
	 * @param string $key
	 * @return mixed
	 */
	public function offsetGet( $key ) {
		return get_post_meta( $this->product_id, $this->maybe_prefix_meta_key( $key ) );
	}

	/**
	 * offsetSet
	 * @param string $key
	 * @param mixed $value
	 */
	public function offsetSet( $key, $value ) {
		update_post_meta( $this->product_id, $this->maybe_prefix_meta_key( $key ), $value );
	}

	/**
	 * offsetExists
	 * @param string $key
	 * @return bool
	 */
	public function offsetExists( $key ) {
		return metadata_exists( 'post', $this->product_id, $this->maybe_prefix_meta_key( $key ) );
	}

	/**
	 * Nothing to do here as we access post meta directly.
	 */
	public function offsetUnset( $key ) {
	}

	/**
	 * We only work with post meta data that has meta keys prefixed with an underscore, so
	 * add a prefix if it is not already set.
	 */
	protected function maybe_prefix_meta_key( $key ) {
		if ( '_' != substr( $key, 0, 1 ) ) {
			$key = '_' . $key;
		}
		return $key;
	}
}
