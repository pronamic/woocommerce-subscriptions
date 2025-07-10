<?php
/**
 * Subscriptions Cart Class
 *
 * Mirrors a few functions in the WC_Cart class to work for subscriptions.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  WC_Subscriptions_Cart
 * @category    Class
 * @author      Brent Shepherd
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v1.0
 */
class WC_Subscriptions_Cart {

	/**
	 * A flag to control how to modify the calculation of totals by WC_Cart::calculate_totals()
	 *
	 * Can take any one of these values:
	 * - 'none' used to calculate the initial total.
	 * - 'combined_total' used to calculate the total of sign-up fee + recurring amount.
	 * - 'sign_up_fee_total' used to calculate the initial amount when there is a free trial period and a sign-up fee. Different to 'combined_total' because shipping is not charged on a sign-up fee.
	 * - 'recurring_total' used to calculate the totals for the recurring amount when the recurring amount differs to to 'combined_total' because of coupons or sign-up fees.
	 * - 'free_trial_total' used to calculate the initial total when there is a free trial period and no sign-up fee. Different to 'combined_total' because shipping is not charged up-front when there is a free trial.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	private static $calculation_type = 'none';

	/**
	 * An internal pointer to the current recurring cart calculation (if any)
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.12
	 */
	private static $recurring_cart_key = 'none';

	/**
	 * A cache of the calculated recurring shipping packages
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	private static $recurring_shipping_packages = array();

	/**
	 * A cache of the current recurring cart being calculated
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.20
	 */
	private static $cached_recurring_cart = null;

	/**
	 * A stack of recurring cart keys being calculated.
	 *
	 * Before calculating a cart's totals, we set the recurring cart key and calculation type to match that cart's key and type. @see self::set_recurring_cart_key_before_calculate_totals()
	 * After a cart's totals have been calculated, we restore the recurring cart key and calculation type. @see self::update_recurring_cart_key_after_calculate_totals()
	 *
	 * @var array
	 */
	private static $recurring_totals_calculation_stack = [];

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function init() {
		// Make sure WC calculates total on sign up fee + price per period, and keep a record of the price per period
		add_action( 'woocommerce_before_calculate_totals', __CLASS__ . '::add_calculation_price_filter', 10 );
		add_action( 'woocommerce_calculate_totals', __CLASS__ . '::remove_calculation_price_filter', 10 );
		add_action( 'woocommerce_after_calculate_totals', __CLASS__ . '::remove_calculation_price_filter', 10 );

		add_filter( 'woocommerce_calculated_total', __CLASS__ . '::calculate_subscription_totals', 1000, 2 );

		// Remove any subscriptions with a free trial from the initial shipping packages
		add_filter( 'woocommerce_cart_shipping_packages', __CLASS__ . '::set_cart_shipping_packages', -10, 1 );

		// Subscriptions with a free trial need extra handling to support the COD gateway
		add_filter( 'woocommerce_available_payment_gateways', __CLASS__ . '::check_cod_gateway_for_free_trials' );

		// Display Formatted Totals
		add_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11, 4 );

		// Sometimes, even if the order total is $0, the cart still needs payment
		add_filter( 'woocommerce_cart_needs_payment', __CLASS__ . '::cart_needs_payment', 10, 2 );

		// Make sure cart product prices correctly include/exclude taxes
		add_filter( 'woocommerce_cart_product_price', __CLASS__ . '::cart_product_price', 10, 2 );

		// Display grouped recurring amounts after order totals on the cart/checkout pages
		add_action( 'woocommerce_cart_totals_after_order_total', __CLASS__ . '::display_recurring_totals' );
		add_action( 'woocommerce_review_order_after_order_total', __CLASS__ . '::display_recurring_totals' );

		add_filter( 'woocommerce_cart_needs_shipping', __CLASS__ . '::cart_needs_shipping', 11, 1 );
		add_filter( 'woocommerce_cart_needs_shipping_address', __CLASS__ . '::cart_needs_shipping_address', 11, 1 );

