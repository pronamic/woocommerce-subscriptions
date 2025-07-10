<?php
/**
 * Subscriptions Coupon Class
 *
 * Mirrors a few functions in the WC_Cart class to handle subscription-specific discounts
 *
 * @package WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Coupon
 * @category Class
 * @author Max Rice
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
 */
class WC_Subscriptions_Coupon {

	/**
	 * The meta key used for the number of renewals.
	 *
	 * @var string
	 */
	protected static $coupons_renewals = '_wcs_number_payments';

	/** @var string error message for invalid subscription coupons */
	public static $coupon_error;

	/**
	 * Stores the coupons not applied to a given calculation (so they can be applied later)
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
	 * @deprecated
	 */
	private static $removed_coupons = array();

	/**
	 * Subscription coupon types.
	 *
	 * @var array
	 */
	private static $recurring_coupons = array(
		'recurring_fee'     => 1,
		'recurring_percent' => 1,
	);

	/**
	 * Subscription sign up fee coupon types.
	 *
	 * @var array
	 */
	private static $sign_up_fee_coupons = array(
		'sign_up_fee_percent' => 1,
		'sign_up_fee'         => 1,
	);

	/**
	 * Virtual renewal coupon types.
	 *
	 * @var array
	 */
	private static $renewal_coupons = array(
		'renewal_cart'    => 1,
		'renewal_fee'     => 1,
		'renewal_percent' => 1,
	);

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 **/
	public static function init() {

		// Add custom coupon types
		add_filter( 'woocommerce_coupon_discount_types', __CLASS__ . '::add_discount_types' );

		// Handle discounts
		add_filter( 'woocommerce_coupon_get_discount_amount', __CLASS__ . '::get_discount_amount', 10, 5 );

		// Validate subscription coupons
		add_filter( 'woocommerce_coupon_is_valid', __CLASS__ . '::validate_subscription_coupon', 10, 3 );

		// Remove coupons which don't apply to certain cart calculations
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::remove_coupons', 10 );

		// Add our recurring product coupon types to the list of coupon types that apply to individual products
		add_filter( 'woocommerce_product_coupon_types', __CLASS__ . '::filter_product_coupon_types', 10, 1 );

		if ( ! is_admin() ) {
			// WC 3.0 only sets a coupon type if it is a pre-defined supported type, so we need to temporarily add our pseudo types. We don't want to add these on admin pages.
			add_filter( 'woocommerce_coupon_discount_types', __CLASS__ . '::add_pseudo_coupon_types' );
		}

		add_filter( 'woocommerce_cart_totals_coupon_label', __CLASS__ . '::get_pseudo_coupon_label', 10, 2 );

		add_filter( 'woocommerce_cart_totals_coupon_html', __CLASS__ . '::mark_recurring_coupon_in_initial_cart_for_hiding', 10, 2 );

		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'validate_subscription_coupon_for_product' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_apply_quantity', array( __CLASS__, 'override_applied_quantity_for_recurring_carts' ), 10, 3 );
	}

	/**
	* When all items in the cart have free trial, a recurring coupon should not be applied to the main cart.
	* Mark such recurring coupons with a dummy span with class wcs-hidden-coupon so that it can be hidden.
	*
	* @param string $coupon_html Html string of the recurring coupon's cell in the Cart totals table
	* @param WC_Coupon $coupon WC_Coupon object of the recurring coupon
	* @return string $coupon_html Modified html string of the coupon containing the marking
	* @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3
	*/
	public static function mark_recurring_coupon_in_initial_cart_for_hiding( $coupon_html, $coupon ) {
		$displaying_initial_cart_totals = false;

		if ( is_cart() ) {
			$displaying_initial_cart_totals = did_action( 'woocommerce_before_cart_totals' ) > did_action( 'woocommerce_cart_totals_before_order_total' );
		} elseif ( is_checkout() ) {
			$displaying_initial_cart_totals = did_action( 'woocommerce_review_order_after_cart_contents' ) > did_action( 'woocommerce_review_order_before_order_total' );
		}

		if ( $displaying_initial_cart_totals && WC_Subscriptions_Cart::all_cart_items_have_free_trial() && in_array( wcs_get_coupon_property( $coupon, 'discount_type' ), array( 'recurring_fee', 'recurring_percent' ) ) ) {
			$coupon_html .= '<span class="wcs-hidden-coupon" type="hidden"></span>';
		}

		return $coupon_html;
	}

