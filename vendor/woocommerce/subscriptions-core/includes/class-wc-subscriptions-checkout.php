<?php
/**
 * Subscriptions Checkout
 *
 * Extends the WooCommerce checkout class to add subscription meta on checkout.
 *
 * @package WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Checkout
 * @category Class
 * @author Brent Shepherd
 */
class WC_Subscriptions_Checkout {

	private static $guest_checkout_option_changed = false;

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function init() {

		// We need to create subscriptions on checkout and want to do it after almost all other extensions have added their products/items/fees
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'process_checkout' ), 100, 2 );

		// Same as above, but this is for the Checkout block.
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Package' ) && ( version_compare( \Automattic\WooCommerce\Blocks\Package::get_version(), '6.3.0', '>=' ) || \Automattic\WooCommerce\Blocks\Package::is_experimental_build() ) ) {
			add_action( 'woocommerce_blocks_checkout_order_processed', array( __CLASS__, 'process_checkout' ), 100, 1 );
		} else {
			add_action( '__experimental_woocommerce_blocks_checkout_order_processed', array( __CLASS__, 'process_checkout' ), 100, 1 );
		}

		// Some callbacks need to hooked after WC has loaded.
		add_action( 'woocommerce_loaded', array( __CLASS__, 'attach_dependant_hooks' ) );

		// When a line item is added to a subscription on checkout, ensure the backorder data added by WC is removed
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'remove_backorder_meta_from_subscription_line_item' ), 10, 4 );

		// When a line item is added to a subscription, ensure the __has_trial meta data is added if applicable.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'maybe_add_free_trial_item_meta' ), 10, 4 );

		// Store the amount of tax removed from a line item to account the base location's tax.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'store_line_item_base_location_taxes' ), 10, 3 );

		// Make sure user registration is required when purchasing subscriptions.
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_registration_during_checkout' ) );
		add_action( 'woocommerce_before_checkout_process', array( __CLASS__, 'force_registration_during_checkout' ), 10 );
		add_filter( 'woocommerce_checkout_registration_enabled', array( __CLASS__, 'maybe_enable_registration' ) );

		// Override the WC default "Add to cart" text to "Sign up now" (in various places/templates)
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ) );

		// Check the "Ship to different address" checkbox if the shipping address of the originating order is different to the billing address.
		add_filter( 'woocommerce_ship_to_different_address_checked', array( __CLASS__, 'maybe_check_ship_to_different_address' ), 10, 1 );
	}

	/**
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.17
	 */
	public static function attach_dependant_hooks() {
		// Make sure guest checkout is not enabled in option param passed to WC JS
		if ( wcs_is_woocommerce_pre( '3.3' ) ) {
			add_filter( 'woocommerce_params', array( __CLASS__, 'filter_woocommerce_script_parameters' ), 10, 1 );
			add_filter( 'wc_checkout_params', array( __CLASS__, 'filter_woocommerce_script_parameters' ), 10, 1 );
		} else {
			add_filter( 'woocommerce_get_script_data', array( __CLASS__, 'filter_woocommerce_script_parameters' ), 10, 2 );
		}
	}

	/**
	 * Create subscriptions purchased on checkout.
	 *
	 * @param int $order_id The post_id of a shop_order post/WC_Order object
	 * @param array $posted_data The data posted on checkout
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function process_checkout( $order_id, $posted_data = array() ) {

		if ( ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		$subscriptions = array();

		// First clear out any subscriptions created for a failed payment to give us a clean slate for creating new subscriptions
		$subscriptions = wcs_get_subscriptions_for_order( wcs_get_objects_property( $order, 'id' ), array( 'order_type' => 'parent' ) );

		if ( ! empty( $subscriptions ) ) {
			$action_hook = wcs_is_custom_order_tables_usage_enabled() ? 'woocommerce_before_delete_subscription' : 'before_delete_post';

			remove_action( $action_hook, 'WC_Subscriptions_Manager::maybe_cancel_subscription' );
			foreach ( $subscriptions as $subscription ) {
				$subscription->delete( true );
			}
			add_action( $action_hook, 'WC_Subscriptions_Manager::maybe_cancel_subscription' );
		}

		WC_Subscriptions_Cart::set_global_recurring_shipping_packages();

		// Create new subscriptions for each group of subscription products in the cart (that is not a renewal)
		foreach ( WC()->cart->recurring_carts as $recurring_cart ) {

			$subscription = self::create_subscription( $order, $recurring_cart, $posted_data ); // Exceptions are caught by WooCommerce

			if ( is_wp_error( $subscription ) ) {
				throw new Exception( $subscription->get_error_message() );
			}

			do_action( 'woocommerce_checkout_subscription_created', $subscription, $order, $recurring_cart );
		}

		do_action( 'subscriptions_created_for_order', $order ); // Backward compatibility
	}

	/**
	 * Create a new subscription from a cart item on checkout.
	 *
	 * The function doesn't validate whether the cart item is a subscription product, meaning it can be used for any cart item,
	 * but the item will need a `subscription_period` and `subscription_period_interval` value set on it, at a minimum.
	 *
	 * @param WC_Order $order
	 * @param WC_Cart $cart
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function create_subscription( $order, $cart, $posted_data ) {

		try {
			// Start transaction if available
			$transaction = new WCS_SQL_Transaction();
			$transaction->start();

			// Set the recurring line totals on the subscription
			$variation_id = wcs_cart_pluck( $cart, 'variation_id' );
			$product_id   = empty( $variation_id ) ? wcs_cart_pluck( $cart, 'product_id' ) : $variation_id;

			$subscription = wcs_create_subscription(
				array(
					'start_date'       => $cart->start_date,
					'order_id'         => wcs_get_objects_property( $order, 'id' ),
					'customer_id'      => $order->get_user_id(),
					'billing_period'   => wcs_cart_pluck( $cart, 'subscription_period' ),
					'billing_interval' => wcs_cart_pluck( $cart, 'subscription_period_interval' ),
					'customer_note'    => wcs_get_objects_property( $order, 'customer_note' ),
				)
			);

			if ( is_wp_error( $subscription ) ) {
				// If the customer wasn't created on checkout and registration isn't enabled, display a more appropriate error message.
				if ( 'woocommerce_subscription_invalid_customer_id' === $subscription->get_error_code() && ! is_user_logged_in() && ! WC()->checkout->is_registration_enabled() ) {
					throw new Exception( self::get_registration_error_message() );
				}

				throw new Exception( $subscription->get_error_message() );
			}

			// Set the subscription's billing and shipping address
			$subscription = wcs_copy_order_address( $order, $subscription );

			$subscription->update_dates(
				array(
					'trial_end'    => $cart->trial_end_date,
					'next_payment' => $cart->next_payment_date,
					'end'          => $cart->end_date,
				)
			);

			// Store trial period for PayPal
			if ( wcs_cart_pluck( $cart, 'subscription_trial_length' ) > 0 ) {
				$subscription->set_trial_period( wcs_cart_pluck( $cart, 'subscription_trial_period' ) );
			}

			// Set the payment method on the subscription
			$available_gateways   = WC()->payment_gateways->get_available_payment_gateways();
			$order_payment_method = wcs_get_objects_property( $order, 'payment_method' );

			if ( $cart->needs_payment() && isset( $available_gateways[ $order_payment_method ] ) ) {
				$subscription->set_payment_method( $available_gateways[ $order_payment_method ] );
			}

			if ( ! $cart->needs_payment() || wcs_is_manual_renewal_required() ) {
				$subscription->set_requires_manual_renewal( true );
			} elseif ( ! isset( $available_gateways[ $order_payment_method ] ) || ! $available_gateways[ $order_payment_method ]->supports( 'subscriptions' ) ) {
				$subscription->set_requires_manual_renewal( true );
			}

			wcs_copy_order_meta( $order, $subscription, 'subscription' );

			// Store the line items
			if ( is_callable( array( WC()->checkout, 'create_order_line_items' ) ) ) {
				WC()->checkout->create_order_line_items( $subscription, $cart );
			} else {
				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
					$item_id = self::add_cart_item( $subscription, $cart_item, $cart_item_key );
				}
			}

			// Store fees (although no fees recur by default, extensions may add them)
			if ( is_callable( array( WC()->checkout, 'create_order_fee_lines' ) ) ) {
				WC()->checkout->create_order_fee_lines( $subscription, $cart );
			} else {
				foreach ( $cart->get_fees() as $fee_key => $fee ) {
					$item_id = $subscription->add_fee( $fee );

					if ( ! $item_id ) {
						// translators: placeholder is an internal error number
						throw new Exception( sprintf( __( 'Error %d: Unable to create subscription. Please try again.', 'woocommerce-subscriptions' ), 403 ) );
					}

					// Allow plugins to add order item meta to fees
					do_action( 'woocommerce_add_order_fee_meta', $subscription->get_id(), $item_id, $fee, $fee_key );
				}
			}

			self::add_shipping( $subscription, $cart );

			// Store tax rows
			if ( is_callable( array( WC()->checkout, 'create_order_tax_lines' ) ) ) {
				WC()->checkout->create_order_tax_lines( $subscription, $cart );
			} else {
				foreach ( array_keys( $cart->taxes + $cart->shipping_taxes ) as $tax_rate_id ) {
					if ( $tax_rate_id && ! $subscription->add_tax( $tax_rate_id, $cart->get_tax_amount( $tax_rate_id ), $cart->get_shipping_tax_amount( $tax_rate_id ) ) && apply_filters( 'woocommerce_cart_remove_taxes_zero_rate_id', 'zero-rated' ) !== $tax_rate_id ) {
						// translators: placeholder is an internal error number
						throw new Exception( sprintf( __( 'Error %d: Unable to add tax to subscription. Please try again.', 'woocommerce-subscriptions' ), 405 ) );
					}
				}
			}

			// Store coupons
			if ( is_callable( array( WC()->checkout, 'create_order_coupon_lines' ) ) ) {
				WC()->checkout->create_order_coupon_lines( $subscription, $cart );
			} else {
				foreach ( $cart->get_coupons() as $code => $coupon ) {
					if ( ! $subscription->add_coupon( $code, $cart->get_coupon_discount_amount( $code ), $cart->get_coupon_discount_tax_amount( $code ) ) ) {
						// translators: placeholder is an internal error number
						throw new Exception( sprintf( __( 'Error %d: Unable to create order. Please try again.', 'woocommerce-subscriptions' ), 406 ) );
					}
				}
			}

			// Set the recurring totals on the subscription
			$subscription->set_shipping_total( $cart->shipping_total );
			$subscription->set_discount_total( $cart->get_cart_discount_total() );
			$subscription->set_discount_tax( $cart->get_cart_discount_tax_total() );
			$subscription->set_cart_tax( $cart->tax_total );
			$subscription->set_shipping_tax( $cart->shipping_tax_total );
			$subscription->set_total( $cart->total );

			// Hook to adjust subscriptions before saving with WC 3.0+ (matches WC 3.0's new 'woocommerce_checkout_create_order' hook)
			do_action( 'woocommerce_checkout_create_subscription', $subscription, $posted_data, $order, $cart );

			// Save the subscription if using WC 3.0 & CRUD
			$subscription->save();

			// If we got here, the subscription was created without problems
			$transaction->commit();

		} catch ( Exception $e ) {
			// There was an error adding the subscription
			$transaction->rollback();
			return new WP_Error( 'checkout-error', $e->getMessage() );
		}

		/**
		 * Fetch and return a fresh instance of the subscription from the database.
		 *
		 * After saving the subscription, we need to fetch the subscription from the database as the current object state may not match the loaded state.
		 * This occurs because different instances of the subscription might have been saved in any one of the processes above resulting in this object being out of sync.
		 */
		return wcs_get_subscription( $subscription );
	}


	/**
	 * Stores shipping info on the subscription
	 *
	 * @param WC_Subscription $subscription instance of a subscriptions object
	 * @param WC_Cart $cart A cart with recurring items in it
	 */
	public static function add_shipping( $subscription, $cart ) {

		// We need to make sure we only get recurring shipping packages
		WC_Subscriptions_Cart::set_calculation_type( 'recurring_total' );
		WC_Subscriptions_Cart::set_recurring_cart_key( $cart->recurring_cart_key );

		if ( $cart->needs_shipping() ) {
			foreach ( $cart->get_shipping_packages() as $recurring_cart_package_key => $recurring_cart_package ) {
				$package_index      = isset( $recurring_cart_package['package_index'] ) ? $recurring_cart_package['package_index'] : 0;
				$package            = WC()->shipping->calculate_shipping_for_package( $recurring_cart_package );
				$shipping_method_id = isset( WC()->checkout()->shipping_methods[ $package_index ] ) ? WC()->checkout()->shipping_methods[ $package_index ] : '';

				if ( isset( WC()->checkout()->shipping_methods[ $recurring_cart_package_key ] ) ) {
					$shipping_method_id = WC()->checkout()->shipping_methods[ $recurring_cart_package_key ];
					$package_key        = $recurring_cart_package_key;
				} else {
					$package_key = $package_index;
				}

				if ( isset( $package['rates'][ $shipping_method_id ] ) ) {
					$shipping_rate            = $package['rates'][ $shipping_method_id ];
					$item                     = new WC_Order_Item_Shipping();
					$item->legacy_package_key = $package_key; // @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions, For legacy actions.
					$item->set_props(
						array(
							'method_title' => $shipping_rate->label,
							'total'        => wc_format_decimal( $shipping_rate->cost ),
							'taxes'        => array( 'total' => $shipping_rate->taxes ),
							'order_id'     => $subscription->get_id(),
						)
					);

					// Backwards compatibility for sites running WC pre 3.4 which stored shipping method and instance ID in a single meta row.
					if ( wcs_is_woocommerce_pre( '3.4' ) ) {
						$item->set_method_id( $shipping_rate->id );
					} else {
						$item->set_method_id( $shipping_rate->method_id );
						$item->set_instance_id( $shipping_rate->instance_id );
					}

					foreach ( $shipping_rate->get_meta_data() as $key => $value ) {
						$item->add_meta_data( $key, $value, true );
					}

					$subscription->add_item( $item );

					$item->save(); // We need the item ID for old hooks, this can be removed once support for WC < 3.0 is dropped
					wc_do_deprecated_action( 'woocommerce_subscriptions_add_recurring_shipping_order_item', array( $subscription->get_id(), $item->get_id(), $package_key ), '2.2.0', 'CRUD and woocommerce_checkout_create_subscription_shipping_item action instead' );

					do_action( 'woocommerce_checkout_create_order_shipping_item', $item, $package_key, $package, $subscription ); // WC 3.0+ will also trigger the deprecated 'woocommerce_add_shipping_order_item' hook
					do_action( 'woocommerce_checkout_create_subscription_shipping_item', $item, $package_key, $package, $subscription );
				}
			}
		}

		WC_Subscriptions_Cart::set_calculation_type( 'none' );
		WC_Subscriptions_Cart::set_recurring_cart_key( 'none' );
	}

	/**
	 * Remove the Backordered meta data from subscription line items added on the checkout.
	 *
	 * @param WC_Order_Item_Product $order_item
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @param array $cart_item The cart item's data.
	 * @param WC_Order|WC_Subscription $subscription The order or subscription object to which the line item relates
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public static function remove_backorder_meta_from_subscription_line_item( $item, $cart_item_key, $cart_item, $subscription ) {

		if ( wcs_is_subscription( $subscription ) ) {
			$item->delete_meta_data( apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'woocommerce-subscriptions' ) ) );
		}
	}

	/**
	 * Set a flag in subscription line item meta if the line item has a free trial.
	 *
	 * @param WC_Order_Item_Product $item The item being added to the subscription.
	 * @param string $cart_item_key The item's cart item key.
	 * @param array $cart_item The cart item.
	 * @param WC_Subscription $subscription The subscription the item is being added to.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public static function maybe_add_free_trial_item_meta( $item, $cart_item_key, $cart_item, $subscription ) {
		if ( wcs_is_subscription( $subscription ) && WC_Subscriptions_Product::get_trial_length( $item->get_product() ) > 0 ) {
			$item->update_meta_data( '_has_trial', 'true' );
		}
	}

	/**
	 * Add a cart item to a subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function add_cart_item( $subscription, $cart_item, $cart_item_key ) {
		_deprecated_function( __METHOD__, '2.2.0', 'WC_Checkout::create_order_line_items( $subscription, $cart )' );

		$item_id = $subscription->add_product(
			$cart_item['data'],
			$cart_item['quantity'],
			array(
				'variation' => $cart_item['variation'],
				'totals'    => array(
					'subtotal'     => $cart_item['line_subtotal'],
					'subtotal_tax' => $cart_item['line_subtotal_tax'],
					'total'        => $cart_item['line_total'],
					'tax'          => $cart_item['line_tax'],
					'tax_data'     => $cart_item['line_tax_data'],
				),
			)
		);

		if ( ! $item_id ) {
			// translators: placeholder is an internal error number
			throw new Exception( sprintf( __( 'Error %d: Unable to create subscription. Please try again.', 'woocommerce-subscriptions' ), 402 ) );
		}

		$cart_item_product_id = ( 0 != $cart_item['variation_id'] ) ? $cart_item['variation_id'] : $cart_item['product_id'];

		if ( WC_Subscriptions_Product::get_trial_length( wcs_get_canonical_product_id( $cart_item ) ) > 0 ) {
			wc_add_order_item_meta( $item_id, '_has_trial', 'true' );
		}

		// Allow plugins to add order item meta
		wc_do_deprecated_action( 'woocommerce_add_order_item_meta', array( $item_id, $cart_item, $cart_item_key ), '3.0', 'CRUD and woocommerce_checkout_create_order_line_item action instead' );
		wc_do_deprecated_action( 'woocommerce_add_subscription_item_meta', array( $item_id, $cart_item, $cart_item_key ), '3.0', 'CRUD and woocommerce_checkout_create_order_line_item action instead' );

		return $item_id;
	}

	/**
	 * When a new order is inserted, add subscriptions related order meta.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function add_order_meta( $order_id, $posted ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Add each subscription product's details to an order so that the state of the subscription persists even when a product is changed
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2.5
	 */
	public static function add_order_item_meta( $item_id, $values ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Also make sure the guest checkout option value passed to the woocommerce.js forces registration.
	 * Otherwise the registration form is hidden by woocommerce.js.
	 *
	 * @param string $handle Default empty string ('').
	 * @param array  $woocommerce_params
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
	 * @return array
	 */
	public static function filter_woocommerce_script_parameters( $woocommerce_params, $handle = '' ) {
		// WC 3.3+ deprecates handle-specific filters in favor of 'woocommerce_get_script_data'.
		if ( 'woocommerce_get_script_data' === current_filter() && ! in_array( $handle, array( 'woocommerce', 'wc-checkout' ) ) ) {
			return $woocommerce_params;
		}

		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() && isset( $woocommerce_params['option_guest_checkout'] ) && 'yes' == $woocommerce_params['option_guest_checkout'] ) {
			$woocommerce_params['option_guest_checkout'] = 'no';
		}

		return $woocommerce_params;
	}

	/**
	 * Stores the subtracted base location tax totals in the subscription line item meta.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.10
	 *
	 * @param WC_Line_Item_Product $line_item     The line item added to the order/subscription.
	 * @param string               $cart_item_key The key of the cart item being added to the cart.
	 * @param array                $cart_item     The cart item data.
	 */
	public static function store_line_item_base_location_taxes( $line_item, $cart_item_key, $cart_item ) {
		if ( isset( $cart_item['_subtracted_base_location_taxes'] ) ) {
			$line_item->add_meta_data( '_subtracted_base_location_taxes', $cart_item['_subtracted_base_location_taxes'] );
			$line_item->add_meta_data( '_subtracted_base_location_rates', $cart_item['_subtracted_base_location_rates'] );
		}
	}

	/**
	 * Also make sure the guest checkout option value passed to the woocommerce.js forces registration.
	 * Otherwise the registration form is hidden by woocommerce.js.
	 *
	 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
	 */
	public static function filter_woocommerce_script_paramaters( $woocommerce_params, $handle = '' ) {
		wcs_deprecated_function( __METHOD__, '2.5.3', 'WC_Subscriptions_Admin::filter_woocommerce_script_parameters( $woocommerce_params, $handle )' );

		return self::filter_woocommerce_script_parameters( $woocommerce_params, $handle );
	}

	/**
	 * Enables the 'registeration required' (guest checkout) setting when purchasing subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param bool $account_required Whether an account is required to checkout.
	 * @return bool
	 */
	public static function require_registration_during_checkout( $account_required ) {
		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {
			$account_required = true;
		}

		return $account_required;
	}

	/**
	 * During the checkout process, force registration when the cart contains a subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @param $woocommerce_params This parameter is not used.
	 */
	public static function force_registration_during_checkout( $woocommerce_params ) {
		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {
			$_POST['createaccount'] = 1;
		}
	}

	/**
	 * Generates a registration failed error message depending on the store's registration settings.
	 *
	 * When a customer wasn't created on checkout because checkout registration is disabled,
	 * this function generates the error message displayed to the customer.
	 *
	 * The message will redirect the customer to the My Account page if registration is enabled there, otherwise a generic 'you need an account' message will be displayed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.11
	 * @return string The error message.
	 */
	private static function get_registration_error_message() {
		// Direct the customer to login/register on the my account page if that's enabled.
		if ( 'yes' === get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			// Translators: Placeholders are opening and closing strong and link tags.
			$message = __( 'Purchasing a subscription product requires an account. Please go to the %1$sMy Account%2$s page to login or register.', 'woocommerce-subscriptions' );
		} else {
			// Translators: Placeholders are opening and closing strong and link tags.
			$message = __( 'Purchasing a subscription product requires an account. Please go to the %1$sMy Account%2$s page to login or contact us if you need assistance.', 'woocommerce-subscriptions' );
		}

		return sprintf( $message, '<strong><a href="' . wc_get_page_permalink( 'myaccount' ) . '">', '</a></strong>' );
	}

	/**
	 * Enables registration for carts containing subscriptions if admin allow it.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param  bool $registration_enabled Whether registration is enabled on checkout by default.
	 * @return bool
	 */
	public static function maybe_enable_registration( $registration_enabled ) {
		// Exit early if regristration is already allowed.
		if ( $registration_enabled ) {
			return $registration_enabled;
		}

		if ( is_user_logged_in() || ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return $registration_enabled;
		}

		if ( apply_filters( 'wc_is_registration_enabled_for_subscription_purchases', 'yes' === get_option( 'woocommerce_enable_signup_from_checkout_for_subscriptions', 'yes' ) ) ) {
			$registration_enabled = true;
		}

		return $registration_enabled;
	}

	/**
	 * When creating an order at checkout, if the checkout is to renew a subscription from a failed
	 * payment, hijack the order creation to make a renewal order - not a plain WooCommerce order.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function filter_woocommerce_create_order( $order_id, $checkout_object ) {
		_deprecated_function( __METHOD__, '2.0' );
		return $order_id;
	}

	/**
	 * Customise which actions are shown against a subscriptions order on the My Account page.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function filter_woocommerce_my_account_my_orders_actions( $actions, $order ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::filter_my_account_my_orders_actions()' );
		return $actions;
	}

	/**
	 * If shopping cart contains subscriptions, make sure a user can register on the checkout page
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public static function make_checkout_registration_possible( $checkout = '' ) {
		wcs_deprecated_function( __METHOD__, '3.1.0' );
		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {

			// Make sure users are required to register an account
			if ( true === $checkout->enable_guest_checkout ) {
				$checkout->enable_guest_checkout     = false;
				self::$guest_checkout_option_changed = true;

				$checkout->must_create_account = true;
			}
		}
	}

	/**
	 * Make sure account fields display the required "*" when they are required.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public static function make_checkout_account_fields_required( $checkout_fields ) {
		wcs_deprecated_function( __METHOD__, '3.1.0' );
		if ( WC_Subscriptions_Cart::cart_contains_subscription() && ! is_user_logged_in() ) {

			$account_fields = array(
				'account_username',
				'account_password',
				'account_password-2',
			);

			foreach ( $account_fields as $account_field ) {
				if ( isset( $checkout_fields['account'][ $account_field ] ) ) {
					$checkout_fields['account'][ $account_field ]['required'] = true;
				}
			}
		}

		return $checkout_fields;
	}

	/**
	 * After displaying the checkout form, restore the store's original registration settings.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 */
	public static function restore_checkout_registration_settings( $checkout = '' ) {
		wcs_deprecated_function( __METHOD__, '3.1.0' );
		if ( self::$guest_checkout_option_changed ) {
			$checkout->enable_guest_checkout = true;
			if ( ! is_user_logged_in() ) { // Also changed must_create_account
				$checkout->must_create_account = false;
			}
		}
	}

	/**
	 * Overrides the "Place order" button text with "Sign up now" when the cart contains initial subscription purchases.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param string  $button_text The place order button text.
	 * @return string $button_text
	 */
	public static function order_button_text( $button_text ) {
		if ( ! WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return $button_text;
		}

		// Return the default button text if the cart contains a Subscription order type. The button text for these carts is filtered separately.
		if ( wcs_cart_contains_renewal() || wcs_cart_contains_resubscribe() || wcs_cart_contains_switches() ) {
			return $button_text;
		}

		return apply_filters( 'wcs_place_subscription_order_text', __( 'Sign up now', 'woocommerce-subscriptions' ) );
	}

	/**
	 * If the cart contains a renewal order, resubscribe order or a subscription switch
	 * that needs to ship to an address that is different to the order's billing address,
	 * tell the checkout to check the "Ship to different address" checkbox.
	 *
	 * @since 5.3.0
	 *
	 * @param  bool $ship_to_different_address Whether the order will check the "Ship to different address" checkbox
	 * @return bool $ship_to_different_address
	 */
	public static function maybe_check_ship_to_different_address( $ship_to_different_address ) {
		$switch_items     = wcs_cart_contains_switches();
		$renewal_item     = wcs_cart_contains_renewal();
		$resubscribe_item = wcs_cart_contains_resubscribe();

		if ( ! $switch_items && ! $renewal_item && ! $resubscribe_item ) {
			return $ship_to_different_address;
		}

		if ( ! $ship_to_different_address ) {
			// Get the subscription ID from the corresponding cart item
			if ( $switch_items ) {
				$subscription_id = array_values( $switch_items )[0]['subscription_id'];
			} elseif ( $renewal_item ) {
				$subscription_id = $renewal_item['subscription_renewal']['subscription_id'];
			} elseif ( $resubscribe_item ) {
				$subscription_id = $resubscribe_item['subscription_resubscribe']['subscription_id'];
			}

			$order = wc_get_order( $subscription_id );

			// If the order's addresses are different, we need to display the shipping fields otherwise the billing address will override it
			$addresses_are_equal = wcs_compare_order_billing_shipping_address( $order );
			if ( ! $addresses_are_equal ) {
				$ship_to_different_address = 1;
			}
		}

		return $ship_to_different_address;
	}
}
