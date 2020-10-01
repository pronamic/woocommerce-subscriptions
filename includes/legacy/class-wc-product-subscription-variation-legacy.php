<?php
/**
 * Subscription Product Variation Legacy Class
 *
 * Extends WC_Product_Subscription_Variation to provide compatibility methods when running WooCommerce < 3.0.
 *
 * @class WC_Product_Subscription_Variation_Legacy
 * @package WooCommerce Subscriptions
 * @category Class
 * @since 2.2.0
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Subscription_Variation_Legacy extends WC_Product_Subscription_Variation {

	/**
	 * Set default array value for WC 3.0's data property.
	 * @var array
	 */
	protected $data = array();

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

	/* Copied from WC 2.6 WC_Product_Variation */

	/**
	 * __isset function.
	 *
	 * @param mixed $key
	 * @return bool
	 */
	public function __isset( $key ) {
		if ( in_array( $key, array( 'variation_data', 'variation_has_stock' ) ) ) {
			return true;
		} elseif ( in_array( $key, array_keys( $this->variation_level_meta_data ) ) ) {
			return metadata_exists( 'post', $this->variation_id, '_' . $key );
		} elseif ( in_array( $key, array_keys( $this->variation_inherited_meta_data ) ) ) {
			return metadata_exists( 'post', $this->variation_id, '_' . $key ) || metadata_exists( 'post', $this->id, '_' . $key );
		} else {
			return metadata_exists( 'post', $this->id, '_' . $key );
		}
	}

	/**
	 * Get method returns variation meta data if set, otherwise in most cases the data from the parent.
	 *
	 * We need to use the WC_Product_Variation's __get() method, not the one in WC_Product_Subscription_Variation,
	 * which handles deprecation notices.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function __get( $key ) {
		return WC_Product_Variation::__get( $key );
	}

	/**
	 * Provide a WC 3.0 method for variations.
	 *
	 * WC < 3.0 products have a get_parent() method, but this is not equivalent to the get_parent_id() method
	 * introduced in WC 3.0, because it derives the parent from $this->post->post_parent, but for variations,
	 * $this->post refers to the parent variable object's post, so $this->post->post_parent will be 0 under
	 * normal circumstances. Becuase of that, we can rely on wcs_get_objects_property( $this, 'parent_id' )
	 * and define this get_parent_id() method for variations even when WC 3.0 is not active.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function get_parent_id() {
		return $this->id; // When WC < 3.0 is active, the ID property is the parent variable product's ID
	}
}