	/**
	 * Add discount types
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	public static function get_discount_amount( $discount, $discounting_amount, $item, $single, $coupon ) {

		if ( is_a( $item, 'WC_Order_Item' ) ) { // WC 3.2 support for applying coupons to line items via admin edit subscription|order screen
			$discount = self::get_discount_amount_for_line_item( $item, $discount, $discounting_amount, $single, $coupon );
		} else {
			$discount = self::get_discount_amount_for_cart_item( $item, $discount, $discounting_amount, $single, $coupon );
		}

		return $discount;
	}

	/**
	 * Get the discount amount which applies for a cart item for subscription coupon types
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.13
	 * @param array $cart_item
	 * @param float $discount the original discount amount
	 * @param float $discounting_amount the cart item price/total which the coupon should apply to
	 * @param boolean $single True if discounting a single qty item, false if it's the line
	 * @param WC_Coupon $coupon
	 * @return float the discount amount which applies to the cart item
	 */
	public static function get_discount_amount_for_cart_item( $cart_item, $discount, $discounting_amount, $single, $coupon ) {

		$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );

		// Only deal with subscriptions coupon types which apply to cart items
		if ( ! in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart', 'initial_cart' ) ) ) {
			return $discount;
		}

		// If not a subscription product return the default discount
		if ( ! wcs_cart_contains_renewal() && ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
			return $discount;
		}
		// But if cart contains a renewal, we need to handle both subscription products and manually added non-subscription products that could be part of a subscription
		if ( wcs_cart_contains_renewal() && ! self::is_subscription_renewal_line_item( $cart_item['data'], $cart_item ) ) {
			return $discount;
		}

