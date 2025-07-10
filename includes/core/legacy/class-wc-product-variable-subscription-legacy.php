<?php
/**
 * Variable Subscription Product Legacy Class
 *
 * Extends WC_Product_Variable_Subscription to provide compatibility methods when running WooCommerce < 3.0.
 *
 * @class WC_Product_Variable_Subscription_Legacy
 * @package WooCommerce Subscriptions
 * @category Class
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Variable_Subscription_Legacy extends WC_Product_Variable_Subscription {

	var $subscription_price;

	var $subscription_period;

	var $max_variation_period;

	var $subscription_period_interval;

	var $max_variation_period_interval;

	var $product_type;

	protected $prices_array;

	/**
	 * Create a simple subscription product object.
	 *
	 * @access public
	 * @param mixed $product
	 */
	public function __construct( $product ) {

		parent::__construct( $product );

		$this->parent_product_type = $this->product_type;

		$this->product_type = 'variable-subscription';

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

		$this->subscription_payment_sync_date = 0;
		$this->subscription_one_time_shipping = ( ! isset( $this->product_custom_fields['_subscription_one_time_shipping'][0] ) ) ? 'no' : $this->product_custom_fields['_subscription_one_time_shipping'][0];
		$this->subscription_limit             = ( ! isset( $this->product_custom_fields['_subscription_limit'][0] ) ) ? 'no' : $this->product_custom_fields['_subscription_limit'][0];
	}


	/**
	 * Get the min or max variation (active) price.
	 *
	 * This is a copy of WooCommerce < 2.4's get_variation_price() method, because 2.4.0 introduced a new
	 * transient caching system which assumes asort() on prices yields correct results for min/max prices
	 * (which it does for prices alone, but that's not the full story for subscription prices). Unfortunately,
	 * the new caching system is also hard to hook into so we'll just use the old system instead as the
	 * @see self::variable_product_sync() uses the old method also.
	 *
	 * @param  string $min_or_max - min or max
	 * @param  boolean  $display Whether the value is going to be displayed
	 * @return string
	 */
	public function get_variation_price( $min_or_max = 'min', $display = false ) {
		$variation_id = $this->get_meta( '_' . $min_or_max . '_price_variation_id', true );

		if ( $display ) {
			if ( $variation = wc_get_product( $variation_id ) ) {
				if ( 'incl' == get_option( 'woocommerce_tax_display_shop' ) ) {
					$price = wcs_get_price_including_tax( $variation );
				} else {
					$price = wcs_get_price_excluding_tax( $variation );
				}
			} else {
				$price = '';
			}
		} else {
			$price = $this->get_meta( '_price', true );
		}

		return apply_filters( 'woocommerce_get_variation_price', $price, $this, $min_or_max, $display );
	}

	/**
	 * Get an array of all sale and regular prices from all variations re-ordered after WC has done a standard sort, to reflect subscription terms.
	 * The first and last element for each price type is the least and most expensive, respectively.
	 *
	 * @see WC_Product_Variable::get_variation_prices()
	 * @param  bool $include_taxes Should taxes be included in the prices.
	 * @return array() Array of RAW prices, regular prices, and sale prices with keys set to variation ID.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function get_variation_prices( $display = false ) {

		$price_hash = $this->get_price_hash( $this, $display );

		$this->prices_array[ $price_hash ] = parent::get_variation_prices( $display );

		$children = array_keys( $this->prices_array[ $price_hash ]['price'] );
		sort( $children );

		$min_max_data = $this->get_min_and_max_variation_data( $children );

		$min_variation_id = $min_max_data['min']['variation_id'];
		$max_variation_id = $min_max_data['max']['variation_id'];

		// Reorder the variable price arrays to reflect the min and max values so that WooCommerce will find them in the correct order
		foreach ( $this->prices_array as $price_hash => $prices ) {

			// Loop over sale_price, regular_price & price values to update them on main array
			foreach ( $prices as $price_key => $variation_prices ) {

				$min_price = $prices[ $price_key ][ $min_variation_id ];
				$max_price = $prices[ $price_key ][ $max_variation_id ];

				unset( $prices[ $price_key ][ $min_variation_id ] );
				unset( $prices[ $price_key ][ $max_variation_id ] );

				// append the minimum variation and prepend the maximum variation
				$prices[ $price_key ]  = array( $min_variation_id => $min_price ) + $prices[ $price_key ];
				$prices[ $price_key ] += array( $max_variation_id => $max_price );

				$this->prices_array[ $price_hash ][ $price_key ] = $prices[ $price_key ];
			}
		}

		$this->subscription_price            = $min_max_data['min']['price'];
		$this->subscription_period           = $min_max_data['min']['period'];
		$this->subscription_period_interval  = $min_max_data['min']['interval'];

		$this->max_variation_price           = $min_max_data['max']['price'];
		$this->max_variation_period          = $min_max_data['max']['period'];
		$this->max_variation_period_interval = $min_max_data['max']['interval'];

		$this->min_variation_price           = $min_max_data['min']['price'];
		$this->min_variation_regular_price   = $min_max_data['min']['regular_price'];

		return $this->prices_array[ $price_hash ];
	}

	/**
	 * Create unique cache key based on the tax location (affects displayed/cached prices), product version and active price filters.
	 * DEVELOPERS should filter this hash if offering conditional pricing to keep it unique.
	 *
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @param  WC_Product
	 * @param  bool $display Are prices for display? If so, taxes will be calculated.
	 * @return string
	 */
	protected function get_price_hash( $display = false ) {
		global $wp_filter;

		if ( $display ) {
			$price_hash = array( get_option( 'woocommerce_tax_display_shop', 'excl' ), WC_Tax::get_rates() );
		} else {
			$price_hash = array( false );
		}

		$filter_names = array( 'woocommerce_variation_prices_price', 'woocommerce_variation_prices_regular_price', 'woocommerce_variation_prices_sale_price' );

		foreach ( $filter_names as $filter_name ) {
			if ( ! empty( $wp_filter[ $filter_name ] ) ) {
				$price_hash[ $filter_name ] = array();

				foreach ( $wp_filter[ $filter_name ] as $priority => $callbacks ) {
					$price_hash[ $filter_name ][] = array_values( wp_list_pluck( $callbacks, 'function' ) );
				}
			}
		}

		$price_hash = md5( json_encode( apply_filters( 'woocommerce_get_variation_prices_hash', $price_hash, $this, $display ) ) );

		return $price_hash;
	}

	/**
	 * Sync variable product prices with the children lowest/highest prices.
	 *
	 * @param int $product_id The ID of the product.
	 * @return void
	 */
	public function variable_product_sync( $product_id = 0 ) {

		WC_Product_Variable::variable_product_sync( $product_id );

		$child_variation_ids = $this->get_children( true );

		if ( $child_variation_ids ) {

			$min_max_data = wcs_get_min_max_variation_data( $this, $child_variation_ids );

			$this->set_min_and_max_variation_data( $min_max_data, $child_variation_ids );

			update_post_meta( $this->id, '_min_price_variation_id', $min_max_data['min']['variation_id'] );
			update_post_meta( $this->id, '_max_price_variation_id', $min_max_data['max']['variation_id'] );

			update_post_meta( $this->id, '_price', $min_max_data['min']['price'] );
			update_post_meta( $this->id, '_min_variation_price', $min_max_data['min']['price'] );
			update_post_meta( $this->id, '_max_variation_price', $min_max_data['max']['price'] );
			update_post_meta( $this->id, '_min_variation_regular_price', $min_max_data['min']['regular_price'] );
			update_post_meta( $this->id, '_max_variation_regular_price', $min_max_data['max']['regular_price'] );
			update_post_meta( $this->id, '_min_variation_sale_price', $min_max_data['min']['sale_price'] );
			update_post_meta( $this->id, '_max_variation_sale_price', $min_max_data['max']['sale_price'] );

			update_post_meta( $this->id, '_min_variation_period', $min_max_data['min']['period'] );
			update_post_meta( $this->id, '_max_variation_period', $min_max_data['max']['period'] );
			update_post_meta( $this->id, '_min_variation_period_interval', $min_max_data['min']['interval'] );
			update_post_meta( $this->id, '_max_variation_period_interval', $min_max_data['max']['interval'] );

			update_post_meta( $this->id, '_subscription_price', $min_max_data['min']['price'] );
			update_post_meta( $this->id, '_subscription_sign_up_fee', $min_max_data['subscription']['signup-fee'] );
			update_post_meta( $this->id, '_subscription_period', $min_max_data['min']['period'] );
			update_post_meta( $this->id, '_subscription_period_interval', $min_max_data['min']['interval'] );
			update_post_meta( $this->id, '_subscription_trial_period', $min_max_data['subscription']['trial_period'] );
			update_post_meta( $this->id, '_subscription_trial_length', $min_max_data['subscription']['trial_length'] );
			update_post_meta( $this->id, '_subscription_length', $min_max_data['subscription']['length'] );

			$this->subscription_price           = $min_max_data['min']['price'];
			$this->subscription_period          = $min_max_data['min']['period'];
			$this->subscription_period_interval = $min_max_data['min']['interval'];
			$this->subscription_sign_up_fee     = $min_max_data['subscription']['signup-fee'];
			$this->subscription_trial_period    = $min_max_data['subscription']['trial_period'];
			$this->subscription_trial_length    = $min_max_data['subscription']['trial_length'];
			$this->subscription_length          = $min_max_data['subscription']['length'];

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $this->id );
			} else {
				WC()->clear_product_transients( $this->id );
			}
		} else { // No variations yet

			$this->subscription_price           = '';
			$this->subscription_sign_up_fee     = '';
			$this->subscription_period          = 'day';
			$this->subscription_period_interval = 1;
			$this->subscription_trial_period    = 'day';
			$this->subscription_trial_length    = 1;
			$this->subscription_length          = 0;

		}
	}

	/**
	 * Returns the price in html format.
	 *
	 * @access public
	 * @param string $price (default: '')
	 * @return string
	 */
	public function get_price_html( $price = '' ) {

		if ( ! isset( $this->subscription_period ) || ! isset( $this->subscription_period_interval ) || ! isset( $this->max_variation_period ) || ! isset( $this->max_variation_period_interval ) ) {
			$this->variable_product_sync();
		}

		// Only create the subscription price string when a price has been set
		if ( $this->subscription_price !== '' ) {

			$price = '';

			if ( $this->is_on_sale() && isset( $this->min_variation_price ) && $this->min_variation_regular_price !== $this->get_price() ) {

				if ( ! $this->min_variation_price || $this->min_variation_price !== $this->max_variation_price ) {
					$price .= wcs_get_price_html_from_text( $this );
				}

				$variation_id     = $this->get_meta( '_min_price_variation_id', true );
				$variation        = wc_get_product( $variation_id );
				$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );

				$sale_price_args    = array(
					'qty'   => 1,
					'price' => $variation->get_sale_price(),
				);
				$regular_price_args = array(
					'qty'   => 1,
					'price' => $variation->get_regular_price(),
				);

				if ( 'incl' == $tax_display_mode ) {
					$sale_price    = wcs_get_price_including_tax( $variation, $sale_price_args );
					$regular_price = wcs_get_price_including_tax( $variation, $regular_price_args );
				} else {
					$sale_price    = wcs_get_price_excluding_tax( $variation, $sale_price_args );
					$regular_price = wcs_get_price_excluding_tax( $variation, $regular_price_args );
				}

				$price .= $this->get_price_html_from_to( $regular_price, $sale_price );

			} else {

				if ( $this->min_variation_price !== $this->max_variation_price ) {
					$price .= wcs_get_price_html_from_text( $this );
				}

				$price .= wc_price( $this->get_variation_price( 'min', true ) );

			}

			// Make sure the price contains "From:" when billing schedule differs between variations
			if ( false === strpos( $price, wcs_get_price_html_from_text( $this ) ) ) {
				if ( $this->subscription_period !== $this->max_variation_period ) {
					$price = wcs_get_price_html_from_text( $this ) . $price;
				} elseif ( $this->subscription_period_interval !== $this->max_variation_period_interval ) {
					$price = wcs_get_price_html_from_text( $this ) . $price;
				}
			}

			$price .= $this->get_price_suffix();

			$price = WC_Subscriptions_Product::get_price_string( $this, array( 'price' => $price ) );
		}

		return apply_filters( 'woocommerce_variable_subscription_price_html', $price, $this );
	}

	/**
	 * Provide the WC_Data::get_meta() function when WC < 3.0 is active.
	 *
	 * @param string $meta_key
	 * @param bool $single
	 * @param string $context
	 * @return object WC_Product_Subscription or WC_Product_Subscription_Variation
	 */
	function get_meta( $meta_key = '', $single = true, $context = 'view' ) {
		return $this->get_meta( $meta_key, $single );
	}

	/**
	 * get_child function.
	 *
	 * @access public
	 * @param mixed $child_id
	 * @return object WC_Product_Subscription or WC_Product_Subscription_Variation
	 */
	public function get_child( $child_id ) {
		return wc_get_product( $child_id, array(
			'product_type' => 'Subscription_Variation',
			'parent_id'    => $this->id,
			'parent'       => $this,
		) );
	}

	/**
	 * Get default attributes.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 * @param  string $context
	 * @return array
	 */
	public function get_default_attributes( $context = 'view' ) {
		return $this->get_variation_default_attributes();
	}

	/**
	 * Set the product's min and max variation data.
	 *
	 * @param array $min_and_max_data The min and max variation data returned by @see wcs_get_min_max_variation_data(). Optional.
	 * @param array $variation_ids The visible child variation IDs. Optional. By default this value be generated by @see WC_Product_Variable->get_children( true ).
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public function set_min_and_max_variation_data( $min_and_max_data = array(), $variation_ids = array() ) {

		if ( empty( $variation_ids ) ) {
			$variation_ids = $this->get_children( true );
		}

		if ( empty( $min_and_max_data ) ) {
			$min_and_max_data = wcs_get_min_max_variation_data( $this, $variation_ids );
		}

		update_post_meta( $this->id, '_min_max_variation_data', $min_and_max_data, true );
		update_post_meta( $this->id, '_min_max_variation_ids_hash', $this->get_variation_ids_hash( $variation_ids ), true );
	}

	/**
	 * Get the min and max variation data.
	 *
	 * This is a wrapper for @see wcs_get_min_max_variation_data() but to avoid calling
	 * that resource intensive function multiple times per request, check the value
	 * stored in meta or cached in memory before calling that function.
	 *
	 * @param  array $variation_ids An array of variation IDs.
	 * @return array The variable product's min and max variation data.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public function get_min_and_max_variation_data( $variation_ids ) {
		$variation_ids_hash = $this->get_variation_ids_hash( $variation_ids );

		// If this variable product has no min and max variation data, set it.
		if ( ! metadata_exists( 'post', $this->id, '_min_max_variation_ids_hash' ) ) {
			$this->set_min_and_max_variation_data();
		}

		if ( $variation_ids_hash === $this->get_meta( '_min_max_variation_ids_hash', true ) ) {
			$min_and_max_variation_data = $this->get_meta( '_min_max_variation_data', true );
		} elseif ( ! empty( $this->min_max_variation_data[ $variation_ids_hash ] ) ) {
			$min_and_max_variation_data = $this->min_max_variation_data[ $variation_ids_hash ];
		} else {
			$min_and_max_variation_data = wcs_get_min_max_variation_data( $this, $variation_ids );
			$this->min_max_variation_data[ $variation_ids_hash ] = $min_and_max_variation_data;
		}

		return $min_and_max_variation_data;
	}
}
