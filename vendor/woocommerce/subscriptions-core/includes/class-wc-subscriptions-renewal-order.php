<?php
/**
 * Subscriptions Renewal Order Class
 *
 * Provides an API for creating and handling renewal orders.
 *
 * @package WooCommerce Subscriptions
 * @subpackage WC_Subscriptions_Order
 * @category Class
 * @author Brent Shepherd
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
 */
class WC_Subscriptions_Renewal_Order {

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function init() {

		// Trigger special hook when payment is completed on renewal orders
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'trigger_renewal_payment_complete' ), 10 );

		// When a renewal order's status changes, check if a corresponding subscription's status should be changed by marking it as paid (we can't use the 'woocommerce_payment_complete' here because it's not triggered by all payment gateways)
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_record_subscription_payment' ), 10, 3 );

		add_filter( 'wcs_renewal_order_created', array( __CLASS__, 'add_order_note' ), 10, 2 );

		// Prevent customers from cancelling renewal orders. Needs to be hooked before WC_Form_Handler::cancel_order() (20)
		add_action( 'wp_loaded', array( __CLASS__, 'prevent_cancelling_renewal_orders' ), 19, 3 );

		// Don't copy switch order item meta to renewal order items
		add_filter( 'wcs_new_order_items', array( __CLASS__, 'remove_switch_item_meta_keys' ), 10, 1 );
	}

	/* Helper functions */

	/**
	 * Trigger a special hook for payments on a completed renewal order.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.4
	 */
	public static function trigger_renewal_payment_complete( $order_id ) {
		if ( wcs_order_contains_renewal( $order_id ) ) {
			do_action( 'woocommerce_renewal_order_payment_complete', $order_id );
		}
	}

	/**
	 * Check if a given renewal order was created to replace a failed renewal order.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.12
	 * @param int ID of the renewal order you want to check against
	 * @return mixed If the renewal order did replace a failed order, the ID of the fail order, else false
	 */
	public static function get_failed_order_replaced_by( $renewal_order_id ) {

		// Get orders where order meta '_failed_order_replaced_by' = $renewal_order_id
		$failed_orders = wcs_get_orders_with_meta_query(
			[
				'limit'      => 1,
				'return'     => 'ids',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_failed_order_replaced_by',
						'compare' => '=',
						'value'   => $renewal_order_id,
					],
				],
			]
		);

		return $failed_orders[0] ?? false;
	}

	/**
	 * Whenever a renewal order's status is changed, check if a corresponding subscription's status should be changed
	 *
	 * This function is hooked to 'woocommerce_order_status_changed', rather than 'woocommerce_payment_complete', to ensure
	 * subscriptions are updated even if payment is processed by a manual payment gateways (which would never trigger the
	 * 'woocommerce_payment_complete' hook) or by some other means that circumvents that hook.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_record_subscription_payment( $order_id, $orders_old_status, $orders_new_status ) {

		if ( ! wcs_order_contains_renewal( $order_id ) ) {
			return;
		}

		$subscriptions        = wcs_get_subscriptions_for_renewal_order( $order_id );
		$was_activated        = false;
		$order                = wc_get_order( $order_id );
		$order_completed      = in_array( $orders_new_status, array( apply_filters( 'woocommerce_payment_complete_order_status', 'processing', $order_id, $order ), 'processing', 'completed' ) );
		$order_needed_payment = in_array( $orders_old_status, apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'on-hold', 'failed' ), $order ) );

		if ( $order_completed && $order_needed_payment ) {

			if ( wcs_is_woocommerce_pre( '3.0' ) ) {
				$update_post_data  = array(
					'ID'            => $order_id,
					'post_date'     => current_time( 'mysql', 0 ),
					'post_date_gmt' => current_time( 'mysql', 1 ),
				);

				wp_update_post( $update_post_data );
				update_post_meta( $order_id, '_paid_date', current_time( 'mysql' ) );
			} else {

				$current_time = current_time( 'timestamp', 1 );

				// Prior to WC 3.0, we need to update the post date (i.e. the date created) to have a reliable representation of the paid date (both because it was in GMT and because it was always set). That's not needed in WC 3.0, but some plugins and store owners still rely on it being updated, so we want to make it possible to update it with 3.0 also.
				if ( apply_filters( 'wcs_renewal_order_payment_update_date_created', false, $order, $subscriptions ) ) {
					$order->set_date_created( $current_time );
				}

				// In WC 3.0, only the paid date prop represents the paid date, the post date isn't used anymore, also the paid date is stored and referenced as a MySQL date string in site timezone and a GMT timestamp
				$order->set_date_paid( $current_time );
				$order->save();
			}
		}

		foreach ( $subscriptions as $subscription ) {

			// Do we need to activate a subscription?
			if ( $order_completed && ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) && ! $subscription->has_status( 'active' ) ) {

				// Included here because calling payment_complete sets the retry status to 'cancelled'
				$is_failed_renewal_order = 'failed' === $orders_old_status;
				$is_failed_renewal_order = apply_filters( 'woocommerce_subscriptions_is_failed_renewal_order', $is_failed_renewal_order, $order_id, $orders_old_status );

				if ( $order_needed_payment ) {
					$subscription->payment_complete();
					$was_activated = true;
				}

				if ( $is_failed_renewal_order ) {
					do_action( 'woocommerce_subscriptions_paid_for_failed_renewal_order', wc_get_order( $order_id ), $subscription );
				}
			} elseif ( 'failed' == $orders_new_status ) {
				$subscription->payment_failed();
			}
		}

		if ( $was_activated ) {
			do_action( 'subscriptions_activated_for_order', $order_id );
		}
	}

	/**
	 * Add order note to subscription to record the renewal order
	 *
	 * @param WC_Order|int $renewal_order
	 * @param WC_Subscription|int $subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function add_order_note( $renewal_order, $subscription ) {
		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		if ( ! is_object( $renewal_order ) ) {
			$renewal_order = wc_get_order( $renewal_order );
		}

		if ( is_a( $renewal_order, 'WC_Order' ) && wcs_is_subscription( $subscription ) ) {

			// translators: %s: order number.
			$order_number = sprintf( _x( '#%s', 'hash before order number', 'woocommerce-subscriptions' ), $renewal_order->get_order_number() );

			// translators: placeholder is order ID
			$subscription->add_order_note( sprintf( __( 'Order %s created to record renewal.', 'woocommerce-subscriptions' ), sprintf( '<a href="%s">%s</a> ', esc_url( wcs_get_edit_post_link( wcs_get_objects_property( $renewal_order, 'id' ) ) ), $order_number ) ) );
		}

		return $renewal_order;
	}

	/**
	 * Do not allow customers to cancel renewal orders.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function prevent_cancelling_renewal_orders() {
		if ( isset( $_GET['cancel_order'] ) && isset( $_GET['order'] ) && isset( $_GET['order_id'] ) ) {

			$order_id = absint( $_GET['order_id'] );
			$order    = wc_get_order( $order_id );
			$redirect = $_GET['redirect'];

			if ( wcs_order_contains_renewal( $order ) ) {
				remove_action( 'wp_loaded', 'WC_Form_Handler::cancel_order', 20 );
				wc_add_notice( __( 'Subscription renewal orders cannot be cancelled.', 'woocommerce-subscriptions' ), 'notice' );

				if ( $redirect ) {
					wp_safe_redirect( $redirect );
					exit;
				}
			}
		}
	}

	/**
	 * Removes switch line item meta data so it isn't copied to renewal order line items
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.16
	 * @param array $order_items
	 * @return array $order_items
	 */
	public static function remove_switch_item_meta_keys( $order_items ) {

		$switched_order_item_keys = array(
			'_switched_subscription_sign_up_fee_prorated' => '',
			'_switched_subscription_price_prorated'       => '',
			'_switched_subscription_item_id'              => '',
		);

		foreach ( $order_items as $order_item_id => $item ) {
			if ( is_callable( array( $item, 'delete_meta_data' ) ) ) { // WC 3.0+
				foreach ( $switched_order_item_keys as $switch_meta_key => $value ) {
					$item->delete_meta_data( $switch_meta_key );
				}
			} else { // WC 2.6
				$order_items[ $order_item_id ]['item_meta'] = array_diff_key( $item['item_meta'], $switched_order_item_keys );
			}
		}

		return $order_items;
	}

	/* Deprecated functions */

	/**
	 * Generate an order to record an automatic subscription payment.
	 *
	 * This function is hooked to the 'process_subscription_payment' which is fired when a payment gateway calls
	 * the @see WC_Subscriptions_Manager::process_subscription_payment() function. Because manual payments will
	 * also call this function, the function only generates a renewal order if the @see WC_Order::payment_complete()
	 * will be called for the renewal order.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function generate_paid_renewal_order( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_create_renewal_order( WC_Subscription $subscription )' );
		$subscription  = wcs_get_subscription_from_key( $subscription_key );
		$renewal_order = wcs_create_renewal_order( $subscription );
		$renewal_order->payment_complete();
		return wcs_get_objects_property( $renewal_order, 'id' );
	}

	/**
	 * Generate an order to record a subscription payment failure.
	 *
	 * This function is hooked to the 'processed_subscription_payment_failure' hook called when a payment
	 * gateway calls the @see WC_Subscriptions_Manager::process_subscription_payment_failure()
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function generate_failed_payment_renewal_order( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_create_renewal_order( WC_Subscription $subscription )' );
		$renewal_order = wcs_create_renewal_order( wcs_get_subscription_from_key( $subscription_key ) );
		$renewal_order->update_status( 'failed' );
		return wcs_get_objects_property( $renewal_order, 'id' );
	}

	/**
	 * Generate an order to record a subscription payment.
	 *
	 * This function is hooked to the scheduled subscription payment hook to create a pending
	 * order for each scheduled subscription payment.
	 *
	 * When a payment gateway calls the @see WC_Subscriptions_Manager::process_subscription_payment()
	 * @see WC_Order::payment_complete() will be called for the renewal order.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function maybe_generate_manual_renewal_order( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::maybe_create_manual_renewal_order( WC_Subscription $subscription )' );
		self::maybe_create_manual_renewal_order( wcs_get_subscription_from_key( $subscription_key ) );
	}

	/**
	 * Get the ID of the parent order for a subscription renewal order.
	 *
	 * Deprecated because a subscription's details are now stored in a WC_Subscription object, not the
	 * parent order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_parent_order_id( $renewal_order ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscriptions_for_renewal_order()' );

		$parent_order = self::get_parent_order( $renewal_order );

		return ( null === $parent_order ) ? null : wcs_get_objects_property( $parent_order, 'id' );
	}

	/**
	 * Get the parent order for a subscription renewal order.
	 *
	 * Deprecated because a subscription's details are now stored in a WC_Subscription object, not the
	 * parent order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0, self::get_parent_subscription() is the better function to use now as a renewal order
	 */
	public static function get_parent_order( $renewal_order ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscriptions_for_renewal_order()' );

		if ( ! is_object( $renewal_order ) ) {
			$renewal_order = new WC_Order( $renewal_order );
		}

		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
		$subscription  = array_pop( $subscriptions );

		if ( false == $subscription->get_parent_id() ) { // There is no original order
			$parent_order = null;
		} else {
			$parent_order = $subscription->get_parent();
		}

		return apply_filters( 'woocommerce_subscriptions_parent_order', $parent_order, $renewal_order );
	}

	/**
	 * Returns the number of renewals for a given parent order
	 *
	 * @param int $order_id The ID of a WC_Order object.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_renewal_order_count( $order_id ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_related_orders()' );

		$subscriptions_for_order = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );

		if ( ! empty( $subscriptions_for_order ) ) {

			$subscription = array_pop( $subscriptions_for_order );
			$all_orders   = $subscription->get_related_orders();

			$renewal_order_count = count( $all_orders );

			// Don't include the initial order (if any)
			if ( $subscription->get_parent_id() ) {
				$renewal_order_count -= 1;
			}
		} else {
			$renewal_order_count = 0;
		}

		return apply_filters( 'woocommerce_subscriptions_renewal_order_count', $renewal_order_count, $order_id );
	}

	/**
	 * Returns a URL including required parameters for an authenticated user to renew a subscription
	 *
	 * Deprecated because the use of a $subscription_key is deprecated.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_users_renewal_link( $subscription_key, $role = 'parent' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_users_resubscribe_link( $subscription )' );
		return wcs_get_users_resubscribe_link( wcs_get_subscription_from_key( $subscription_key ) );
	}

	/**
	 * Returns a URL including required parameters for an authenticated user to renew a subscription by product ID.
	 *
	 * Deprecated because the use of a $subscription_key is deprecated.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_users_renewal_link_for_product( $product_id ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_users_resubscribe_link_for_product( $subscription )' );
		return wcs_get_users_resubscribe_link_for_product( $product_id );
	}

	/**
	 * Check if a given subscription can be renewed.
	 *
	 * Deprecated because the use of a $subscription_key is deprecated.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function can_subscription_be_renewed( $subscription_key, $user_id = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_can_user_resubscribe_to( $subscription, $user_id )' );
		return wcs_can_user_resubscribe_to( wcs_get_subscription_from_key( $subscription_key ), $user_id );
	}

	/**
	 * Checks if the current request is by a user to renew their subscription, and if it is
	 * set up a subscription renewal via the cart for the product/variation that is being renewed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_create_renewal_order_for_user() {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::maybe_setup_resubscribe_via_cart()' );
	}

	/**
	 * When restoring the cart from the session, if the cart item contains addons, but is also
	 * a subscription renewal, do not adjust the price because the original order's price will
	 * be used, and this includes the addons amounts.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function product_addons_adjust_price( $adjust_price, $cart_item ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::product_addons_adjust_price()' );
	}

	/**
	 * Created a new order for renewing a subscription product based on the details of a previous order.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of the order for which the a new order should be created.
	 * @param string $product_id The ID of the subscription product in the order which needs to be added to the new order.
	 * @param array $args (optional) An array of name => value flags:
	 *         'new_order_role' string A flag to indicate whether the new order should become the master order for the subscription. Accepts either 'parent' or 'child'. Defaults to 'parent' - replace the existing order.
	 *         'checkout_renewal' bool Indicates if invoked from an interactive cart/checkout session and certain order items are not set, like taxes, shipping as they need to be set in teh calling function, like @see WC_Subscriptions_Checkout::filter_woocommerce_create_order(). Default false.
	 *         'failed_order_id' int For checkout_renewal true, indicates order id being replaced
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function generate_renewal_order( $original_order, $product_id, $args = array() ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_create_renewal_order() or wcs_create_resubscribe_order()' );

		if ( ! wcs_order_contains_subscription( $original_order, 'parent' ) ) {
			return false;
		}

		$args = wp_parse_args(
			$args,
			array(
				'new_order_role'   => 'parent',
				'checkout_renewal' => false,
			)
		);

		$subscriptions = wcs_get_subscriptions_for_order( $original_order, array( 'order_type' => 'parent' ) );
		$subscription  = array_shift( $subscriptions );

		if ( 'parent' == $args['new_order_role'] ) {
			$new_order = wcs_create_resubscribe_order( $subscription );
		} else {
			$new_order = wcs_create_renewal_order( $subscription );
		}

		return wcs_get_objects_property( $new_order, 'id' );
	}

	/**
	 * If a product is being marked as not purchasable because it is limited and the customer has a subscription,
	 * but the current request is to resubscribe to the subscription, then mark it as purchasable.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function is_purchasable( $is_purchasable, $product ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::is_purchasable()' );
		return $is_purchasable;
	}

	/**
	 * Check if a given order is a subscription renewal order and optionally, if it is a renewal order of a certain role.
	 *
	 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
	 * @param array $args (optional) An array of name => value flags:
	 *         'order_role' string (optional) A specific role to check the order against. Either 'parent' or 'child'.
	 *         'via_checkout' Indicates whether to check if the renewal order was via the cart/checkout process.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function is_renewal( $order, $args = array() ) {

		$args = wp_parse_args(
			$args,
			array(
				'order_role'   => '',
				'via_checkout' => false,
			)
		);

		$is_resubscribe_order = wcs_order_contains_resubscribe( $order );
		$is_renewal_order     = wcs_order_contains_renewal( $order );

		if ( empty( $args['new_order_role'] ) ) {
			_deprecated_function( __METHOD__, '2.0', 'wcs_order_contains_resubscribe( $order ) and wcs_order_contains_renewal( $order )' );
			return ( $is_resubscribe_order || $is_renewal_order );
		} elseif ( 'parent' == $args['new_order_role'] ) {
			_deprecated_function( __METHOD__, '2.0', 'wcs_order_contains_resubscribe( $order )' );
			return $is_resubscribe_order;
		} else {
			_deprecated_function( __METHOD__, '2.0', 'wcs_order_contains_renewal( $order )' );
			return $is_renewal_order;
		}
	}

	/**
	 * Returns the renewal orders for a given parent order
	 *
	 * @param int $order_id The ID of a WC_Order object.
	 * @param string $output (optional) How you'd like the result. Can be 'ID' for IDs only or 'WC_Order' for order objects.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_renewal_orders( $order_id, $output = 'ID' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_related_orders()' );

		$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );
		$subscription  = array_shift( $subscriptions );

		if ( 'WC_Order' == $output ) {

			$renewal_orders = $subscription->get_related_orders( 'all', 'renewal' );

		} else {

			$renewal_orders = $subscription->get_related_orders( 'ids', 'renewal' );

		}

		return apply_filters( 'woocommerce_subscriptions_renewal_orders', $renewal_orders, $order_id );
	}

	/**
	 * Flag payment of manual renewal orders.
	 *
	 * This is particularly important to ensure renewals of limited subscriptions can be completed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.5.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_checkout_payment_url( $pay_url, $order ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::get_checkout_payment_url() or WCS_Cart_Resubscribe::get_checkout_payment_url()' );
		return $pay_url;
	}

	/**
	 * Process a renewal payment when a customer has completed the payment for a renewal payment which previously failed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_process_failed_renewal_order_payment( $order_id ) {
		_deprecated_function( __METHOD__, '2.0', 'WCS_Cart_Renewal::maybe_change_subscription_status( $order_id, $orders_old_status, $orders_new_status )' );
	}

	/**
	 * If the payment for a renewal order has previously failed and is then paid, then the
	 * @see WC_Subscriptions_Manager::process_subscription_payments_on_order() function would
	 * never be called. This function makes sure it is called.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function process_failed_renewal_order_payment( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
			$subscription  = array_pop( $subscriptions );

			if ( $subscription->is_manual() ) {
				add_action( 'woocommerce_payment_complete', __CLASS__ . '::process_subscription_payment_on_child_order', 10, 1 );
			}
		}
	}

	/**
	 * Records manual payment of a renewal order against a subscription.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_record_renewal_order_payment( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
			$subscription  = array_pop( $subscriptions );

			if ( $subscription->is_manual() ) {
				self::process_subscription_payment_on_child_order( $order_id );
			}
		}
	}

	/**
	 * Records manual payment of a renewal order against a subscription.
	 *
	 * @param WC_Order|int $order A WC_Order object or ID of a WC_Order order.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_record_renewal_order_payment_failure( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
			$subscription  = array_pop( $subscriptions );

			if ( $subscription->is_manual() ) {
				self::process_subscription_payment_on_child_order( $order_id, 'failed' );
			}
		}
	}

	/**
	 * If the payment for a renewal order has previously failed and is then paid, we need to make sure the
	 * subscription payment function is called.
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function process_subscription_payment_on_child_order( $order_id, $payment_status = 'completed' ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( wcs_order_contains_renewal( $order_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );

			foreach ( $subscriptions as $subscription ) {

				if ( 'failed' == $payment_status ) {

					$subscription->payment_failed();

				} else {

					$subscription->payment_complete();

					$subscription->update_status( 'active' );
				}
			}
		}
	}

	/**
	 * Adds a renewal orders section to the Related Orders meta box displayed on subscription orders.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function renewal_orders_meta_box_section( $order, $post ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Trigger a hook when a subscription suspended due to a failed renewal payment is reactivated
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function trigger_processed_failed_renewal_order_payment_hook( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::maybe_record_subscription_payment( $order_id, $orders_old_status, $orders_new_status )' );
	}
}