		$is_switch  = ! empty( $cart_item['subscription_switch'] );

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
			$apply_recurring_coupon         = 'recurring_fee' == $coupon_type;
			$apply_recurring_percent_coupon = 'recurring_percent' == $coupon_type;
		}

		// Check if we're applying any initial discounts
		if ( 'none' == $calculation_type ) {

			// If all items have a free trial we don't need to apply recurring coupons to the initial total
			if ( $is_switch || ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) {

				if ( 'recurring_fee' === $coupon_type || 'initial_cart' === $coupon_type ) {
					$apply_initial_coupon = true;
				}

				if ( 'recurring_percent' == $coupon_type ) {
					$apply_initial_percent_coupon = true;
				}
			}

			// Apply sign-up discounts. Exclude switch cart items because their initial amount is entirely sign-up fees but should be treated as initial amounts
			if ( ! $is_switch && WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] ) > 0 ) {

				if ( 'sign_up_fee' == $coupon_type ) {
					$apply_initial_coupon = true;
				}

				if ( 'sign_up_fee_percent' == $coupon_type ) {
					$apply_initial_percent_coupon = true;
				}

				// Get the sign up fee amount depending on the store's tax inclusivity.
				if ( wc_prices_include_tax() ) {
					$signup_fee = wc_get_price_including_tax(
						$cart_item['data'],
						array(
							'qty'   => 1,
							'price' => WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] ),
						)
					);
				} else {
					$signup_fee = wc_get_price_excluding_tax(
						$cart_item['data'],
						array(
							'qty'   => 1,
							'price' => WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] ),
						)
					);
				}

				// Only Sign up fee coupons apply to sign up fees, adjust the discounting_amount accordingly
				if ( in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) ) {
					$discounting_amount = $signup_fee;
				} else {
					$discounting_amount -= $signup_fee;
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
			if ( $apply_initial_coupon && 'recurring_fee' == $coupon_type && ! $is_switch && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
				$discounting_amount = 0;
			}

			$discount_amount = min( wcs_get_coupon_property( $coupon, 'coupon_amount' ), $discounting_amount );
			$discount_amount = $single ? $discount_amount : $discount_amount * $cart_item_qty;

		} elseif ( $apply_recurring_percent_coupon ) {

			$discount_amount = ( $discounting_amount / 100 ) * wcs_get_coupon_property( $coupon, 'coupon_amount' );

		} elseif ( $apply_initial_percent_coupon ) {

			// Recurring coupons only apply when there is no free trial (carts can have a mix of free trial and non free trial items)
			if ( 'recurring_percent' == $coupon_type && ! $is_switch && WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
				$discounting_amount = 0;
			}

			$discount_amount = ( $discounting_amount / 100 ) * wcs_get_coupon_property( $coupon, 'coupon_amount' );

		} elseif ( $apply_renewal_cart_coupon ) {

			/**
			 * See WC Core fixed_cart coupons - we need to divide the discount between rows based on their price in proportion to the subtotal.
			 * This is so rows with different tax rates get a fair discount, and so rows with no price (free) don't get discounted.
			 *
			 * BUT... we also need the subtotal to exclude non renewal products, so user the renewal subtotal
			 *
			 * @phpstan-ignore binaryOp.invalid
			 */
			$discount_percent = ( $discounting_amount * $cart_item['quantity'] ) / self::get_renewal_subtotal( wcs_get_coupon_property( $coupon, 'code' ) );

			$discount_amount = ( wcs_get_coupon_property( $coupon, 'coupon_amount' ) * $discount_percent ) / $cart_item_qty;
		}

		// Round - consistent with WC approach
		$discount_amount = round( $discount_amount, wcs_get_rounding_precision() );

		return $discount_amount;
	}

	/**
	 * Get the discount amount which applies for a line item for subscription coupon types
	 *
	 * Uses methods and data structures introduced in WC 3.0.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.13
	 * @param WC_Order_Item $line_item
	 * @param float $discount the original discount amount
	 * @param float $discounting_amount the line item price/total
	 * @param boolean $single True if discounting a single qty item, false if it's the line
	 * @param WC_Coupon $coupon
	 * @return float the discount amount which applies to the line item
	 */
	public static function get_discount_amount_for_line_item( $line_item, $discount, $discounting_amount, $single, $coupon ) {

		if ( ! is_callable( array( $line_item, 'get_order' ) ) ) {
			return $discount;
		}

		$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );
		$order       = $line_item->get_order();
		$product     = $line_item->get_product();

		// Recurring coupons can be applied to subscriptions, any renewal line item or subscription products in other order types
		if ( in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) && ( wcs_is_subscription( $order ) || wcs_order_contains_renewal( $order ) || WC_Subscriptions_Product::is_subscription( $product ) ) ) {
			if ( 'recurring_fee' === $coupon_type ) {
				$discount = min( $coupon->get_amount(), $discounting_amount );
				$discount = $single ? $discount : $discount * $line_item->get_quantity();
			} else { // recurring_percent
				$discount = (float) $coupon->get_amount() * ( $discounting_amount / 100 );
			}
		// Sign-up fee coupons apply to parent order line items which are subscription products and have a signup fee
		} elseif ( in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) && WC_Subscriptions_Product::is_subscription( $product ) && wcs_order_contains_subscription( $order, 'parent' ) && 0 !== WC_Subscriptions_Product::get_sign_up_fee( $product ) ) {
			if ( 'sign_up_fee' === $coupon_type ) {
				$discount = min( $coupon->get_amount(), WC_Subscriptions_Product::get_sign_up_fee( $product ) );
				$discount = $single ? $discount : $discount * $line_item->get_quantity();
			} else { // sign_up_fee_percent
				$discount = (float) $coupon->get_amount() * ( WC_Subscriptions_Product::get_sign_up_fee( $product ) / 100 );
			}
		}

		return $discount;
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
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
	 * @param boolean $valid
	 * @param WC_Coupon $coupon
	 * @param WC_Discounts $discount Added in WC 3.2 the WC_Discounts object contains information about the coupon being applied to either carts or orders - Optional
	 * @return boolean Whether the coupon is valid or not
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function validate_subscription_coupon( $valid, $coupon, $discount = null ) {

		if ( ! apply_filters( 'woocommerce_subscriptions_validate_coupon_type', true, $coupon, $valid ) ) {
			return $valid;
		}

		if ( is_a( $discount, 'WC_Discounts' ) ) { // WC 3.2+
			$discount_items = $discount->get_items();

			if ( is_array( $discount_items ) && ! empty( $discount_items ) ) {
				$item = reset( $discount_items );

				if ( isset( $item->object ) && is_a( $item->object, 'WC_Order_Item' ) ) {
					$valid = self::validate_subscription_coupon_for_order( $valid, $coupon, $item->object->get_order() );
				} else {
					$valid = self::validate_subscription_coupon_for_cart( $valid, $coupon );
				}
			}
		} else {
			$valid = self::validate_subscription_coupon_for_cart( $valid, $coupon );
		}

		return $valid;
	}

	/**
	 * Check if a subscription coupon is valid for the cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.13
	 * @param boolean $valid
	 * @param WC_Coupon $coupon
	 * @return bool whether the coupon is valid
	 */
	public static function validate_subscription_coupon_for_cart( $valid, $coupon ) {
		self::$coupon_error = '';
		$coupon_type        = wcs_get_coupon_property( $coupon, 'discount_type' );

		// ignore non-subscription coupons
		if ( ! in_array( $coupon_type, array( 'recurring_fee', 'sign_up_fee', 'recurring_percent', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart', 'initial_cart' ) ) ) {

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
	 * Check if a subscription coupon is valid for an order/subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.13
	 * @param WC_Coupon $coupon The subscription coupon being validated. Can accept recurring_fee, recurring_percent, sign_up_fee or sign_up_fee_percent coupon types.
	 * @param WC_Order|WC_Subscription $order The order or subscription object to which the coupon is being applied
	 * @return bool whether the coupon is valid
	 */
	public static function validate_subscription_coupon_for_order( $valid, $coupon, $order ) {
		$coupon_type   = wcs_get_coupon_property( $coupon, 'discount_type' );
		$error_message = '';

		// Recurring coupons can be applied to subscriptions and renewal orders
		if ( in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) && ! ( wcs_is_subscription( $order ) || wcs_order_contains_subscription( $order, 'any' ) ) ) {
			$error_message = __( 'Sorry, recurring coupons can only be applied to subscriptions or subscription orders.', 'woocommerce-subscriptions' );
		// Sign-up fee coupons can be applied to parent orders which contain subscription products with at least one sign up fee
		} elseif ( in_array( $coupon_type, array( 'sign_up_fee', 'sign_up_fee_percent' ) ) && ! ( wcs_order_contains_subscription( $order, 'parent' ) || 0 !== WC_Subscriptions_Order::get_sign_up_fee( $order ) ) ) {
			// translators: placeholder is coupon code
			$error_message = sprintf( __( 'Sorry, "%s" can only be applied to subscription parent orders which contain a product with signup fees.', 'woocommerce-subscriptions' ), wcs_get_coupon_property( $coupon, 'code' ) );
		// Only recurring coupons can be applied to subscriptions
		} elseif ( ! in_array( $coupon_type, array( 'recurring_fee', 'recurring_percent' ) ) && wcs_is_subscription( $order ) ) {
			$error_message = __( 'Sorry, only recurring coupons can be applied to subscriptions.', 'woocommerce-subscriptions' );
		}

		if ( ! empty( $error_message ) ) {
			throw new Exception( $error_message );
		}

		return $valid;
	}

	/**
	 * Returns a subscription coupon-specific error if validation failed
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function add_coupon_error( $error ) {

		if ( self::$coupon_error ) {
			return self::$coupon_error;
		} else {
			return $error;
		}

	}

	/**
	 * Sets which coupons should be applied for this calculation.
	 *
	 * This function is hooked to "woocommerce_before_calculate_totals" so that WC will calculate a subscription
	 * product's total based on the total of it's price per period and sign up fee (if any).
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
	 *
	 * @param WC_Cart $cart
	 */
	public static function remove_coupons( $cart ) {
		$calculation_type = WC_Subscriptions_Cart::get_calculation_type();

		// Only hook when totals are being calculated completely (on cart & checkout pages)
		if (
			'none' === $calculation_type ||
			! WC_Subscriptions_Cart::cart_contains_subscription() ||
			empty( $cart->recurring_cart_key )
		) {
			return;
		}

		$applied_coupons = $cart->get_applied_coupons();
		if ( empty( $applied_coupons ) ) {
			return;
		}

		// If we're calculating a sign-up fee or recurring fee only amount, remove irrelevant coupons
		foreach ( $applied_coupons as $coupon_code ) {
			$coupon      = new WC_Coupon( $coupon_code );
			$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );

			/**
			 * Filters whether the coupon should be allowed to be removed.
			 *
			 * @param bool      $bypass_removal   Whether to bypass removing the coupon.
			 * @param WC_Coupon $coupon           The coupon object.
			 * @param string    $coupon_type      The coupon's discount_type property.
			 * @param string    $calculation_type The current calculation type.
			 * @param WC_Cart   $cart             The cart object.
			 */
			if ( apply_filters( 'wcs_bypass_coupon_removal', false, $coupon, $coupon_type, $calculation_type, $cart ) ) {
				continue;
			}

			if ( ! isset( self::$recurring_coupons[ $coupon_type ] ) ) {
				$cart->remove_coupon( $coupon_code );
				continue;
			}

			if ( 'recurring_total' === $calculation_type || ! WC_Subscriptions_Cart::all_cart_items_have_free_trial() ) {
				continue;
			}

			$cart->remove_coupon( $coupon_code );
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
			$product_coupon_types = array_merge( $product_coupon_types, array( 'recurring_fee', 'recurring_percent', 'sign_up_fee', 'sign_up_fee_percent', 'renewal_fee', 'renewal_percent', 'renewal_cart', 'initial_cart' ) );
		}

		return $product_coupon_types;
	}

	/**
	 * Get subtotals for a renewal subscription so that our pseudo renewal_cart discounts can be applied correctly even if other items have been added to the cart
	 *
	 * @param  string $code coupon code
	 * @return array subtotal
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	private static function get_renewal_subtotal( $code ) {

		$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons' );

		if ( empty( $renewal_coupons ) ) {
			return false;
		}

		$subtotal = 0;

		foreach ( $renewal_coupons as $order_id => $coupons ) {

			foreach ( $coupons as $coupon_code => $coupon_properties ) {

				if ( $coupon_code == $code ) {

					if ( $order = wc_get_order( $order_id ) ) {
						$subtotal = $order->get_subtotal();
					}
					break;
				}
			}
		}

		return $subtotal;
	}

	/**
	 * Check if a product is a renewal order line item (rather than a "subscription") - to pick up non-subscription products added to a subscription manually
	 *
	 * @param int|WC_Product $product_id
	 * @param array $cart_item
	 * @return boolean whether a product is a renewal order line item
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	private static function is_subscription_renewal_line_item( $product_id, $cart_item ) {

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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2
	 */
	public static function add_pseudo_coupon_types( $coupon_types ) {
		return array_merge(
			$coupon_types,
			array(
				'renewal_percent' => __( 'Renewal % discount', 'woocommerce-subscriptions' ),
				'renewal_fee'     => __( 'Renewal product discount', 'woocommerce-subscriptions' ),
				'renewal_cart'    => __( 'Renewal cart discount', 'woocommerce-subscriptions' ),
				'initial_cart'    => __( 'Initial payment discount', 'woocommerce-subscriptions' ),
			)
		);
	}

	/**
	 * Filter the default coupon cart label for renewal pseudo coupons
	 *
	 * @param  string $label
	 * @param  WC_Coupon $coupon
	 * @return string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.8
	 */
	public static function get_pseudo_coupon_label( $label, $coupon ) {
		// If the coupon is one of our pseudo coupons, rather than displaying "Coupon: discount_renewal" display a nicer label.
		switch ( $coupon->get_discount_type() ) {
			case 'renewal_cart':
				$label = esc_html( __( 'Renewal Discount', 'woocommerce-subscriptions' ) );
				break;
			case 'initial_cart':
				$label = esc_html( __( 'Discount', 'woocommerce-subscriptions' ) );
				break;
		}

		return $label;
	}

	/**
	 * Get a normal coupon from one of our virtual coupons.
	 *
	 * This is necessary when manually processing a renewal to ensure that we are correctly
	 * identifying limited payment coupons.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $code The virtual coupon code.
	 *
	 * @return WC_Coupon The original coupon.
	 */
	public static function map_virtual_coupon( $code ) {
		add_filter( 'woocommerce_get_shop_coupon_data', '__return_false', 100 );
		$coupon = new WC_Coupon( $code );
		remove_filter( 'woocommerce_get_shop_coupon_data', '__return_false', 100 );

		return $coupon;
	}

	/**
	 * Checks if a coupon is one of our virtual coupons applied to renewal carts.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param string $coupon_type The coupon's type.
	 * @return bool Whether the coupon is a recurring cart virtual coupon.
	 */
	public static function is_renewal_cart_coupon( $coupon_type ) {
		return isset( self::$renewal_coupons[ $coupon_type ] );
	}

	/**
	 * Checks if a coupon is one of our recurring coupons.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param string $coupon_type The coupon's type.
	 * @return bool Whether the coupon is a recurring cart virtual coupon.
	 */
	public static function is_recurring_coupon( $coupon_type ) {
		return isset( self::$recurring_coupons[ $coupon_type ] );
	}

	/* Deprecated */

	/**
	 * Apply sign up fee or recurring fee discount
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function apply_subscription_discount( $original_price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0.10', 'Have moved to filtering on "woocommerce_coupon_get_discount_amount" to return discount amount. See: ' . __CLASS__ . '::get_discount_amount()' );

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

				$is_valid_for_product = $coupon->is_valid_for_product( $cart_item['data'], $cart_item );

				if ( $coupon->apply_before_tax() && $coupon->is_valid() && $is_valid_for_product ) {

					$apply_recurring_coupon = $apply_recurring_percent_coupon = $apply_initial_coupon = $apply_initial_percent_coupon = false;

					// Apply recurring fee discounts to recurring total calculations
					if ( 'recurring_total' == $calculation_type ) {
						$apply_recurring_coupon         = 'recurring_fee' == $coupon_type;
						$apply_recurring_percent_coupon = 'recurring_percent' == $coupon_type;
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
	 * Validates a subscription coupon's use for a given product.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.4
	 *
	 * @param bool       $is_valid Whether the coupon is valid for the product.
	 * @param WC_Product $product  The product object.
	 * @param WC_Coupon  $coupon   The coupon object.
	 *
	 * @return bool Whether the coupon is valid for the product.
	 */
	public static function validate_subscription_coupon_for_product( $is_valid, $product, $coupon ) {

		// Exit early if the coupon is already invalid.
		if ( ! $is_valid ) {
			return $is_valid;
		}

		$coupon_type           = $coupon->get_discount_type();
		$is_recurring_coupon   = isset( self::$recurring_coupons[ $coupon_type ] );
		$is_sign_up_fee_coupon = isset( self::$sign_up_fee_coupons[ $coupon_type ] );

		// Recurring and sign up fee coupons are not valid for non-subscription products.
		if ( ( $is_recurring_coupon || $is_sign_up_fee_coupon ) && ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			$is_valid = false;
		}

		// Sign up fee coupons are not valid for products without a sign up fee.
		if ( $is_sign_up_fee_coupon && 0 === WC_Subscriptions_Product::get_sign_up_fee( $product ) ) {
			$is_valid = false;
		}

		return $is_valid;
	}

	/**
	 * Store how much discount each coupon grants.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
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
	 * @param WC_Cart $cart The cart object.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
	 */
	public static function restore_coupons( $cart ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( ! empty( self::$removed_coupons ) ) {

			// Can't use $cart->add_discount here as it calls calculate_totals()
			$cart->applied_coupons = array_merge( $cart->applied_coupons, self::$removed_coupons );

			if ( isset( $cart->coupons ) ) { // WC 2.3+
				$cart->coupons = $cart->get_coupons();
			}

			self::$removed_coupons = array();
		}
	}

	/**
	 * Override the quantity to apply limited coupons to recurring cart items.
	 *
	 * Limited coupons can only apply to x number of items. By default that limit applies
	 * to items in each cart instance. Because recurring carts are separate, the limit applies to
	 * each recurring cart leading to the limit really being x * number-of-recurring-carts.
	 *
	 * This function overrides that by ensuring the limit is accounted for across all recurring carts.
	 * The items which the coupon applied to in initial cart are the items in recurring carts that the coupon will apply to.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.0
	 *
	 * @param int       $apply_quantity The item quantity to apply the coupon to.
	 * @param object    $item The stdClass cart item object. @see WC_Discounts::set_items_from_cart() for an example of object properties.
	 * @param WC_Coupon $coupon The coupon being applied
	 *
	 * @return int The item quantity to apply the coupon to.
	 */
	public static function override_applied_quantity_for_recurring_carts( $apply_quantity, $item, $coupon ) {
		static $recurring_cart_items_priority = array();

		$coupon_code          = $coupon->get_code();
		$coupon_type          = $coupon->get_discount_type();
		$limited_use_quantity = $coupon->get_limit_usage_to_x_items();

		if ( null === $limited_use_quantity || ! isset( self::$recurring_coupons[ $coupon_type ] ) ) {
			return $apply_quantity;
		}

		if ( 'none' === WC_Subscriptions_Cart::get_calculation_type() ) {
			return $apply_quantity;
		}

		// Build a sorted list of recurring items. Used later to find which items we can apply the coupon to. $recurring_cart_items_priority is static so this only happens once.
		if ( empty( $recurring_cart_items_priority ) ) {
			$prices = $quantities = array();

			foreach ( WC()->cart->cart_contents as $cart_item_key => $initial_cart_item ) {
				// Because we're in the recurring cart calculation type (WC_Subscriptions_Cart::get_calculation_type()), get_price() will return the recurring price, not the sign up price.
				$prices[ $cart_item_key ]['price']        = $initial_cart_item['data']->get_price();
				$quantities[ $cart_item_key ]['quantity'] = $initial_cart_item['quantity'];
			}

			// Sort the items by price so we apply coupons to higher priced recurring items first.
			arsort( $prices );
			$recurring_cart_items_priority = array_merge_recursive( $prices, $quantities );
		}

		// Loop over the sorted recurring items to see if we will have enough usages left to apply the coupon to this item.
		$recurring_coupon_applied_count = 0;
		foreach ( $recurring_cart_items_priority as $item_key => $price_and_quantity ) {
			if ( $item_key === $item->key ) {
				// Find the maximum number of times this coupon could be applied.
				if ( ( $limited_use_quantity - $recurring_coupon_applied_count ) < $item->quantity ) {
					$apply_quantity = $limited_use_quantity - $recurring_coupon_applied_count;
				} else {
					$apply_quantity = $item->quantity;
				}

				break;
			}

			$recurring_coupon_applied_count += $price_and_quantity['quantity'];

			// If we've run out of uses without reaching this item, exit out.
			if ( $recurring_coupon_applied_count >= $limited_use_quantity ) {
				$apply_quantity = 0;
				break;
			}
		}

		return $apply_quantity;
	}

	/**
	 * Apply sign up fee or recurring fee discount before tax is calculated
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function apply_subscription_discount_before_tax( $original_price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::apply_subscription_discount( $original_price, $cart_item, $cart )' );
		return self::apply_subscription_discount( $original_price, $cart_item, $cart );
	}

	/**
	 * Apply sign up fee or recurring fee discount after tax is calculated
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.6
	 */
	public static function apply_subscription_discount_after_tax( $coupon, $cart_item, $price ) {
		_deprecated_function( __METHOD__, '2.0', 'WooCommerce 2.3 removed after tax discounts. Use ' . __CLASS__ . '::apply_subscription_discount( $original_price, $cart_item, $cart )' );
	}

	/**
	 * Maybe add Recurring Coupon functionality.
	 *
	 * WC 3.2 added many API enhancements, especially around coupons. It would be very challenging to implement
	 * this functionality in older versions of WC, so we require 3.2+ to enable this.
	 *
	 * @author Jeremy Pry
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function maybe_add_recurring_coupon_hooks() {
		_deprecated_function( __METHOD__, '4.0.0' );
	}

	/**
	 * Add custom fields to the coupon data form.
	 *
	 * @see    WC_Meta_Box_Coupon_Data::output()
	 * @author Jeremy Pry
	 *
	 * @param int $id The coupon ID.
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function add_coupon_fields( $id ) {
		_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::add_coupon_fields( $id ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			WCS_Limited_Recurring_Coupon_Manager::add_coupon_fields( $id );
		}
	}

	/**
	 * Save our custom coupon fields.
	 *
	 * @see    WC_Meta_Box_Coupon_Data::save()
	 * @author Jeremy Pry
	 *
	 * @param int $post_id
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function save_coupon_fields( $post_id ) {
		_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::save_coupon_fields( $post_id ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			WCS_Limited_Recurring_Coupon_Manager::save_coupon_fields( $post_id );
		}
	}

	/**
	 * Determine how many subscriptions the coupon has been applied to.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Subscription $subscription The current subscription.
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function check_coupon_usages( $subscription ) {
		_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::check_coupon_usages( $subscription ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			WCS_Limited_Recurring_Coupon_Manager::check_coupon_usages( $subscription );
		}
	}

	/**
	 * Add our limited coupon data to the Coupon list table.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $column_name The name of the current column in the table.
	 * @param int    $post_id     The coupon post ID.
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function add_limit_to_list_table( $column_name, $post_id ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::add_limit_to_list_table( $column_name, $post_id ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			WCS_Limited_Recurring_Coupon_Manager::add_limit_to_list_table( $column_name, $post_id );
		}
	}

	/**
	 * Filter the available gateways when there is a recurring coupon.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Payment_Gateway[] $gateways The available payment gateways.
	 *
	 * @return array The filtered payment gateways.
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function gateways_subscription_amount_changes( $gateways ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::gateways_subscription_amount_changes( $gateways ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			return WCS_Limited_Recurring_Coupon_Manager::gateways_subscription_amount_changes( $gateways );
		}

		return $gateways;
	}

	/**
	 * Filter the message for when no payment gateways are available.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $message The current message indicating there are no payment methods available..
	 *
	 * @return string The filtered message indicating there are no payment methods available.
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function no_available_payment_methods_message( $message ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::no_available_payment_methods_message() if available' );
		return __( 'Sorry, it seems there are no available payment methods which support the recurring coupon you are using. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce-subscriptions' );
	}

	/**
	 * Determine if a given coupon is limited to a certain number of renewals.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $code The coupon code.
	 *
	 * @return bool
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function coupon_is_limited( $code ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::coupon_is_limited() if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			return WCS_Limited_Recurring_Coupon_Manager::coupon_is_limited( $code );
		}

		return false;
	}

	/**
	 * Determine whether the cart contains a recurring coupon with set number of renewals.
	 *
	 * @author Jeremy Pry
	 * @return bool
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function cart_contains_limited_recurring_coupon() {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::cart_contains_limited_recurring_coupon() if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			return WCS_Limited_Recurring_Coupon_Manager::cart_contains_limited_recurring_coupon();
		}

		return false;
	}

	/**
	 * Determine if a given order has a limited use coupon.
	 *
	 * @author Jeremy Pry
	 *
	 * @param WC_Order|WC_Subscription $order
	 *
	 * @return bool
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function order_has_limited_recurring_coupon( $order ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::order_has_limited_recurring_coupon( $order ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			return WCS_Limited_Recurring_Coupon_Manager::order_has_limited_recurring_coupon( $order );
		}

		return false;
	}

	/**
	 * Get the number of renewals for a limited coupon.
	 *
	 * @author Jeremy Pry
	 *
	 * @param string $code The coupon code.
	 *
	 * @return false|int False for non-recurring coupons, or the limit number for recurring coupons.
	 *                   A value of 0 is for unlimited usage.
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function get_coupon_limit( $code ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::get_coupon_limit( $code ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			return WCS_Limited_Recurring_Coupon_Manager::get_coupon_limit( $code );
		}

		return false;
	}

	/**
	 * Determine if a given recurring cart contains a limited use coupon which when applied to a subscription will reach its usage limit within the subscription's length.
	 *
	 * @param WC_Cart $recurring_cart The recurring cart object.
	 * @return bool
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function recurring_cart_contains_expiring_coupon( $recurring_cart ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Limited_Recurring_Coupon_Manager::recurring_cart_contains_expiring_coupon( $recurring_cart ) if available' );
		if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			return WCS_Limited_Recurring_Coupon_Manager::recurring_cart_contains_expiring_coupon( $recurring_cart );
		}

		return false;
	}
}
