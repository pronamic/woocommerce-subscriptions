<?php
/**
 * Implement resubscribing to a subscription via the cart.
 *
 * Resubscribing is a similar process to renewal via checkout (which is why this class extends WCS_Cart_Renewal), only it:
 * - creates a new subscription with similar terms to the existing subscription, where as a renewal resumes the existing subscription
 * - is for an expired or cancelled subscription only.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WCS_Cart_Resubscribe
 * @category	Class
 * @author		Prospress
 * @since		2.0
 */

class WCS_Cart_Resubscribe extends WCS_Cart_Renewal {

	/* The flag used to indicate if a cart item is a renewal */
	public $cart_item_key = 'subscription_resubscribe';

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 2.0
	 */
	public function __construct() {

		$this->setup_hooks();

		// When a resubscribe order is created on checkout, record the resubscribe, attached after WC_Subscriptions_Checkout::process_checkout()
		add_action( 'woocommerce_checkout_subscription_created', array( &$this, 'maybe_record_resubscribe' ), 10, 3 );
	}

	/**
	 * Checks if the current request is by a user to resubcribe to a subscription, and if it is setup a
	 * subscription resubcribe process via the cart for the product/variation/s that are being renewed.
	 *
	 * @since 2.0
	 */
	public function maybe_setup_cart() {
		global $wp;

		if ( isset( $_GET['resubscribe'] ) && isset( $_GET['_wpnonce'] ) ) {

			$subscription = wcs_get_subscription( $_GET['resubscribe'] );
			$redirect_to  = get_permalink( wc_get_page_id( 'myaccount' ) );

			if ( wp_verify_nonce( $_GET['_wpnonce'], $subscription->id ) === false ) {

				wc_add_notice( __( 'There was an error with your request to resubscribe. Please try again.', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( empty( $subscription ) ) {

				wc_add_notice( __( 'That subscription does not exist. Has it been deleted?', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( ! current_user_can( 'subscribe_again', $subscription->id ) ) {

				wc_add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( ! wcs_can_user_resubscribe_to( $subscription ) ) {

				wc_add_notice( __( 'You can not resubscribe to that subscription. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );

			} else {

				$this->setup_cart( $subscription, array(
					'subscription_id' => $subscription->id,
				) );

				if ( WC()->cart->get_cart_contents_count() != 0 ) {
					wc_add_notice( __( 'Complete checkout to resubscribe.', 'woocommerce-subscriptions' ), 'success' );
				}

				$redirect_to = WC()->cart->get_checkout_url();
			}

			wp_safe_redirect( $redirect_to );
			exit;

		} elseif ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) && isset( $wp->query_vars['order-pay'] ) ) {

			$order_id     = ( isset( $wp->query_vars['order-pay'] ) ) ? $wp->query_vars['order-pay'] : absint( $_GET['order_id'] );
			$order        = wc_get_order( $wp->query_vars['order-pay'] );
			$order_key    = $_GET['key'];

			if ( $order->order_key == $order_key && $order->has_status( array( 'pending', 'failed' ) ) && wcs_order_contains_resubscribe( $order ) ) {

				wc_add_notice( __( 'Complete checkout to resubscribe.', 'woocommerce-subscriptions' ), 'success' );

				$subscriptions = wcs_get_subscriptions_for_resubscribe_order( $order );

				foreach ( $subscriptions as $subscription ) {
					$this->setup_cart( $subscription, array(
						'subscription_id' => $subscription->id,
					) );
				}

				$redirect_to = WC()->cart->get_checkout_url();
				wp_safe_redirect( $redirect_to );
				exit;
			}
		}
	}

	/**
	 * When creating an order at checkout, if the checkout is to resubscribe to an expired or cancelled
	 * subscription, make sure we record that on the order and new subscription.
	 *
	 * @since 2.0
	 */
	public function maybe_record_resubscribe( $new_subscription, $order, $recurring_cart ) {

		$cart_item = $this->cart_contains( $recurring_cart );

		if ( false !== $cart_item ) {
			update_post_meta( $order->id, '_subscription_resubscribe', $cart_item[ $this->cart_item_key ]['subscription_id'], true );
			update_post_meta( $new_subscription->id, '_subscription_resubscribe', $cart_item[ $this->cart_item_key ]['subscription_id'], true );
		}
	}

	/**
	 * Restore renewal flag when cart is reset and modify Product object with renewal order related info
	 *
	 * @since 2.0
	 */
	public function get_cart_item_from_session( $cart_item_session_data, $cart_item, $key ) {
		if ( isset( $cart_item[ $this->cart_item_key ]['subscription_id'] ) ) {

			// Setup the cart as if it's a renewal (as the setup process is almost the same)
			$cart_item_session_data = parent::get_cart_item_from_session( $cart_item_session_data, $cart_item, $key );

			// Need to get the original subscription price, not the current price
			$subscription = wcs_get_subscription( $cart_item[ $this->cart_item_key ]['subscription_id'] );
			if ( $subscription ) {
				// Make sure the original subscription terms perisist
				$_product                               = $cart_item_session_data['data'];
				$_product->subscription_period          = $subscription->billing_period;
				$_product->subscription_period_interval = $subscription->billing_interval;

				// And don't give another free trial period
				$_product->subscription_trial_length = 0;
			}
		}

		return $cart_item_session_data;
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

			// Validating when restoring cart from session
			if ( false !== $this->cart_contains() ) {

				$resubscribe_cart_item = $this->cart_contains();
				$subscription          = wcs_get_subscription( $resubscribe_cart_item['subscription_resubscribe']['subscription_id'] );

				if ( $subscription->has_product( $product->id ) ) {
					$is_purchasable = true;
				}

			// Restoring cart from session, so need to check the cart in the session (wcs_cart_contains_renewal() only checks the cart)
			} elseif ( isset( WC()->session->cart ) ) {

				foreach ( WC()->session->cart as $cart_item_key => $cart_item ) {
					if ( $product->id == $cart_item['product_id'] && isset( $cart_item[ $this->cart_item_key ] ) ) {
						$is_purchasable = true;
						break;
					}
				}
			} elseif ( isset( $_GET['resubscribe'] ) ) { // Is a request to resubscribe

				$subscription = wcs_get_subscription( absint( $_GET['resubscribe'] ) );

				if ( false !== $subscription && $subscription->has_product( $product->id ) && wcs_can_user_resubscribe_to( $subscription ) ) {
					$is_purchasable = true;
				}
			}
		}

		return $is_purchasable;
	}

	/**
	 * Checks the cart to see if it contains a subscription resubscribe item.
	 *
	 * @see wcs_cart_contains_resubscribe()
	 * @param WC_Cart $cart The cart object to search in.
	 * @return bool | Array The cart item containing the renewal, else false.
	 * @since  2.0.10
	 */
	protected function cart_contains( $cart = '' ) {
		return wcs_cart_contains_resubscribe( $cart );
	}

	/**
	 * Get the subscription object used to construct the resubscribe cart.
	 *
	 * @param Array The resubscribe cart item.
	 * @return WC_Subscription | The subscription object.
	 * @since  2.0.13
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
}
new WCS_Cart_Resubscribe();
