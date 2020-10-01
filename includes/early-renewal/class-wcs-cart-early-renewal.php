<?php
/**
 * Implement renewing a subscription early via the cart.
 *
 * @class      WCS_Cart_Early_Renewal
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Early_Renewal
 * @category   Class
 * @since      2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Cart_Early_Renewal extends WCS_Cart_Renewal {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 */
	public function __construct() {

		// Add the renew now button to the view subscription page.
		add_filter( 'wcs_view_subscription_actions', array( $this, 'add_renew_now_action' ), 10, 2 );

		// Check if a user is requesting to create an early renewal order for a subscription.
		add_action( 'template_redirect', array( $this, 'maybe_setup_cart' ), 100 );

		add_action( 'woocommerce_checkout_create_order', array( $this, 'copy_subscription_meta_to_order' ), 90 );
		// Record early renewal payments.
		if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'maybe_record_early_renewal' ), 100, 2 );
		} else {
			add_action( 'woocommerce_checkout_create_order', array( $this, 'add_early_renewal_metadata_to_order' ), 100, 2 );
		}

		// Process early renewal by making sure subscription's dates are updated.
		add_action( 'subscriptions_activated_for_order', array( $this, 'maybe_update_dates' ) );

		// Handle early renewal orders that are cancelled.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'maybe_reactivate_subscription' ), 100, 2 );

		// Add a subscription note to record early renewal order.
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'add_note_to_record_early_renewal' ) );

		// After the renewal order is created on checkout, set the renewal order cart item data now that we have an order. Must be hooked on before WCS_Cart_Renewal->set_order_item_id(), in order for the line item ID set by that function to be correct.
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'set_cart_item_renewal_order_data' ), 5 );

		// Allow customers to cancel early renewal orders from their my account page.
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_cancel_order_action' ), 15, 2 );
		add_action( 'wp_loaded', array( $this, 'allow_early_renewal_order_cancellation' ), 10, 3 );

		// Handles early renew of password-protected products.
		add_action( 'wcs_before_early_renewal_setup_cart_subscription', 'wcs_allow_protected_products_to_renew' );
		add_action( 'wcs_after_early_renewal_setup_cart_subscription', 'wcs_disallow_protected_product_add_to_cart_validation' );
	}

	/**
	 * Adds a "Renew Now" button to the "View Subscription" page.
	 *
	 * @param array $actions The $subscription_key => $actions array with all actions that will be displayed for a subscription on the "View Subscription" page.
	 * @param WC_Subscription $subscription The current subscription being viewed.
	 * @since 2.3.0
	 * @return array $actions The subscription actions with the "Renew Now" action added if it's permitted.
	 */
	public function add_renew_now_action( $actions, $subscription ) {

		if ( wcs_can_user_renew_early( $subscription ) && $subscription->payment_method_supports( 'subscription_date_changes' ) && $subscription->has_status( 'active' ) ) {

			$actions['subscription_renewal_early'] = array(
				'url'  => wcs_get_early_renewal_url( $subscription ),
				'name' => __( 'Renew now', 'woocommerce-subscriptions' ),
			);
		}

		return $actions;
	}

	/**
	 * Check if a payment is being made on an early renewal order.
	 */
	public function maybe_setup_cart() {
		if ( ! isset( $_GET['subscription_renewal_early'] ) ) {
			return;
		}

		$subscription = wcs_get_subscription( absint( $_GET['subscription_renewal_early'] ) );
		$redirect_to  = get_permalink( wc_get_page_id( 'myaccount' ) );

		if ( empty( $subscription ) ) {

			wc_add_notice( __( 'That subscription does not exist. Has it been deleted?', 'woocommerce-subscriptions' ), 'error' );

		} elseif ( ! current_user_can( 'subscribe_again', $subscription->get_id() ) ) {

			wc_add_notice( __( "That doesn't appear to be one of your subscriptions.", 'woocommerce-subscriptions' ), 'error' );

		} elseif ( ! wcs_can_user_renew_early( $subscription ) ) {

			wc_add_notice( __( 'You can not renew this subscription early. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );

		} else {

			do_action( 'wcs_before_early_renewal_setup_cart_subscription', $subscription );

			wc_add_notice( __( 'Complete checkout to renew now.', 'woocommerce-subscriptions' ), 'success' );

			$this->setup_cart( $subscription, array(
				'subscription_id'            => $subscription->get_id(),
				'subscription_renewal_early' => true,
				'renewal_order_id'           => $subscription->get_id(),
			), 'all_items_required' );

			do_action( 'wcs_after_early_renewal_setup_cart_subscription', $subscription );

			$redirect_to = wc_get_checkout_url();
		}

		wp_safe_redirect( $redirect_to );
		exit;
	}

	/**
	 * Records an early renewal against order created on checkout (only for WooCommerce < 3.0).
	 *
	 * @param int $order_id The post_id of a shop_order post/WC_Order object.
	 * @param array $posted_data The data posted on checkout.
	 * @since 2.3.0
	 */
	public function maybe_record_early_renewal( $order_id, $posted_data ) {
		if ( ! WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
			wcs_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Early_Renewal::add_early_renewal_metadata_to_order( $order, $posted_data )' );
		}

		$cart_item = $this->cart_contains();

		if ( ! $cart_item ) {
			return;
		}

		// Get the subscription.
		$subscription = wcs_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );

		// Mark this order as a renewal.
		update_post_meta( $order_id, '_subscription_renewal', $subscription->get_id() );

		// Mark this order as an early renewal.
		update_post_meta( $order_id, '_subscription_renewal_early', $subscription->get_id() );

		// Put the subscription on hold until payment is complete.
		$subscription->update_status( 'on-hold', _x( 'Customer requested to renew early:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' ) );
	}

	/**
	 * Copies the metadata from the subscription to the order created on checkout.
	 *
	 * @param WC_Order $order The WC Order object.
	 *
	 * @since 2.5.2
	 */
	public function copy_subscription_meta_to_order( $order ) {
		if ( $this->cart_contains() ) {
			// Get the subscription.
			$subscription = $this->get_order();

			if ( $subscription ) {
				// Copy all meta, excluding core properties (totals etc), from the subscription to new renewal order
				add_filter( 'wcs_renewal_order_meta', array( $this, 'exclude_core_order_meta_properties' ) );
				wcs_copy_order_meta( $subscription, $order, 'renewal_order' );
				remove_filter( 'wcs_renewal_order_meta', array( $this, 'exclude_core_order_meta_properties' ) );
			}
		}
	}

	/**
	 * Adds the early renewal metadata to the order created on checkout.
	 *
	 * @param WC_Order $order The WC Order object.
	 * @param array $data The data posted on checkout.
	 * @since 2.3.0
	 */
	public function add_early_renewal_metadata_to_order( $order, $data ) {

		$cart_item = $this->cart_contains();
		if ( ! $cart_item ) {
			return;
		}

		// Get the subscription.
		$subscription = wcs_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );

		// Mark this order as a renewal.
		$order->update_meta_data( '_subscription_renewal', $subscription->get_id() );

		// Mark this order as an early renewal.
		$order->update_meta_data( '_subscription_renewal_early', $subscription->get_id() );

		// Put the subscription on hold until payment is complete.
		$subscription->update_status( 'on-hold', _x( 'Customer requested to renew early:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' ) );
	}

	/**
	 * Update the next payment and end dates on a subscription to extend them and account
	 * for early renewal.
	 *
	 * @param int $order_id The WC Order ID which contains an early renewal.
	 * @since 2.3.0
	 */
	public function maybe_update_dates( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order || ! wcs_order_contains_early_renewal( $order ) ) {
			return;
		}

		$subscription_id = wcs_get_objects_property( $order, 'subscription_renewal_early' );
		$subscription    = wcs_get_subscription( $subscription_id );

		if ( ! $subscription ) {
			return;
		}

		wcs_update_dates_after_early_renewal( $subscription, $order );
	}

	/**
	 * Reactivates an on hold subscription when an early renewal order
	 * is cancelled by the user.
	 *
	 * @param int $order_id The WC Order ID which contains an early renewal.
	 * @since 2.3.0
	 */
	public function maybe_reactivate_subscription( $order_id ) {

		// Get the order and make sure we have one.
		$order = wc_get_order( $order_id );

		if ( wcs_order_contains_early_renewal( $order ) ) {

			// Get the subscription and make sure we have one.
			$subscription = wcs_get_subscription( wcs_get_objects_property( $order, 'subscription_renewal_early' ) );

			if ( ! $subscription || ! $subscription->has_status( 'on-hold' ) ) {
				return;
			}

			// Make sure the next payment date isn't in the past.
			if ( strtotime( $subscription->get_date( 'next_payment' ) ) < time() ) {
				return;
			}

			// Reactivate the subscription.
			$subscription->update_status( 'active' );
		}
	}

	/**
	 * Checks the cart to see if it contains a subscription renewal item.
	 *
	 * @see    wcs_cart_contains_early_renewal().
	 * @return bool|array The cart item containing the renewal, else false.
	 * @since  2.3.0
	 */
	protected function cart_contains() {
		return wcs_cart_contains_early_renewal();
	}

	/**
	 * Get the subscription object used to construct the early renewal cart.
	 *
	 * @param  array The resubscribe cart item.
	 * @return WC_Subscription The subscription object.
	 * @since  2.3.0
	 */
	protected function get_order( $cart_item = '' ) {

		$subscription = false;

		if ( empty( $cart_item ) ) {
			$cart_item = $this->cart_contains();
		}

		if ( false !== $cart_item && isset( $cart_item[ $this->cart_item_key ] ) ) {
			$subscription = wcs_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );
		}

		return $subscription;
	}

	/**
	 * Add a note to the subscription to record the creation of the early renewal order.
	 *
	 * @param int $order_id The order ID created on checkout.
	 * @since 2.3.0
	 */
	public function add_note_to_record_early_renewal( $order_id ) {

		$cart_item = $this->cart_contains();

		if ( ! $cart_item ) {
			return;
		}

		$order        = wc_get_order( $order_id );
		$subscription = wcs_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );

		if ( wcs_is_order( $order ) && wcs_is_subscription( $subscription ) ) {
			// translators: %s: order ID.
			$order_number = sprintf( _x( '#%s', 'hash before order number', 'woocommerce-subscriptions' ), $order->get_order_number() );

			// translators: %s: order ID (linked to details page).
			$subscription->add_order_note( sprintf( __( 'Order %s created to record early renewal.', 'woocommerce-subscriptions' ), sprintf( '<a href="%s">%s</a> ', esc_url( wcs_get_edit_post_link( $order_id ) ), $order_number ) ) );
		}
	}

	/**
	 * Set the renewal order ID in early renewal order cart items.
	 *
	 * Hooked onto the 'woocommerce_checkout_update_order_meta' hook after the renewal order has been
	 * created on checkout. Required so the line item ID set by @see WCS_Cart_Renewal->set_order_item_id()
	 * matches the order.
	 *
	 * @param int $order_id The WC Order ID created on checkout.
	 * @since 2.3.0
	 */
	public function set_cart_item_renewal_order_data( $order_id ) {

		if ( ! wcs_cart_contains_early_renewal() ) {
			return;
		}

		foreach ( WC()->cart->cart_contents as $key => &$cart_item ) {
			if ( isset( $cart_item[ $this->cart_item_key ] ) && ! empty( $cart_item[ $this->cart_item_key ]['subscription_renewal_early'] ) ) {
				$cart_item[ $this->cart_item_key ]['renewal_order_id'] = $order_id;
			}
		}
	}

	/**
	 * Ensure customers can cancel early renewal orders.
	 *
	 * Renewal orders are usually not cancellable because @see WCS_Cart_Renewal::filter_my_account_my_orders_actions() prevents it.
	 * In the case of early renewals, the customer has opted for early renewal and so should be able to cancel it in order to reactivate their subscription.
	 *
	 * @param array $actions A list of actions customers can make on an order from their My Account page
	 * @param WC_Order $order The order the list of actions relate to.
	 * @return array $actions
	 * @since 2.3.0
	 */
	public static function add_cancel_order_action( $actions, $order ) {

		if ( ! isset( $actions['cancel'] ) && wcs_order_contains_early_renewal( $order ) && in_array( $order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order ) ) ) {
			$redirect = wc_get_page_permalink( 'myaccount' );

			// Redirect the customer back to the view subscription page if that is where they cancel the order from.
			if ( wcs_is_view_subscription_page() ) {
				global $wp;
				$subscription = wcs_get_subscription( $wp->query_vars['view-subscription'] );

				if ( wcs_is_subscription( $subscription ) ) {
					$redirect = $subscription->get_view_order_url();
				}
			}

			$actions['cancel'] = array(
				'url'  => $order->get_cancel_order_url( $redirect ),
				'name' => __( 'Cancel', 'woocommerce-subscriptions' ),
			);
		}

		return $actions;
	}

	/**
	 * Allow customers to cancel early renewal orders from their account page.
	 *
	 * Renewal orders are usually not cancellable because @see WC_Subscriptions_Renewal_Order::prevent_cancelling_renewal_orders() prevents the request from being processed.
	 * In the case of early renewals, the customer has opted for early renewal and so should be able to cancel it in order to reactivate their subscription.
	 *
	 * @since 2.3.0
	 */
	public static function allow_early_renewal_order_cancellation() {
		if ( isset( $_GET['cancel_order'] ) && isset( $_GET['order_id'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'woocommerce-cancel_order' ) ) {
			$order_id = absint( $_GET['order_id'] );
			$order    = wc_get_order( $order_id );

			if ( wcs_order_contains_early_renewal( $order ) ) {
				remove_action( 'wp_loaded', 'WC_Subscriptions_Renewal_Order::prevent_cancelling_renewal_orders', 19 );
			}
		}
	}

	/**
	 * Excludes core order meta properties from the meta copied from the subscription.
	 *
	 * Attached to the dynamic hook 'wcs_renewal_order_meta' which is triggered by wcs_copy_order_meta
	 * when copying meta from the subscription to the early renewal order.
	 *
	 * @since 2.5.6
	 *
	 * @param array $order_meta The meta keys and values to copy from the subscription to the early renewal order.
	 * @return array The subscription meta to copy to the early renewal order.
	 */
	public function exclude_core_order_meta_properties( $order_meta ) {

		// Additional meta keys to exclude. These are in addition to the meta keys already excluded by wcs_copy_order_meta().
		$excluded_meta_keys = array(
			'_customer_user'          => 1,
			'_order_currency'         => 1,
			'_prices_include_tax'     => 1,
			'_order_version'          => 1,
			'_shipping_first_name'    => 1,
			'_shipping_last_name'     => 1,
			'_shipping_company'       => 1,
			'_shipping_address_1'     => 1,
			'_shipping_address_2'     => 1,
			'_shipping_city'          => 1,
			'_shipping_state'         => 1,
			'_shipping_postcode'      => 1,
			'_shipping_country'       => 1,
			'_shipping_address_index' => 1,
			'_billing_first_name'     => 1,
			'_billing_last_name'      => 1,
			'_billing_company'        => 1,
			'_billing_address_1'      => 1,
			'_billing_address_2'      => 1,
			'_billing_city'           => 1,
			'_billing_state'          => 1,
			'_billing_postcode'       => 1,
			'_billing_country'        => 1,
			'_billing_email'          => 1,
			'_billing_phone'          => 1,
			'_billing_address_index'  => 1,
			'is_vat_exempt'           => 1,
			'_customer_ip_address'    => 1,
			'_customer_user_agent'    => 1,
			'_cart_discount'          => 1,
			'_cart_discount_tax'      => 1,
			'_order_shipping'         => 1,
			'_order_shipping_tax'     => 1,
			'_order_tax'              => 1,
			'_order_total'            => 1,
		);

		foreach ( $order_meta as $index => $meta ) {
			if ( isset( $excluded_meta_keys[ $meta['meta_key'] ] ) ) {
				unset( $order_meta[ $index ] );
			}
		}

		return $order_meta;
	}
}
