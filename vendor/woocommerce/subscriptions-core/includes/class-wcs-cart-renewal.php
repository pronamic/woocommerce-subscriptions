<?php
/**
 * Implement renewing to a subscription via the cart.
 *
 * For manual renewals and the renewal of a subscription after a failed automatic payment, the customer must complete
 * the renewal via checkout in order to pay for the renewal. This class handles that.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Cart_Renewal
 * @category   Class
 * @author     Prospress
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

class WCS_Cart_Renewal {

	/* The flag used to indicate if a cart item is a renewal */
	public $cart_item_key = 'subscription_renewal';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function __construct() {

		$this->setup_hooks();

		// Attach hooks which depend on WooCommerce constants
		add_action( 'woocommerce_loaded', array( &$this, 'attach_dependant_hooks' ), 10 );

		// Set URL parameter for manual subscription renewals
		add_filter( 'woocommerce_get_checkout_payment_url', array( &$this, 'get_checkout_payment_url' ), 10, 2 );

		// Remove order action buttons from the My Account page
		add_filter( 'woocommerce_my_account_my_orders_actions', array( &$this, 'filter_my_account_my_orders_actions' ), 10, 2 );

		// When a failed/pending renewal order is paid for via checkout, ensure a new order isn't created due to mismatched cart hashes
		add_filter( 'woocommerce_create_order', array( &$this, 'update_cart_hash' ), 10, 1 );
		add_filter( 'woocommerce_order_has_status', array( &$this, 'set_renewal_order_cart_hash_on_block_checkout' ), 10, 3 );

		// When a user is prevented from paying for a failed/pending renewal order because they aren't logged in, redirect them back after login
		add_filter( 'woocommerce_login_redirect', array( &$this, 'maybe_redirect_after_login' ), 10, 2 );

		// Once we have finished updating the renewal order on checkout, update the session cart so the cart changes are honoured.
		add_action( 'woocommerce_checkout_order_processed', array( &$this, 'update_session_cart_after_updating_renewal_order' ), 10 );

		add_filter( 'wc_dynamic_pricing_apply_cart_item_adjustment', array( &$this, 'prevent_compounding_dynamic_discounts' ), 10, 2 );

		// Remove non-recurring fees from renewal carts. Hooked in late (priority 1000), to ensure we handle all fees added by third-parties.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'remove_non_recurring_fees' ), 1000 );

		// Remove subscription products with "one time shipping" from shipping packages.
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'maybe_update_shipping_packages' ), 0, 1 );

		add_action( 'wcs_before_renewal_setup_cart_subscriptions', array( &$this, 'clear_coupons' ), 10 );

		// Handles renew of password-protected products.
		add_action( 'wcs_before_renewal_setup_cart_subscriptions', 'wcs_allow_protected_products_to_renew' );
		add_action( 'wcs_after_renewal_setup_cart_subscriptions', 'wcs_disallow_protected_product_add_to_cart_validation' );

		// Apply renewal discounts as pseudo coupons
		add_action( 'woocommerce_setup_cart_for_subscription_renewal', array( $this, 'setup_discounts' ) );

		// Work around WC changing the "created_via" meta to "checkout" regardless of its previous value during checkout.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'maybe_preserve_order_created_via' ), 0, 1 );

		add_action( 'plugins_loaded', array( $this, 'maybe_disable_manual_renewal_stock_validation' ) );
	}

	/**
	 * Attach WooCommerce version dependent hooks
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function attach_dependant_hooks() {

		if ( wcs_is_woocommerce_pre( '3.0' ) ) {

			// When a renewal order's line items are being updated, update the line item IDs stored in cart data.
			add_action( 'woocommerce_add_order_item_meta', array( &$this, 'update_line_item_cart_data' ), 10, 3 );

			add_filter( 'woocommerce_checkout_update_customer_data', array( &$this, 'maybe_update_subscription_customer_data' ), 10, 2 );

		} else {

			// For order items created as part of a renewal, keep a record of the cart item key so that we can match it later once the order item has been saved and has an ID
			add_action( 'woocommerce_checkout_create_order_line_item', array( &$this, 'add_line_item_meta' ), 10, 3 );

			// After order meta is saved, get the order line item ID for the renewal so we can update it later
			add_action( 'woocommerce_checkout_update_order_meta', array( &$this, 'set_order_item_id' ), 10, 2 );

			// After order meta is saved, get the order line item ID for the renewal so we can update it later
			add_action( 'woocommerce_store_api_checkout_update_order_meta', array( &$this, 'set_order_item_id' ) );

			// Don't display cart item key meta stored above on the Edit Order screen
			add_action( 'woocommerce_hidden_order_itemmeta', array( &$this, 'hidden_order_itemmeta' ), 10 );

			// Update customer's address on the subscription if it is changed during renewal
			add_filter( 'woocommerce_checkout_update_user_meta', array( &$this, 'maybe_update_subscription_address_data' ), 10, 2 );
			add_filter( 'woocommerce_store_api_checkout_update_customer_from_request', array( &$this, 'maybe_update_subscription_address_data_from_store_api' ), 10, 2 );

		}
	}

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function setup_hooks() {

		// Make sure renewal meta data persists between sessions
		add_filter( 'woocommerce_get_cart_item_from_session', array( &$this, 'get_cart_item_from_session' ), 10, 3 );
		add_action( 'woocommerce_cart_loaded_from_session', array( &$this, 'cart_items_loaded_from_session' ), 10 );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'restore_order_awaiting_payment' ), 10 );

		// Make sure fees are added to the cart
		add_action( 'woocommerce_cart_calculate_fees', array( &$this, 'maybe_add_fees' ), 10, 1 );

		// Check if a user is requesting to create a renewal order for a subscription, needs to happen after $wp->query_vars are set
		add_action( 'template_redirect', array( &$this, 'maybe_setup_cart' ), 100 );

		add_filter( 'woocommerce_get_shop_coupon_data', array( &$this, 'renewal_coupon_data' ), 10, 2 );

		add_action( 'woocommerce_remove_cart_item', array( &$this, 'maybe_remove_items' ), 10, 1 );
		wcs_add_woocommerce_dependent_action( 'woocommerce_before_cart_item_quantity_zero', array( &$this, 'maybe_remove_items' ), '3.7.0', '<' );

		add_action( 'woocommerce_cart_emptied', array( &$this, 'clear_coupons' ), 10 );

		add_filter( 'woocommerce_cart_item_removed_title', array( &$this, 'items_removed_title' ), 10, 2 );

		add_action( 'woocommerce_cart_item_restored', array( &$this, 'maybe_restore_items' ), 10, 1 );

		// Use original order price when resubscribing to products with addons (to ensure the adds on prices are included)
		add_filter( 'woocommerce_product_addons_adjust_price', array( &$this, 'product_addons_adjust_price' ), 10, 2 );

		// When loading checkout address details, use the renewal order address details for renewals
		add_filter( 'woocommerce_checkout_get_value', array( &$this, 'checkout_get_value' ), 10, 2 );

		add_filter( 'woocommerce_get_item_data', array( &$this, 'display_line_item_data_in_cart' ), 10, 2 );

		// Attach hooks which depend on WooCommerce version constants. Differs from @see attach_dependant_hooks() in that this is hooked inside an inherited function and so extended classes will also inherit these callbacks
		add_action( 'woocommerce_loaded', array( &$this, 'attach_dependant_callbacks' ), 10 );

		// Filters the Place order button text on checkout.
		add_filter( 'woocommerce_order_button_text', array( $this, 'order_button_text' ), 15 );

		// Before WC loads the cart from the session, verify if it belongs to the current user.
		add_action( 'woocommerce_load_cart_from_session', array( $this, 'verify_session_belongs_to_customer' ) );
	}

	/**
	 * Attach callbacks dependant on WC versions
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.11
	 */
	public function attach_dependant_callbacks() {

		if ( wcs_is_woocommerce_pre( '3.0' ) ) {
			add_action( 'woocommerce_add_order_item_meta', array( &$this, 'add_order_item_meta' ), 10, 2 );
			add_action( 'woocommerce_add_subscription_item_meta', array( &$this, 'add_order_item_meta' ), 10, 2 );
		} else {
			add_action( 'woocommerce_checkout_create_order_line_item', array( &$this, 'add_order_line_item_meta' ), 10, 3 );
		}
	}

	/**
	 * Check if a payment is being made on a renewal order from 'My Account'. If so,
	 * redirect the order into a cart/checkout payment flow so that the customer can
	 * choose payment method, apply discounts set shipping and pay for the order.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function maybe_setup_cart() {
		global $wp;

		if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) && isset( $wp->query_vars['order-pay'] ) ) {

			// Pay for existing order
			$order_key = $_GET['key'];
			$order_id  = isset( $wp->query_vars['order-pay'] ) ? $wp->query_vars['order-pay'] : absint( $_GET['order_id'] );
			$order     = wc_get_order( $order_id );

			if ( wcs_get_objects_property( $order, 'order_key' ) === $order_key && $order->has_status( array( 'pending', 'failed' ) ) && wcs_order_contains_renewal( $order ) ) {

				// If a user isn't logged in, allow them to login first and then redirect back
				if ( ! is_user_logged_in() ) {
					$redirect = add_query_arg(
						array(
							'wcs_redirect'    => 'pay_for_order',
							'wcs_redirect_id' => $order_id,
						),
						get_permalink( wc_get_page_id( 'myaccount' ) )
					);

					wp_safe_redirect( $redirect );
					exit;
				} elseif ( ! current_user_can( 'pay_for_order', $order_id ) ) {
					wc_add_notice( __( 'That doesn\'t appear to be your order.', 'woocommerce-subscriptions' ), 'error' );
					wp_safe_redirect( get_permalink( wc_get_page_id( 'myaccount' ) ) );
					exit;
				}

				$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

				do_action( 'wcs_before_renewal_setup_cart_subscriptions', $subscriptions, $order );

				foreach ( $subscriptions as $subscription ) {

					do_action( 'wcs_before_renewal_setup_cart_subscription', $subscription, $order );

					// Check if order/subscription can be paid for
					if ( empty( $subscription ) || ! $subscription->has_status( array( 'on-hold', 'pending' ) ) ) {
						wc_add_notice( __( 'This order can no longer be paid because the corresponding subscription does not require payment at this time.', 'woocommerce-subscriptions' ), 'error' );
					} else {
						// Add the existing subscription items to the cart
						$this->setup_cart(
							$order,
							array(
								'subscription_id'  => $subscription->get_id(),
								'renewal_order_id' => $order_id,
							),
							'all_items_required'
						);
					}

					do_action( 'wcs_after_renewal_setup_cart_subscription', $subscription, $order );
				}

				do_action( 'wcs_after_renewal_setup_cart_subscriptions', $subscriptions, $order );

				if ( WC()->cart->cart_contents_count != 0 ) {
					// Store renewal order's ID in session so it can be re-used after payment
					$this->set_order_awaiting_payment( $order_id );
					wc_add_notice( __( 'Complete checkout to renew your subscription.', 'woocommerce-subscriptions' ), 'success' );
				}

				wp_safe_redirect( wc_get_checkout_url() );
				exit;
			}
		}
	}

	/**
	 * Updates the WooCommerce session variables so that an order can be resumed/paid for without a new order being
	 * created.
	 *
	 * @internal Core checkout uses order_awaiting_payment, Blocks checkout uses store_api_draft_order. Both validate the
	 * cart hash to ensure the order matches the cart.
	 *
	 * @param int|WC_Order $order_id The order that is awaiting payment, or 0 to unset it.
	 */
	protected function set_order_awaiting_payment( $order_id ) {
		$order = null;

		if ( is_a( $order_id, 'WC_Abstract_Order' ) ) {
			$order    = $order_id;
			$order_id = $order->get_id();
		} elseif ( ! empty( $order_id ) ) {
			$order = wc_get_order( $order_id );
		}

		// Only ever set the order awaiting payment to 0 or an Order ID - not a subscription.
		if ( $order && ! wcs_is_order( $order ) ) {
			return;
		}

		WC()->session->set( 'order_awaiting_payment', $order_id );
		WC()->session->set( 'store_api_draft_order', $order_id );

		if ( $order_id ) {
			$this->set_cart_hash( $order );
		}
	}

	/**
	 * Set up cart item meta data to complete a subscription renewal via the cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 *
	 * @param WC_Abstract_Order $subscription The subscription or Order object to set up the cart from.
	 * @param array             $cart_item_data Additional cart item data to set on the cart items.
	 * @param string            $validation_type Whether all items are required or not. Optional. Can be 'all_items_not_required' or 'all_items_required'. 'all_items_not_required' by default.
	 *     'all_items_not_required' - If an order/subscription line item fails to be added to the cart, the remaining items will be added.
	 *     'all_items_required'     - If an order/subscription line item fails to be added to the cart, all items will be removed and the cart setup will be aborted.
	 */
	protected function setup_cart( $subscription, $cart_item_data, $validation_type = 'all_items_not_required' ) {

		WC()->cart->empty_cart( true );
		$success = true;

		foreach ( $subscription->get_items() as $item_id => $line_item ) {

			$variations              = array();
			$item_data               = array();
			$custom_line_item_meta   = array();
			$reserved_item_meta_keys = array(
				'_item_meta',
				'_item_meta_array',
				'_qty',
				'_tax_class',
				'_product_id',
				'_variation_id',
				'_line_subtotal',
				'_line_total',
				'_line_tax',
				'_line_tax_data',
				'_line_subtotal_tax',
				'_cart_item_key_' . $this->cart_item_key, // This value is unique per checkout attempt and so shouldn't be copied from existing line items.
				'Backordered', // WC will reapply this meta if the line item is backordered. Therefore it shouldn't be copied through the cart.
			);

			// Load all product info including variation data
			$product_id   = $line_item->get_product_id();
			$quantity     = $line_item->get_quantity();
			$variation_id = $line_item->get_variation_id();
			$item_name    = $line_item->get_name();

			foreach ( $line_item->get_meta_data() as $meta ) {
				if ( taxonomy_is_product_attribute( $meta->key ) || meta_is_product_attribute( $meta->key, $meta->value, $product_id ) ) {
					$variations[ "attribute_{$meta->key}" ] = $meta->value;
				} elseif ( ! in_array( $meta->key, $reserved_item_meta_keys, true ) ) {
					$custom_line_item_meta[ $meta->key ] = $meta->value;
				}
			}

			$product_id = apply_filters( 'woocommerce_add_to_cart_product_id', $product_id );
			$product    = wc_get_product( $product_id );

			// The notice displayed when a subscription product has been deleted and the customer attempts to manually renew or make a renewal payment for a failed recurring payment for that product/subscription
			// translators: placeholder is an item name
			$product_deleted_error_message = apply_filters( 'woocommerce_subscriptions_renew_deleted_product_error_message', __( 'The %s product has been deleted and can no longer be renewed. Please choose a new product or contact us for assistance.', 'woocommerce-subscriptions' ) );

			// Display error message for deleted products
			if ( false === $product ) {

				wc_add_notice( sprintf( $product_deleted_error_message, $item_name ), 'error' );

				// Make sure we don't actually need the variation ID (if the product was a variation, it will have a variation ID; however, if the product has changed from a simple subscription to a variable subscription, there will be no variation_id)
			} elseif ( $product->is_type( array( 'variable-subscription' ) ) && ! empty( $variation_id ) ) {

				$variation = wc_get_product( $variation_id );

				// Display error message for deleted product variations
				if ( false === $variation ) {
					wc_add_notice( sprintf( $product_deleted_error_message, $item_name ), 'error' );
				}
			}

			$cart_item_data['line_item_id']          = $item_id;
			$cart_item_data['custom_line_item_meta'] = $custom_line_item_meta;

			$item_data = apply_filters( 'woocommerce_order_again_cart_item_data', array( $this->cart_item_key => $cart_item_data ), $line_item, $subscription );

			if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations, $item_data ) ) {
				continue;
			}

			$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations, $item_data );
			$success       = $success && (bool) $cart_item_key;
		}

		// If a product couldn't be added to the cart and if all items are required, prevent partially paying for the order by removing all cart items.
		if ( ! $success && 'all_items_required' === $validation_type ) {
			if ( wcs_is_subscription( $subscription ) ) {
				// translators: %s is subscription's number
				wc_add_notice( sprintf( esc_html__( 'Subscription #%s has not been added to the cart.', 'woocommerce-subscriptions' ), $subscription->get_order_number() ), 'error' );
			} else {
				// translators: %s is order's number
				wc_add_notice( sprintf( esc_html__( 'Order #%s has not been added to the cart.', 'woocommerce-subscriptions' ), $subscription->get_order_number() ), 'error' );
			}

			WC()->cart->empty_cart( true );
			wp_safe_redirect( wc_get_page_permalink( 'cart' ) );
			exit;
		}

		do_action( 'woocommerce_setup_cart_for_' . $this->cart_item_key, $subscription, $cart_item_data );
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

			if ( isset( $item[ $this->cart_item_key ]['renewal_order_id'] ) && ! 'shop_order' === WC_Data_Store::load( 'order' )->get_order_type( $item[ $this->cart_item_key ]['renewal_order_id'] ) ) {
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 *
	 * @param array  $cart_item_session_data Cart item session data.
	 * @param array  $cart_item              Cart item data.
	 * @param string $key                    Cart item key.
	 */
	public function get_cart_item_from_session( $cart_item_session_data, $cart_item, $key ) {

		if ( $this->should_honor_subscription_prices( $cart_item ) ) {
			$cart_item_session_data[ $this->cart_item_key ] = $cart_item[ $this->cart_item_key ];

			$_product = $cart_item_session_data['data'];

			// Need to get the original subscription or order price, not the current price
			$subscription = $this->get_order( $cart_item );

			if ( $subscription ) {
				$subscription_items = $subscription->get_items();
				$item_to_renew      = [];

				/**
				 * Find the subscription or order line item that represents this cart item.
				 *
				 * If cart item data correctly records a valid line item ID, use that to find the line item.
				 * Otherwise, use the cart item key stored in line item meta.
				 */
				if ( isset( $subscription_items[ $cart_item_session_data[ $this->cart_item_key ]['line_item_id'] ] ) ) {
					$item_to_renew = $subscription_items[ $cart_item_session_data[ $this->cart_item_key ]['line_item_id'] ];
				} else {
					foreach ( $subscription_items as $item ) {
						if ( $item->get_meta( '_cart_item_key_' . $this->cart_item_key, true ) === $key ) {
							$item_to_renew = $item;
							break;
						}
					}
				}

				// If we can't find the item to renew, return the cart item session data as is.
				if ( empty( $item_to_renew ) ) {
					return $cart_item_session_data;
				}

				$price = $item_to_renew['line_subtotal'];

				if ( $_product->is_taxable() && $subscription->get_prices_include_tax() ) {

					// If this item's subtracted tax data hasn't been repaired, do that now.
					if ( isset( $item_to_renew['_subtracted_base_location_tax'] ) ) {
						WC_Subscriptions_Upgrader::repair_subtracted_base_taxes( $item_to_renew->get_id() );

						// The item has been updated so get a refreshed version of the item.
						$item_to_renew = WC_Order_Factory::get_order_item( $item_to_renew->get_id() );
					}

					if ( isset( $item_to_renew['_subtracted_base_location_taxes'] ) ) {
						$price += array_sum( $item_to_renew['_subtracted_base_location_taxes'] ) * $item_to_renew['qty'];
					} elseif ( isset( $item_to_renew['taxes']['subtotal'] ) ) {
						$price += array_sum( $item_to_renew['taxes']['subtotal'] ); // Use the taxes array items here as they contain taxes to a more accurate number of decimals.
					}
				}

				// In rare cases quantity can be zero. Check first to prevent triggering a fatal error in php8+
				if ( 0 !== (int) $item_to_renew['qty'] ) {
					$_product->set_price( $price / $item_to_renew['qty'] );
				}

				// Don't carry over any sign up fee
				wcs_set_objects_property( $_product, 'subscription_sign_up_fee', 0, 'set_prop_only' );

				// Allow plugins to add additional strings to the product name for renewals
				$line_item_name = is_callable( $item_to_renew, 'get_name' ) ? $item_to_renew->get_name() : $item_to_renew['name'];
				wcs_set_objects_property( $_product, 'name', apply_filters( 'woocommerce_subscriptions_renewal_product_title', $line_item_name, $_product ), 'set_prop_only' );

				// Make sure the same quantity is renewed
				$cart_item_session_data['quantity'] = $item_to_renew['qty'];
			}
		}

		return $cart_item_session_data;
	}

	/**
	 * Returns address details from the renewal order if the checkout is for a renewal.
	 *
	 * @param string $value Default checkout field value.
	 * @param string $key   The checkout form field name/key.
	 *
	 * @return string $value Checkout field value.
	 */
	public function checkout_get_value( $value, $key ) {

		// Only hook in after WC()->checkout() has been initialised.
		if ( ! $this->cart_contains() || did_action( 'woocommerce_checkout_init' ) <= 0 ) {
			return $value;
		}

		// Get the most specific order object, which will be the renewal order for renewals, initial order for initial payments, or a subscription for switches/resubscribes.
		$order = $this->get_order();

		if ( ! $order ) {
			return $value;
		}

		$address_fields = array_merge(
			WC()->countries->get_address_fields(
				$order->get_billing_country(),
				'billing_'
			),
			WC()->countries->get_address_fields(
				$order->get_shipping_country(),
				'shipping_'
			)
		);

		// Generate the address getter method for the key.
		$getter = "get_{$key}";

		if ( array_key_exists( $key, $address_fields ) && is_callable( [ $order, $getter ] ) ) {
			$order_value = call_user_func( [ $order, $getter ] );

			// Given this is fetching the value for a checkout field, we need to ensure the value is a scalar.
			if ( is_scalar( $order_value ) ) {
				$value = $order_value;
			}
		}

		return $value;
	}

	/**
	 * If the cart contains a renewal order that needs to ship to an address that is different
	 * to the order's billing address, tell the checkout to toggle the ship to a different address
	 * checkbox and make sure the shipping fields are displayed by default.
	 *
	 * @deprecated subscriptions-core 5.3.0 - This method has moved to the WC_Subscriptions_Checkout class.
	 *
	 * @param bool $ship_to_different_address Whether the order will ship to a different address
	 * @return bool $ship_to_different_address
	 */
	public function maybe_check_ship_to_different_address( $ship_to_different_address ) {
		wcs_deprecated_function( __METHOD__, '5.3.0', 'WC_Subscriptions_Checkout::maybe_check_ship_to_different_address( $ship_to_different_address )' );

		if ( ! $ship_to_different_address && false !== ( $item = $this->cart_contains() ) ) {

			$order = $this->get_order( $item );

			$renewal_shipping_address = $order->get_address( 'shipping' );
			$renewal_billing_address  = $order->get_address( 'billing' );

			if ( isset( $renewal_billing_address['email'] ) ) {
				unset( $renewal_billing_address['email'] );
			}

			if ( isset( $renewal_billing_address['phone'] ) ) {
				unset( $renewal_billing_address['phone'] );
			}

			// If the order's addresses are different, we need to display the shipping fields otherwise the billing address will override it
			if ( $renewal_shipping_address != $renewal_billing_address ) {
				$ship_to_different_address = 1;
			}
		}

		return $ship_to_different_address;
	}

	/**
	 * When completing checkout for a subscription renewal, update the address on the subscription to use
	 * the shipping/billing address entered in case it has changed since the subscription was first created.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function maybe_update_subscription_customer_data( $update_customer_data, $checkout_object ) {

		$cart_renewal_item = $this->cart_contains();

		if ( false !== $cart_renewal_item ) {

			$subscription = wcs_get_subscription( $cart_renewal_item[ $this->cart_item_key ]['subscription_id'] );

			$billing_address = array();
			if ( $checkout_object->checkout_fields['billing'] ) {
				foreach ( array_keys( $checkout_object->checkout_fields['billing'] ) as $field ) {
					$field_name                     = str_replace( 'billing_', '', $field );
					$billing_address[ $field_name ] = $checkout_object->get_posted_address_data( $field_name );
				}
			}

			$shipping_address = array();
			if ( $checkout_object->checkout_fields['shipping'] ) {
				foreach ( array_keys( $checkout_object->checkout_fields['shipping'] ) as $field ) {
					$field_name                      = str_replace( 'shipping_', '', $field );
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @return bool
	 */
	public function is_purchasable( $is_purchasable, $product ) {
		_deprecated_function( __METHOD__, '2.1', 'WCS_Limiter::is_purchasable_renewal' );
		return WCS_Limiter::is_purchasable_renewal( $is_purchasable, $product );

	}

	/**
	 * Flag payment of manual renewal orders via an extra URL param.
	 *
	 * This is particularly important to ensure renewals of limited subscriptions can be completed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_checkout_payment_url( $pay_url, $order ) {

		if ( wcs_order_contains_renewal( $order ) ) {
			$pay_url = add_query_arg( array( $this->cart_item_key => 'true' ), $pay_url );
		}

		return $pay_url; // nosemgrep: audit.php.wp.security.xss.query-arg -- False positive. $pay_url should be escaped at the point of output or usage. Keep the URL in tact for functions hooked in further down the chain.
	}

	/**
	 * Customise which actions are shown against a subscription renewal order on the My Account page.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
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
	 * Removes all the linked renewal/resubscribe items from the cart if a renewal/resubscribe item is removed.
	 *
	 * @param string $cart_item_key The cart item key of the item removed from the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
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

			// remove the renewal order flag
			$this->set_order_awaiting_payment( 0 );

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
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function items_removed_title( $product_title, $cart_item ) {

		if ( isset( $cart_item[ $this->cart_item_key ]['subscription_id'] ) ) {
			$subscription  = $this->get_order( $cart_item );
			$product_title = ( count( $subscription->get_items() ) > 1 ) ? esc_html_x( 'All linked subscription items were', 'Used in WooCommerce by removed item notification: "_All linked subscription items were_ removed. Undo?" Filter for item title.', 'woocommerce-subscriptions' ) : $product_title;
		}

		return $product_title;
	}

	/**
	 * Restores all linked renewal/resubscribe items to the cart if the customer has restored one.
	 *
	 * @param string $cart_item_key The cart item key of the item being restored to the cart.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
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

			// restore the renewal order flag
			if ( isset( WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['renewal_order_id'] ) ) {
				$this->set_order_awaiting_payment( WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['renewal_order_id'] );
			}
		}
	}

	/**
	 * Return our custom pseudo coupon data for renewal coupons
	 *
	 * @param array $data the coupon data
	 * @param string $code the coupon code that data is being requested for
	 * @return array the custom coupon data
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	public function renewal_coupon_data( $data, $code ) {

		if ( ! is_object( WC()->session ) ) {
			return $data;
		}

		$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons' );

		if ( empty( $renewal_coupons ) ) {
			return $data;
		}

		foreach ( $renewal_coupons as $order_id => $coupons ) {

			foreach ( $coupons as $coupon_code => $coupon_properties ) {

				// Tweak the coupon data for renewal coupons
				if ( $coupon_code == $code ) {
					$expiry_date_property = wcs_is_woocommerce_pre( '3.0' ) ? 'expiry_date' : 'date_expires';

					// Some coupon properties are overridden specifically for renewals
					$renewal_coupon_overrides = array(
						'id'                  => true,
						'usage_limit'         => '',
						'usage_count'         => '',
						$expiry_date_property => '',
					);

					$data = array_merge( $coupon_properties, $renewal_coupon_overrides );
					break 2;
				}
			}
		}

		return $data;
	}

	/**
	 * Get original products for a renewal order - so that we can ensure renewal coupons are only applied to those
	 *
	 * @param  object WC_Order | WC_Subscription $order
	 * @return array $product_ids an array of product ids on a subscription/order
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	protected function get_products( $order ) {

		$product_ids = array();

		if ( is_a( $order, 'WC_Abstract_Order' ) ) {
			foreach ( $order->get_items() as $item ) {
				$product_id = wcs_get_canonical_product_id( $item );
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	protected function store_coupon( $order_id, $coupon ) {
		if ( ! empty( $order_id ) && ! empty( $coupon ) ) {
			$renewal_coupons   = WC()->session->get( 'wcs_renewal_coupons', array() );
			$use_bools         = wcs_is_woocommerce_pre( '3.0' ); // Some coupon properties have changed from accepting 'no' and 'yes' to true and false args.
			$coupon_properties = array();
			$property_defaults = array(
				'discount_type'               => '',
				'amount'                      => 0,
				'individual_use'              => ( $use_bools ) ? false : 'no',
				'product_ids'                 => array(),
				'excluded_product_ids'        => array(),
				'free_shipping'               => ( $use_bools ) ? false : 'no',
				'product_categories'          => array(),
				'excluded_product_categories' => array(),
				'exclude_sale_items'          => ( $use_bools ) ? false : 'no',
				'minimum_amount'              => '',
				'maximum_amount'              => '',
				'email_restrictions'          => array(),
				'limit_usage_to_x_items'      => null,
			);

			foreach ( $property_defaults as $property => $value ) {
				$getter = 'get_' . $property;

				if ( is_callable( array( $coupon, $getter ) ) ) {
					$value = $coupon->$getter();
				} else { // WC < 3.0
					// Map the property to its version compatible name ( 3.0+ => WC < 3.0 )
					$getter_to_property_map = array(
						'amount'                      => 'coupon_amount',
						'excluded_product_ids'        => 'exclude_product_ids',
						'date_expires'                => 'expiry_date',
						'excluded_product_categories' => 'exclude_product_categories',
						'email_restrictions'          => 'customer_email',
					);

					$property = array_key_exists( $property, $getter_to_property_map ) ? $getter_to_property_map[ $property ] : $property;

					if ( property_exists( $coupon, $property ) ) {
						$value = $coupon->$property;
					}
				}

				$coupon_properties[ $property ] = $value;
			}

			// Subscriptions may have multiple coupons, store coupons in an array
			if ( array_key_exists( $order_id, $renewal_coupons ) ) {
				$renewal_coupons[ $order_id ][ wcs_get_coupon_property( $coupon, 'code' ) ] = $coupon_properties;
			} else {
				$renewal_coupons[ $order_id ] = array( wcs_get_coupon_property( $coupon, 'code' ) => $coupon_properties );
			}

			WC()->session->set( 'wcs_renewal_coupons', $renewal_coupons );
		}
	}

	/**
	 * Clear renewal coupons - protects against confusing customer facing notices if customers add one renewal order to the cart with a set of coupons and then decide to add another renewal order with a different set of coupons
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	public function clear_coupons() {

		$renewal_coupons = WC()->session->get( 'wcs_renewal_coupons' );

		// Remove the coupons from the cart
		if ( ! empty( $renewal_coupons ) ) {
			foreach ( $renewal_coupons as $order_id => $coupons ) {
				foreach ( $coupons as $coupon_code => $coupon_properties ) {
					WC()->cart->remove_coupons( $coupon_code );
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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	public function maybe_add_fees( $cart ) {

		if ( $cart_item = $this->cart_contains() ) {

			$order = $this->get_order( $cart_item );

			/**
			 * Allow other plugins to remove/add fees of an existing order prior to building the cart without changing the saved order values
			 * (e.g. payment gateway based fees can remove fees and later can add new fees depending on the actual selected payment gateway)
			 *
			 * @param WC_Order $order is rendered by reference - change meta data of this object
			 * @param WC_Cart $cart
			 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.9
			 */
			do_action( 'woocommerce_adjust_order_fees_for_setup_cart_for_' . $this->cart_item_key, $order, $cart );

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
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function product_addons_adjust_price( $adjust_price, $cart_item ) {

		if ( true === $adjust_price && isset( $cart_item[ $this->cart_item_key ] ) && $this->should_honor_subscription_prices( $cart_item ) ) {
			$adjust_price = false;
		}

		return $adjust_price;
	}

	/**
	 * Get the order object used to construct the renewal cart.
	 *
	 * @param Array The renewal cart item.
	 * @return WC_Order | The order object
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0.13
	 */
	protected function get_order( $cart_item = '' ) {
		$order = false;

		if ( empty( $cart_item ) ) {
			$cart_item = $this->cart_contains();
		}

		if ( false !== $cart_item && isset( $cart_item[ $this->cart_item_key ] ) ) {
			$order = wc_get_order( $cart_item[ $this->cart_item_key ]['renewal_order_id'] );
		}

		return $order;
	}

	/**
	 * Before allowing payment on an order awaiting payment via checkout, WC >= 2.6 validates
	 * order items haven't changed by checking for a cart hash on the order, so we need to set
	 * that here. @see WC_Checkout::create_order()
	 *
	 * @param WC_Order|int $order The order object or order ID.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.14
	 */
	protected function set_cart_hash( $order ) {

		if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
			$order = wc_get_order( $order );

			if ( ! $order ) {
				return;
			}
		}

		// Use cart hash generator introduced in WooCommerce 3.6
		if ( is_callable( array( WC()->cart, 'get_cart_hash' ) ) ) {
			$cart_hash = WC()->cart->get_cart_hash();
		} else {
			$cart_hash = md5( json_encode( wc_clean( WC()->cart->get_cart_for_session() ) ) . WC()->cart->total );
		}

		$order->set_cart_hash( $cart_hash );
		$order->save();
	}

	/**
	 * Right before WC processes a renewal cart through the checkout, set the cart hash.
	 * This ensures legitimate changes to taxes and shipping methods don't cause a new order to be created.
	 *
	 * @param Mixed | An order generated by third party plugins
	 * @return Mixed | The unchanged order param
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.11
	 */
	public function update_cart_hash( $order ) {

		if ( $item = $this->cart_contains() ) {

			if ( isset( $item[ $this->cart_item_key ]['renewal_order_id'] ) ) {
				$order_id = $item[ $this->cart_item_key ]['renewal_order_id'];
			} elseif ( isset( $item[ $this->cart_item_key ]['order_id'] ) ) {
				$order_id = $item[ $this->cart_item_key ]['order_id'];
			} else {
				$order_id = '';
			}

			if ( $order_id ) {
				$this->set_cart_hash( $order_id );
			}
		}

		return $order;
	}

	/**
	 * Redirect back to pay for an order after successfully logging in.
	 *
	 * @param string  The redirect URL after successful login.
	 * @param WP_User The newly logged in user object.
	 * @return string
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.1.0
	 */
	public function maybe_redirect_after_login( $redirect, $user = null ) {
		/**
		 * Nonce verification is not needed here as it was already checked during the log-in process, see WC_Form_Handler::process_login().
		 */
		if ( isset( $_GET['wcs_redirect'], $_GET['wcs_redirect_id'] ) && 'pay_for_order' === $_GET['wcs_redirect'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$order = wc_get_order( $_GET['wcs_redirect_id'] );

			if ( $order && $order->get_user_id() && user_can( $user, 'pay_for_order', $order->get_id() ) ) {
				$redirect = $order->get_checkout_payment_url();
			} else {
				// Remove the wcs_redirect query args if the user doesn't have permission to pay for the order.
				$redirect = remove_query_arg( array( 'wcs_redirect', 'wcs_redirect_id' ), $redirect );
			}
		}

		return $redirect;
	}

	/**
	 * Force an update to the session cart after updating renewal order line items.
	 *
	 * This is required so that changes made by @see WCS_Cart_Renewal->add_line_item_meta() (or @see
	 * WCS_Cart_Renewal->update_line_item_cart_data() for WC < 3.0), are also reflected
	 * in the session cart.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1.3
	 */
	public function update_session_cart_after_updating_renewal_order() {

		if ( $this->cart_contains() ) {
			// Update the cart stored in the session with the new data
			WC()->session->cart = WC()->cart->get_cart_for_session();
			WC()->cart->persistent_cart_update();
		}
	}

	/**
	* Prevent compounding dynamic discounts on cart items.
	* Dynamic discounts are copied from the subscription to the renewal order and so don't need to be applied again in the cart.
	*
	* @param bool Whether to apply the dynamic discount
	* @param string The cart item key of the cart item the dynamic discount is being applied to.
	* @return bool
	* @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.1.4
	*/
	function prevent_compounding_dynamic_discounts( $adjust_price, $cart_item_key ) {

		if ( $adjust_price && isset( WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ] ) ) {
			$adjust_price = false;
		}

		return $adjust_price;
	}

	/**
	 * For order items created as part of a renewal, keep a record of the cart item key so that we can match it
	 * later in @see this->set_order_item_id() once the order item has been saved and has an ID.
	 *
	 * Attached to WC 3.0+ hooks and uses WC 3.0 methods.
	 *
	 * @param WC_Order_Item_Product $order_item
	 * @param string $cart_item_key The hash used to identify the item in the cart
	 * @param array $cart_item The cart item's data.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function add_line_item_meta( $order_item, $cart_item_key, $cart_item ) {
		if ( isset( $cart_item[ $this->cart_item_key ] ) ) {
			// Store the cart item key on the line item so that we can link it later on to the order line item ID
			$order_item->add_meta_data( '_cart_item_key_' . $this->cart_item_key, $cart_item_key );
		}
	}

	/**
	 * After order meta is saved, get the order line item ID for this renewal and keep a record of it in
	 * the cart so we can update it later.
	 *
	 * @param int|WC_Order $order_id
	 * @param array $checkout_posted_data
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.1
	 */
	public function set_order_item_id( $order_id, $posted_checkout_data = array() ) {

		$order = wc_get_order( $order_id );

		foreach ( $order->get_items( 'line_item' ) as $order_item_id => $order_item ) {

			$cart_item_key = $order_item->get_meta( '_cart_item_key_' . $this->cart_item_key );

			if ( ! empty( $cart_item_key ) ) {
				// Update the line_item_id to the new corresponding item_id
				$this->set_cart_item_order_item_id( $cart_item_key, $order_item_id );
			}
		}
	}

	/**
	 * After updating renewal order line items, update the values stored in cart item data
	 * which would now reference old line item IDs.
	 *
	 * Used when WC 3.0 or newer is active. When prior versions are active,
	 * @see WCS_Cart_Renewal->update_line_item_cart_data()
	 *
	 * @param string $cart_item_key
	 * @param int $order_item_id
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.1
	 */
	protected function set_cart_item_order_item_id( $cart_item_key, $order_item_id ) {
		WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['line_item_id'] = $order_item_id;
	}

	/**
	 * Do not display cart item key order item meta keys unless Subscriptions is in debug mode.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.1
	 */
	public function hidden_order_itemmeta( $hidden_meta_keys ) {

		if ( apply_filters( 'woocommerce_subscriptions_hide_itemmeta', ! defined( 'WCS_DEBUG' ) || true !== WCS_DEBUG ) ) {
			$hidden_meta_keys[] = '_cart_item_key_' . $this->cart_item_key;
		}

		return $hidden_meta_keys;
	}

	/**
	 * When completing checkout for a subscription renewal, update the subscription's address to match
	 * the shipping/billing address entered on checkout.
	 *
	 * @param int $customer_id
	 * @param array $checkout_data the posted checkout data
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.7
	 */
	public function maybe_update_subscription_address_data( $customer_id, $checkout_data ) {
		$cart_renewal_item = $this->cart_contains();

		if ( false !== $cart_renewal_item ) {
			$subscription         = wcs_get_subscription( $cart_renewal_item[ $this->cart_item_key ]['subscription_id'] );
			$subscription_updated = false;

			foreach ( [ 'billing', 'shipping' ] as $address_type ) {
				$checkout_fields = WC()->checkout()->get_checkout_fields( $address_type );

				if ( is_array( $checkout_fields ) ) {
					foreach ( array_keys( $checkout_fields ) as $field ) {
						if ( isset( $checkout_data[ $field ] ) && is_callable( [ $subscription, "set_$field" ] ) ) {
							$subscription->{"set_$field"}( $checkout_data[ $field ] );
							$subscription_updated = true;
						}
					}
				}
			}

			if ( $subscription_updated ) {
				$subscription->save();
			}
		}
	}

	/**
	 * When completing checkout for a subscription renewal, update the subscription's address to match
	 * the shipping/billing address entered on checkout.
	 *
	 * @param \WC_Customer $customer
	 * @param \WP_REST_Request $request Full details about the request.
	 * @since 4.1.1
	 */
	public function maybe_update_subscription_address_data_from_store_api( $customer, $request ) {
		$cart_renewal_item = $this->cart_contains();

		if ( false !== $cart_renewal_item ) {
			$subscription = wcs_get_subscription( $cart_renewal_item[ $this->cart_item_key ]['subscription_id'] );

			// Billing address is a required field.
			foreach ( $request['billing_address'] as $key => $value ) {
				if ( is_callable( [ $customer, "set_billing_$key" ] ) ) {
					$customer->{"set_billing_$key"}( $value );
				}
			}

			// Save Billing & Shipping addresses. Billing address is a required field, if shipping address (optional field) was not provided, set it to the given billing address.
			if ( wcs_is_woocommerce_pre( '7.1' ) ) {
				$subscription->set_address( $request['billing_address'], 'billing' );
				$subscription->set_address( $request['shipping_address'] ?? $request['billing_address'], 'shipping' );
			} else {
				$subscription->set_billing_address( $request['billing_address'] );
				$subscription->set_shipping_address( $request['shipping_address'] ?? $request['billing_address'] );

				$subscription->save();
			}
		}
	}

	/**
	 * Add custom line item meta to the cart item data so it's displayed in the cart.
	 *
	 * @param array $cart_item_data
	 * @param array $cart_item
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.11
	 */
	public function display_line_item_data_in_cart( $cart_item_data, $cart_item ) {

		if ( ! empty( $cart_item[ $this->cart_item_key ]['custom_line_item_meta'] ) ) {
			foreach ( $cart_item[ $this->cart_item_key ]['custom_line_item_meta'] as $item_meta_key => $value ) {

				$cart_item_data[] = array(
					'key'    => $item_meta_key,
					'value'  => $value,
					'hidden' => substr( $item_meta_key, 0, 1 ) === '_', // meta keys prefixed with an `_` are hidden by default
				);
			}
		}

		return $cart_item_data;
	}

	/**
	 * Add custom line item meta from the old line item into the new line item meta.
	 *
	 * Used when WC versions prior to 3.0 are active. When WC 3.0 or newer is active,
	 * @see WCS_Cart_Renewal->add_order_line_item_meta() replaces this function
	 *
	 * @param int $item_id
	 * @param array $cart_item_data
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.11
	 */
	public function add_order_item_meta( $item_id, $cart_item_data ) {
		if ( ! empty( $cart_item_data[ $this->cart_item_key ]['custom_line_item_meta'] ) ) {
			foreach ( $cart_item_data[ $this->cart_item_key ]['custom_line_item_meta'] as $meta_key => $value ) {
				woocommerce_add_order_item_meta( $item_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Add custom line item meta from the old line item into the new line item meta.
	 *
	 * Used when WC 3.0 or newer is active. When prior versions are active,
	 * @see WCS_Cart_Renewal->add_order_item_meta() replaces this function
	 *
	 * @param WC_Order_Item_Product
	 * @param string $cart_item_key
	 * @param array $cart_item_data
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.11
	 */
	public function add_order_line_item_meta( $item, $cart_item_key, $cart_item_data ) {
		if ( ! empty( $cart_item_data[ $this->cart_item_key ]['custom_line_item_meta'] ) ) {
			foreach ( $cart_item_data[ $this->cart_item_key ]['custom_line_item_meta'] as $meta_key => $value ) {
				$item->add_meta_data( $meta_key, $value );
			}
		}
	}

	/**
	 * Remove any fees applied to the renewal cart which aren't recurring.
	 *
	 * @param WC_Cart $cart A WooCommerce cart object.
	 */
	public function remove_non_recurring_fees( $cart ) {

		if ( ! $this->cart_contains() ) {
			return;
		}

		$renewal_order = $this->get_order();

		if ( ! $renewal_order ) {
			return;
		}

		// Fees are naturally recurring if they have been applied to the renewal order. Generate a key (name + amount) for each fee applied to the order.
		$renewal_order_fees = array();
		$cart_fees          = $cart->get_fees();

		foreach ( $renewal_order->get_fees() as $item_id => $fee_line_item ) {
			$renewal_order_fees[ $item_id ] = $fee_line_item->get_name() . wc_format_decimal( $fee_line_item->get_total() );
		}

		// WC doesn't have a method for removing fees individually so we clear them and re-add them where applicable.
		if ( is_callable( array( $cart, 'fees_api' ) ) ) { // WC 3.2 +
			$cart->fees_api()->remove_all_fees();
		} else {
			$cart->fees = array();
		}

		foreach ( $cart_fees as $fee ) {
			// By default, a fee is automatically recurring if it was applied to the renewal order.
			$is_recurring_fee = in_array( $fee->name . wc_format_decimal( $fee->amount ), $renewal_order_fees );

			if ( true === apply_filters( 'woocommerce_subscriptions_is_recurring_fee', $is_recurring_fee, $fee, $cart ) ) {
				if ( is_callable( array( $cart, 'fees_api' ) ) ) { // WC 3.2 +
					$cart->fees_api()->add_fee( $fee );
				} else {
					$cart->add_fee( $fee->name, $fee->amount, $fee->taxable, $fee->tax_class );
				}
			}
		}
	}

	/**
	 * Filters the shipping packages to remove subscriptions that have "one time shipping" enabled and, as such,
	 * shouldn't have a shipping amount associated during a renewal.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.3
	 */
	public function maybe_update_shipping_packages( $packages ) {
		if ( ! $this->cart_contains() ) {
			return $packages;
		}

		foreach ( $packages as $index => $package ) {
			foreach ( $package['contents'] as $cart_item_key => $cart_item ) {
				if ( WC_Subscriptions_Product::needs_one_time_shipping( $cart_item['data'] ) ) {
					$packages[ $index ]['contents_cost'] -= $cart_item['line_total'];
					unset( $packages[ $index ]['contents'][ $cart_item_key ] );
				}
			}

			if ( empty( $packages[ $index ]['contents'] ) ) {
				unset( $packages[ $index ] );
			}
		}

		return $packages;
	}

	/**
	 * Check if the order has any discounts applied and if so reapply them to the cart
	 * or add pseudo coupon equivalents if the coupons no longer exist.
	 *
	 * @param WC_Order $order The order to copy coupons and discounts from.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.3
	 */
	public function setup_discounts( $order ) {
		$prices_include_tax = $order->get_prices_include_tax();
		$order_discount     = $order->get_total_discount( ! $prices_include_tax );
		$coupon_items       = $order->get_items( 'coupon' );

		if ( empty( $order_discount ) && empty( $coupon_items ) ) {
			return;
		}

		$total_coupon_discount = floatval( array_sum( wc_list_pluck( $coupon_items, 'get_discount' ) ) );
		$coupons               = array();

		if ( $prices_include_tax ) {
			$total_coupon_discount += floatval( array_sum( wc_list_pluck( $coupon_items, 'get_discount_tax' ) ) );
		}

		// If the order total discount is different from the discount applied from coupons we have a manually applied discount.
		$order_has_manual_discount = $order_discount !== $total_coupon_discount;

		// Get all coupon line items as coupon objects.
		if ( ! empty( $coupon_items ) ) {
			$coupons = $this->get_line_item_coupons( $coupon_items );
		}

		if ( $order_has_manual_discount ) {
			// Remove any coupon line items which don't grant free shipping.
			foreach ( $coupons as $index => $coupon ) {
				if ( ! $coupon->get_free_shipping() ) {
					unset( $coupons[ $index ] );
				}

				// We're going to apply a coupon for the full order discount so make sure free shipping coupons don't apply any discount.
				$coupon->set_amount( 0 );
			}

			$coupons[] = $this->get_pseudo_coupon( $order_discount );
		}

		foreach ( $coupons as $coupon ) {
			$this->apply_order_coupon( $order, $coupon );
		}
	}

	/**
	 * Create coupon objects from coupon line items.
	 *
	 * @param WC_Order_Item_Coupon[] $coupon_line_items The coupon line items to apply to the cart.
	 * @return array $coupons
	 */
	protected function get_line_item_coupons( $coupon_line_items ) {
		$coupons = array();

		foreach ( $coupon_line_items as $coupon_item ) {
			$coupon = new WC_Coupon( $coupon_item->get_name() );

			// If the coupon no longer exists, get a pseudo coupon for the discounting amount.
			if ( ! $coupon->get_id() > 0 ) {
				// We shouldn't apply coupons which no longer exists it to initial payment carts.
				if ( 'subscription_initial_payment' === $this->cart_item_key ) {
					continue;
				}

				$coupon = $this->get_pseudo_coupon( $coupon_item->get_discount() );
				$coupon->set_code( $coupon_item->get_code() );
			} elseif ( 'subscription_renewal' === $this->cart_item_key ) {
				$coupon_type = $coupon->get_discount_type();

				// Change recurring coupons into renewal coupons so we can handle validation while paying for a renewal order manually.
				if ( in_array( $coupon_type, array( 'recurring_percent', 'recurring_fee' ) ) ) {
					$coupon->set_discount_type( str_replace( 'recurring', 'renewal', $coupon_type ) );
				}
			}

			$coupons[] = $coupon;
		}

		return $coupons;
	}

	/**
	 * Apply a pseudo coupon to the cart for a specific discount amount.
	 *
	 * @param float $discount The discount amount.
	 * @return WC_Coupon
	 */
	protected function get_pseudo_coupon( $discount ) {
		$cart_types = array(
			'subscription_initial_payment' => 'initial',
			'subscription_renewal'         => 'renewal',
		);

		$cart_type = $cart_types[ $this->cart_item_key ];

		// Generate a unique coupon code from the cart type.
		$coupon = new WC_Coupon( "discount_{$cart_type}" );

		// Apply our cart style pseudo coupon type and the set the amount.
		$coupon->set_discount_type( "{$cart_type}_cart" );
		$coupon->set_amount( $discount );

		return $coupon;
	}

	/**
	 * Apply an order coupon to the cart.
	 *
	 * @param WC_Order $order The order the discount should apply to.
	 * @param WC_Coupon $coupon The coupon to add to the cart.
	 */
	protected function apply_order_coupon( $order, $coupon ) {
		$coupon_code = $coupon->get_code();

		// Set order products as the product ids on the coupon if the coupon does not already have usage restrictions for some products
		if ( ! $coupon->get_product_ids() ) {
			$coupon->set_product_ids( $this->get_products( $order ) );
		}

		// Store the coupon info for later
		$this->store_coupon( $order->get_id(), $coupon );

		// Add the coupon to the cart
		if ( WC()->cart && ! WC()->cart->has_discount( $coupon_code ) ) {
			WC()->cart->add_discount( $coupon_code );
		}
	}

	/**
	 * Makes sure a renewal order's "created via" meta is not changed to "checkout" by WC during checkout.
	 *
	 * @param WC_Order $order
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.4
	 */
	public function maybe_preserve_order_created_via( $order ) {
		$changes      = $order->get_changes();
		$current_data = $order->get_data();

		if ( isset( $changes['created_via'], $current_data['created_via'] ) && 'subscription' === $current_data['created_via'] && 'checkout' === $changes['created_via'] && wcs_order_contains_renewal( $order ) ) {
			$order->set_created_via( 'subscription' );
		}
	}


	/**
	 * Determines if the cart should honor the grandfathered subscription/order line item total.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.10
	 *
	 * @param array $cart_item The cart item to check.
	 * @return bool Whether the cart should honor the order's prices.
	 */
	public function should_honor_subscription_prices( $cart_item ) {
		return isset( $cart_item[ $this->cart_item_key ]['subscription_id'] );
	}

	/**
	 * Disables renewal cart stock validation if the store has switched it off via a filter.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public function maybe_disable_manual_renewal_stock_validation() {
		if ( apply_filters( 'woocommerce_subscriptions_disable_manual_renewal_stock_validation', false ) ) {
			WCS_Renewal_Cart_Stock_Manager::attach_callbacks();
		}
	}

	/**
	 * Overrides the place order button text on the checkout when the cart contains renewal order items, exclusively.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 *
	 * @param string $place_order_text The place order button text.
	 * @return string The place order button text. 'Renew subscription' if the cart contains only renewals, otherwise the default.
	 */
	public function order_button_text( $place_order_text ) {
		if ( isset( WC()->cart ) && count( wcs_get_order_type_cart_items( 'renewal' ) ) === count( WC()->cart->get_cart() ) ) {
			$place_order_text = _x( 'Renew subscription', 'The place order button text while renewing a subscription', 'woocommerce-subscriptions' );
		}

		return $place_order_text;
	}

	/**
	 * Verifies if the cart being loaded from the session belongs to the current user.
	 *
	 * If a customer is logged out via the session cookie expiring or being killed, it's possible that
	 * their cart session persists. Before WC load it, we need to verify if it contains a
	 * subscription-related order and if so, whether the current user has permission to pay for it.
	 *
	 * This function will destroy any session which contains a subscription-related payment that doesn't belong to the current user.
	 *
	 * @since 1.6.3
	 */
	public function verify_session_belongs_to_customer() {
		$cart     = WC()->session->get( 'cart', null );
		$customer = WC()->session->get( 'customer', null );

		if ( ! $cart ) {
			return;
		}

		foreach ( $cart as $cart_item ) {
			$order = $this->get_order( $cart_item );

			// If this cart item doesn't contain a subscription-related order, skip.
			if ( ! $order ) {
				continue;
			}

			// If there is no logged in user. The session has most likely expired.
			if ( ! is_user_logged_in() ) {
				WC()->session->destroy_session();
				return;
			}

			// If the session has a stored customer and that customer is no longer logged in, destroy the session.
			if ( $customer && get_current_user_id() !== (int) $customer['id'] ) {
				WC()->session->destroy_session();
				return;
			}

			if ( ! $this->validate_current_user( $order ) ) {
				WC()->session->destroy_session();
				return;
			}
		}
	}

	/**
	 * Checks if the current user can pay for the order.
	 *
	 * @since 1.6.3
	 *
	 * @param WC_Order $order The order to check the current user against.
	 * @return bool Whether the current user can pay for this order.
	 */
	public function validate_current_user( $order ) {
		return current_user_can( 'pay_for_order', $order->get_id() );
	}

	/**
	 * Sets the order cart hash when paying for a renewal order via the Block Checkout.
	 *
	 * This function is hooked onto the 'woocommerce_order_has_status' filter, is only applied during REST API requests, only applies to the
	 * 'checkout-draft' status (which only Block Checkout orders use) and to renewal orders that are currently being paid for in the cart.
	 * All other order statuses, orders and scenarios remain unaffected by this function.
	 *
	 * This function is necessary to override the default logic in @see DraftOrderTrait::is_valid_draft_order().
	 * This function behaves similarly to @see WCS_Cart_Renewal::update_cart_hash() for the standard checkout and is hooked onto the 'woocommerce_create_order' filter.
	 *
	 * @param bool     $has_status Whether the order has the status.
	 * @param WC_Order $order      The order.
	 * @param string   $status     The status to check.
	 *
	 * @return bool Whether the order has the status. Unchanged by this function.
	 */
	public function set_renewal_order_cart_hash_on_block_checkout( $has_status, $order, $status ) {
		/**
		 * We only need to update the order's cart hash when the has_status() check is for 'checkout-draft' (indicating
		 * this is the status check in DraftOrderTrait::is_valid_draft_order()) and the order doesn't have that status. Orders
		 * which already have the checkout-draft status don't need to be updated to bypass the checkout block logic.
		 */
		if ( $has_status || 'checkout-draft' !== $status ) {
			return $has_status;
		}

		// If the order being validated is the order in the cart, then we need to update the cart hash so it can be resumed.
		if ( $order && $order->get_id() === (int) WC()->session->get( 'store_api_draft_order', 0 ) ) {
			$cart_order = $this->get_order();

			if ( $cart_order && $cart_order->get_id() === $order->get_id() ) {
				// Note: We need to pass the order object so the order instance WooCommerce uses will have the updated hash.
				$this->set_cart_hash( $order );
			}
		}

		return $has_status;
	}

	/**
	 * Restores the order awaiting payment session args if the cart contains a subscription-related order.
	 *
	 * It's possible the that order_awaiting_payment and store_api_draft_order session args are not set if those session args are lost due
	 * to session destruction.
	 *
	 * This function checks the cart that is being loaded from the session and if the cart contains a subscription-related order and if the
	 * current user has permission to pay for it. If so, it restores the order awaiting payment session args.
	 *
	 * @param WC_Cart $cart The cart object.
	 */
	public function restore_order_awaiting_payment( $cart ) {
		if ( ! is_a( $cart, WC_Cart::class ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$order = $this->get_order( $cart_item );

			if ( ! $order ) {
				continue;
			}

			// If the current user has permission to pay for the order, restore the order awaiting payment session arg.
			if ( wcs_is_order( $order ) && $this->validate_current_user( $order ) ) {
				$this->set_order_awaiting_payment( $order );
			}

			// Once we found an order, exit even if the user doesn't have permission to pay for it.
			return;

		}
	}

	/* Deprecated */

	/**
	 * For subscription renewal via cart, use original order discount
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function set_renewal_discounts( $cart ) {
		_deprecated_function( __METHOD__, '2.0.10', 'Applying original subscription discounts to renewals via cart are now handled within ' . __CLASS__ . '::maybe_setup_cart()' );
	}

	/**
	 * For subscription renewal via cart, previously adjust item price by original order discount
	 *
	 * No longer required as of 1.3.5 as totals are calculated correctly internally.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public function get_discounted_price_for_renewal( $price, $cart_item, $cart ) {
		_deprecated_function( __METHOD__, '2.0.10', 'No longer required as of 1.3.5 as totals are calculated correctly internally.' );
	}

	/**
	 * Add subscription fee line items to the cart when a renewal order or resubscribe is in the cart.
	 *
	 * @param WC_Cart $cart
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 */
	public function maybe_add_subscription_fees( $cart ) {
		_deprecated_function( __METHOD__, '2.0.13', __CLASS__ . '::maybe_add_fees()' );
	}

	/**
	 * After updating renewal order line items, update the values stored in cart item data
	 * which would now reference old line item IDs.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1.3
	 */
	public function update_line_item_cart_data( $item_id, $cart_item_data, $cart_item_key ) {

		if ( false === wcs_is_woocommerce_pre( '3.0' ) ) {
			_deprecated_function( __METHOD__, '2.2.0 and WooCommerce 3.0', __CLASS__ . '::add_line_item_meta( $order_item, $cart_item_key, $cart_item )' );
		}

		if ( isset( $cart_item_data[ $this->cart_item_key ] ) ) {
			// Update the line_item_id to the new corresponding item_id
			WC()->cart->cart_contents[ $cart_item_key ][ $this->cart_item_key ]['line_item_id'] = $item_id;
		}
	}

	/**
	 * After updating renewal order line items, update the values stored in cart item data
	 * which would now reference old line item IDs.
	 *
	 * Used when WC 3.0 or newer is active. When prior versions are active,
	 * @see WCS_Cart_Renewal->update_line_item_cart_data()
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.1
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
	 */
	public function update_order_item_data_in_cart( $order_item, $cart_item_key, $cart_item ) {
		_deprecated_function( __METHOD__, '2.2.1', __CLASS__ . '::add_line_item_meta( $order_item, $cart_item_key, $cart_item )' );
		$this->add_line_item_meta( $order_item, $cart_item_key, $cart_item );
	}

	/**
	 * Right before WC processes a renewal cart through the checkout, set the cart hash.
	 * This ensures legitimate changes to taxes and shipping methods don't cause a new order to be created.
	 *
	 * @param Mixed | An order generated by third party plugins
	 * @return Mixed | The unchanged order param
	 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.1.0
	 */
	public function set_renewal_order_cart_hash( $order ) {
		_deprecated_function( __METHOD__, '2.3', __CLASS__ . '::update_cart_hash( $order )' );
		$this->update_cart_hash( $order );
		return $order;
	}

	/**
	 * Check if a renewal order subscription has any coupons applied and if so add pseudo renewal coupon equivalents to ensure the discount is still applied
	 *
	 * @param WC_Subscription $subscription subscription
	 * @param WC_Order $order
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.10
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.3
	 */
	public function maybe_setup_discounts( $subscription, $order = null ) {
		wcs_deprecated_function( __METHOD__, '2.4.3' );

		if ( null === $order ) {
			// If no order arg is passed, to honor backward compatibility, apply discounts which apply to the subscription
			$order = $subscription;
		}

		if ( wcs_is_subscription( $order ) || wcs_order_contains_renewal( $order ) ) {

			$used_coupons   = wcs_get_used_coupon_codes( $order );
			$order_discount = wcs_get_objects_property( $order, 'cart_discount' );

			// Add any used coupon discounts to the cart (as best we can) using our pseudo renewal coupons
			if ( ! empty( $used_coupons ) ) {
				$coupon_items = $order->get_items( 'coupon' );

				foreach ( $coupon_items as $coupon_item ) {

					$coupon      = new WC_Coupon( $coupon_item->get_name() );
					$coupon_type = wcs_get_coupon_property( $coupon, 'discount_type' );
					$coupon_code = '';

					// If the coupon still exists we can use the existing/available coupon properties
					if ( true === wcs_get_coupon_property( $coupon, 'exists' ) ) {

						// But we only want to handle recurring coupons that have been applied to the order
						if ( in_array( $coupon_type, array( 'recurring_percent', 'recurring_fee' ) ) ) {

							// Set the coupon type to be a renewal equivalent for correct validation and calculations
							if ( 'recurring_percent' == $coupon_type ) {
								wcs_set_coupon_property( $coupon, 'discount_type', 'renewal_percent' );
							} elseif ( 'recurring_fee' == $coupon_type ) {
								wcs_set_coupon_property( $coupon, 'discount_type', 'renewal_fee' );
							}

							// Adjust coupon code to reflect that it is being applied to a renewal
							$coupon_code = wcs_get_coupon_property( $coupon, 'code' );
						}
					} else {
						// If the coupon doesn't exist we can only really apply the discount amount we know about - so we'll apply a cart style pseudo coupon and then set the amount
						wcs_set_coupon_property( $coupon, 'discount_type', 'renewal_cart' );

						// Adjust coupon code to reflect that it is being applied to a renewal
						$coupon_code   = wcs_get_coupon_property( $coupon, 'code' );
						$coupon_amount = is_callable( array( $coupon_item, 'get_discount' ) ) ? $coupon_item->get_discount() : $coupon_item['item_meta']['discount_amount']['0'];

						wcs_set_coupon_property( $coupon, 'coupon_amount', $coupon_amount );
					}

					// Now that we have a coupon we know we want to apply
					if ( ! empty( $coupon_code ) ) {

						// Set renewal order products as the product ids on the coupon
						wcs_set_coupon_property( $coupon, 'product_ids', $this->get_products( $order ) );

						// Store the coupon info for later
						$this->store_coupon( wcs_get_objects_property( $order, 'id' ), $coupon );

						// Add the coupon to the cart - the actually coupon values / data are grabbed when needed later
						if ( WC()->cart && ! WC()->cart->has_discount( $coupon_code ) ) {
							WC()->cart->add_discount( $coupon_code );
						}
					}
				}
				// If there are no coupons but there is still a discount (i.e. it might have been manually added), we need to account for that as well
			} elseif ( ! empty( $order_discount ) ) {
				$coupon = new WC_Coupon( 'discount_renewal' );

				// Apply our cart style pseudo coupon and the set the amount
				wcs_set_coupon_property( $coupon, 'discount_type', 'renewal_cart' );

				wcs_set_coupon_property( $coupon, 'coupon_amount', $order_discount );

				// Set renewal order products as the product ids on the coupon
				wcs_set_coupon_property( $coupon, 'product_ids', $this->get_products( $order ) );

				// Store the coupon info for later
				$this->store_coupon( wcs_get_objects_property( $order, 'id' ), $coupon );

				// Add the coupon to the cart
				if ( WC()->cart && ! WC()->cart->has_discount( 'discount_renewal' ) ) {
					WC()->cart->add_discount( 'discount_renewal' );
				}
			}
		}
	}

	/**
	 * When a failed renewal order is being paid for via checkout, make sure WC_Checkout::create_order() preserves its
	 * status as 'failed' until it is paid. By default, it will always set it to 'pending', but we need it left as 'failed'
	 * so that we can correctly identify the status change in @see self::maybe_change_subscription_status().
	 *
	 * @param string Default order status for orders paid for via checkout. Default 'pending'
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 *
	 * @deprecated 6.3.0
	 */
	public function maybe_preserve_order_status( $order_status ) {
		wcs_deprecated_function( __METHOD__, '6.3.0' );
		if ( null !== WC()->session && 'failed' !== $order_status ) {

			$order_id = absint( WC()->session->order_awaiting_payment );

			// Guard against infinite loops in WC 3.0+ where default order status is set in WC_Abstract_Order::__construct()
			remove_filter( 'woocommerce_default_order_status', array( &$this, __FUNCTION__ ), 10 );

			$order = $order_id > 0 ? wc_get_order( $order_id ) : null;

			if ( $order && wcs_order_contains_renewal( $order ) && $order->has_status( 'failed' ) ) {
				$order_status = 'failed';
			}

			add_filter( 'woocommerce_default_order_status', array( &$this, __FUNCTION__ ) );
		}

		return $order_status;
	}
}