		// Remove recurring shipping methods stored in the session whenever a subscription product is removed from the cart
		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'maybe_reset_chosen_shipping_methods' ) );
		wcs_add_woocommerce_dependent_action( 'woocommerce_before_cart_item_quantity_zero', array( __CLASS__, 'maybe_reset_chosen_shipping_methods' ), '3.7.0', '<' );

		// Make sure we use our recurring shipping method for recurring shipping calculations not the default method
		add_filter( 'woocommerce_shipping_chosen_method', array( __CLASS__, 'set_chosen_shipping_method' ), 10, 2 );

		// When WooCommerce calculates rates for a recurring shipping package, make sure there is a different set of rates
		add_filter( 'woocommerce_shipping_package_name', __CLASS__ . '::change_initial_shipping_package_name', 1, 3 );

		// When WooCommerce determines the taxable address only return pick up shipping methods chosen for the recurring cart being calculated.
		add_filter( 'woocommerce_local_pickup_methods', __CLASS__ . '::filter_recurring_cart_chosen_shipping_method', 100, 1 );
		add_filter( 'wc_shipping_local_pickup_plus_chosen_shipping_methods', __CLASS__ . '::filter_recurring_cart_chosen_shipping_method', 10, 1 );

		// Validate chosen recurring shipping methods
		add_action( 'woocommerce_after_checkout_validation', __CLASS__ . '::validate_recurring_shipping_methods' );

		add_filter( 'woocommerce_add_to_cart_handler', __CLASS__ . '::add_to_cart_handler', 10, 2 );

		add_action( 'woocommerce_cart_calculate_fees', __CLASS__ . '::apply_recurring_fees', 1000, 1 );

		add_action( 'woocommerce_checkout_update_order_review', __CLASS__ . '::update_chosen_shipping_methods' );
		add_action( 'plugins_loaded', array( __CLASS__, 'attach_dependant_hooks' ) );

		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'record_base_tax_rates' ) );

		// Add Subscriptions data to cart items.
		add_filter( 'woocommerce_get_item_data', __CLASS__ . '::woocommerce_get_item_data', 10, 2 );

		// Redirect the user immediately to the checkout page after clicking "Sign Up Now" buttons to encourage immediate checkout
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'add_to_cart_redirect' ) );

		// Set the recurring cart being calculated.
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'set_recurring_cart_key_before_calculate_totals' ], 1 );
		add_action( 'woocommerce_after_calculate_totals', [ __CLASS__, 'update_recurring_cart_key_after_calculate_totals' ], 1 );
	}

	/**
	 * Attach dependant callbacks.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.6
	 */
	public static function attach_dependant_hooks() {
		// WooCommerce determines if free shipping is available using the WC->cart total and coupons, we need to recalculate its availability when obtaining shipping methods for a recurring cart
		if ( wcs_is_woocommerce_pre( '3.2' ) ) {
			add_filter( 'woocommerce_shipping_free_shipping_is_available', array( __CLASS__, 'maybe_recalculate_shipping_method_availability' ), 10, 2 );
		} else {
			add_filter( 'woocommerce_shipping_free_shipping_is_available', array( __CLASS__, 'recalculate_shipping_method_availability' ), 10, 3 );
		}
	}

	/**
	 * Attaches the "set_subscription_prices_for_calculation" filter to the WC Product's woocommerce_get_price hook.
	 *
	 * This function is hooked to "woocommerce_before_calculate_totals" so that WC will calculate a subscription
	 * product's total based on the total of it's price per period and sign up fee (if any).
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function add_calculation_price_filter() {
		WC()->cart->recurring_carts = array();

		// Only hook when cart contains a subscription
		if ( ! self::cart_contains_subscription() ) {
			return;
		}

		// Set which price should be used for calculation
		add_filter( 'woocommerce_product_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100, 2 );
		add_filter( 'woocommerce_product_variation_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100, 2 );
	}

	/**
	 * Removes the "set_subscription_prices_for_calculation" filter from the WC Product's woocommerce_get_price hook once
	 * calculations are complete.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function remove_calculation_price_filter() {
		remove_filter( 'woocommerce_product_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100 );
		remove_filter( 'woocommerce_product_variation_get_price', __CLASS__ . '::set_subscription_prices_for_calculation', 100 );
	}

	/**
	 * Use WC core add-to-cart handlers for subscription products.
	 *
	 * @param string     $handler The name of the handler to use when adding product to the cart
	 * @param WC_Product $product
	 */
	public static function add_to_cart_handler( $handler, $product ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
			switch ( $handler ) {
				case 'variable-subscription':
					$handler = 'variable';
					break;
				case 'subscription':
					$handler = 'simple';
					break;
			}
		}

		return $handler;
	}

	/**
	 * If we are running a custom calculation, we need to set the price returned by a product
	 * to be the appropriate value. This may include just the sign-up fee, a combination of the
	 * sign-up fee and recurring amount or just the recurring amount (default).
	 *
	 * If there are subscriptions in the cart and the product is not a subscription, then
	 * set the recurring total to 0.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function set_subscription_prices_for_calculation( $price, $product ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {

			// For original calculations, we need the items price to account for sign-up fees and/or free trial
			if ( 'none' == self::$calculation_type ) {

				$sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( $product );

				// Extra check to make sure that the sign up fee is numeric before using it
				$sign_up_fee = is_numeric( $sign_up_fee ) ? (float) $sign_up_fee : 0;

				$trial_length = WC_Subscriptions_Product::get_trial_length( $product );

				if ( $trial_length > 0 ) {
					$price = $sign_up_fee;
				} else {
					$price = (float) $price + $sign_up_fee; // Casting to float for php8 compatibility.
				}
			}  // else $price = recurring amount already as WC_Product->get_price() returns subscription price

			$price = apply_filters( 'woocommerce_subscriptions_cart_get_price', $price, $product );

			// Make sure the recurring amount for any non-subscription products in the cart with a subscription is $0
		} elseif ( 'recurring_total' == self::$calculation_type ) {

			$price = 0;

		}

		return $price;
	}

	/**
	 * Sets the recurring cart key and calculation type before calculating a carts totals.
	 *
	 * @param WC_Cart $cart The cart object being calculated.
	 */
	public static function set_recurring_cart_key_before_calculate_totals( $cart ) {
		$recurring_cart_key = ! empty( $cart->recurring_cart_key ) ? $cart->recurring_cart_key : 'none';

		// Store the recurring cart key in the stack.
		array_unshift( self::$recurring_totals_calculation_stack, $recurring_cart_key );

		// Set the current recurring cart key and calculation type.
		self::set_recurring_cart_key( $recurring_cart_key );
		self::set_calculation_type( 'none' === $recurring_cart_key ? 'none' : 'recurring_total' );
	}

	/**
	 * Updates the recurring cart key and calculation type after calculating a carts totals.
	 *
	 * @param WC_Cart $cart The cart object that finished calculating it's totals.
	 */
	public static function update_recurring_cart_key_after_calculate_totals( $cart ) {
		// Remove the recurring cart key from the stack. It has finished calculating.
		array_shift( self::$recurring_totals_calculation_stack );

		$recurring_cart_key = empty( self::$recurring_totals_calculation_stack ) ? 'none' : reset( self::$recurring_totals_calculation_stack );

		self::set_recurring_cart_key( $recurring_cart_key );
		self::set_calculation_type( 'none' === $recurring_cart_key ? 'none' : 'recurring_total' );
	}

	/**
	 * Calculate the initial and recurring totals for all subscription products in the cart.
	 *
	 * We need to group subscriptions by billing schedule to make the display and creation of recurring totals sane,
	 * when there are multiple subscriptions in the cart. To do that, we use an array with keys of the form:
	 * '{billing_interval}_{billing_period}_{trial_interval}_{trial_period}_{length}_{billing_period}'. This key
	 * is used to reference WC_Cart objects for each recurring billing schedule and these are stored in the master
	 * cart with the billing schedule key.
	 *
	 * After we have calculated and grouped all recurring totals, we need to checks the structure of the subscription
	 * product prices to see whether they include sign-up fees and/or free trial periods and then recalculates the
	 * appropriate totals by using the @see self::$calculation_type flag and cloning the cart to run @see WC_Cart::calculate_totals()
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
	 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function calculate_subscription_totals( $total, $cart ) {
		// If the cart doesn't contain a subscription, skip calculating recurring totals.
		if ( ! self::cart_contains_subscription() && ! wcs_cart_contains_resubscribe() ) {
			return $total;
		}

		// We're in the middle of a recalculation, let it run.
		if ( 'none' !== self::$calculation_type ) {
			return $total;
		}

		/**
		 * If we're in the middle of calculating recurring totals, skip this calculation to avoid infinite loops.
		 * We use whether there's a recurring cart key in the calculation stack (ie has started but hasn't finished) to determine if we're in the middle calculating recurring totals.
		 */
		if ( ! empty( array_diff( self::$recurring_totals_calculation_stack, [ 'none' ] ) ) ) {
			return $total;
		}

		// Save the original cart values/totals, as we'll use this when there is no sign-up fee
		WC()->cart->total = ( $total < 0 ) ? 0 : $total;

		do_action( 'woocommerce_subscription_cart_before_grouping' );

		$subscription_groups = array();

		// Group the subscription items by their cart item key based on billing schedule
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				$subscription_groups[ self::get_recurring_cart_key( $cart_item ) ][] = $cart_item_key;
			}
		}

		do_action( 'woocommerce_subscription_cart_after_grouping' );

		$recurring_carts = array();

		// Back up the shipping method. Chances are WC is going to wipe the chosen_shipping_methods data
		WC()->session->set( 'wcs_shipping_methods', WC()->session->get( 'chosen_shipping_methods', array() ) );

		// Now let's calculate the totals for each group of subscriptions
		self::$calculation_type = 'recurring_total';

		foreach ( $subscription_groups as $recurring_cart_key => $subscription_group ) {

			// Create a clone cart to calculate and store totals for this group of subscriptions
			$recurring_cart = clone WC()->cart;
			$product        = null;

			// Set the current recurring key flag on this class, and store the recurring_cart_key to the new cart instance.
			self::$recurring_cart_key           = $recurring_cart_key;
			$recurring_cart->recurring_cart_key = $recurring_cart_key;

			// Remove any items not in this subscription group
			foreach ( $recurring_cart->get_cart() as $cart_item_key => $cart_item ) {
				if ( ! in_array( $cart_item_key, $subscription_group, true ) ) {
					unset( $recurring_cart->cart_contents[ $cart_item_key ] );
					continue;
				}

				if ( null === $product ) {
					$product = $cart_item['data'];
				}
			}

			$recurring_cart->start_date        = apply_filters( 'wcs_recurring_cart_start_date', gmdate( 'Y-m-d H:i:s' ), $recurring_cart );
			$recurring_cart->trial_end_date    = apply_filters( 'wcs_recurring_cart_trial_end_date', WC_Subscriptions_Product::get_trial_expiration_date( $product, $recurring_cart->start_date ), $recurring_cart, $product );
			$recurring_cart->next_payment_date = apply_filters( 'wcs_recurring_cart_next_payment_date', WC_Subscriptions_Product::get_first_renewal_payment_date( $product, $recurring_cart->start_date ), $recurring_cart, $product );
			$recurring_cart->end_date          = apply_filters( 'wcs_recurring_cart_end_date', WC_Subscriptions_Product::get_expiration_date( $product, $recurring_cart->start_date ), $recurring_cart, $product );

			// Before calculating recurring cart totals, store this recurring cart object
			self::set_cached_recurring_cart( $recurring_cart );

			// No fees recur (yet)
			if ( is_callable( array( $recurring_cart, 'fees_api' ) ) ) { // WC 3.2 +
				$recurring_cart->fees_api()->remove_all_fees();
			} else {
				$recurring_cart->fees = array();
			}

			$recurring_cart->fee_total = 0;
			$recurring_cart->calculate_totals();

			// Store this groups cart details
			$recurring_carts[ $recurring_cart_key ] = clone $recurring_cart;

			// And remove some other floatsam
			$recurring_carts[ $recurring_cart_key ]->removed_cart_contents = array();
			$recurring_carts[ $recurring_cart_key ]->cart_session_data     = array();

			// Keep a record of the shipping packages so we can add them to the global packages later
			self::$recurring_shipping_packages[ $recurring_cart_key ] = WC()->shipping->get_packages();
		}

		// Reset flags when we're done processing recurring carts.
		self::$calculation_type = self::$recurring_cart_key = 'none';

		// Only calculate the initial order cart shipping if we need to show shipping.
		if ( WC()->cart->show_shipping() ) {
			WC()->cart->calculate_shipping();
		}

		// We no longer need our backup of shipping methods
		unset( WC()->session->wcs_shipping_methods );

		// If there is no sign-up fee and a free trial, and no products being purchased with the subscription, we need to zero the fees for the first billing period
		$remove_fees_from_cart = ( 0 == self::get_cart_subscription_sign_up_fee() && self::all_cart_items_have_free_trial() );

		/**
		 * Allow third-parties to override whether the fees will be removed from the initial order cart.
		 *
		 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.3
		 * @param bool $remove_fees_from_cart Whether the fees will be removed. By default fees will be removed if there is no signup fee and all cart items have a trial.
		 * @param WC_Cart $cart The standard WC cart object.
		 * @param array $recurring_carts All the recurring cart objects.
		 */
		if ( apply_filters( 'wcs_remove_fees_from_initial_cart', $remove_fees_from_cart, $cart, $recurring_carts ) ) {
			$cart_fees = WC()->cart->get_fees();

			if ( wcs_is_woocommerce_pre( '3.2' ) ) {
				foreach ( $cart_fees as $fee_index => $fee ) {
					WC()->cart->fees[ $fee_index ]->amount = 0;
					WC()->cart->fees[ $fee_index ]->tax    = 0;
				}
			} else {
				foreach ( $cart_fees as $fee ) {
					$fee->amount = 0;
					$fee->tax    = 0;
					$fee->total  = 0;
				}

				WC()->cart->fees_api()->set_fees( $cart_fees );
			}
			WC()->cart->fee_total = 0;
		}

		WC()->cart->recurring_carts = $recurring_carts;

		$total = max( 0, round( WC()->cart->cart_contents_total + WC()->cart->tax_total + WC()->cart->shipping_tax_total + WC()->cart->shipping_total + WC()->cart->fee_total, WC()->cart->dp ) );

		if ( ! self::charge_shipping_up_front() ) {
			$total                         = max( 0, $total - WC()->cart->shipping_tax_total - WC()->cart->shipping_total );
			WC()->cart->shipping_taxes     = array();
			WC()->cart->shipping_tax_total = 0;
			WC()->cart->shipping_total     = 0;
		}

		return apply_filters( 'woocommerce_subscriptions_calculated_total', $total );
	}

	/**
	 * Check whether shipping should be charged on the initial order.
	 *
	 * When the cart contains a physical subscription with a free trial and no other physical items, shipping
	 * should not be charged up-front.
	 *
	 * @internal self::all_cart_items_have_free_trial() is false if non-subscription products are in the cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.4
	 */
	public static function charge_shipping_up_front() {
		return apply_filters( 'woocommerce_subscriptions_cart_shipping_up_front', ! self::all_cart_items_have_free_trial() );
	}

	/**
	 * The cart needs shipping only if it needs shipping up front and/or for recurring items.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @param boolean $needs_shipping True if shipping is needed for the cart.
	 * @return boolean
	 */
	public static function cart_needs_shipping( $needs_shipping ) {

		if ( self::cart_contains_subscription() ) {
			if ( 'none' === self::$calculation_type ) {
				$has_subscription_needing_shipping = self::cart_contains_subscriptions_needing_shipping();
				$charge_shipping_up_front          = self::charge_shipping_up_front();

				// If we have a subscription that either needs shipping, or needs shipping charging up front, force true.
				if ( $has_subscription_needing_shipping && $charge_shipping_up_front ) {
					$needs_shipping = true;
				}
			} elseif ( 'recurring_total' === self::$calculation_type ) {
				$cart           = isset( self::$cached_recurring_cart ) ? self::$cached_recurring_cart : WC()->cart;
				$needs_shipping = self::cart_contains_subscriptions_needing_shipping( $cart );
			}
		}

		return $needs_shipping;
	}

	/**
	 * The cart needs a shipping address if any item needs shipping, including recurring items.
	 *
	 * @param boolean $needs_shipping_address True if a shipping address is needed for the cart.
	 * @return boolean
	 */
	public static function cart_needs_shipping_address( $needs_shipping_address ) {
		if ( $needs_shipping_address ) {
			return $needs_shipping_address;
		}

		if ( ! wc_ship_to_billing_address_only() && self::cart_contains_subscription() ) {
			$needs_shipping_address = self::cart_contains_subscriptions_needing_shipping();
		}

		return $needs_shipping_address;
	}

	/**
	 * Remove all recurring shipping methods stored in the session (i.e. methods with a key that is a string)
	 *
	 * This is attached as a callback to hooks triggered whenever a product is removed from the cart.
	 *
	 * @param $cart_item_key string The key for a cart item about to be removed from the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.15
	 */
	public static function maybe_reset_chosen_shipping_methods( $cart_item_key ) {

		if ( isset( WC()->cart->cart_contents[ $cart_item_key ] ) ) {

			$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );

			// Remove all recurring methods
			foreach ( $chosen_methods as $key => $methods ) {
				if ( ! is_numeric( $key ) ) {
					unset( $chosen_methods[ $key ] );
				}
			}

			WC()->session->set( 'chosen_shipping_methods', $chosen_methods );
		}
	}

	/**
	 * When shipping subscriptions, changes the original package to "initial shipment".
	 *
	 * @param string $package_name Package name.
	 * @param string|int $package_id Package ID.
	 * @return array $package Package contents.
	 */
	public static function change_initial_shipping_package_name( $package_name, $package_id, $package ) {
		if ( ! self::cart_contains_subscription() || isset( $package['recurring_cart_key'] ) ) {
			return $package_name;
		}
		return __( 'Initial Shipment', 'woocommerce-subscriptions' );
	}

	/**
	 * Create a shipping package index for a given shipping package on a recurring cart.
	 *
	 * @param string $recurring_cart_key a cart key of the form returned by @see self::get_recurring_cart_key()
	 * @param int    $package_index the index of a package
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.12
	 */
	public static function get_recurring_shipping_package_key( $recurring_cart_key, $package_index ) {
		return $recurring_cart_key . '_' . $package_index;
	}

	/**
	 * Create a shipping package index for a given shipping package on a recurring cart.
	 *
	 * @return array
	 */
	public static function get_recurring_shipping_packages() {
		return self::$recurring_shipping_packages;
	}

	/**
	 * Add the shipping packages stored in @see self::$recurring_shipping_packages to WooCommerce's global
	 * set of packages in WC()->shipping->packages so that plugins attempting to get the details of recurring
	 * packages can get them with WC()->shipping->get_packages() like any other packages.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	public static function set_global_recurring_shipping_packages() {
		foreach ( self::$recurring_shipping_packages as $recurring_cart_key => $packages ) {
			foreach ( $packages as $package_index => $package ) {
				WC()->shipping->packages[ $package_index ] = $package;
			}
		}
	}

	/**
	 * Check whether all the subscription product items in the cart have a free trial.
	 *
	 * Useful for determining if certain up-front amounts should be charged.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function all_cart_items_have_free_trial() {

		$all_items_have_free_trial = true;

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				$all_items_have_free_trial = false;
				break;
			} else {
				if ( 0 == WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) ) {
					$all_items_have_free_trial = false;
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_all_cart_items_have_free_trial', $all_items_have_free_trial );
	}

	/**
	 * Check if the cart contains a subscription which requires shipping.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.4
	 */
	public static function cart_contains_subscriptions_needing_shipping( $cart = null ) {

		if ( 'no' === get_option( 'woocommerce_calc_shipping' ) ) {
			return false;
		}

		if ( null === $cart ) {
			$cart = WC()->cart;
		}

		$cart_contains_subscriptions_needing_shipping = false;

		if ( self::cart_contains_subscription() ) {
			foreach ( $cart->cart_contents as $cart_item_key => $values ) {
				$_product = $values['data'];

				if ( ! WC_Subscriptions_Product::is_subscription( $_product ) || ! $_product->needs_shipping() ) {
					continue;
				}

				if ( 'recurring_total' === self::$calculation_type && WC_Subscriptions_Product::needs_one_time_shipping( $_product ) ) {
					continue;
				}

				$cart_contains_subscriptions_needing_shipping = true;
			}
		}

		return apply_filters( 'woocommerce_cart_contains_subscriptions_needing_shipping', $cart_contains_subscriptions_needing_shipping );
	}

	/**
	 * Filters the cart contents to remove any subscriptions with free trials (or synchronised to a date in the future)
	 * to make sure no shipping amount is calculated for them.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function set_cart_shipping_packages( $packages ) {
		if ( ! self::cart_contains_subscription() ) {
			return $packages;
		}

		if ( 'none' === self::$calculation_type ) {
			foreach ( $packages as $index => $package ) {
				foreach ( $package['contents'] as $cart_item_key => $cart_item ) {
					if ( WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
						unset( $packages[ $index ]['contents'][ $cart_item_key ] );
					}
				}

				if ( empty( $packages[ $index ]['contents'] ) ) {
					unset( $packages[ $index ] );
				}
			}
		} elseif ( 'recurring_total' === self::$calculation_type ) {
			/**
			 * This logic runs for recurring carts, not the main cart.
			 */
			$new_packages = array();

			foreach ( $packages as $index => $package ) {
				$new_package = $package;

				// we need to make sure the package is different for recurring carts to bypass WC's cache
				$new_package['recurring_cart_key'] = self::$recurring_cart_key;

				// We need to track the original package index.
				$new_package['package_index'] = $index;

				foreach ( $new_package['contents'] as $cart_item_key => $cart_item ) {
					if ( WC_Subscriptions_Product::needs_one_time_shipping( $cart_item['data'] ) ) {
						$new_package['contents_cost'] -= $cart_item['line_total'];
						unset( $new_package['contents'][ $cart_item_key ] );
					}
				}

				if ( ! empty( $packages[ $index ]['contents'] ) ) {
					$new_packages[ self::get_recurring_shipping_package_key( self::$recurring_cart_key, $index ) ] = $new_package;
				}
			}

			$packages = $new_packages;
		}

		return $packages;
	}

	/**
	 * Checks whether or not the COD gateway should be available on checkout when a subscription has a free trial.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.6
	 *
	 * @param array $available_gateways The currently available payment gateways.
	 * @return array All of the available payment gateways.
	 */
	public static function check_cod_gateway_for_free_trials( $available_gateways ) {

		if ( ! self::cart_contains_free_trial() ) {
			return $available_gateways;
		}

		$all_gateways = WC()->payment_gateways->payment_gateways();

		if ( ! isset( $all_gateways['cod'] ) ) {
			return $available_gateways;
		}

		$gateway = $all_gateways['cod'];

		/**
		 * Since the COD gateway supports shipping method restrictions we run into problems with free trials.
		 * We don't make packages for free trial subscriptions and thus they have no assigned shipping
		 * method to match against the payment gateway. We can get around this limitation by abusing
		 * the fact that the user has to select a shipping method for the recurring cart.
		 */
		$packages = WC()->shipping->packages;
		self::set_global_recurring_shipping_packages();

		if ( $gateway->is_available() ) {
			$available_gateways['cod'] = $gateway;
		} else {
			// Handle the case where it was previous available but the method chosen by the recurring package
			// causes it to no longer be available.
			unset( $available_gateways['cod'] );
		}

		WC()->shipping->packages = $packages;

		return $available_gateways;
	}

	/* Formatted Totals Functions */

	/**
	 * Returns the subtotal for a cart item including the subscription period and duration details
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function get_formatted_product_subtotal( $product_subtotal, $product, $quantity, $cart ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) && ! wcs_cart_contains_renewal() ) {
			$product_price_filter = is_a( $product, 'WC_Product_Variation' ) ? 'woocommerce_product_variation_get_price' : 'woocommerce_product_get_price';

			// Avoid infinite loop
			remove_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11 );

			add_filter( $product_price_filter, 'WC_Subscriptions_Product::get_sign_up_fee_filter', 100, 2 );

			// And get the appropriate sign up fee string
			$sign_up_fee_string = $cart->get_product_subtotal( $product, $quantity );

			remove_filter( $product_price_filter, 'WC_Subscriptions_Product::get_sign_up_fee_filter', 100 );

			add_filter( 'woocommerce_cart_product_subtotal', __CLASS__ . '::get_formatted_product_subtotal', 11, 4 );

			$product_subtotal = WC_Subscriptions_Product::get_price_string(
				$product,
				array(
					'price'           => $product_subtotal,
					'sign_up_fee'     => $sign_up_fee_string,
					'tax_calculation' => wcs_is_woocommerce_pre( '4.4' ) ? WC()->cart->tax_display_cart : WC()->cart->get_tax_price_display_mode(),
				)
			);

			$inc_tax_or_vat_string = WC()->countries->inc_tax_or_vat();
			$ex_tax_or_vat_string  = WC()->countries->ex_tax_or_vat();

			if ( ! empty( $inc_tax_or_vat_string ) && false !== strpos( $product_subtotal, $inc_tax_or_vat_string ) ) {
				$product_subtotal = str_replace( WC()->countries->inc_tax_or_vat(), '', $product_subtotal ) . ' <small class="tax_label">' . WC()->countries->inc_tax_or_vat() . '</small>';
			}
			if ( ! empty( $ex_tax_or_vat_string ) && false !== strpos( $product_subtotal, $ex_tax_or_vat_string ) ) {
				$product_subtotal = str_replace( WC()->countries->ex_tax_or_vat(), '', $product_subtotal ) . ' <small class="tax_label">' . WC()->countries->ex_tax_or_vat() . '</small>';
			}

			$product_subtotal = '<span class="subscription-price">' . $product_subtotal . '</span>';
		}

		return $product_subtotal;
	}

	/*
	 * Helper functions for extracting the details of subscriptions in the cart
	 */

	/**
	 * Checks the cart to see if it contains a subscription product.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @return boolean
	 */
	public static function cart_contains_subscription() {
		if ( ! empty( WC()->cart->cart_contents ) && ! wcs_cart_contains_renewal() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Checks the cart to see if it contains a subscription product with a free trial
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function cart_contains_free_trial() {

		$cart_contains_free_trial = false;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::get_trial_length( $cart_item['data'] ) > 0 ) {
					$cart_contains_free_trial = true;
					break;
				}
			}
		}

		return $cart_contains_free_trial;
	}

	/**
	 * Gets the cart calculation type flag
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_calculation_type() {
		return self::$calculation_type;
	}

	/**
	 * Sets the cart calculation type flag
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function set_calculation_type( $calculation_type ) {
		self::$calculation_type = $calculation_type;
		return $calculation_type;
	}

	/**
	 * Sets the recurring cart key flag.
	 *
	 * @internal While this is indeed stored to the cart object, some hooks such as woocommerce_cart_shipping_packages
	 *           do not have access to this property. So we can properly set package IDs we make use of this flag.
	 *
	 * @param string $recurring_cart_key Recurring cart key used to identify the current recurring cart being processed.
	 */
	public static function set_recurring_cart_key( $recurring_cart_key ) {
		self::$recurring_cart_key = $recurring_cart_key;
		return $recurring_cart_key;
	}

	/**
	 * Update the cached recurring cart.
	 *
	 * @param \WC_Cart $recurring_cart Cart object.
	 */
	public static function set_cached_recurring_cart( $recurring_cart ) {
		self::$cached_recurring_cart = $recurring_cart;
	}

	/**
	 * Gets the subscription sign up fee for the cart and returns it
	 *
	 * Currently short-circuits to return just the sign-up fee of the first subscription, because only
	 * one subscription can be purchased at a time.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function get_cart_subscription_sign_up_fee() {

		$sign_up_fee = 0;

		if ( self::cart_contains_subscription() || wcs_cart_contains_renewal() ) {

			$renewal_item = wcs_cart_contains_renewal();

			foreach ( WC()->cart->cart_contents as $cart_item ) {

				// Renewal items do not have sign-up fees
				if ( $renewal_item == $cart_item ) {
					continue;
				}

				$cart_item_sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( $cart_item['data'] );
				// Extra check to make sure that the sign up fee is numeric before using it
				$cart_item_sign_up_fee = is_numeric( $cart_item_sign_up_fee ) ? (float) $cart_item_sign_up_fee : 0;

				$sign_up_fee += $cart_item_sign_up_fee;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_sign_up_fee', $sign_up_fee );
	}

	/**
	 * Check whether the cart needs payment even if the order total is $0
	 *
	 * @param bool    $needs_payment The existing flag for whether the cart needs payment or not.
	 * @param WC_Cart $cart The WooCommerce cart object.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs_payment, $cart ) {

		// Skip checks if needs payment is already set or cart total not 0.
		if ( false !== $needs_payment || 0 != $cart->total ) {
			return $needs_payment;
		}

		// Skip checks if cart has no subscriptions.
		if ( ! self::cart_contains_subscription() ) {
			return $needs_payment;
		}

		// Skip checks if cart contains subscription switches or automatic payments are disabled.
		if ( false !== wcs_cart_contains_switches( 'any' ) || wcs_is_manual_renewal_required() ) {
			return $needs_payment;
		}

		$recurring_total                  = 0;
		$is_one_period                    = true;
		$contains_synced                  = false;
		$contains_expiring_limited_coupon = false;

		if ( ! empty( WC()->cart->recurring_carts ) ) {
			foreach ( WC()->cart->recurring_carts as $recurring_cart ) {
				$recurring_total                 += $recurring_cart->total;
				$subscription_length              = wcs_cart_pluck( $recurring_cart, 'subscription_length' );
				$contains_synced                  = $contains_synced || (bool) WC_Subscriptions_Synchroniser::cart_contains_synced_subscription( $recurring_cart );
				$contains_expiring_limited_coupon = $contains_expiring_limited_coupon || ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) && WCS_Limited_Recurring_Coupon_Manager::recurring_cart_contains_expiring_coupon( $recurring_cart ) );

				if ( 0 == $subscription_length || wcs_cart_pluck( $recurring_cart, 'subscription_period_interval' ) != $subscription_length ) {
					$is_one_period = false;
				}
			}
		}

		$needs_trial_payment = self::cart_contains_free_trial();

		if ( $contains_expiring_limited_coupon || $recurring_total > 0 && ( ! $is_one_period || $needs_trial_payment || $contains_synced ) ) {
			$needs_payment = true;
		}

		return $needs_payment;
	}

	/**
	 * Make sure cart product prices correctly include/exclude taxes.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.8
	 */
	public static function cart_product_price( $price, $product ) {

		if ( WC_Subscriptions_Product::is_subscription( $product ) ) {
			$tax_price_display_mode = wcs_is_woocommerce_pre( '4.4' ) ? WC()->cart->tax_display_cart : WC()->cart->get_tax_price_display_mode();
			$price                  = WC_Subscriptions_Product::get_price_string(
				$product,
				array(
					'price'           => $price,
					'tax_calculation' => $tax_price_display_mode,
				)
			);
		}

		return $price;
	}

	/**
	 * Displays the recurring totals for items in the cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function display_recurring_totals() {

		if ( self::cart_contains_subscription() && ! empty( WC()->cart->recurring_carts ) ) {
			// We only want shipping for recurring amounts, and they need to be calculated again here.
			self::$calculation_type       = 'recurring_total';
			$carts_with_multiple_payments = 0;

			foreach ( WC()->cart->recurring_carts as $recurring_cart ) {
				// Cart contains more than one payment.
				if ( 0 != $recurring_cart->next_payment_date ) {
					$carts_with_multiple_payments++;
				}
			}

			if ( apply_filters( 'woocommerce_subscriptions_display_recurring_totals', $carts_with_multiple_payments >= 1 ) ) {
				wc_get_template(
					'checkout/recurring-totals.php',
					array(
						'shipping_methods'             => array(),
						'recurring_carts'              => WC()->cart->recurring_carts,
						'carts_with_multiple_payments' => $carts_with_multiple_payments,
					),
					'',
					WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' )
				);
			}

			self::$calculation_type = 'none';
		}
	}

	/**
	 * Construct a cart key based on the billing schedule of a subscription product.
	 *
	 * Subscriptions groups products by billing schedule when calculating cart totals, so that shipping and other "per order" amounts
	 * can be calculated for each group of items for each renewal. This method constructs a cart key based on the billing schedule
	 * to allow products on the same billing schedule to be grouped together - free trials and synchronisation is accounted for by
	 * using the first renewal date (if any) for the subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_recurring_cart_key( $cart_item, $renewal_time = '' ) {
		return apply_filters( 'woocommerce_subscriptions_recurring_cart_key', wcs_get_subscription_grouping_key( $cart_item['data'], $renewal_time ), $cart_item );
	}

	/**
	 * When calculating shipping for recurring carts, return a revised list of shipping methods that apply to this recurring cart.
	 *
	 * When WooCommerce determines the taxable address for local pick up methods, we only want to return pick up shipping methods
	 * chosen for the recurring cart being calculated instead of all methods.
	 *
	 * @param array $shipping_methods
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	public static function filter_recurring_cart_chosen_shipping_method( $shipping_methods ) {

		if ( 'recurring_total' === self::$calculation_type && 'none' !== self::$recurring_cart_key ) {

			$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );

			$standard_package_methods        = array();
			$recurring_cart_shipping_methods = array();

			foreach ( $chosen_shipping_methods as $key => $method ) {

				if ( is_numeric( $key ) ) {
					$standard_package_methods[ $key ] = $method;

				} elseif ( strpos( $key, self::$recurring_cart_key ) !== false ) {

					$recurring_cart_shipping_methods[ $key ] = $method;
				}
			}

			// pick which chosen methods apply to this recurring cart. Defaults to standard methods if there is no specific recurring cart shipping methods chosen.
			$applicable_chosen_shipping_methods = ( empty( $recurring_cart_shipping_methods ) ) ? $standard_package_methods : $recurring_cart_shipping_methods;

			$shipping_methods = array_intersect( $applicable_chosen_shipping_methods, $shipping_methods );
		}

		return $shipping_methods;
	}

	/**
	 * Validate the chosen recurring shipping methods for each recurring shipping package.
	 * Ensures there is at least one chosen shipping method and that the chosen method is valid considering the available
	 * package rates.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.14
	 */
	public static function validate_recurring_shipping_methods() {
		$shipping_methods     = WC()->checkout()->shipping_methods;
		$added_invalid_notice = false;
		$standard_packages    = WC()->shipping->get_packages();

		// Temporarily store the current calculation type and recurring cart key so we can restore them later.
		$calculation_type        = self::$calculation_type;
		$recurring_cart_key_flag = self::$recurring_cart_key;
		$cached_recurring_cart   = self::$cached_recurring_cart;

		self::set_calculation_type( 'recurring_total' );

		foreach ( WC()->cart->recurring_carts as $recurring_cart_key => $recurring_cart ) {
			if ( false === $recurring_cart->needs_shipping() || 0 == $recurring_cart->next_payment_date ) {
				continue;
			}

			// Set the recurring cart flags so shipping calculations have the recurring cart as context.
			self::set_recurring_cart_key( $recurring_cart_key );
			self::set_cached_recurring_cart( $recurring_cart );

			foreach ( $recurring_cart->get_shipping_packages() as $recurring_cart_package_key => $recurring_cart_package ) {
				$package_index = isset( $recurring_cart_package['package_index'] ) ? $recurring_cart_package['package_index'] : 0;
				$package       = WC()->shipping->calculate_shipping_for_package( $recurring_cart_package );

				$package_rates_match = false;
				if ( isset( $standard_packages[ $package_index ] ) ) {
					// phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
					$package_rates_match = apply_filters( 'wcs_recurring_shipping_package_rates_match_standard_rates', $package['rates'] == $standard_packages[ $package_index ]['rates'], $package['rates'], $standard_packages[ $package_index ]['rates'], $recurring_cart_key );
				}

				if ( $package_rates_match ) {
					// The recurring package rates match the initial package rates, there won't be a selected shipping method for this recurring cart package move on to the next package.
					if ( apply_filters( 'wcs_cart_totals_shipping_html_price_only', true, $package, $recurring_cart ) ) {
						continue;
					}
				}

				// If the chosen shipping method is not available for this recurring cart package, display an error and unset the selected method.
				if ( ! isset( $package['rates'][ $shipping_methods[ $recurring_cart_package_key ] ] ) ) {
					if ( ! $added_invalid_notice ) {
						wc_add_notice( __( 'Invalid recurring shipping method.', 'woocommerce-subscriptions' ), 'error' );
						$added_invalid_notice = true;
					}

					$shipping_methods[ $recurring_cart_package_key ] = '';
				}
			}
		}

		// If there was an invalid recurring shipping method found, we need to apply the changes to WC()->checkout()->shipping_methods.
		if ( $added_invalid_notice ) {
			WC()->checkout()->shipping_methods = $shipping_methods;
		}

		// Restore the calculation type and recurring cart key.
		self::set_calculation_type( $calculation_type );
		self::set_recurring_cart_key( $recurring_cart_key_flag );
		self::set_cached_recurring_cart( $cached_recurring_cart );
	}

	/**
	 * Checks the cart to see if it contains a specific product.
	 *
	 * @param int $product_id The product ID or variation ID to look for.
	 * @return bool Whether the product is in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	public static function cart_contains_product( $product_id ) {

		$cart_contains_product = false;

		if ( ! empty( WC()->cart->cart_contents ) ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( wcs_get_canonical_product_id( $cart_item ) === $product_id ) {
					$cart_contains_product = true;
					break;
				}
			}
		}

		return $cart_contains_product;
	}

	/**
	 * Checks the cart to see if it contains any subscription product other than a specific product.
	 *
	 * @param int $product_id The product ID or variation ID other than which to look for.
	 * @return bool Whether another subscription product is in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.5
	 */
	public static function cart_contains_other_subscription_products( $product_id ) {

		$cart_contains_other_subscription_products = false;

		if ( ! empty( WC()->cart->cart_contents ) && WC_Subscriptions_Product::is_subscription( $product_id ) ) {
			$is_subscription = WC_Subscriptions_Product::is_subscription( $product_id );
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( wcs_get_canonical_product_id( $cart_item ) !== $product_id ) {
					$cart_contains_other_subscription_products = true;
					break;
				}
			}
		}

		return $cart_contains_other_subscription_products;
	}

	/**
	 * When calculating the free shipping method availability, WC uses the WC->cart object. During shipping calculations for
	 * recurring carts we need the recurring cart's total and coupons to be the base for checking its availability
	 *
	 * @param bool  $is_available
	 * @param array $package
	 * @return bool $is_available a revised version of is_available based off the recurring cart object
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.20
	 */
	public static function maybe_recalculate_shipping_method_availability( $is_available, $package ) {

		if ( ! isset( $package['recurring_cart_key'], self::$cached_recurring_cart ) || $package['recurring_cart_key'] !== self::$cached_recurring_cart->recurring_cart_key ) {
			return $is_available;
		}

		if ( ! wcs_is_woocommerce_pre( '3.2' ) ) {
			wcs_doing_it_wrong( __METHOD__, 'This method should no longer be used on WC 3.2.0 and newer. Use WC_Subscriptions_Cart::recalculate_shipping_method_availability() and pass the specific shipping method as the third parameter instead.', '2.5.6' );
		}

		// Take a copy of the WC global cart object so we can temporarily set it to base shipping method availability on the cached recurring cart
		$global_cart      = WC()->cart;
		WC()->cart        = self::$cached_recurring_cart;
		$shipping_methods = WC()->shipping->get_shipping_methods();
		$is_available     = false;

		remove_filter( 'woocommerce_shipping_free_shipping_is_available', __METHOD__ );

		foreach ( $shipping_methods as $shipping_method ) {
			if ( 'free_shipping' === $shipping_method->id && $shipping_method->get_instance_id() && $shipping_method->is_available( $package ) ) {
				$is_available = true;
				break;
			}
		}

		add_filter( 'woocommerce_shipping_free_shipping_is_available', __METHOD__, 10, 2 );

		WC()->cart = $global_cart;

		return $is_available;
	}

	/**
	 * Calculates whether a shipping method is available for the recurring cart.
	 *
	 * By default WooCommerce core checks the initial cart for shipping method availability. For recurring carts,
	 * shipping method availability is based whether the recurring total and coupons meet the requirements.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.6
	 *
	 * @param bool               $is_available Whether the shipping method is available or not.
	 * @param array              $package a shipping package.
	 * @param WC_Shipping_Method $shipping_method An instance of a shipping method.
	 * @return bool Whether the shipping method is available for the recurring cart or not.
	 */
	public static function recalculate_shipping_method_availability( $is_available, $package, $shipping_method ) {
		if ( ! isset( $package['recurring_cart_key'], self::$cached_recurring_cart ) || $package['recurring_cart_key'] !== self::$cached_recurring_cart->recurring_cart_key ) {
			return $is_available;
		}

		// Take a copy of the WC global cart object so we can temporarily set it to base shipping method availability on the cached recurring cart
		$global_cart = WC()->cart;
		WC()->cart   = self::$cached_recurring_cart;

		remove_filter( 'woocommerce_shipping_free_shipping_is_available', __METHOD__ );
		$is_available = $shipping_method->is_available( $package );
		add_filter( 'woocommerce_shipping_free_shipping_is_available', __METHOD__, 10, 3 );

		// Restore the global cart object.
		WC()->cart = $global_cart;

		return $is_available;
	}

	/**
	 * Allow third-parties to apply fees which apply to the cart to recurring carts.
	 *
	 * @param WC_Cart $cart
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.16
	 */
	public static function apply_recurring_fees( $cart ) {

		if ( ! empty( $cart->recurring_cart_key ) ) {

			foreach ( WC()->cart->get_fees() as $fee ) {

				if ( apply_filters( 'woocommerce_subscriptions_is_recurring_fee', false, $fee, $cart ) ) {
					if ( is_callable( array( $cart, 'fees_api' ) ) ) { // WC 3.2 +
						$cart->fees_api()->add_fee( $fee );
					} else {
						$cart->add_fee( $fee->name, $fee->amount, $fee->taxable, $fee->tax_class );
					}
				}
			}
		}
	}

	/**
	 * Update the chosen recurring package shipping methods from posted checkout form data.
	 *
	 * Between requests, the presence of recurring package chosen shipping methods in posted
	 * checkout data can change. For example, when the number of available shipping methods
	 * change and cause the hidden elements (generated by @see wcs_cart_print_shipping_input())
	 * to be displayed or not displayed.
	 *
	 * When this occurs, we need to remove those chosen shipping methods from the session so
	 * that those packages no longer use the previously selected shipping method.
	 *
	 * @param string $encoded_form_data Encoded checkout form data.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 */
	public static function update_chosen_shipping_methods( $encoded_form_data ) {
		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );

		parse_str( $encoded_form_data, $form_data );

		foreach ( $chosen_shipping_methods as $package_index => $method ) {
			// Remove the chosen shipping methods for recurring packages which are no longer present in posted checkout data.
			if ( ! is_numeric( $package_index ) && ! isset( $form_data['shipping_method'][ $package_index ] ) ) {
				unset( $chosen_shipping_methods[ $package_index ] );
			}
		}

		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
	}

	/**
	 * Removes all subscription products from the shopping cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function remove_subscriptions_from_cart() {

		foreach ( WC()->cart->cart_contents as $cart_item_key => $cart_item ) {
			if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				WC()->cart->set_quantity( $cart_item_key, 0 );
			}
		}
	}

	/**
	 * Records the cart item base location tax total for later storage.
	 *
	 * If the customer is outside of the base location, WC core removes the taxes
	 * which apply to the base location. @see WC_Cart_Totals::adjust_non_base_location_price().
	 *
	 * We need to record these base tax rates to be able to honour grandfathered subscription
	 * recurring prices in renewal carts.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.10
	 * @param WC_Cart $cart The cart object. Could be the global (initial cart) or a recurring cart.
	 */
	public static function record_base_tax_rates( $cart ) {
		// We only need to record the tax rates on recurring carts when prices are reduced by tax applicable to the base store location.
		if ( ! isset( $cart->recurring_cart_key ) || ! apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) || ! wc_prices_include_tax() ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			// Skip non-taxable items
			if ( 'taxable' !== $cart_item['data']->get_tax_status() ) {
				continue;
			}

			$product = $cart_item['data'];

			// Get the taxes which apply to the store's base location and to the customer.
			$base_tax_rates       = WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
			$applicable_tax_rates = WC_Tax::get_rates( $product->get_tax_class(), $cart->get_customer() );

			// We only need to keep track if the taxes applicable to the customer are different to the taxes which apply to the store's base location.
			if ( $applicable_tax_rates !== $base_tax_rates ) {
				$cart->cart_contents[ $cart_item_key ]['_subtracted_base_location_taxes'] = WC_Tax::calc_tax( $product->get_price(), $base_tax_rates, true );
				$cart->cart_contents[ $cart_item_key ]['_subtracted_base_location_rates'] = $base_tax_rates;
			}
		}
	}

	/**
	 * Set the chosen shipping method for recurring cart calculations
	 *
	 * In WC_Shipping::calculate_shipping(), WooCommerce tries to determine the chosen shipping method
	 * based on the package index and stores rates. However, for recurring cart shipping selection, we
	 * use the recurring cart key instead of numeric index. Therefore, we need to hook in to override
	 * the default shipping method when WooCommerce could not find a matching shipping method.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.12
	 *
	 * @param string $default_method the default shipping method for the customer/store returned by WC_Shipping::get_default_method()
	 * @param array  $available_methods set of shipping rates for this calculation
	 * @param int    $package_index WC doesn't pass the package index to callbacks on the 'woocommerce_shipping_chosen_method' filter (yet) so we set a default value of 0 for it in the function params
	 *
	 * @return string
	 */
	public static function set_chosen_shipping_method( $default_method, $available_methods, $package_index = 0 ) {
		$chosen_methods             = WC()->session->get( 'chosen_shipping_methods', array() );
		$recurring_cart_package_key = self::get_recurring_shipping_package_key( self::$recurring_cart_key, $package_index );

		if ( 'none' !== self::$recurring_cart_key && isset( $chosen_methods[ $recurring_cart_package_key ], $available_methods[ $chosen_methods[ $recurring_cart_package_key ] ] ) ) {
			$default_method = $chosen_methods[ $recurring_cart_package_key ];

		// Set the chosen shipping method (if available) to workaround WC_Shipping::get_default_method() setting the default shipping method whenever method count changes
		} elseif ( isset( $chosen_methods[ $package_index ], $available_methods[ $chosen_methods[ $package_index ] ] ) && $default_method !== $chosen_methods[ $package_index ] ) {
			$default_method = $chosen_methods[ $package_index ];
		}

		return $default_method;
	}

	/**
	 * Redirects the customer to the cart after they add a subscription to the cart.
	 *
	 * Only enabled if multiple checkout is not enabled.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param string $url The cart redirect $url.
	 * @return string $url.
	 */
	public static function add_to_cart_redirect( $url ) {
		if ( ! isset( $_REQUEST['add-to-cart'] ) || ! is_numeric( $_REQUEST['add-to-cart'] ) ) {
			return $url;
		}

		// If product is of the subscription type
		if ( ! WC_Subscriptions_Product::is_subscription( absint( $_REQUEST['add-to-cart'] ) ) ) {
			return $url;
		}

		// Redirect to checkout if mixed checkout is disabled
		if ( 'yes' === get_option( WC_Subscriptions_Admin::$option_prefix . '_multiple_purchase', 'no' ) ) {
			return $url;
		}

		$quantity   = isset( $_REQUEST['quantity'] ) ? wc_clean( wp_unslash( $_REQUEST['quantity'] ) ) : 1;
		$product_id = wc_clean( wp_unslash( $_REQUEST['add-to-cart'] ) );

		$add_to_cart_notice = wc_add_to_cart_message( array( $product_id => $quantity ), true, true );

		if ( wc_has_notice( $add_to_cart_notice ) ) {
			$notices                  = wc_get_notices();
			$add_to_cart_notice_index = array_search( $add_to_cart_notice, $notices['success'] );

			unset( $notices['success'][ $add_to_cart_notice_index ] );
			wc_set_notices( $notices );
		}

		return wc_get_checkout_url();
	}

	/* Deprecated */

	/**
	 * Calculates the shipping rates for a package.
	 *
	 * This function will check cached rates based on a hash of the package contents to avoid re-calculation per page load.
	 * If there are no rates stored in the cache for this package, it will fall back to @see WC_Shipping::calculate_shipping_for_package()
	 *
	 * @deprecated 5.4.0
	 *
	 * @param array $package A shipping package in the form returned by @see WC_Cart->get_shipping_packages()
	 * @return array $package
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.18
	 */
	public static function get_calculated_shipping_for_package( $package ) {
		_deprecated_function( __METHOD__, 'subscriptions-core 5.4.0', 'WC()->shipping->calculate_shipping_for_package()' );
		return WC()->shipping->calculate_shipping_for_package( $package );
	}

	/**
	 * Cache the package rates calculated by @see WC_Shipping::calculate_shipping_for_package() to avoid multiple calls of calculate_shipping_for_package() per request.
	 *
	 * @deprecated 5.4.0
	 *
	 * @param array $rates A set of WC_Shipping_Rate objects.
	 * @param array $package A shipping package in the form returned by @see WC_Cart->get_shipping_packages()
	 * @return array $rates An unaltered set of WC_Shipping_Rate objects passed to the function
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.18
	 */
	public static function cache_package_rates( $rates, $package ) {
		_deprecated_function( __METHOD__, 'subscriptions-core 5.4.0' );
		return $rates;
	}

	/**
	 * Don't allow new subscription products to be added to the cart if it contains a subscription renewal already.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function check_valid_add_to_cart( $is_valid, $product_id, $quantity, $variation_id = '', $variations = array(), $item_data = array() ) {
		_deprecated_function( __METHOD__, '2.6.0', 'WC_Subscriptions_Cart_Validator::check_valid_add_to_cart' );

		if ( $is_valid && ! isset( $item_data['subscription_renewal'] ) && wcs_cart_contains_renewal() && WC_Subscriptions_Product::is_subscription( $product_id ) ) {

			wc_add_notice( __( 'That subscription product can not be added to your cart as it already contains a subscription renewal.', 'woocommerce-subscriptions' ), 'error' );
			$is_valid = false;
		}

		return $is_valid;
	}

	/**
	 * Make sure cart totals are calculated when the cart widget is populated via the get_refreshed_fragments() method
	 * so that @see self::get_formatted_cart_subtotal() returns the correct subtotal price string.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.11
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 */
	public static function pre_get_refreshed_fragments() {
		wcs_deprecated_function( __METHOD__, '2.5.0' );
		if ( defined( 'DOING_AJAX' ) && true === DOING_AJAX && ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Checks the cart to see if it contains a subscription product renewal.
	 *
	 * Returns the cart_item containing the product renewal, else false.
	 *
	 * @param string $role The role of the cart item to check for.
	 * @return array|false The cart item containing the renewal, else false.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function cart_contains_subscription_renewal( $role = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_cart_contains_renewal( $role )' );
		return wcs_cart_contains_renewal();
	}

	/**
	 * Checks the cart to see if it contains a subscription product renewal.
	 *
	 * Returns the cart_item containing the product renewal, else false.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4
	 */
	public static function cart_contains_failed_renewal_order_payment() {
		_deprecated_function( __METHOD__, '2.0', 'wcs_cart_contains_failed_renewal_order_payment()' );
		return wcs_cart_contains_failed_renewal_order_payment();
	}


	/**
	 * Restore renewal flag when cart is reset and modify Product object with
	 * renewal order related info
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function get_cart_item_from_session( $session_data, $values, $key ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::get_cart_item_from_session( $session_data, $values, $key )' );
	}

	/**
	 * For subscription renewal via cart, use original order discount
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function before_calculate_totals( $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::set_renewal_discounts( $cart )' );
	}

	/**
	 * For subscription renewal via cart, previously adjust item price by original order discount
	 *
	 * No longer required as of 1.3.5 as totals are calculated correctly internally.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function get_discounted_price_for_renewal( $price, $values, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::get_discounted_price_for_renewal( $price, $values, $cart )' );
	}

	/**
	 * Returns a string with the cart discount and subscription period.
	 *
	 * @return mixed formatted price or false if there are none
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_formatted_discounts_before_tax( $discount, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $discount;
	}

	/**
	 * Gets the order discount amount - these are applied after tax
	 *
	 * @return mixed formatted price or false if there are none
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_formatted_discounts_after_tax( $discount, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $discount;
	}

	/**
	 * Returns an individual coupon's formatted discount amount for WooCommerce 2.1+
	 *
	 * @param string $discount_html String of the coupon's discount amount
	 * @param string $coupon WC_Coupon object for the coupon to which this line item relates
	 * @return string formatted subscription price string if the cart includes a coupon being applied to recurring amount
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4.6
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function cart_coupon_discount_amount_html( $discount_html, $coupon ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $discount_html;
	}

	/**
	 * Returns individual coupon's formatted discount amount for WooCommerce 2.1+
	 *
	 * @param string $cart_totals_fee_html String of the coupon's discount amount
	 * @param string $fee WC_Coupon object for the coupon to which this line item relates
	 * @return string formatted subscription price string if the cart includes a coupon being applied to recurring amount
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4.6
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function cart_totals_fee_html( $cart_totals_fee_html, $fee ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $cart_totals_fee_html;
	}

	/**
	 * Includes the sign-up fee total in the cart total (after calculation).
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.10
	 * @return string formatted price
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_formatted_cart_total( $cart_contents_total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $cart_contents_total;
	}

	/**
	 * Includes the sign-up fee subtotal in the subtotal displayed in the cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_formatted_cart_subtotal( $cart_subtotal, $compound, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $cart_subtotal;
	}

	/**
	 * Returns an array of taxes merged by code, formatted with recurring amount ready for output.
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_recurring_tax_totals( $tax_totals, $cart ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return apply_filters( 'woocommerce_cart_recurring_tax_totals', $tax_totals, $cart );
	}

	/**
	 * Returns a string of the sum of all taxes in the cart for initial payment and
	 * recurring amount.
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4.10
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_taxes_total_html( $total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total;
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it.
	 *
	 * @return string Formatted subscription price string for the cart total.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_formatted_total( $total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total;
	}

	/**
	 * Appends the cart subscription string to a cart total using the @see self::get_cart_subscription_string and then returns it.
	 *
	 * @return string Formatted subscription price string for the cart total.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_formatted_total_ex_tax( $total_ex_tax ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total_ex_tax;
	}

	/**
	 * Returns an array of the recurring total fields
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_recurring_totals_fields() {
		_deprecated_function( __METHOD__, '2.0', 'recurring total values stored in WC()->cart->recurring_carts' );
		return array();
	}

	/**
	 * Gets the subscription period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single period for the entire cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_cart_subscription_period() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$period = '';

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$period = WC_Subscriptions_Product::get_period( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_period', $period );
	}

	/**
	 * Gets the subscription period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single interval for the entire cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_cart_subscription_interval() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$interval = 0;

		foreach ( WC()->cart->cart_contents as $cart_item ) {
			if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
				$interval = WC_Subscriptions_Product::get_interval( $cart_item['data'] );
				break;
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_interval', $interval );
	}

	/**
	 * Gets the subscription length from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single length for the entire cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_cart_subscription_length() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$length = 0;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$length = WC_Subscriptions_Product::get_length( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_length', $length );
	}

	/**
	 * Gets the subscription length from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single trial length for the entire cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_cart_subscription_trial_length() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$trial_length = 0;

		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$trial_length = WC_Subscriptions_Product::get_trial_length( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_trial_length', $trial_length );
	}

	/**
	 * Gets the subscription trial period from the cart and returns it as an array (eg. array( 'month', 'day' ) )
	 *
	 * Deprecated because a cart can now contain multiple subscription products, so there is no single trial period for the entire cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_cart_subscription_trial_period() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$trial_period = '';

		// Get the original trial period
		if ( self::cart_contains_subscription() ) {
			foreach ( WC()->cart->cart_contents as $cart_item ) {
				if ( WC_Subscriptions_Product::is_subscription( $cart_item['data'] ) ) {
					$trial_period = WC_Subscriptions_Product::get_trial_period( $cart_item['data'] );
					break;
				}
			}
		}

		return apply_filters( 'woocommerce_subscriptions_cart_trial_period', $trial_period );
	}

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return float price
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_recurring_cart_contents_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			if ( ! $cart->prices_include_tax ) {
				$recurring_total += $cart->cart_contents_total;
			} else {
				$recurring_total += $cart->cart_contents_total + $cart->tax_total;
			}
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring item subtotal amount less tax for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_subtotal_ex_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->subtotal_ex_tax;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring item subtotal amount for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_subtotal() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->subtotal;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of cart discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring cart discount amount for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_discount_cart() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->discount_cart;
		}

		return $recurring_total;
	}

	/**
	 * Returns the cart discount tax amount for WC 2.3 and newer
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_recurring_discount_cart_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->discount_cart_tax;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of total discount that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring discount amount for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_discount_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->discount_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns the amount of shipping tax that is recurring. As shipping only applies
	 * to recurring payments, and only 1 subscription can be purchased at a time,
	 * this is equal to @see WC_Cart::$shipping_tax_total
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring shipping tax amount for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_shipping_tax_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->shipping_tax_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns the recurring shipping price . As shipping only applies to recurring
	 * payments, and only 1 subscription can be purchased at a time, this is
	 * equal to @see WC_Cart::shipping_total
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring shipping amount for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_shipping_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->shipping_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns an array of taxes on an order with their recurring totals.
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return array Array of tax_id => tax_amounts for items in the cart
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_taxes() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$taxes = array();

		$recurring_fees = array();

		foreach ( WC()->cart->recurring_carts as $cart ) {
			foreach ( array_keys( $cart->taxes + $cart->shipping_taxes ) as $key ) {
				$taxes[ $key ] = ( isset( $cart->shipping_taxes[ $key ] ) ? $cart->shipping_taxes[ $key ] : 0 ) + ( isset( $cart->taxes[ $key ] ) ? $cart->taxes[ $key ] : 0 );
			}
		}

		return $taxes;
	}

	/**
	 * Returns an array of recurring fees.
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return array Array of fee_id => fee_details for items in the cart
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4.9
	 */
	public static function get_recurring_fees() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_fees = array();

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_fees = array_merge( $recurring_fees, $cart->get_fees() );
		}

		return $recurring_fees;
	}

	/**
	 * Get tax row amounts with or without compound taxes includes
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring tax amount tax for items in the cart (maybe not including compound taxes)
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_taxes_total( $compound = true ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			foreach ( $cart->taxes as $key => $tax ) {
				if ( ! $compound && WC_Tax::is_compound( $key ) ) {
					continue;
				}
				$recurring_total += $tax;
			}
			foreach ( $cart->shipping_taxes as $key => $tax ) {
				if ( ! $compound && WC_Tax::is_compound( $key ) ) {
					continue;
				}
				$recurring_total += $tax;
			}
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of total tax on an order that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring tax amount tax for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_total_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->tax_total;
		}

		return $recurring_total;
	}

	/**
	 * Returns the proportion of total before tax on an order that is recurring for the product specified with $product_id
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring amount less tax for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_total_ex_tax() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return self::get_recurring_total() - self::get_recurring_total_tax() - self::get_recurring_shipping_tax_total();
	}

	/**
	 * Returns the price per period for a subscription in an order.
	 *
	 * Deprecated because the cart can now contain subscriptions on multiple billing schedules so there is no one "total"
	 *
	 * @return double The total recurring amount for items in the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_recurring_total() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total += $cart->get_total();
		}

		return $recurring_total;
	}

	/**
	 * Calculate the total amount of recurring shipping needed.  Removes any item from the calculation that
	 * is not a subscription and calculates the totals.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function calculate_recurring_shipping() {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		$recurring_total = 0;

		foreach ( WC()->cart->recurring_carts as $cart ) {
			$recurring_total = $cart->shipping_total;
		}

		return $recurring_total;
	}

	/**
	 * Creates a string representation of the subscription period/term for each item in the cart
	 *
	 * @param string $initial_amount The initial amount to be displayed for the subscription as passed through the @see woocommerce_price() function.
	 * @param float  $recurring_amount The price to display in the subscription.
	 * @param array  $args (optional) Flags to customise  to display the trial and length of the subscription. Default to false - don't display.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_cart_subscription_string( $initial_amount, $recurring_amount, $args = array() ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );

		if ( ! is_array( $args ) ) {
			_deprecated_argument( __CLASS__ . '::' . __FUNCTION__, '1.4', 'Third parameter is now an array of name => value pairs. Use array( "include_lengths" => true ) instead.' );
			$args = array(
				'include_lengths' => $args,
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'include_lengths' => false,
				'include_trial'   => true,
			)
		);

		$subscription_details = array(
			'initial_amount'        => $initial_amount,
			'initial_description'   => __( 'now', 'woocommerce-subscriptions' ),
			'recurring_amount'      => $recurring_amount,
			'subscription_interval' => self::get_cart_subscription_interval(),
			'subscription_period'   => self::get_cart_subscription_period(),
			'trial_length'          => self::get_cart_subscription_trial_length(),
			'trial_period'          => self::get_cart_subscription_trial_period(),
		);

		$is_one_payment = self::get_cart_subscription_length() > 0 && self::get_cart_subscription_length() == self::get_cart_subscription_interval();

		// Override defaults when subscription is for one billing period
		if ( $is_one_payment ) {

			$subscription_details['subscription_length'] = self::get_cart_subscription_length();

		} else {

			if ( true === $args['include_lengths'] ) {
				$subscription_details['subscription_length'] = self::get_cart_subscription_length();
			}

			if ( false === $args['include_trial'] ) {
				$subscription_details['trial_length'] = 0;
			}
		}

		$initial_amount_string   = ( is_numeric( $subscription_details['initial_amount'] ) ) ? wc_price( $subscription_details['initial_amount'] ) : $subscription_details['initial_amount'];
		$recurring_amount_string = ( is_numeric( $subscription_details['recurring_amount'] ) ) ? wc_price( $subscription_details['recurring_amount'] ) : $subscription_details['recurring_amount'];

		// Don't show up front fees when there is no trial period and no sign up fee and they are the same as the recurring amount
		if ( self::get_cart_subscription_trial_length() == 0 && self::get_cart_subscription_sign_up_fee() == 0 && $initial_amount_string == $recurring_amount_string ) {
			$subscription_details['initial_amount'] = '';
		} elseif ( wc_price( 0 ) == $initial_amount_string && false === $is_one_payment && self::get_cart_subscription_trial_length() > 0 ) { // don't show $0.00 initial amount (i.e. a free trial with no non-subscription products in the cart) unless the recurring period is the same as the billing period
			$subscription_details['initial_amount'] = '';
		}

		// Include details of a synced subscription in the cart
		if ( $synchronised_cart_item = WC_Subscriptions_Synchroniser::cart_contains_synced_subscription() ) {
			$subscription_details += array(
				'is_synced'                => true,
				'synchronised_payment_day' => WC_Subscriptions_Synchroniser::get_products_payment_day( $synchronised_cart_item['data'] ),
			);
		}

		$subscription_details = apply_filters( 'woocommerce_cart_subscription_string_details', $subscription_details, $args );

		$subscription_string = wcs_price_string( $subscription_details );

		return $subscription_string;
	}

	/**
	 * Uses the a subscription's combined price total calculated by WooCommerce to determine the
	 * total price that should be charged per period.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function set_calculated_total( $total ) {
		_deprecated_function( __METHOD__, '2.0', 'values from WC()->cart->recurring_carts' );
		return $total;
	}

	/**
	 * Get the recurring amounts values from the session
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function get_cart_from_session() {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Store the sign-up fee cart values in the session
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function set_session() {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Reset the sign-up fee fields in the current session
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function reset() {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Returns a cart item's product ID. For a variation, this will be a variation ID, for a simple product,
	 * it will be the product's ID.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5
	 */
	public static function get_items_product_id( $cart_item ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_canonical_product_id( $cart_item )' );
		return wcs_get_canonical_product_id( $cart_item );
	}

	/**
	 * Store how much discount each coupon grants.
	 *
	 * @param mixed $code
	 * @param mixed $amount
	 * @return void
	 */
	public static function increase_coupon_discount_amount( $code, $amount ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions_Coupon::increase_coupon_discount_amount( WC()->cart, $code, $amount )' );

		if ( empty( WC()->cart->coupon_discount_amounts[ $code ] ) ) {
			WC()->cart->coupon_discount_amounts[ $code ] = 0;
		}

		if ( 'recurring_total' != self::$calculation_type ) {
			WC()->cart->coupon_discount_amounts[ $code ] += $amount;
		}
	}

	/**
	 * Don't display shipping prices if the initial order won't require shipping (i.e. all the products in the cart are subscriptions with a free trial or synchronised to a date in the future)
	 *
	 * @return string Label for a shipping method
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function get_cart_shipping_method_full_label( $label, $method ) {
		_deprecated_function( __METHOD__, '2.0.12' );

		if ( ! self::charge_shipping_up_front() ) {
			$label = $method->label;
		}

		return $label;
	}

	/**
	 * One time shipping can null the need for shipping needs. WooCommerce treats that as no need to ship, therefore it will call
	 * WC()->shipping->reset() on it, which will wipe the preferences saved. That can cause the chosen shipping method for the one
	 * time shipping feature to be lost, and the first default to be applied instead. To counter that, we save the chosen shipping
	 * method to a key that's not going to get wiped by WC's method, and then later restore it.
	 *
	 * @deprecated 7.3.0  - no longer in use internally
	 */
	public static function maybe_restore_chosen_shipping_method() {
		wcs_deprecated_function( __METHOD__, '7.3.0', 'The use of this function is no longer recommended and will be removed in a future version.' );
		$chosen_shipping_method_cache = WC()->session->get( 'wcs_shipping_methods', false );

		if ( false !== $chosen_shipping_method_cache ) {
			WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_method_cache );
		}
	}

	/**
	 * Return a localized free trial period string.
	 *
	 * @param int $number An interval in the range 1-6
	 * @param string $period One of day, week, month or year.
	 */
	public static function format_free_trial_period( $number, $period ) {
		if ( 'day' === $period ) {
			// translators: placeholder is a number of days.
			return sprintf( _n( '%s day', '%s days', $number, 'woocommerce-subscriptions' ), $number );
		}
		if ( 'week' === $period ) {
			// translators: placeholder is a number of weeks.
			return sprintf( _n( '%s week', '%s weeks', $number, 'woocommerce-subscriptions' ), $number );
		}
		if ( 'month' === $period ) {
			// translators: placeholder is a number of months.
			return sprintf( _n( '%s month', '%s months', $number, 'woocommerce-subscriptions' ), $number );
		}
		if ( 'year' === $period ) {
			// translators: placeholder is a number of years.
			return sprintf( _n( '%s year', '%s years', $number, 'woocommerce-subscriptions' ), $number );
		}
		return '';
	}


	/**
	 * Return a localized sync string, copied from WC_Subscriptions_Product::get_price_string
	 *
	 * @param WC_Product_Subscription $product The synced product.
	 * @param string                  $period One of day, week, month or year.
	 * @param int                     $interval An interval in the range 1-6
	 * @return string The new sync string.
	 */
	public static function format_sync_period( $product, string $period, int $interval ) {
		global $wp_locale;
		$payment_day = WC_Subscriptions_Synchroniser::get_products_payment_day( $product );
		switch ( $period ) {
			case 'week':
				$payment_day_of_week = WC_Subscriptions_Synchroniser::get_weekday( $payment_day );
				if ( 1 === $interval ) {
					// translators: 1$: day of the week (e.g. "every Wednesday").
					return sprintf( __( 'every %1$s', 'woocommerce-subscriptions' ), $payment_day_of_week );
				} else {
					return sprintf(
						// translators: 1$: period, 2$: day of the week (e.g. "every 2nd week on Wednesday").
						__( 'every %1$s on %2$s', 'woocommerce-subscriptions' ),
						wcs_get_subscription_period_strings( $interval,$period ),
						$payment_day_of_week
					);
				}
				break;
			case 'month':
				if ( 1 === $interval ) {
					if ( $payment_day > 27 ) {
						return __( 'on the last day of each month', 'woocommerce-subscriptions' );
					} else {
						return sprintf(
							// translators: 1$: day of the month (e.g. "23rd") (e.g. "every 23rd of each month").
							__( 'on the %1$s of each month', 'woocommerce-subscriptions' ),
							wcs_append_numeral_suffix( $payment_day )
						);
					}
				} else {
					if ( $payment_day > 27 ) {
						return sprintf(
							// translators: 1$: interval (e.g. "3rd") (e.g. "on the last day of every 3rd month").
							__( 'on the last day of every %1$s month', 'woocommerce-subscriptions' ),
							wcs_append_numeral_suffix( $interval )
						);
					} else {
						return sprintf(
							// translators: on the, 1$: <date> day of every, 2$: <interval> month (e.g. "on the 23rd day of every 2nd month").
							__( 'on the %1$s day of every %2$s month', 'woocommerce-subscriptions' ),
							wcs_append_numeral_suffix( $payment_day ),
							wcs_append_numeral_suffix( $interval )
						);
					}
				}
				break;
			case 'year':
				if ( 1 === $interval ) {
					return sprintf(
						// translators: on, 1$: <date>, 2$: <month> each year (e.g. "on March 15th each year").
						__( 'on %1$s %2$s each year', 'woocommerce-subscriptions' ),
						$wp_locale->month[ $payment_day['month'] ],
						wcs_append_numeral_suffix( $payment_day['day'] )
					);
				} else {
					return sprintf(
						// translators: 1$: month (e.g. "March"), 2$: day of the month (e.g. "23rd), 3$: interval year (r.g  March 23rd every 2nd year").
						__( 'on %1$s %2$s every %3$s year', 'woocommerce-subscriptions' ),
						$wp_locale->month[ $payment_day['month'] ],
						wcs_append_numeral_suffix( $payment_day['day'] ),
						wcs_append_numeral_suffix( $interval )
					);
				}
				break;
		}

		return '';
	}
	/**
	 * Adds meta data so it can be displayed in the Cart.
	 */
	public static function woocommerce_get_item_data( $other_data, $cart_item ) {
		$product = $cart_item['data'];

		if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
			return $other_data;
		}

		$trial_length = WC_Subscriptions_Product::get_trial_length( $product );
		if ( $trial_length ) {
			$other_data[] = array(
				'name'                                     => __( 'Free trial', 'woocommerce-subscriptions' ),
				'value'                                    => self::format_free_trial_period( $trial_length, WC_Subscriptions_Product::get_trial_period( $product ) ),
				'hidden'                                   => true,
				'__experimental_woocommerce_blocks_hidden' => false,
			);
		}

		$sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( $product );
		if ( $sign_up_fee ) {
			$other_data[] = array(
				'name'                                     => __( 'Sign up fee', 'woocommerce-subscriptions' ),
				'value'                                    => wc_price( $sign_up_fee ),
				'hidden'                                   => true,
				'__experimental_woocommerce_blocks_hidden' => false,
			);
		}

		$synchronised_cart_item = WC_Subscriptions_Synchroniser::is_product_synced( $product );
		if ( $synchronised_cart_item ) {
			$other_data[] = array(
				'name'                                     => __( 'Renews', 'woocommerce-subscriptions' ),
				'value'                                    => self::format_sync_period( $product, WC_Subscriptions_Product::get_period( $product ), WC_Subscriptions_Product::get_interval( $product ) ),
				'hidden'                                   => true,
				'__experimental_woocommerce_blocks_hidden' => false,
			);
		}

		return $other_data;
	}

	/**
	 * Parse recurring shipping rates from the front end and put them into the $_POST['shipping_method'] used by WooCommerce.
	 *
	 * When WooCommerce takes the value of inputs for shipping methods selection from the cart and checkout pages, it uses a
	 * JavaScript array and therefore, can only use numerical indexes. This works for WC core, because it only needs shipping
	 * selection for different packages. However, we want to use string indexes to differentiate between different recurring
	 * cart shipping selection inputs *and* packages. To do this, we need to get our shipping methods from the $_POST['post_data']
	 * values and manually add them $_POST['shipping_method'] array.
	 *
	 * We can't do this on the cart page unfortunately because it doesn't pass the entire forms post data and instead only
	 * sends the shipping methods with a numerical index.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 * @return void
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.12
	 */
	public static function add_shipping_method_post_data() {
		wcs_deprecated_function( __METHOD__, '3.1.0' );
		if ( ! wcs_is_woocommerce_pre( '2.6' ) ) {
			return;
		}

		check_ajax_referer( 'update-order-review', 'security' );

		parse_str( wc_clean( wp_unslash( $_POST['post_data'] ) ), $form_data );

		// In case we have only free trials/sync'd products in the cart and shipping methods aren't being displayed
		if ( ! isset( $_POST['shipping_method'] ) ) {
			$_POST['shipping_method'] = array();
		}
		if ( ! isset( $form_data['shipping_method'] ) ) {
			$form_data['shipping_method'] = array();
		}

		foreach ( $form_data['shipping_method'] as $key => $methods ) {
			if ( ! is_numeric( $key ) && ! array_key_exists( $key, $_POST['shipping_method'] ) ) {
				$_POST['shipping_method'][ $key ] = $methods;
			}
		}
	}

	/**
	 * When WooCommerce calculates rates for a recurring shipping package, we need to make sure there is a
	 * different number of rates to make sure WooCommerce updates the chosen method for the recurring cart
	 * and the 'woocommerce_shipping_chosen_method' filter is called, which we use to make sure the chosen
	 * method is the recurring method, not the initial method.
	 *
	 * This function is hooked to 'woocommerce_shipping_packages' called by WC_Shipping->calculate_shipping()
	 * which is why it accepts and returns the $packages array. It is also attached with a very high priority
	 * to avoid conflicts with any 3rd party plugins that may use the method count session value (only a couple
	 * of other hooks, including 'woocommerce_shipping_chosen_method' and 'woocommerce_shipping_method_chosen'
	 * are triggered between when this callback runs on 'woocommerce_shipping_packages' and when the session
	 * value is set again by WC_Shipping->calculate_shipping()).
	 *
	 * For more details, see: https://github.com/Prospress/woocommerce-subscriptions/pull/1187#issuecomment-186091152
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param array $packages An array of shipping package of the form returned by WC_Cart->get_shipping_packages() which includes the package's contents, cost, customer, destination and alternative rates
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.19
	 */
	public static function reset_shipping_method_counts( $packages ) {
		wcs_deprecated_function( __METHOD__, '3.1.0' );
		if ( 'none' !== self::$recurring_cart_key ) {
			WC()->session->set( 'shipping_method_counts', array() );
		}

		return $packages;
	}

	/**
	 * Checks to see if payment method is required on a subscription product with a $0 initial payment.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 */
	public static function zero_initial_payment_requires_payment() {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Zero_Initial_Payment_Checkout_Manager::zero_initial_checkout_requires_payment() if available.' );

		if ( class_exists( 'WCS_Zero_Initial_Payment_Checkout_Manager' ) ) {
			return WCS_Zero_Initial_Payment_Checkout_Manager::zero_initial_checkout_requires_payment();
		}

		return true;
	}
}
