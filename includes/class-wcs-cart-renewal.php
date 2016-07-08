<?php
/**
 * Implement renewing to a subscription via the cart.
 *
 * For manual renewals and the renewal of a subscription after a failed automatic payment, the customer must complete
 * the renewal via checkout in order to pay for the renewal. This class handles that.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WCS_Cart_Renewal
 * @category	Class
 * @author		Prospress
 * @since		2.0
 */

class WCS_Cart_Renewal {

	/* The flag used to indicate if a cart item is a renewal */
	public $cart_item_key = 'subscription_renewal';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public function __construct() {

		$this->setup_hooks();

		// Set URL parameter for manual subscription renewals
		add_filter( 'woocommerce_get_checkout_payment_url', array( &$this, 'get_checkout_payment_url' ), 10, 2 );

		// Remove order action buttons from the My Account page
		add_filter( 'woocommerce_my_account_my_orders_actions', array( &$this, 'filter_my_account_my_orders_actions' ), 10, 2 );

		// Update customer's address on the subscription if it is changed during renewal
		add_filter( 'woocommerce_checkout_update_customer_data', array( &$this, 'maybe_update_subscription_customer_data' ), 10, 2 );

		// When a failed renewal order is paid for via checkout, make sure WC_Checkout::create_order() preserves its "failed" status until it is paid
		add_filter( 'woocommerce_default_order_status', array( &$this, 'maybe_preserve_order_status' ) );
	}

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public function setup_hooks() {

		// Make sure renewal meta data persists between sessions
		add_filter( 'woocommerce_get_cart_item_from_session', array( &$this, 'get_cart_item_from_session' ), 10, 3 );
		add_action( 'woocommerce_cart_loaded_from_session', array( &$this, 'cart_items_loaded_from_session' ), 10 );

		// Make sure fees are added to the cart
		add_action( 'woocommerce_cart_calculate_fees', array( &$this, 'maybe_add_fees' ), 10, 1 );

		// Allow renewal of limited subscriptions
		add_filter( 'woocommerce_subscription_is_purchasable', array( &$this, 'is_purchasable' ), 12, 2 );
		add_filter( 'woocommerce_subscription_variation_is_purchasable', array( &$this, 'is_purchasable' ), 12, 2 );

		// Check if a user is requesting to create a renewal order for a subscription, needs to happen after $wp->query_vars are set
		add_action( 'template_redirect', array( &$this, 'maybe_setup_cart' ), 100 );

		// Apply renewal discounts as pseudo coupons
		add_action( 'wcs_after_renewal_setup_cart_subscription', array( &$this, 'maybe_setup_discounts' ), 10, 1 );
		add_filter( 'woocommerce_get_shop_coupon_data', array( &$this, 'renewal_coupon_data' ), 10, 2 );
		add_action( 'wcs_before_renewal_setup_cart_subscriptions', array( &$this, 'clear_coupons' ), 10 );

		add_action( 'woocommerce_remove_cart_item', array( &$this, 'maybe_remove_items' ), 10, 1 );
		add_action( 'woocommerce_before_cart_item_quantity_zero', array( &$this, 'maybe_remove_items' ), 10, 1 );
		add_action( 'woocommerce_cart_emptied', array( &$this, 'clear_coupons' ), 10 );

		add_filter( 'woocommerce_cart_item_removed_title', array( &$this, 'items_removed_title' ), 10, 2 );

		add_action( 'woocommerce_cart_item_restored', array( &$this, 'maybe_restore_items' ), 10, 1 );

		// Use original order price when resubscribing to products with addons (to ensure the adds on prices are included)
		add_filter( 'woocommerce_product_addons_adjust_price', array( &$this, 'product_addons_adjust_price' ), 10, 2 );
	}

