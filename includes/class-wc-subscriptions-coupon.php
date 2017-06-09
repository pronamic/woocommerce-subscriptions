<?php
/**
 * Subscriptions Coupon Class
 *
 * Mirrors a few functions in the WC_Cart class to handle subscription-specific discounts
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Coupon
 * @category	Class
 * @author		Max Rice
 * @since		1.2
 */
class WC_Subscriptions_Coupon {

	/** @var string error message for invalid subscription coupons */
	public static $coupon_error;

	/**
	 * Stores the coupons not applied to a given calculation (so they can be applied later)
	 *
	 * @since 1.3.5
	 */
	private static $removed_coupons = array();

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.2
	 **/
	public static function init() {

		// Add custom coupon types
		add_filter( 'woocommerce_coupon_discount_types', __CLASS__ . '::add_discount_types' );

		// Handle discounts
		add_filter( 'woocommerce_coupon_get_discount_amount', __CLASS__ . '::get_discount_amount', 10, 5 );

		// Validate subscription coupons
		add_filter( 'woocommerce_coupon_is_valid', __CLASS__ . '::validate_subscription_coupon', 10, 2 );

		// Remove coupons which don't apply to certain cart calculations
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::remove_coupons', 10 );

		// Add our recurring product coupon types to the list of coupon types that apply to individual products
		add_filter( 'woocommerce_product_coupon_types', __CLASS__ . '::filter_product_coupon_types', 10, 1 );

		if ( ! is_admin() ) {
			// WC 3.0 only sets a coupon type if it is a pre-defined supported type, so we need to temporarily add our pseudo types. We don't want to add these on admin pages.
			add_filter( 'woocommerce_coupon_discount_types', __CLASS__ . '::add_pseudo_coupon_types' );
		}
	}

	/**
	 * Add discount types
	 *
	 * @since 1.2
	 */
	public static function add_discount_types( $discount_types ) {

		return array_merge(
			$discount_types,
			array(
				'sign_up_fee'         => __( 'Sign Up Fee Discount', 'woocommerce-subscriptions' ),
				'sign_up_fee_percent' => __( 'Sign Up Fee % Discount', 'woocommerce-subscriptions' ),
				'recurring_fee'       => __( 'Recurring Product Discount', 'woocommerce-subscriptions' ),
				'recurring_percent'   => __( 'Recurring Product % Discount', 'woocommerce-subscriptions' ),
			)
		);
	}

	/**
	 * Get the discount amount for Subscriptions coupon types
	 *
	 * @since 2.0.10
	 */
	public static function get_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {

		$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );

