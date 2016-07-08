<?php
/**
 * Variable Subscription Product Class
 *
 * This class extends the WC Variable product class to create variable products with recurring payments.
 *
 * @class 		WC_Product_Variable_Subscription
 * @package		WooCommerce Subscriptions
 * @category	Class
 * @since		1.3
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Product_Variable_Subscription extends WC_Product_Variable {

	var $subscription_price;

	var $subscription_period;

	var $max_variation_period;

	var $subscription_period_interval;

	var $max_variation_period_interval;

	var $product_type;

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

		if ( ! isset( $this->product_custom_fields['_subscription_limit'][0] ) ) {
			$this->limit_subscriptions = 'no';
		} elseif ( 'yes' == $this->product_custom_fields['_subscription_limit'][0] ) { // backward compatibility
			$this->limit_subscriptions = 'any';
		} else {
			$this->limit_subscriptions = $this->product_custom_fields['_subscription_limit'][0];
		}

		add_filter( 'woocommerce_add_to_cart_handler', array( &$this, 'add_to_cart_handler' ), 10, 2 );
	}

	/**
	 * Get the add to cart button text for the single page
	 *
	 * @access public
	 * @return string
	 */
	public function single_add_to_cart_text() {

		if ( $this->is_purchasable() && $this->is_in_stock() ) {
			$text = get_option( WC_Subscriptions_Admin::$option_prefix . '_add_to_cart_button_text', __( 'Sign Up Now', 'woocommerce-subscriptions' ) );
		} else {
			$text = parent::add_to_cart_text(); // translated "Read More"
		}

		return apply_filters( 'woocommerce_product_single_add_to_cart_text', $text, $this );
	}

	/**
	 * Sync variable product prices with the childs lowest/highest prices.
	 *
	 * @access public
	 * @return void
	 */
	public function variable_product_sync( $product_id = '' ) {

		parent::variable_product_sync();

		$children = get_posts( array(
			'post_parent'    => $this->id,
			'posts_per_page' => -1,
			'post_type'      => 'product_variation',
			'fields'         => 'ids',
			'post_status'    => 'publish',
		) );

		$lowest_initial_amount             = $highest_initial_amount = $lowest_price = $highest_price = '';
		$shortest_initial_period           = $longest_initial_period = $shortest_trial_period = $longest_trial_period = $shortest_trial_length = $longest_trial_length = '';
		$longest_initial_interval          = $shortest_initial_interval = $variable_subscription_period = $variable_subscription_period_interval = '';
		$lowest_regular_price              = $highest_regular_price = $lowest_sale_price = $highest_sale_price = $max_subscription_period = $max_subscription_period_interval = '';
		$variable_subscription_sign_up_fee = $variable_subscription_trial_period = $variable_subscription_trial_length = $variable_subscription_length = $variable_subscription_sign_up_fee = $variable_subscription_trial_period = $variable_subscription_trial_length = $variable_subscription_length = '';
		$min_variation_id                  = $max_variation_id = null;

		if ( $children ) {

			foreach ( $children as $child ) {

				$is_max = $is_min = false;

				// WC has already determined the correct price which accounts for sale price
				$child_price = get_post_meta( $child, '_price', true );

				$child_billing_period    = get_post_meta( $child, '_subscription_period', true );
				$child_billing_interval  = get_post_meta( $child, '_subscription_period_interval', true );
				$child_sign_up_fee       = get_post_meta( $child, '_subscription_sign_up_fee', true );
				$child_free_trial_length = get_post_meta( $child, '_subscription_trial_length', true );
				$child_free_trial_period = get_post_meta( $child, '_subscription_trial_period', true );

				if ( '' === $child_price && '' === $child_sign_up_fee ) {
					continue;
				}

				$child_price       = ( '' === $child_price ) ? 0 : $child_price;
				$child_sign_up_fee = ( '' === $child_sign_up_fee ) ? 0 : $child_sign_up_fee;

				$has_free_trial = ( '' !== $child_free_trial_length && $child_free_trial_length > 0 ) ? true : false;

				// Determine some recurring price flags
				$is_lowest_price     = ( $child_price < $lowest_price || '' === $lowest_price ) ? true : false;
				$is_longest_period   = ( WC_Subscriptions::get_longest_period( $variable_subscription_period, $child_billing_period ) === $child_billing_period ) ? true : false;
				$is_longest_interval = ( $child_billing_interval >= $variable_subscription_period_interval || '' === $variable_subscription_period_interval ) ? true : false;

				// Find the amount the subscriber will have to pay up-front
				if ( $has_free_trial ) {
					$initial_amount   = $child_sign_up_fee;
					$initial_period   = $child_free_trial_period;
					$initial_interval = $child_free_trial_length;
				} else {
					$initial_amount   = $child_price + $child_sign_up_fee;
					$initial_period   = $child_billing_period;
					$initial_interval = $child_billing_interval;
				}

				// We have a free trial & no sign-up fee, so need to choose the longest free trial (and maybe the shortest)
				if ( $has_free_trial && 0 == $child_sign_up_fee ) {

					// First variation
					if ( '' === $longest_trial_period ) {

						$is_min = true;

					// If two variations have the same free trial, choose the variation with the lowest recurring price for the longest period
					} elseif ( $variable_subscription_trial_period === $child_free_trial_period && $child_free_trial_length === $variable_subscription_trial_length ) {

						// If the variation has the lowest recurring price, it's the cheapest
						if ( $is_lowest_price ) {

							$is_min = true;

						// When current variation's free trial is the same as the lowest, it's the cheaper if it has a longer billing schedule
						} elseif ( $child_price === $lowest_price ) {

							if ( $is_longest_period && $is_longest_interval ) {

								$is_min = true;

							// Longest with a new billing period
							} elseif ( $is_longest_period && $child_billing_period !== $variable_subscription_trial_period ) {

								$is_min = true;

							}
						}

					// Otherwise the cheapest variation is the one with the longer trial
					} elseif ( $variable_subscription_trial_period === $child_free_trial_period ) {

						$is_min = ( $child_free_trial_length > $variable_subscription_trial_length ) ? true : false;

					// Otherwise just a longer trial period (that isn't equal to the longest period)
					} elseif ( WC_Subscriptions::get_longest_period( $longest_trial_period, $child_free_trial_period ) === $child_free_trial_period ) {

						$is_min = true;

					}

					if ( $is_min ) {
						$longest_trial_period = $child_free_trial_period;
						$longest_trial_length = $child_free_trial_length;
					}

					// If the current cheapest variation is also free then the shortest trial period is the most expensive
					if ( 0 == $lowest_price || '' === $lowest_price ) {

						if ( '' === $shortest_trial_period ) {

							$is_max = true;

						// Need to check trial length
						} elseif ( $shortest_trial_period === $child_free_trial_period ) {

							$is_max = ( $child_free_trial_length < $shortest_trial_length ) ? true : false;

						// Need to find shortest period
						} elseif ( WC_Subscriptions::get_shortest_period( $shortest_trial_period, $child_free_trial_period ) === $child_free_trial_period ) {

							$is_max = true;

						}

						if ( $is_max ) {
							$shortest_trial_period = $child_free_trial_period;
							$shortest_trial_length = $child_free_trial_length;
						}
					}
				} else {

					$longest_initial_period  = WC_Subscriptions::get_longest_period( $longest_initial_period, $initial_period );
					$shortest_initial_period = WC_Subscriptions::get_shortest_period( $shortest_initial_period, $initial_period );

					$is_lowest_initial_amount    = ( $initial_amount < $lowest_initial_amount || '' === $lowest_initial_amount ) ? true : false;
					$is_longest_initial_period   = ( $initial_period === $longest_initial_period ) ? true : false;
					$is_longest_initial_interval = ( $initial_interval >= $longest_initial_interval || '' === $longest_initial_interval ) ? true : false;

					$is_highest_initial   = ( $initial_amount > $highest_initial_amount || '' === $highest_initial_amount ) ? true : false;
					$is_shortest_period   = ( $initial_period === $shortest_initial_period || '' === $shortest_initial_period ) ? true : false;
					$is_shortest_interval = ( $initial_interval < $shortest_initial_interval || '' === $shortest_initial_interval ) ? true : false;

					// If we're not dealing with the lowest initial access amount, then ignore this variation
					if ( ! $is_lowest_initial_amount && $initial_amount !== $lowest_initial_amount ) {
						continue;
					}

					// If the variation has the lowest price, it's the cheapest
					if ( $is_lowest_initial_amount ) {

						$is_min = true;

					// When current variation's price is the same as the lowest, it's the cheapest only if it has a longer billing schedule
					} elseif ( $initial_amount === $lowest_initial_amount ) {

						// We need to check the recurring schedule when the sign-up fee & free trial periods are equal
						if ( $has_free_trial && $initial_period == $longest_initial_period && $initial_interval == $longest_initial_interval ) {

							// If the variation has the lowest recurring price, it's the cheapest
							if ( $is_lowest_price ) {

								$is_min = true;

							// When current variation's price is the same as the lowest, it's the cheapest only if it has a longer billing schedule
							} elseif ( $child_price === $lowest_price ) {

								if ( $is_longest_period && $is_longest_interval ) {

									$is_min = true;

								// Longest with a new billing period
								} elseif ( $is_longest_period && $child_billing_period !== $variable_subscription_period ) {

									$is_min = true;

								}
							}

						// Longest initial term is the cheapest
						} elseif ( $is_longest_initial_period && $is_longest_initial_interval ) {

							$is_min = true;

						// Longest with a new billing period
						} elseif ( $is_longest_initial_period && $initial_period !== $variable_subscription_period ) {

							$is_min = true;

						}
					}

					// If we have the highest price for the shortest period, we might have the maximum variation
					if ( $is_highest_initial && $is_shortest_period && $is_shortest_interval ) {

						$is_max = true;

					// But only if its for the shortest billing period
					} elseif ( $child_price === $highest_price ) {

						if ( $is_shortest_period && $is_shortest_interval ) {
							$is_max = true;
						} elseif ( $is_shortest_period ) {
							$is_max = true;
						}
					}
				}

				// If it's the min subscription terms
				if ( $is_min ) {

					$min_variation_id      = $child;

					$lowest_price          = $child_price;
					$lowest_regular_price  = get_post_meta( $child, '_regular_price', true );
					$lowest_sale_price     = get_post_meta( $child, '_sale_price', true );

					$lowest_regular_price = ( '' === $lowest_regular_price ) ? 0 : $lowest_regular_price;
					$lowest_sale_price    = ( '' === $lowest_sale_price ) ? 0 : $lowest_sale_price;

					$lowest_initial_amount    = $initial_amount;
					$longest_initial_period   = $initial_period;
					$longest_initial_interval = $initial_interval;

					$variable_subscription_sign_up_fee     = $child_sign_up_fee;
					$variable_subscription_period          = $child_billing_period;
					$variable_subscription_period_interval = $child_billing_interval;
					$variable_subscription_trial_length    = $child_free_trial_length;
					$variable_subscription_trial_period    = $child_free_trial_period;
					$variable_subscription_length          = get_post_meta( $child, '_subscription_length', true );
				}

				if ( $is_max ) {

					$max_variation_id       = $child;

					$highest_price          = $child_price;
					$highest_regular_price  = get_post_meta( $child, '_regular_price', true );
					$highest_sale_price     = get_post_meta( $child, '_sale_price', true );
					$highest_initial_amount = $initial_amount;

					$highest_regular_price = ( '' === $highest_regular_price ) ? 0 : $highest_regular_price;
					$highest_sale_price    = ( '' === $highest_sale_price ) ? 0 : $highest_sale_price;

					$max_subscription_period          = $child_billing_period;
					$max_subscription_period_interval = $child_billing_interval;
				}
			}

			update_post_meta( $this->id, '_min_price_variation_id', $min_variation_id );
			update_post_meta( $this->id, '_max_price_variation_id', $max_variation_id );

			update_post_meta( $this->id, '_price', $lowest_price );
			update_post_meta( $this->id, '_min_variation_price', $lowest_price );
			update_post_meta( $this->id, '_max_variation_price', $highest_price );
			update_post_meta( $this->id, '_min_variation_regular_price', $lowest_regular_price );
			update_post_meta( $this->id, '_max_variation_regular_price', $highest_regular_price );
			update_post_meta( $this->id, '_min_variation_sale_price', $lowest_sale_price );
			update_post_meta( $this->id, '_max_variation_sale_price', $highest_sale_price );

			update_post_meta( $this->id, '_min_variation_period', $variable_subscription_period );
			update_post_meta( $this->id, '_max_variation_period', $variable_subscription_period_interval );
			update_post_meta( $this->id, '_min_variation_period_interval', $max_subscription_period );
			update_post_meta( $this->id, '_max_variation_period_interval', $max_subscription_period_interval );

			update_post_meta( $this->id, '_subscription_price', $lowest_price );
			update_post_meta( $this->id, '_subscription_sign_up_fee', $variable_subscription_sign_up_fee );
			update_post_meta( $this->id, '_subscription_period', $variable_subscription_period );
			update_post_meta( $this->id, '_subscription_period_interval', $variable_subscription_period_interval );
			update_post_meta( $this->id, '_subscription_trial_period', $variable_subscription_trial_period );
			update_post_meta( $this->id, '_subscription_trial_length', $variable_subscription_trial_length );
			update_post_meta( $this->id, '_subscription_length', $variable_subscription_length );

			$this->subscription_price           = $lowest_price;
			$this->subscription_sign_up_fee     = $variable_subscription_sign_up_fee;
			$this->subscription_period          = $variable_subscription_period;
			$this->subscription_period_interval = $variable_subscription_period_interval;
			$this->subscription_trial_period    = $variable_subscription_trial_period;
			$this->subscription_trial_length    = $variable_subscription_trial_length;
			$this->subscription_length          = $variable_subscription_length;

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

		$price = parent::get_price_html( $price );

		if ( ! isset( $this->subscription_period ) || ! isset( $this->subscription_period_interval ) || ! isset( $this->max_variation_period ) || ! isset( $this->max_variation_period_interval ) ) {
			$this->variable_product_sync();
		}

		// Only create the subscription price string when a price has been set
		if ( $this->subscription_price !== '' || $this->subscription_sign_up_fee !== '' ) {

			$price = '';

			if ( $this->is_on_sale() && isset( $this->min_variation_price ) && $this->min_variation_regular_price !== $this->get_price() ) {

				if ( ! $this->min_variation_price || $this->min_variation_price !== $this->max_variation_price ) {
					$price .= $this->get_price_html_from_text();
				}

				$variation_id     = get_post_meta( $this->id, '_min_price_variation_id', true );
				$variation        = $this->get_child( $variation_id );
				$tax_display_mode = get_option( 'woocommerce_tax_display_shop' );

				$sale_price       = 'incl' == $tax_display_mode ? $variation->get_price_including_tax( 1, $variation->get_sale_price() ) : $variation->get_price_excluding_tax( 1, $variation->get_sale_price() );
				$regular_price    = 'incl' == $tax_display_mode ? $variation->get_price_including_tax( 1, $variation->get_regular_price() ) : $variation->get_price_excluding_tax( 1, $variation->get_regular_price() );

				$price .= $this->get_price_html_from_to( $regular_price, $sale_price );

			} else {

				if ( $this->min_variation_price !== $this->max_variation_price ) {
					$price .= $this->get_price_html_from_text();
				}

				$price .= wc_price( $this->get_variation_price( 'min', true ) );

			}

			// Make sure the price contains "From:" when billing schedule differs between variations
			if ( false === strpos( $price, $this->get_price_html_from_text() ) ) {
				if ( $this->subscription_period !== $this->max_variation_period ) {
					$price = $this->get_price_html_from_text() . $price;
				} elseif ( $this->subscription_period_interval !== $this->max_variation_period_interval ) {
					$price = $this->get_price_html_from_text() . $price;
				}
			}

			$price .= $this->get_price_suffix();

			$price = WC_Subscriptions_Product::get_price_string( $this, array( 'price' => $price ) );
		}

		return apply_filters( 'woocommerce_variable_subscription_price_html', $price, $this );
	}

	/**
	 * Returns the sign up fee (including tax) by filtering the products price used in
	 * @see WC_Product::get_price_including_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_including_tax( $qty = 1 ) {

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_including_tax = parent::get_price_including_tax( $qty );

		remove_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		return $sign_up_fee_including_tax;
	}

	/**
	 * Returns the sign up fee (excluding tax) by filtering the products price used in
	 * @see WC_Product::get_price_excluding_tax( $qty )
	 *
	 * @return string
	 */
	public function get_sign_up_fee_excluding_tax( $qty = 1 ) {

		add_filter( 'woocommerce_get_price', array( &$this, 'get_sign_up_fee' ), 100, 0 );

		$sign_up_fee_excluding_tax = parent::get_price_excluding_tax( $qty );

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
	 *
	 * @param string $product_type A string representation of a product type
	 */
	public function add_to_cart_handler( $handler, $product ) {

		if ( 'variable-subscription' === $handler ) {
			$handler = 'variable';
		}

		return $handler;
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

		$purchasable = parent::is_purchasable();

		if ( true === $purchasable && false === WC_Subscriptions_Product::is_purchasable( $purchasable, $this ) ) {
			$purchasable = false;
		}

		return apply_filters( 'woocommerce_subscription_is_purchasable', $purchasable, $this );
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