	/**
	 * Check if a payment is being made on a renewal order from 'My Account'. If so,
	 * redirect the order into a cart/checkout payment flow so that the customer can
	 * choose payment method, apply discounts set shipping and pay for the order.
	 *
	 * @since 2.0
	 */
	public function maybe_setup_cart() {

		global $wp;

		if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) && isset( $wp->query_vars['order-pay'] ) ) {

			// Pay for existing order
			$order_key = $_GET['key'];
			$order_id  = ( isset( $wp->query_vars['order-pay'] ) ) ? $wp->query_vars['order-pay'] : absint( $_GET['order_id'] );
			$order     = wc_get_order( $wp->query_vars['order-pay'] );

			if ( $order->order_key == $order_key && $order->has_status( array( 'pending', 'failed' ) ) && wcs_order_contains_renewal( $order ) ) {

				$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

				do_action( 'wcs_before_renewal_setup_cart_subscriptions', $subscriptions, $order );

				foreach ( $subscriptions as $subscription ) {

					do_action( 'wcs_before_renewal_setup_cart_subscription', $subscription, $order );

					// Add the existing subscription items to the cart
					$this->setup_cart( $subscription, array(
						'subscription_id'  => $subscription->id,
						'renewal_order_id' => $order_id,
					) );

					do_action( 'wcs_after_renewal_setup_cart_subscription', $subscription, $order );
				}

				do_action( 'wcs_after_renewal_setup_cart_subscriptions', $subscriptions, $order );

				if ( WC()->cart->cart_contents_count != 0 ) {
					// Store renewal order's ID in session so it can be re-used after payment
					WC()->session->set( 'order_awaiting_payment', $order_id );

					// Set cart hash for orders paid in WC >= 2.6
					$this->set_cart_hash( $order_id );
				}

				wp_safe_redirect( WC()->cart->get_checkout_url() );
				exit;
			}
		}
	}

	/**
	 * Set up cart item meta data for a to complete a subscription renewal via the cart.
	 *
	 * @since 2.0
	 */
	protected function setup_cart( $subscription, $cart_item_data ) {

		WC()->cart->empty_cart( true );
		$success = true;

		foreach ( $subscription->get_items() as $item_id => $line_item ) {
			// Load all product info including variation data
			$product_id   = (int) apply_filters( 'woocommerce_add_to_cart_product_id', $line_item['product_id'] );
			$quantity     = (int) $line_item['qty'];
			$variation_id = (int) $line_item['variation_id'];
			$variations   = array();

			foreach ( $line_item['item_meta'] as $meta_name => $meta_value ) {
				if ( taxonomy_is_product_attribute( $meta_name ) ) {
					$variations[ $meta_name ] = $meta_value[0];
				} elseif ( meta_is_product_attribute( $meta_name, $meta_value[0], $product_id ) ) {
					$variations[ $meta_name ] = $meta_value[0];
				}
			}

			$product = wc_get_product( $line_item['product_id'] );

			// The notice displayed when a subscription product has been deleted and the custoemr attempts to manually renew or make a renewal payment for a failed recurring payment for that product/subscription
			// translators: placeholder is an item name
			$product_deleted_error_message = apply_filters( 'woocommerce_subscriptions_renew_deleted_product_error_message', __( 'The %s product has been deleted and can no longer be renewed. Please choose a new product or contact us for assistance.', 'woocommerce-subscriptions' ) );

			// Display error message for deleted products
			if ( false === $product ) {

				wc_add_notice( sprintf( $product_deleted_error_message, $line_item['name'] ), 'error' );

			// Make sure we don't actually need the variation ID (if the product was a variation, it will have a variation ID; however, if the product has changed from a simple subscription to a variable subscription, there will be no variation_id)
			} elseif ( $product->is_type( array( 'variable-subscription' ) ) && ! empty( $line_item['variation_id'] ) ) {

				$variation = wc_get_product( $variation_id );

				// Display error message for deleted product variations
				if ( false === $variation ) {
					wc_add_notice( sprintf( $product_deleted_error_message, $line_item['name'] ), 'error' );
				}
			}

			if ( wcs_is_subscription( $subscription ) ) {
				$cart_item_data['subscription_line_item_id'] = $item_id;
			}

			$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations, apply_filters( 'woocommerce_order_again_cart_item_data', array( $this->cart_item_key => $cart_item_data ), $line_item, $subscription ) );
			$success       = $success && (bool) $cart_item_key;
		}

		// If a product linked to a subscription failed to be added to the cart prevent partially paying for the order by removing all cart items.
		if ( ! $success && wcs_is_subscription( $subscription ) ) {
			// translators: %s is subscription's number
			wc_add_notice( sprintf( esc_html__( 'Subscription #%s has not been added to the cart.', 'woocommerce-subscriptions' ), $subscription->get_order_number() ) , 'error' );
			WC()->cart->empty_cart( true );
		}

		do_action( 'woocommerce_setup_cart_for_' . $this->cart_item_key, $subscription, $cart_item_data );
	}

	/**
	 * Check if a renewal order subscription has any coupons applied and if so add pseudo renewal coupon equivalents to ensure the discount is still applied
	 *
	 * @param object $subscription subscription
	 * @since 2.0.10
	 */
	public function maybe_setup_discounts( $subscription ) {

		if ( wcs_is_subscription( $subscription ) ) {

			$used_coupons = $subscription->get_used_coupons();

			// Add any used coupon discounts to the cart (as best we can) using our pseudo renewal coupons
			if ( ! empty( $used_coupons ) ) {

				$coupon_items = $subscription->get_items( 'coupon' );

				foreach ( $coupon_items as $coupon_item ) {

					$coupon = new WC_Coupon( $coupon_item['name'] );

					$coupon_code = '';

					// If the coupon still exists we can use the existing/available coupon properties
					if ( true === $coupon->exists ) {

						// But we only want to handle recurring coupons that have been applied to the subscription
						if ( in_array( $coupon->type, array( 'recurring_percent', 'recurring_fee' ) ) ) {

							// Set the coupon type to be a renewal equivalent for correct validation and calculations
							if ( 'recurring_percent' == $coupon->type ) {
								$coupon->type = 'renewal_percent';
							} elseif ( 'recurring_fee' == $coupon->type ) {
								$coupon->type = 'renewal_fee';
							}

							// Adjust coupon code to reflect that it is being applied to a renewal
							$coupon_code = $coupon->code;
						}
					} else {

						// If the coupon doesn't exist we can only really apply the discount amount we know about - so we'll apply a cart style pseudo coupon and then set the amount
						$coupon->type = 'renewal_cart';
						$coupon->amount = $coupon_item['item_meta']['discount_amount']['0'];

						// Adjust coupon code to reflect that it is being applied to a renewal
						$coupon_code = $coupon->code;
					}

					// Now that we have a coupon we know we want to apply
					if ( ! empty( $coupon_code ) ) {

						// Set renewal order products as the product ids on the coupon
						if ( ! WC_Subscriptions::is_woocommerce_pre( '2.5' ) ) {
							$coupon->product_ids = $this->get_products( $subscription );
						}

						// Store the coupon info for later
						$this->store_coupon( $subscription->id, $coupon );

						// Add the coupon to the cart - the actually coupon values / data are grabbed when needed later
						if ( WC()->cart && ! WC()->cart->has_discount( $coupon_code ) ) {
							WC()->cart->add_discount( $coupon_code );
						}
					}
				}
			// If there are no coupons but there is still a discount (i.e. it might have been manually added), we need to account for that as well
			} elseif ( ! empty( $subscription->cart_discount ) ) {

				$coupon = new WC_Coupon( 'discount_renewal' );

				// Apply our cart style pseudo coupon and the set the amount
				$coupon->type = 'renewal_cart';
				$coupon->amount = $subscription->cart_discount;

				// Set renewal order products as the product ids on the coupon
				if ( ! WC_Subscriptions::is_woocommerce_pre( '2.5' ) ) {
					$coupon->product_ids = $this->get_products( $subscription );
				}

				// Store the coupon info for later
				$this->store_coupon( $subscription->id, $coupon );

				// Add the coupon to the cart
				if ( WC()->cart && ! WC()->cart->has_discount( 'discount_renewal' ) ) {
					WC()->cart->add_discount( 'discount_renewal' );
				}
			}
		}
	}

	/**
	 * Does some housekeeping. Fires after the items have been passed through the get items from session filter. Because
	 * that filter is not good for removing cart items, we need to work around that by doing it later, in the cart
	 * loaded from session action.
	 *
	 * This checks cart items whether underlying subscriptions / renewal orders they depend exist. If not, they are
	 * removed from the cart.
	 *
	 * @param $cart WC_Cart the one we got from session
	 */
	public function cart_items_loaded_from_session( $cart ) {
		$removed_count_subscription = $removed_count_order = 0;

		foreach ( $cart->cart_contents as $key => $item ) {
			if ( isset( $item[ $this->cart_item_key ]['subscription_id'] ) && ! wcs_is_subscription( $item[ $this->cart_item_key ]['subscription_id'] ) ) {
				$cart->remove_cart_item( $key );
				$removed_count_subscription++;
				continue;
			}

			if ( isset( $item[ $this->cart_item_key ]['renewal_order_id'] ) && ! 'shop_order' == get_post_type( $item[ $this->cart_item_key ]['renewal_order_id'] ) ) {
				$cart->remove_cart_item( $key );
				$removed_count_order++;
				continue;
			}
		}

		if ( $removed_count_subscription ) {
			$error_message = esc_html( _n( 'We couldn\'t find the original subscription for an item in your cart. The item was removed.', 'We couldn\'t find the original subscriptions for items in your cart. The items were removed.', $removed_count_subscription, 'woocommerce-subscriptions' ) );
			if ( ! wc_has_notice( $error_message, 'notice' ) ) {
				wc_add_notice( $error_message, 'notice' );
			}
		}

		if ( $removed_count_order ) {
			$error_message = esc_html( _n( 'We couldn\'t find the original renewal order for an item in your cart. The item was removed.', 'We couldn\'t find the original renewal orders for items in your cart. The items were removed.', $removed_count_order, 'woocommerce-subscriptions' ) );
			if ( ! wc_has_notice( $error_message, 'notice' ) ) {
				wc_add_notice( $error_message, 'notice' );
			}
		}
	}

	/**
	 * Restore renewal flag when cart is reset and modify Product object with renewal order related info
	 *
	 * @since 2.0
	 */
	public function get_cart_item_from_session( $cart_item_session_data, $cart_item, $key ) {

		if ( isset( $cart_item[ $this->cart_item_key ]['subscription_id'] ) ) {
			$cart_item_session_data[ $this->cart_item_key ] = $cart_item[ $this->cart_item_key ];

			$_product = $cart_item_session_data['data'];

			// Need to get the original subscription price, not the current price
			$subscription       = wcs_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );

			if ( $subscription ) {
				$subscription_items = $subscription->get_items();
				$item_to_renew      = $subscription_items[ $cart_item_session_data[ $this->cart_item_key ]['subscription_line_item_id'] ];

				$price = $item_to_renew['line_subtotal'];

				if ( wc_prices_include_tax() ) {
					$base_tax_rates = WC_Tax::get_base_tax_rates( $_product->tax_class );
					$base_taxes_on_item = WC_Tax::calc_tax( $price, $base_tax_rates, false, false );
					$price += array_sum( $base_taxes_on_item );
				}

				$_product->price = $price / $item_to_renew['qty'];

				// Don't carry over any sign up fee
				$_product->subscription_sign_up_fee = 0;

				$_product->post->post_title = apply_filters( 'woocommerce_subscriptions_renewal_product_title', $_product->get_title(), $_product );

				// Make sure the same quantity is renewed
				$cart_item_session_data['quantity'] = $item_to_renew['qty'];
			}
		}

		return $cart_item_session_data;
	}

	/**
	 * When completing checkout for a subscription renewal, update the address on the subscription to use
	 * the shipping/billing address entered in case it has changed since the subscription was first created.
	 *
	 * @since 2.0
	 */
	public function maybe_update_subscription_customer_data( $update_customer_data, $checkout_object ) {

		$cart_renewal_item = $this->cart_contains();

		if ( false !== $cart_renewal_item ) {

			$subscription = wcs_get_subscription( $cart_renewal_item[ $this->cart_item_key ]['subscription_id'] );

			$billing_address = array();
			if ( $checkout_object->checkout_fields['billing'] ) {
				foreach ( array_keys( $checkout_object->checkout_fields['billing'] ) as $field ) {
					$field_name = str_replace( 'billing_', '', $field );
					$billing_address[ $field_name ] = $checkout_object->get_posted_address_data( $field_name );
				}
			}

			$shipping_address = array();
			if ( $checkout_object->checkout_fields['shipping'] ) {
				foreach ( array_keys( $checkout_object->checkout_fields['shipping'] ) as $field ) {
					$field_name = str_replace( 'shipping_', '', $field );
					$shipping_address[ $field_name ] = $checkout_object->get_posted_address_data( $field_name, 'shipping' );
				}
			}

			$subscription->set_address( $billing_address, 'billing' );
			$subscription->set_address( $shipping_address, 'shipping' );
		}

		return $update_customer_data;
	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to resubscribe to the subscription, then mark it as purchasable.
	 *
	 * @since 2.0
	 * @return bool
	 */
	public function is_purchasable( $is_purchasable, $product ) {

		// If the product is being set as not-purchasable by Subscriptions (due to limiting)
		if ( false === $is_purchasable && false === WC_Subscriptions_Product::is_purchasable( $is_purchasable, $product ) ) {

			// Adding to cart from the product page or paying for a renewal
			if ( isset( $_GET[ $this->cart_item_key ] ) || isset( $_GET['subscription_renewal'] ) || $this->cart_contains() ) {

				$is_purchasable = true;

			} else if ( WC()->session->cart ) {

				foreach ( WC()->session->cart as $cart_item_key => $cart_item ) {

					if ( $product->id == $cart_item['product_id'] && isset( $cart_item['subscription_renewal'] ) ) {
						$is_purchasable = true;
						break;
					}
				}
			}
		}

		return $is_purchasable;
	}

	/**
	 * Flag payment of manual renewal orders via an extra URL param.
	 *
	 * This is particularly important to ensure renewals of limited subscriptions can be completed.
	 *
	 * @since 2.0
	 */
	public function get_checkout_payment_url( $pay_url, $order ) {

		if ( wcs_order_contains_renewal( $order ) ) {
			$pay_url = add_query_arg( array( $this->cart_item_key => 'true' ), $pay_url );
		}

		return $pay_url;
	}

	/**
	 * Customise which actions are shown against a subscription renewal order on the My Account page.
	 *
	 * @since 2.0
	 */
	public function filter_my_account_my_orders_actions( $actions, $order ) {

		if ( wcs_order_contains_renewal( $order ) ) {

			unset( $actions['cancel'] );

			// If the subscription has been deleted or reactivated some other way, don't support payment on the order
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

			foreach ( $subscriptions as $subscription ) {
				if ( empty( $subscription ) || ! $subscription->has_status( array( 'on-hold', 'pending' ) ) ) {
					unset( $actions['pay'] );
					break;
				}
			}
		}

		return $actions;
	}

	/**
	 * When a failed renewal order is being paid for via checkout, make sure WC_Checkout::create_order() preserves its
	 * status as 'failed' until it is paid. By default, it will always set it to 'pending', but we need it left as 'failed'
	 * so that we can correctly identify the status change in @see self::maybe_change_subscription_status().
	 *
	 * @param string Default order status for orders paid for via checkout. Default 'pending'
	 * @since 2.0
	 */
	public function maybe_preserve_order_status( $order_status ) {

		if ( null !== WC()->session ) {

			$order_id = absint( WC()->session->order_awaiting_payment );

			if ( $order_id > 0 && ( $order = wc_get_order( $order_id ) ) && wcs_order_contains_renewal( $order ) && $order->has_status( 'failed' ) ) {
				$order_status = 'failed';
			}
		}

		return $order_status;
	}

	/**
	 * Removes all the linked renewal/resubscribe items from the cart if a renewal/resubscribe item is removed.
	 *
	 * @param string $cart_item_key The cart item key of the item removed from the cart.
	 * @since 2.0
	 */
	public function maybe_remove_items( $cart_item_key ) {

		if ( isset( WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['subscription_id'] ) ) {

			$removed_item_count = 0;
			$subscription_id    = WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['subscription_id'];

			foreach ( WC()->cart->cart_contents as $key => $cart_item ) {

				if ( isset( $cart_item[ $this->cart_item_key ] ) && $subscription_id == $cart_item[ $this->cart_item_key ]['subscription_id'] ) {
					WC()->cart->removed_cart_contents[ $key ] = WC()->cart->cart_contents[ $key ];
					unset( WC()->cart->cart_contents[ $key ] );
					$removed_item_count++;
				}
			}

			//remove the renewal order flag
			unset( WC()->session->order_awaiting_payment );

			//clear renewal coupons
			$this->clear_coupons();

			if ( $removed_item_count > 1 && 'woocommerce_before_cart_item_quantity_zero' == current_filter() ) {
				wc_add_notice( esc_html__( 'All linked subscription items have been removed from the cart.', 'woocommerce-subscriptions' ), 'notice' );
			}
		}
	}

	/**
	 * Checks the cart to see if it contains a subscription renewal item.
	 *
	 * @see wcs_cart_contains_renewal()
	 * @return bool | Array The cart item containing the renewal, else false.
	 * @since  2.0.10
	 */
	protected function cart_contains() {
		return wcs_cart_contains_renewal();
	}

	/**
	 * Formats the title of the product removed from the cart. Because we have removed all
	 * linked renewal/resubscribe items from the cart we need a product title to reflect that.
	 *
	 * @param string $product_title
	 * @param $cart_item
	 * @return string $product_title
	 * @since 2.0
	 */
	public function items_removed_title( $product_title, $cart_item ) {

		if ( isset( $cart_item[ $this->cart_item_key ]['subscription_id'] ) ) {
			$subscription  = wcs_get_subscription( absint( $cart_item[ $this->cart_item_key ]['subscription_id'] ) );
			$product_title = ( count( $subscription->get_items() ) > 1 ) ? esc_html_x( 'All linked subscription items were', 'Used in WooCommerce by removed item notification: "_All linked subscription items were_ removed. Undo?" Filter for item title.', 'woocommerce-subscriptions' ) : $product_title;
		}

		return $product_title;
	}

	/**
	 * Restores all linked renewal/resubscribe items to the cart if the customer has restored one.
	 *
	 * @param string $cart_item_key The cart item key of the item being restored to the cart.
	 * @since 2.0
	 */
	public function maybe_restore_items( $cart_item_key ) {

		if ( isset( WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['subscription_id'] ) ) {

			$subscription_id = WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['subscription_id'];

			foreach ( WC()->cart->removed_cart_contents as $key => $cart_item ) {

				if ( isset( $cart_item[ $this->cart_item_key ] ) && $key != $cart_item_key && $cart_item[ $this->cart_item_key ]['subscription_id'] == $subscription_id ) {
					WC()->cart->cart_contents[ $key ] = WC()->cart->removed_cart_contents[ $key ];
					unset( WC()->cart->removed_cart_contents[ $key ] );
				}
			}

			//restore the renewal order flag
			if ( isset( WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['renewal_order_id'] ) ) {
				WC()->session->set( 'order_awaiting_payment', WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['renewal_order_id'] );
			}
		}
	}

	/**
	 * Return our custom pseudo coupon data for renewal coupons
	 *
	 * @param array $data the coupon data
	 * @param string $code the coupon code that data is being requested for
	 * @return array the custom coupon data
	 * @since 2.0.10
	 */
	public function renewal_coupon_data( $data, $code ) {

		if ( ! is_object( WC()->session ) ) {
			return $data;
		}

		$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons' );

		if ( empty( $renewal_coupons ) ) {
			return $data;
		}

		foreach ( $renewal_coupons as $subscription_id => $coupons ) {

			foreach ( $coupons as $coupon ) {

				// Tweak the coupon data for renewal coupons
				if ( $code == $coupon->code ) {

					$data = array(
						'discount_type'              => $coupon->type,
						'coupon_amount'              => $coupon->amount,
						'individual_use'             => ( $coupon->individual_use ) ? $coupon->individual_use : 'no',
						'product_ids'                => ( $coupon->product_ids ) ? $coupon->product_ids : array(),
						'exclude_product_ids'        => ( $coupon->exclude_product_ids ) ? $coupon->exclude_product_ids : array(),
						'usage_limit'                => '',
						'usage_count'                => '',
						'expiry_date'                => '',
						'free_shipping'              => ( $coupon->free_shipping ) ? $coupon->free_shipping : '',
						'product_categories'         => ( $coupon->product_categories ) ? $coupon->product_categories : array(),
						'exclude_product_categories' => ( $coupon->exclude_product_categories ) ? $coupon->exclude_product_categories : array(),
						'exclude_sale_items'         => ( $coupon->exclude_sale_items ) ? $coupon->exclude_sale_items : 'no',
						'minimum_amount'             => ( $coupon->minimum_amount ) ? $coupon->minimum_amount : '',
						'maximum_amount'             => ( $coupon->maximum_amount ) ? $coupon->maximum_amount : '',
						'customer_email'             => ( $coupon->customer_email ) ? $coupon->customer_email : array(),
					);
				}
			}
		}
		return $data;
	}

	/**
	 * Get original products for a renewal order - so that we can ensure renewal coupons are only applied to those
	 *
	 * @param  object $subscription subscription
	 * @return array $product_ids an array of product ids on a subscription renewal order
	 * @since 2.0.10
	 */
	protected function get_products( $subscription ) {

		$product_ids = array();

		if ( wcs_is_subscription( $subscription ) ) {
			foreach ( $subscription->get_items() as $item ) {
				$product_id = ( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
				if ( ! empty( $product_id ) ) {
					$product_ids[] = $product_id;
				}
			}
		}

		return $product_ids;
	}

	/**
	 * Store renewal coupon information in a session variable so we can access it later when coupon data is being retrieved
	 *
	 * @param  int $subscription_id subscription id
	 * @param  object $coupon coupon
	 * @since 2.0.10
	 */
	protected function store_coupon( $subscription_id, $coupon ) {
		if ( ! empty( $subscription_id ) && ! empty( $coupon ) ) {

			$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons', array() );

			// Subscriptions may have multiple coupons, store coupons in array
			if ( array_key_exists( $subscription_id, $renewal_coupons ) ) {
				$renewal_coupons[ $subscription_id ][] = $coupon;
			} else {
				$renewal_coupons[ $subscription_id ] = array( $coupon );
			}

			WC()->session->set( 'wcs_renewal_coupons', $renewal_coupons );
		}
	}

	/**
	 * Clear renewal coupons - protects against confusing customer facing notices if customers add one renewal order to the cart with a set of coupons and then decide to add another renewal order with a different set of coupons
	 *
	 * @since 2.0.10
	 */
	public function clear_coupons() {

		$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons' );

		// Remove the coupons from the cart
		if ( ! empty( $renewal_coupons ) ) {
			foreach ( $renewal_coupons as $subscription_id => $coupons ) {
				foreach ( $coupons as $coupon ) {
					WC()->cart->remove_coupons( $coupon->code );
				}
			}
		}

		// Clear the session information we have stored
		WC()->session->set( 'wcs_renewal_coupons', array() );
	}

	/**
	 * Add order/subscription fee line items to the cart when a renewal order, initial order or resubscribe is in the cart.
	 *
	 * @param WC_Cart $cart
	 * @since 2.0.13
	 */
	public function maybe_add_fees( $cart ) {

		if ( $cart_item = $this->cart_contains() ) {

			$order = $this->get_order( $cart_item );

			if ( $order instanceof WC_Order ) {
				foreach ( $order->get_fees() as $fee ) {
					$cart->add_fee( $fee['name'], $fee['line_total'], abs( $fee['line_tax'] ) > 0, $fee['tax_class'] );
				}
			}
		}
	}

	/**
	 * When restoring the cart from the session, if the cart item contains addons, as well as
	 * a renewal or resubscribe, do not adjust the price because the original order's price will
	 * be used, and this includes the addons amounts.
	 *
	 * @since 2.0
	 */
	public function product_addons_adjust_price( $adjust_price, $cart_item ) {

		if ( true === $adjust_price && isset( $cart_item[ $this->cart_item_key ] ) ) {
			$adjust_price = false;
		}

		return $adjust_price;
	}

	/**
	 * Get the order object used to construct the renewal cart.
	 *
	 * @param Array The renewal cart item.
	 * @return WC_Order | The order object
	 * @since  2.0.13
	 */
	protected function get_order( $cart_item = '' ) {
		$order = false;

		if ( empty( $cart_item ) ) {
			$cart_item = $this->cart_contains();
		}

		if ( false !== $cart_item  && isset( $cart_item[ $this->cart_item_key ] ) ) {
			$order = wc_get_order( $cart_item[ $this->cart_item_key ]['renewal_order_id'] );
		}

		return $order;
	}

	/**
	 * Before allowing payment on an order awaiting payment via checkout, WC >= 2.6 validates
	 * order items haven't changed by checking for a cart hash on the order, so we need to set
	 * that here. @see WC_Checkout::create_order()
	 *
	 * @since 2.0.14
	 */
	protected function set_cart_hash( $order_id ) {
		update_post_meta( $order_id, '_cart_hash', md5( json_encode( wc_clean( WC()->cart->get_cart_for_session() ) ) . WC()->cart->total ) );
	}

	/* Deprecated */

	/**
	 * For subscription renewal via cart, use original order discount
	 *
	 * @since 2.0
	 */
	public function set_renewal_discounts( $cart ) {
		_deprecated_function( __METHOD__, '2.0.10', 'Applying original subscription discounts to renewals via cart are now handled within ' . __CLASS__ .'::maybe_setup_cart()' );
	}

	/**
	 * For subscription renewal via cart, previously adjust item price by original order discount
	 *
	 * No longer required as of 1.3.5 as totals are calculated correctly internally.
	 *
	 * @since 2.0
	 */
	public function get_discounted_price_for_renewal( $price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0.10', 'No longer required as of 1.3.5 as totals are calculated correctly internally.' );
	}

	/**
	 * Add subscription fee line items to the cart when a renewal order or resubscribe is in the cart.
	 *
	 * @param WC_Cart $cart
	 * @since 2.0.10
	 */
	public function maybe_add_subscription_fees( $cart ) {
		_deprecated_function( __METHOD__, '2.0.13', __CLASS__ .'::maybe_add_fees()' );
	}
}
new WCS_Cart_Renewal();