		// Only deal with subscriptions coupon types
		if ( ! in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {
			return $discount;
		}

		// If not a subscription product return the default discount
		if ( ! wcs_cart_contains_renewal() && ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
			return $discount;
		}
		// But if cart contains a renewal, we need to handle both subscription products and manually added non-susbscription products that could be part of a subscription
		if ( wcs_cart_contains_renewal() && ! self::is_subsbcription_renewal_line_item( $cart_item['data'], $cart_item ) ) {
			return $discount;
		}

		// Set our starting discount amount to 0
		$discount_amount = 0;

		// Item quantity
		$cart_item_qty = is_null( $cart_item ) ? 1 : $cart_item['quantity'];

		// Get calculation type
		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		// Set the defaults for our logic checks to false
		$apply_recurring_coupon = $apply_recurring_percent_coupon = $apply_initial_coupon = $apply_initial_percent_coupon = $apply_renewal_cart_coupon = false;

		// Check if we're applying any recurring discounts to recurring total calculations
		if ( 'recurring_total' == $calculation_type ) {
			$apply_recurring_coupon         = ( 'recurring_fee' == $coupon_type ) ? true : false;
			$apply_recurring_percent_coupon = ( 'recurring_percent' == $coupon_type ) ? true : false;
		}

		// Check if we're applying any initial discounts
		if ( 'none' == $calculation_type ) {

			// If all items have a free trial we don't need to apply recurring coupons to the initial total
			if ( ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) {

				if ( 'recurring_fee' == $coupon_type ) {
					$apply_initial_coupon = true;
				}

				if ( 'recurring_percent' == $coupon_type ) {
					$apply_initial_percent_coupon = true;
				}
			}

			// Apply sign-up discounts
			if ( WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] ) > 0 ) {

				if ( 'sign_up_fee' == $coupon_type ) {
					$apply_initial_coupon = true;
				}

				if ( 'sign_up_fee_percent' == $coupon_type ) {
					$apply_initial_percent_coupon = true;
				}

				// Only Sign up fee coupons apply to sign up fees, adjust the discounting_amount accordingly
				if ( in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) ) {
					$discounting_amount = WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
				} else {
					$discounting_amount -= WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
				}
			}

			// Apply renewal discounts
			if ( 'renewal_fee' == $coupon_type ) {
				$apply_recurring_coupon = true;
			}
			if ( 'renewal_percent' == $coupon_type ) {
				$apply_recurring_percent_coupon = true;
			}
			if ( 'renewal_cart' == $coupon_type ) {
				$apply_renewal_cart_coupon = true;
			}
		}

		// Calculate our discount
		if ( $apply_recurring_coupon || $apply_initial_coupon ) {

			// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
			if ( $apply_initial_coupon && 'recurring_fee' == $coupon_type && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
				$discounting_amount = 0;
			}

			$discount_amount = min( wcs_get_coupon_property( $coupon, 'coupon_amount' ), $discounting_amount );
			$discount_amount = $single ? $discount_amount : $discount_amount * $cart_item_qty;

		} elseif ( $apply_recurring_percent_coupon ) {

			$discount_amount = ( $discounting_amount / 100 ) * wcs_get_coupon_property( $coupon, 'coupon_amount' );

		} elseif ( $apply_initial_percent_coupon ) {

			// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
			if ( 'recurring_percent' == $coupon_type && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
				$discounting_amount = 0;
			}

			$discount_amount = ( $discounting_amount / 100 ) * wcs_get_coupon_property( $coupon, 'coupon_amount' );

		} elseif ( $apply_renewal_cart_coupon ) {

			/**
			 * See WC Core fixed_cart coupons - we need to divide the discount between rows based on their price in proportion to the subtotal.
			 * This is so rows with different tax rates get a fair discount, and so rows with no price (free) don't get discounted.
			 *
			 * BUT... we also need the subtotal to exclude non renewal products, so user the renewal subtotal
			 */
			$discount_percent = ( $discounting_amount * $cart_item['quantity'] ) / self::get_renewal_subtotal( wcs_get_coupon_property( $coupon, 'code' ) );

			$discount_amount = ( wcs_get_coupon_property( $coupon, 'coupon_amount' ) * $discount_percent ) / $cart_item_qty;
		}

		// Round - consistent with WC approach
		$discount_amount = round( $discount_amount, wcs_get_rounding_precision() );

		return $discount_amount;
	}

	/**
	 * Determine if the cart contains a discount code of a given coupon type.
	 *
	 * Used internally for checking if a WooCommerce discount coupon ('core') has been applied, or for if a specific
	 * subscription coupon type, like 'recurring_fee' or 'sign_up_fee', has been applied.
	 *
	 * @param string $coupon_type Any available coupon type or a special keyword referring to a class of coupons. Can be:
	 *  - 'any' to check for any type of discount
	 *  - 'core' for any core WooCommerce coupon
	 *  - 'recurring_fee' for the recurring amount subscription coupon
	 *  - 'sign_up_fee' for the sign-up fee subscription coupon
	 *
	 * @since 1.3.5
	 */
	public static function cart_contains_discount( $coupon_type = 'any' ) {

		$contains_discount = false;
		$core_coupons = array( 'fixed_product', 'percent_product', 'fixed_cart', 'percent' );

		if ( WC()->cart->applied_coupons ) {

			foreach ( WC()->cart->applied_coupons as $code ) {

				$coupon           = new WC_Coupon( $code );
				$cart_coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );

				if ( 'any' == $coupon_type || $coupon_type == $cart_coupon_type || ( 'core' == $coupon_type && in_array( $cart_coupon_type, $core_coupons ) ) ) {
					$contains_discount = true;
					break;
				}
			}
		}

		return $contains_discount;
	}

	/**
	 * Check if a subscription coupon is valid before applying
	 *
	 * @since 1.2
	 */
	public static function validate_subscription_coupon( $valid, $coupon ) {

		if ( ! apply_filters( 'woocommerce_subscriptions_validate_coupon_type', true, $coupon, $valid ) ) {
			return $valid;
		}

		self::$coupon_error = '';
		$coupon_type        = wcs_get_coupon_property( $coupon, 'discount_type' );

		// ignore non-subscription coupons
		if ( ! in_array( $coupon_type, array( 'recurring_fee', 'sign_up_fee', 'recurring_percent', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {

			// but make sure there is actually something for the coupon to be applied to (i.e. not a free trial)
			if ( ( wcs_cart_contains_renewal() || WC_Subscriptions_Cart::cart_contains_subscription() ) && 0 == WC()->cart->subtotal ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for an initial payment and the cart does not require an initial payment.', 'woocommerce-subscriptions' );
			}
		} else {

			// prevent subscription coupons from being applied to renewal payments
			if ( wcs_cart_contains_renewal() && ! in_array( $coupon_type, array( 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for new subscriptions.', 'woocommerce-subscriptions' );
			}

			// prevent subscription coupons from being applied to non-subscription products
			if ( ! wcs_cart_contains_renewal() && ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for subscription products.', 'woocommerce-subscriptions' );
			}

			// prevent subscription renewal coupons from being applied to non renewal payments
			if ( ! wcs_cart_contains_renewal() && in_array( $coupon_type, array( 'renewal_fee', 'renewal_percent', 'renewal_cart' ) ) ) {
				// translators: 1$: coupon code that is being removed
				self::$coupon_error = sprintf( __( 'Sorry, the "%1$s" coupon is only valid for renewals.', 'woocommerce-subscriptions' ), wcs_get_coupon_property( $coupon, 'code' ) );
			}

			// prevent sign up fee coupons from being applied to subscriptions without a sign up fee
			if ( 0 == WC_Subscriptions_Cart::get_cart_subscription_sign_up_fee() && in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) ) {
				self::$coupon_error = __( 'Sorry, this coupon is only valid for subscription products with a sign-up fee.', 'woocommerce-subscriptions' );
			}
		}

		if ( ! empty( self::$coupon_error ) ) {
			$valid = false;
			add_filter( 'woocommerce_coupon_error', __CLASS__ . '::add_coupon_error', 10 );
		}

		return $valid;
	}

	/**
	 * Returns a subscription coupon-specific error if validation failed
	 *
	 * @since 1.2
	 */
	public static function add_coupon_error( $error ) {

		if ( self::$coupon_error ) {
			return self::$coupon_error;
		} else {
			return $error;
		}

	}

	/**
	 * Checks a given product / coupon combination to determine if the subscription should be discounted
	 *
	 * @since 1.2
	 */
	private static function is_subscription_discountable( $cart_item, $coupon ) {

		$product_cats = wp_get_post_terms( $cart_item['product_id'], 'product_cat', array( 'fields' => 'ids' ) );

		$this_item_is_discounted = false;

		// Specific products get the discount
		if ( sizeof( $coupon_product_ids = wcs_get_coupon_property( $coupon, 'product_ids' ) ) > 0 ) {

			if ( in_array( wcs_get_canonical_product_id( $cart_item ), $coupon_product_ids ) || in_array( $cart_item['data']->get_parent(), $coupon_product_ids ) ) {
				$this_item_is_discounted = true;
			}

		// Category discounts
		} elseif ( sizeof( $coupon_product_categories = wcs_get_coupon_property( $coupon, 'product_categories' ) ) > 0 ) {

			if ( sizeof( array_intersect( $product_cats, $coupon_product_categories ) ) > 0 ) {
				$this_item_is_discounted = true;
			}
		} else {

			// No product ids - all items discounted
			$this_item_is_discounted = true;

		}

		// Specific product ID's excluded from the discount
		if ( sizeof( $coupon_excluded_product_ids = wcs_get_coupon_property( $coupon, 'exclude_product_ids' ) ) > 0 ) {
			if ( in_array( wcs_get_canonical_product_id( $cart_item ), $coupon_excluded_product_ids ) || in_array( $cart_item['data']->get_parent(), $coupon_excluded_product_ids ) ) {
				$this_item_is_discounted = false;
			}
		}

		// Specific categories excluded from the discount
		if ( sizeof( $coupon_excluded_product_categories = wcs_get_coupon_property( $coupon, 'exclude_product_categories' ) ) > 0 ) {
			if ( sizeof( array_intersect( $product_cats, $coupon_excluded_product_categories ) ) > 0 ) {
				$this_item_is_discounted = false;
			}
		}

		// Apply filter
		return apply_filters( 'woocommerce_item_is_discounted', $this_item_is_discounted, $cart_item, $before_tax = false );
	}

	/**
	 * Sets which coupons should be applied for this calculation.
	 *
	 * This function is hooked to "woocommerce_before_calculate_totals" so that WC will calculate a subscription
	 * product's total based on the total of it's price per period and sign up fee (if any).
	 *
	 * @since 1.3.5
	 */
	public static function remove_coupons( $cart ) {

		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		// Only hook when totals are being calculated completely (on cart & checkout pages)
		if ( 'none' == $calculation_type || ! WC_Subscriptions_Cart::cart_contains_subscription() || ( ! is_checkout() && ! is_cart() && ! defined( 'WOOCOMMERCE_CHECKOUT' ) && ! defined( 'WOOCOMMERCE_CART' ) ) ) {
			return;
		}

		$applied_coupons = $cart->get_applied_coupons();

		// If we're calculating a sign-up fee or recurring fee only amount, remove irrelevant coupons
		if ( ! empty( $applied_coupons ) ) {

			// Keep track of which coupons, if any, need to be reapplied immediately
			$coupons_to_reapply = array();

			foreach ( $applied_coupons as $coupon_code ) {

				$coupon      = new WC_Coupon( $coupon_code );
				$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );

				if ( in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) ) {  // always apply coupons to their specific calculation case
					if ( 'recurring_total' == $calculation_type ) {
						$coupons_to_reapply[] = $coupon_code;
					} elseif ( 'none' == $calculation_type && ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) { // sometimes apply recurring coupons to initial total
						$coupons_to_reapply[] = $coupon_code;
					} else {
						self::$removed_coupons[] = $coupon_code;
					}
				} elseif ( ( 'none' == $calculation_type ) && ! in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) ) { // apply all coupons to the first payment
					$coupons_to_reapply[] = $coupon_code;
				} else {
					self::$removed_coupons[] = $coupon_code;
				}
			}

			// Now remove all coupons (WC only provides a function to remove all coupons)
			$cart->remove_coupons();

			// And re-apply those which relate to this calculation
			$cart->applied_coupons = $coupons_to_reapply;

			if ( isset( $cart->coupons ) ) { // WC 2.3+
				$cart->coupons = $cart->get_coupons();
			}
		}
	}

	/**
	 * Add our recurring product coupon types to the list of coupon types that apply to individual products.
	 * Used to control which validation rules will apply.
	 *
	 * @param array $product_coupon_types
	 * @return array $product_coupon_types
	 */
	public static function filter_product_coupon_types( $product_coupon_types ) {

		if ( is_array( $product_coupon_types ) ) {
			$product_coupon_types = array_merge( $product_coupon_types, array( 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart' ) );
		}

		return $product_coupon_types;
	}

	/**
	 * Get subtotals for a renewal subscription so that our pseudo renewal_cart discounts can be applied correctly even if other items have been added to the cart
	 *
	 * @param  string $code coupon code
	 * @return array subtotal
	 * @since 2.0.10
	 */
	private static function get_renewal_subtotal( $code ) {

		$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons' );

		if ( empty( $renewal_coupons ) ) {
			return false;
		}

		$subtotal = 0;

		foreach ( $renewal_coupons as $subscription_id => $coupons ) {

			foreach ( $coupons as $coupon_code => $coupon_properties ) {

				if ( $coupon_code == $code ) {

					if ( $subscription = wcs_get_subscription( $subscription_id ) ) {
						$subtotal = $subscription->get_subtotal();
					}
					break;
				}
			}
		}

		return $subtotal;
	}

	/**
	 * Check if a product is a renewal order line item (rather than a "susbscription") - to pick up non-subsbcription products added a subscription manually
	 *
	 * @param int|WC_Product $product_id
	 * @param array $cart_item
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @return boolean whether a product is a renewal order line item
	 * @since 2.0.10
	 */
	private static function is_subsbcription_renewal_line_item( $product_id, $cart_item ) {

		$is_subscription_line_item = false;

		if ( is_object( $product_id ) ) {
			$product_id = $product_id->get_id();
		}

		if ( ! empty( $cart_item['subscription_renewal'] ) ) {
			if ( $subscription = wcs_get_subscription( $cart_item['subscription_renewal']['subscription_id'] ) ) {
				foreach ( $subscription->get_items() as $item ) {
					$item_product_id = wcs_get_canonical_product_id( $item );
					if ( ! empty( $item_product_id ) && $item_product_id == $product_id ) {
						$is_subscription_line_item = true;
					}
				}
			}
		}

		return apply_filters( 'woocommerce_is_subscription_renewal_line_item', $is_subscription_line_item, $product_id, $cart_item );
	}

	/**
	 * Add our pseudo renewal coupon types to the list of supported types.
	 *
	 * @param array $coupon_types
	 * @return array supported coupon types
	 * @since 2.2
	 */
	public static function add_pseudo_coupon_types( $coupon_types ) {
		return array_merge(
			$coupon_types,
			array(
				'renewal_percent' => __( 'Renewal % discount', 'woocommerce-subscriptions' ),
				'renewal_fee'     => __( 'Renewal product discount', 'woocommerce-subscriptions' ),
				'renewal_cart'    => __( 'Renewal cart discount', 'woocommerce-subscriptions' ),
			)
		);
	}

	/* Deprecated */

	/**
	 * Apply sign up fee or recurring fee discount
	 *
	 * @since 1.2
	 */
	public static function apply_subscription_discount( $original_price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0.10', 'Have moved to filtering on "woocommerce_coupon_get_discount_amount" to return discount amount. See: '. __CLASS__ .'::get_discount_amount()' );

		if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
			return $original_price;
		}

		$price = $calculation_price = $original_price;

		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		if ( ! empty( $cart->applied_coupons ) ) {

			foreach ( $cart->applied_coupons as $coupon_code ) {

				$coupon        = new WC_Coupon( $coupon_code );
				$coupon_type   = wcs_get_coupon_property( $coupon, 'discount_type' );
				$coupon_amount = wcs_get_coupon_property( $coupon, 'coupon_amount' );

				// Pre 2.5 is_valid_for_product() does not use wc_get_product_coupon_types()
				if ( WC_Subscriptions::is_woocommerce_pre( '2.5' ) ) {
					$is_valid_for_product = true;
				} else {
					$is_valid_for_product = $coupon->is_valid_for_product( $cart_item['data'], $cart_item );
				}

				if ( $coupon->apply_before_tax() && $coupon->is_valid() && $is_valid_for_product ) {

					$apply_recurring_coupon = $apply_recurring_percent_coupon = $apply_initial_coupon = $apply_initial_percent_coupon = false;

					// Apply recurring fee discounts to recurring total calculations
					if ( 'recurring_total' == $calculation_type ) {
						$apply_recurring_coupon         = ( 'recurring_fee' == $coupon_type ) ? true : false;
						$apply_recurring_percent_coupon = ( 'recurring_percent' == $coupon_type ) ? true : false;
					}

					if ( 'none' == $calculation_type ) {

						// If all items have a free trial we don't need to apply recurring coupons to the initial total
						if ( ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) {

							if ( 'recurring_fee' == $coupon_type ) {
								$apply_initial_coupon = true;
							}

							if ( 'recurring_percent' == $coupon_type ) {
								$apply_initial_percent_coupon = true;
							}
						}

						// Apply sign-up discounts to initial total
						if ( WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] ) > 0 ) {

							if ( 'sign_up_fee' == $coupon_type ) {
								$apply_initial_coupon = true;
							}

							if ( 'sign_up_fee_percent' == $coupon_type ) {
								$apply_initial_percent_coupon = true;
							}

							$calculation_price = WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
						}
					}

					if ( $apply_recurring_coupon || $apply_initial_coupon ) {

						$discount_amount = ( $calculation_price < $coupon_amount ) ? $calculation_price : $coupon_amount;

						// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
						if ( $apply_initial_coupon && 'recurring_fee' == $coupon_type && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
							$discount_amount = 0;
						}

						$cart->discount_cart = $cart->discount_cart + ( $discount_amount * $cart_item['quantity'] );
						$cart = self::increase_coupon_discount_amount( $cart, $coupon_code, $discount_amount * $cart_item['quantity'] );

						$price = $price - $discount_amount;

					} elseif ( $apply_recurring_percent_coupon ) {

						$discount_amount = round( ( $calculation_price / 100 ) * $coupon_amount, WC()->cart->dp );

						$cart->discount_cart = $cart->discount_cart + ( $discount_amount * $cart_item['quantity'] );
						$cart = self::increase_coupon_discount_amount( $cart, $coupon_code, $discount_amount * $cart_item['quantity'] );

						$price = $price - $discount_amount;

					} elseif ( $apply_initial_percent_coupon ) {

						// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
						if ( 'recurring_percent' == $coupon_type && 0 == WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) ) {
							$amount_to_discount = WC_Subscriptions_Product::get_price( $cart_item['data'] );
						} else {
							$amount_to_discount = 0;
						}

						// Sign up fee coupons only apply to sign up fees
						if ( 'sign_up_fee_percent' == $coupon_type ) {
							$amount_to_discount = WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
						}

						$discount_amount = round( ( $amount_to_discount / 100 ) * $coupon_amount, WC()->cart->dp );

						$cart->discount_cart = $cart->discount_cart + $discount_amount * $cart_item['quantity'];
						$cart = self::increase_coupon_discount_amount( $cart, $coupon_code, $discount_amount * $cart_item['quantity'] );

						$price = $price - $discount_amount;
					}
				}
			}

			if ( $price < 0 ) {
				$price = 0;
			}
		}

		return $price;
	}

	/**
	 * Store how much discount each coupon grants.
	 *
	 * @since 2.0
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @param mixed $code
	 * @param mixed $amount
	 * @return WC_Cart $cart
	 */
	public static function increase_coupon_discount_amount( $cart, $code, $amount ) {
		_deprecated_function( __METHOD__, '2.0.10' );

		if ( empty( $cart->coupon_discount_amounts[ $code ] ) ) {
			$cart->coupon_discount_amounts[ $code ] = 0;
		}

		$cart->coupon_discount_amounts[ $code ] += $amount;

		return $cart;
	}

	/**
	 * Restores discount coupons which had been removed for special subscription calculations.
	 *
	 * @since 1.3.5
	 */
	public static function restore_coupons( $cart ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( ! empty( self::$removed_coupons ) ) {

			// Can't use $cart->add_dicount here as it calls calculate_totals()
			$cart->applied_coupons = array_merge( $cart->applied_coupons, self::$removed_coupons );

			if ( isset( $cart->coupons ) ) { // WC 2.3+
				$cart->coupons = $cart->get_coupons();
			}

			self::$removed_coupons = array();
		}
	}

	/**
	 * Apply sign up fee or recurring fee discount before tax is calculated
	 *
	 * @since 1.2
	 */
	public static function apply_subscription_discount_before_tax( $original_price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ .'::apply_subscription_discount( $original_price, $cart_item, $cart )' );
		return self::apply_subscription_discount( $original_price, $cart_item, $cart );
	}

	/**
	 * Apply sign up fee or recurring fee discount after tax is calculated
	 *
	 * @since 1.2
	 * @version 1.3.6
	 */
	public static function apply_subscription_discount_after_tax( $coupon, $cart_item, $price ) {
		_deprecated_function( __METHOD__, '2.0', 'WooCommerce 2.3 removed after tax discounts. Use ' . __CLASS__ .'::apply_subscription_discount( $original_price, $cart_item, $cart )' );
	}
}

WC_Subscriptions_Coupon::init();
