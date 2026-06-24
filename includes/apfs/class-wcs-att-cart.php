<?php
/**
 * WCS_ATT_Cart class
 *
 * @package  WooCommerce All Products for Subscriptions
 * @since    APFS 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart support.
 *
 * @class    WCS_ATT_Cart
 * @version  6.0.0
 */
class WCS_ATT_Cart {

	/**
	 * Initialize.
	 */
	public static function init() {
		self::add_hooks();
	}

	/**
	 * Hook-in.
	 */
	private static function add_hooks() {

		// Add scheme data to cart items that can be purchased on a recurring basis.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );

		// Load saved session data of cart items that can be purchased on a recurring basis.
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'load_cart_item_data_from_session' ), 5, 2 );

		// Inspect product-level/cart-level session data and apply subscription schemes to cart items as needed.
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'apply_subscription_schemes' ), 5 );

		// Inspect product-level/cart-level session data on add-to-cart and apply subscription schemes to cart items as needed. Then, recalculate totals.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'apply_subscription_schemes_on_add_to_cart' ), 19, 6 );

		// Update the subscription scheme saved on a cart item when chosing a new option.
		add_filter( 'woocommerce_update_cart_action_cart_updated', array( __CLASS__, 'update_cart_item_data' ), 10 );

		// Check successful application of subscription schemes.
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_applied_subscription_schemes' ), 10 );

		// Restore selected plan when clicking cart item titles.
		add_filter( 'woocommerce_cart_item_permalink', array( __CLASS__, 'cart_item_permalink' ), 100, 2 );
	}

	/*
	|--------------------------------------------------------------------------
	| Cart item methods
	|--------------------------------------------------------------------------
	*/

	/**
	 * Returns all subscription schemes associated with a cart item - @see 'WCS_ATT_Product_Schemes::get_subscription_schemes'.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  array  $cart_item
	 * @param  string $context
	 * @return array
	 */
	public static function get_subscription_schemes( $cart_item, $context = 'any' ) {
		return apply_filters( 'wcsatt_cart_item_subscription_schemes', WCS_ATT_Product_Schemes::get_subscription_schemes( $cart_item['data'], $context ), $cart_item, $context );
	}

	/**
	 * Returns the subscription scheme key (to apply) of a cart item, or false if the cart item is a one-time purchase.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @return string|null|false
	 */
	public static function get_subscription_scheme( $cart_item ) {

		$active_scheme = isset( $cart_item['wcsatt_data']['active_subscription_scheme'] ) ? $cart_item['wcsatt_data']['active_subscription_scheme'] : null;

		return $active_scheme;
	}

	/**
	 * Get the posted cart-item subscription scheme.
	 *
	 * @since  APFS 2.1.0
	 *
	 * @param  string $cart_item_key
	 * @return string
	 */
	public static function get_posted_subscription_scheme( $cart_item_key ) {

		$posted_subscription_scheme_key = null;

		$key = 'convert_to_sub';

		$posted_subscription_scheme_option = isset( $_POST['cart'][ $cart_item_key ][ $key ] ) ? wc_clean( $_POST['cart'][ $cart_item_key ][ $key ] ) : null;

		if ( null !== $posted_subscription_scheme_option ) {
			$posted_subscription_scheme_key = WCS_ATT_Product_Schemes::parse_subscription_scheme_key( $posted_subscription_scheme_option );
		}

		return $posted_subscription_scheme_key;
	}

	/**
	 * Equivalent of 'WC_Cart::get_product_price' that utilizes 'WCS_ATT_Product_Prices::get_price' instead of 'WC_Product::get_price'.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  WC_Product $product
	 * @param  string     $scheme_key
	 * @return string
	 */
	public static function get_product_price( $cart_item, $scheme_key = '' ) {

		$product = $cart_item['data'];

		if ( ! WCS_ATT_Display_Cart::display_prices_including_tax() ) {
			$product_price = wc_get_price_excluding_tax( $product, array( 'price' => WCS_ATT_Product_Prices::get_price( $product, $scheme_key ) ) );
		} else {
			$product_price = wc_get_price_including_tax( $product, array( 'price' => WCS_ATT_Product_Prices::get_price( $product, $scheme_key ) ) );
		}

		return apply_filters( 'wcsatt_cart_product_price', wc_price( $product_price ), $cart_item );
	}

	/**
	 * Applies a saved subscription key to a cart item.
	 *
	 * @see 'WCS_ATT_Product_Schemes::set_subscription_scheme'.
	 *
	 * @since  APFS 2.0.0
	 *
	 * @param  array $cart_item
	 * @return array
	 */
	public static function apply_subscription_scheme( $cart_item ) {

		if ( self::is_supported( $cart_item ) ) {

			$scheme_to_apply = self::get_subscription_scheme( $cart_item );

			if ( null !== $scheme_to_apply ) {

				// Attempt to apply scheme.
				WCS_ATT_Product_Schemes::set_subscription_scheme( $cart_item['data'], $scheme_to_apply );

				// Grab the applied scheme.
				$applied_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'] );

				// If the scheme was not applied sucessfully, then it was probably deleted, or something fishy happened.
				if ( $scheme_to_apply !== $applied_scheme ) {
					// In this case, simply ensure that no scheme is set on the object and handle the mismatch later.
					WCS_ATT_Product_Schemes::set_subscription_scheme( $cart_item['data'], null );
				}
			}
		}

		return apply_filters( 'wcsatt_cart_item', $cart_item );
	}

	/*
	|--------------------------------------------------------------------------
	| Hooks
	|--------------------------------------------------------------------------
	*/

	/**
	 * Add scheme data to cart items that can be purchased on a recurring basis.
	 *
	 * @param  array $cart_item
	 * @param  int   $product_id
	 * @param  int   $variation_id
	 * @return array
	 */
	public static function add_cart_item_data( $cart_item, $product_id, $variation_id ) {

		if ( self::is_supported( array_merge( $cart_item, array( 'product_id' => $product_id ) ) ) && ! isset( $cart_item['wcsatt_data'] ) ) { // Might be set - @see 'WCS_ATT_Order::restore_cart_item_from_order_item'.

			$posted_subscription_scheme_key = WCS_ATT_Product_Schemes::get_posted_subscription_scheme( $product_id );

			if ( null === $posted_subscription_scheme_key ) {

				if ( $variation_id ) {
					$product = wc_get_product( $variation_id );
				} else {
					$product = wc_get_product( $product_id );
				}

				if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
					$posted_subscription_scheme_key = WCS_ATT_Product_Schemes::get_default_subscription_scheme( $product );
				}
			}

			$cart_item['wcsatt_data'] = array(
				'active_subscription_scheme' => $posted_subscription_scheme_key,
			);
		}

		return $cart_item;
	}

	/**
	 * Load saved session data of cart items that can be pruchased on a recurring basis.
	 *
	 * @param  array $cart_item
	 * @param  array $item_session_values
	 * @return array
	 */
	public static function load_cart_item_data_from_session( $cart_item, $item_session_values ) {

		if ( self::is_supported( $cart_item ) && isset( $item_session_values['wcsatt_data'] ) ) {
			$cart_item['wcsatt_data'] = $item_session_values['wcsatt_data'];
		}

		return $cart_item;
	}

	/**
	 * Inspect product-level/cart-level session data and apply subscription schemes to cart items as needed.
	 *
	 * @param  WC_Cart $cart
	 * @return void
	 */
	public static function apply_subscription_schemes( $cart ) {

		foreach ( $cart->cart_contents as $cart_item_key => $cart_item ) {

			if ( ! self::is_supported( $cart_item ) ) {
				continue;
			}

			if ( ! isset( $cart_item['wcsatt_data'] ) ) {
				continue;
			}

			// If renewing a subscription, create a dummy subscription scheme that matches the subscription's billing schedule on the fly.
			if ( isset( $cart_item['subscription_renewal'] ) ) {

				$subscription_id = isset( $cart_item['subscription_renewal']['subscription_id'] ) ? $cart_item['subscription_renewal']['subscription_id'] : false;
				$subscription    = $subscription_id ? wcs_get_subscription( $subscription_id ) : false;

				if ( $subscription ) {

					// Extract the scheme details from the subscription and create a dummy scheme.
					$subscription_scheme_obj = new WCS_ATT_Scheme(
						array(
							'context' => 'product',
							'data'    => array(
								'subscription_period' => $subscription->get_billing_period(),
								'subscription_period_interval' => $subscription->get_billing_interval(),
							),
						)
					);

					$subscription_scheme_key = $subscription_scheme_obj->get_key();

					WCS_ATT_Product_Schemes::set_subscription_schemes( $cart->cart_contents[ $cart_item_key ]['data'], array( $subscription_scheme_key => $subscription_scheme_obj ) );
					WCS_ATT_Product_Schemes::set_forced_subscription_scheme( $cart->cart_contents[ $cart_item_key ]['data'], true );

					$cart->cart_contents[ $cart_item_key ]['wcsatt_data']['active_subscription_scheme'] = $subscription_scheme_key;
				}

				// Check if there is an `add_product_to_subscription` action and copy live schemes.
			} elseif ( isset( $cart_item['add_product_to_subscription_schemes'] ) ) {

				// Set schemes to the product object.
				WCS_ATT_Product_Schemes::set_subscription_schemes( $cart->cart_contents[ $cart_item_key ]['data'], $cart_item['add_product_to_subscription_schemes'] );

			}

			if ( ! WCS_ATT_Product_Schemes::has_subscription_schemes( $cart->cart_contents[ $cart_item_key ]['data'] ) ) {
				continue;
			}

			// Get subscription scheme to apply.
			$scheme_to_apply = self::get_subscription_scheme_to_apply( $cart->cart_contents[ $cart_item_key ] );

			// Update cart item.
			$cart->cart_contents[ $cart_item_key ]['wcsatt_data']['active_subscription_scheme'] = ! empty( $scheme_to_apply ) ? $scheme_to_apply : false;

			// Convert the product object to a subscription, if needed.
			$cart->cart_contents[ $cart_item_key ] = self::apply_subscription_scheme( $cart->cart_contents[ $cart_item_key ] );

			/*
			 * Grab the applied scheme.
			 * Note this might not be the same as the scheme we attempted to apply earlier.
			 * See 'WCS_ATT_Cart::apply_subscription_scheme' for details.
			 */
			$applied_scheme = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart->cart_contents[ $cart_item_key ]['data'] );

			/*
			 * 1. Keep only the applied scheme when resubscribing, or paying for a failed order (and force it).
			 *    If we don't do this, then multiple scheme options will show up next to the cart item.
			 * 2. Prevent scheme discounts from being applied again when renewing or resubscribing.
			 */
			if ( isset( $cart->cart_contents[ $cart_item_key ]['subscription_initial_payment'] ) || isset( $cart->cart_contents[ $cart_item_key ]['subscription_resubscribe'] ) ) {

				$schemes = array();

				foreach ( self::get_subscription_schemes( $cart->cart_contents[ $cart_item_key ] ) as $scheme_key => $scheme ) {

					if ( $scheme_key === $applied_scheme ) {

						// Prevent scheme discounts from being applied again when renewing or resubscribing.
						if ( isset( $cart->cart_contents[ $cart_item_key ]['subscription_resubscribe'] ) ) {
							$scheme->set_pricing_mode( 'inherit' );
							$scheme->set_discount( '' );
						}

						$schemes[ $scheme_key ] = $scheme;
					}
				}

				WCS_ATT_Product_Schemes::set_subscription_schemes( $cart->cart_contents[ $cart_item_key ]['data'], $schemes );
				WCS_ATT_Product_Schemes::set_forced_subscription_scheme( $cart->cart_contents[ $cart_item_key ]['data'], true );
			}

			/**
			 * 'wcsatt_applied_cart_item_subscription_scheme' action.
			 *
			 * @since  APFS 2.1.0
			 *
			 * @param  array   $cart_item
			 * @param  string  $cart_item_key
			 */
			do_action( 'wcsatt_applied_cart_item_subscription_scheme', $cart_item, $cart_item_key );
		}
	}

	/**
	 * Gets the subscription scheme to apply against a cart item product object on session load.
	 *
	 * @see 'WCS_ATT_Cart::apply_subscription_scheme'.
	 *
	 * @param  array $cart_item
	 * @return string|false
	 */
	private static function get_subscription_scheme_to_apply( $cart_item ) {

		$scheme_key_to_apply = $cart_item['wcsatt_data']['active_subscription_scheme'];

		if ( null === $scheme_key_to_apply ) {
			if ( WCS_ATT_Product_Schemes::has_subscription_schemes( $cart_item['data'] ) ) {
				$scheme_key_to_apply = WCS_ATT_Product_Schemes::get_default_subscription_scheme( $cart_item['data'] );
			}
		}

		return apply_filters( 'wcsatt_set_subscription_scheme_id', $scheme_key_to_apply, $cart_item, false );
	}

	/**
	 * Inspect product-level/cart-level session data and apply subscription schemes on cart items as needed.
	 * Then, recalculate totals.
	 *
	 * @return void
	 */
	public static function apply_subscription_schemes_on_add_to_cart( $item_key, $product_id, $quantity, $variation_id, $variation, $item_data ) {
		self::apply_subscription_schemes( WC()->cart );
	}

	/**
	 * Update the subscription scheme saved on a cart item when chosing a new option.
	 *
	 * @param  boolean $updated
	 * @return boolean
	 */
	public static function update_cart_item_data( $updated ) {

		$schemes_changed = false;

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! empty( $cart_item['wcsatt_data'] ) ) {

				$posted_subscription_scheme_key = self::get_posted_subscription_scheme( $cart_item_key );

				if ( null !== $posted_subscription_scheme_key ) {

					$existing_subscription_scheme_key = self::get_subscription_scheme( $cart_item );

					if ( $posted_subscription_scheme_key !== $existing_subscription_scheme_key ) {
						WC()->cart->cart_contents[ $cart_item_key ]['wcsatt_data']['active_subscription_scheme'] = $posted_subscription_scheme_key;
						$schemes_changed = true;
					}
				}
			}
		}

		if ( $schemes_changed ) {
			self::apply_subscription_schemes( WC()->cart );
		}

		return true;
	}

	/**
	 * True if the product corresponding to a cart item is one of the types supported by the plugin.
	 *
	 * @param  mixed $arg
	 * @return boolean
	 */
	public static function is_supported( $arg ) {

		$product_type = '';

		if ( is_array( $arg ) ) {

			$cart_item = $arg;

			if ( isset( $cart_item['data'] ) ) {
				$product_type = $cart_item['data']->get_type();
			} elseif ( isset( $cart_item['product_id'] ) ) {
				$product_type = WCS_ATT_Core_Compatibility::get_product_type( $cart_item['product_id'] );
			}

			/**
			 * When passing cart item data, filter result via 'wcsatt_cart_item_is_supported'.
			 *
			 * @since  APFS 3.1.25
			 */
			return apply_filters( 'wcsatt_cart_item_is_supported', in_array( $product_type, WCS_ATT()->get_supported_product_types() ), $cart_item );
		}

		if ( is_a( $arg, 'WC_Product' ) ) {
			$product_type = $arg->get_type();
		} else {
			$product_type = WCS_ATT_Core_Compatibility::get_product_type( absint( $arg ) );
		}

		return in_array( $product_type, WCS_ATT()->get_supported_product_types() );
	}

	/**
	 * Validates the subscription schemes applied on a cart item.
	 *
	 * @since  APFS 3.3.2
	 *
	 * @return array
	 */
	public static function validate_applied_subscription_scheme( $cart_item ) {

		$scheme_to_apply = self::get_subscription_scheme( $cart_item );
		$applied_scheme  = WCS_ATT_Product_Schemes::get_subscription_scheme( $cart_item['data'] );

		// Handle mismatch. Remember that when renewing we are deleting all scheme data from the object and letting WCS handle everything.
		if ( $scheme_to_apply !== $applied_scheme ) {

			$error              = '';
			$available_schemes  = WCS_ATT_Product_Schemes::get_subscription_schemes( $cart_item['data'] );
			$has_forced_schemes = WCS_ATT_Product_Schemes::has_forced_subscription_scheme( $cart_item['data'] );

			// The product was purchased as a subscription...
			if ( $scheme_to_apply ) {

				// ...and the purchased scheme does not exist anymore...
				if ( ! in_array( $scheme_to_apply, $available_schemes ) ) {
					$error = sprintf( __( 'The &quot;%1$s&quot; subscription plan that you originally signed up for is no longer available.', 'woocommerce-subscriptions' ), $cart_item['data']->get_name() );
					// ...or a dev misbehaved and deserves some bad karma.
				} else {
					$error = sprintf( __( 'The &quot;%s&quot; subscription plan that you originally signed up for has changed. Please remove the product from your cart and try again.', 'woocommerce-subscriptions' ), $cart_item['data']->get_name() );
				}

				// ... or the product wasn't purchased as a subscription although it should...
			} elseif ( false === $scheme_to_apply && $has_forced_schemes ) {
				$error = sprintf( __( '&quot;%1$s&quot; is only available for purchase on subscription.', 'woocommerce-subscriptions' ), $cart_item['data']->get_name() );
				// ...or a dev did something very fishy (perhaps it was you).
			} else {
				$error = sprintf( __( 'The &quot;%s&quot; subscription plan that you originally signed up for has changed. Please remove the product from your cart and try again.', 'woocommerce-subscriptions' ), $cart_item['data']->get_name() );
			}

			return new WP_Error( 'wcsatt_subscription_plan_invalid', $error );
		}

		return true;
	}

	/**
	 * Validates the subscription schemes applied on cart items.
	 */
	public static function check_applied_subscription_schemes() {

		// Store API cart item validation is done via 'wooocommerce_store_api_validate_cart_item'.
		if ( WCS_ATT_Core_Compatibility::is_store_api_request() ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

			$result = self::validate_applied_subscription_scheme( $cart_item );

			if ( is_wp_error( $result ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
			}
		}
	}

	/**
	 * Restore selected plan when clicking cart item titles.
	 *
	 * @since  APFS 3.1.14
	 *
	 * @param  string $html
	 * @param  array  $cart_item
	 * @return string
	 */
	public static function cart_item_permalink( $html, $cart_item ) {

		// Add query string parameter only if the product is visible.
		if ( $html ) {

			$scheme_key = self::get_subscription_scheme( $cart_item );

			if ( ! is_null( $scheme_key ) ) {
				$key  = 'convert_to_sub_' . $cart_item['product_id'];
				$html = add_query_arg( array( $key => WCS_ATT_Product_Schemes::stringify_subscription_scheme_key( $scheme_key ) ), $html );
			}
		}

		// It's safe to ignore the warning. The url returned is escaped downstream in cart.php .
		// nosemgrep: audit.php.wp.security.xss.query-arg
		return $html;
	}
}
