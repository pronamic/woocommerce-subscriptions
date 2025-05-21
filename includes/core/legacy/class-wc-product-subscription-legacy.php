<?php
/**
 * Simple Subscription Product Legacy Class
 *
 * Extends WC_Product_Subscription to provide compatibility methods when running WooCommerce < 3.0.
 *
 * @class WC_Product_Subscription_Legacy
 * @package WooCommerce Subscriptions
 * @category Class
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Subscription_Legacy extends WC_Product_Subscription {

	var $subscription_price;

	var $subscription_period;

	var $subscription_period_interval;

	var $subscription_length;

	var $subscription_trial_length;

	var $subscription_trial_period;

	var $subscription_sign_up_fee;

	/**
	 * Create a simple subscription product object.
	 *
	 * @access public
	 * @param mixed $product
	 */
	public function __construct( $product ) {
		parent::__construct( $product );
		$this->product_type = 'subscription';

		// Load all meta fields
		$this->product_custom_fields = get_post_meta( $this->id );

		// Convert selected subscription meta fields for easy access
		if ( ! empty( $this->product_custom_fields['_subscription_price'][0] ) ) {
			$this->subscription_price = $this->product_custom_fields['_subscription_price'][0];
		}

		if ( ! empty( $this->product_custom_fields['_subscription_sign_up_fee'][0] ) ) {
			$this->subscription_sign_up_fee = $this->product_custom_fields['_subscription_sign_up_fee'][0];
		}

		if ( ! empty( $this->product_custom_fields['_subscription_period'][0] ) ) {
			$this->subscription_period = $this->product_custom_fields['_subscription_period'][0];
		}

		if ( ! empty( $this->product_custom_fields['_subscription_period_interval'][0] ) ) {
			$this->subscription_period_interval = $this->product_custom_fields['_subscription_period_interval'][0];
		}

		if ( ! empty( $this->product_custom_fields['_subscription_length'][0] ) ) {
			$this->subscription_length = $this->product_custom_fields['_subscription_length'][0];
		}

		if ( ! empty( $this->product_custom_fields['_subscription_trial_length'][0] ) ) {
			$this->subscription_trial_length = $this->product_custom_fields['_subscription_trial_length'][0];
		}

		if ( ! empty( $this->product_custom_fields['_subscription_trial_period'][0] ) ) {
			$this->subscription_trial_period = $this->product_custom_fields['_subscription_trial_period'][0];
		}

		$this->subscription_payment_sync_date = ( ! isset( $this->product_custom_fields['_subscription_payment_sync_date'][0] ) ) ? 0 : maybe_unserialize( $this->product_custom_fields['_subscription_payment_sync_date'][0] );
		$this->subscription_one_time_shipping = ( ! isset( $this->product_custom_fields['_subscription_one_time_shipping'][0] ) ) ? 'no' : $this->product_custom_fields['_subscription_one_time_shipping'][0];
		$this->subscription_limit             = ( ! isset( $this->product_custom_fields['_subscription_limit'][0] ) ) ? 'no' : $this->product_custom_fields['_subscription_limit'][0];
	}
}
