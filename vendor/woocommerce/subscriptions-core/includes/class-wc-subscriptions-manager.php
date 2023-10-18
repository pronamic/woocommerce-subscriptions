<?php
/**
 * Subscriptions Management Class
 *
 * An API of Subscription utility functions and Account Management functions.
 *
 * Subscription activation and cancellation functions are hooked directly to order status changes
 * so your payment gateway only needs to work with WooCommerce APIs. You can however call other
 * management functions directly when necessary.
 *
 * @package     WooCommerce Subscriptions
 * @subpackage  WC_Subscriptions_Manager
 * @category    Class
 * @author      Brent Shepherd
 * @since       1.0.0 - Migrated from WooCommerce Subscriptions v1.0
 */
class WC_Subscriptions_Manager {

	/**
	 * The database key for user's subscriptions.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static $users_meta_key = 'woocommerce_subscriptions';

	/**
	 * Set up the class, including it's hooks & filters, when the file is loaded.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 **/
	public static function init() {
		// When an order's status is changed, run the appropriate subscription function
		add_action( 'woocommerce_order_status_cancelled', __CLASS__ . '::cancel_subscriptions_for_order' );
		add_action( 'woocommerce_order_status_failed', __CLASS__ . '::failed_subscription_sign_ups_for_order' );
		add_action( 'woocommerce_order_status_on-hold', __CLASS__ . '::put_subscription_on_hold_for_order' );

		// Expire a user's subscription
		add_action( 'woocommerce_scheduled_subscription_expiration', __CLASS__ . '::expire_subscription', 10, 1 );

		// Expire a user's subscription
		add_action( 'woocommerce_scheduled_subscription_end_of_prepaid_term', __CLASS__ . '::subscription_end_of_prepaid_term', 10, 1 );

		// Check if the subscription needs to use the failed payment process to repair its status
		add_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::maybe_process_failed_renewal_for_repair', 0, 1 );

		// Whenever a renewal payment is due, put the subscription on hold and create a renewal order before anything else, in case things don't go to plan
		add_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::prepare_renewal', 1, 1 );

		// When a subscriptions trial end scheduled action is run, attach a callback to trigger a subscription specific trial ended hook.
		add_action( 'woocommerce_scheduled_subscription_trial_end', __CLASS__ . '::trigger_subscription_trial_ended_hook', 10, 1 );

		// Attach hooks that depend on WooCommerce being loaded.
		add_action( 'woocommerce_loaded', [ __CLASS__, 'attach_wc_dependant_hooks' ] );

		// When a user is being deleted from the site, via standard WordPress functions, make sure their subscriptions are cancelled
		add_action( 'delete_user', __CLASS__ . '::trash_users_subscriptions' );

		// Do the same thing for WordPress networks
		add_action( 'wpmu_delete_user', __CLASS__ . '::trash_users_subscriptions_for_network' );
	}

	/**
	 * Attaches hooks that depend on WooCommerce being loaded.
	 *
	 * We need to use different hooks on stores that have HPOS enabled but to check if this feature
	 * is enabled, we must wait for WooCommerce to be loaded first.
	 *
	 * @since 5.2.0
	 */
	public static function attach_wc_dependant_hooks() {
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			// When a parent order is trashed, untrashed or deleted, make sure the appropriate action is taken on the related subscription
			add_action( 'woocommerce_before_trash_order', [ __CLASS__, 'maybe_trash_subscription' ], 10 );
			add_action( 'woocommerce_untrash_order', [ __CLASS__, 'maybe_untrash_subscription' ], 10 );
			add_action( 'woocommerce_before_delete_order', [ __CLASS__, 'maybe_delete_subscription' ] );

			// make sure a subscription is cancelled before it is trashed/deleted
			add_action( 'woocommerce_before_trash_subscription', [ __CLASS__, 'maybe_cancel_subscription' ], 10, 1 );
			add_action( 'woocommerce_before_delete_subscription', [ __CLASS__, 'maybe_cancel_subscription' ], 10, 1 );

			// set correct status to restore after a subscription is trashed/deleted
			add_action( 'woocommerce_trash_subscription', [ __CLASS__, 'fix_trash_meta_status' ] );
		} else {
			// When a parent order is trashed, untrashed or deleted, make sure the appropriate action is taken on the related subscription
			add_action( 'wp_trash_post', __CLASS__ . '::maybe_trash_subscription', 10 );
			add_action( 'untrashed_post', __CLASS__ . '::maybe_untrash_subscription', 10 );
			add_action( 'before_delete_post', array( __CLASS__, 'maybe_delete_subscription' ) );

			// make sure a subscription is cancelled before it is trashed/deleted
			add_action( 'wp_trash_post', __CLASS__ . '::maybe_cancel_subscription', 10, 1 );
			add_action( 'before_delete_post', __CLASS__ . '::maybe_cancel_subscription', 10, 1 );

			// set correct status to restore after a subscription is trashed/deleted
			add_action( 'trashed_post', __CLASS__ . '::fix_trash_meta_status' );

			// call special hooks when a subscription is trashed/deleted
			add_action( 'trashed_post', __CLASS__ . '::trigger_subscription_trashed_hook' );
			add_action( 'deleted_post', __CLASS__ . '::trigger_subscription_deleted_hook' );
		}
	}

	/**
	 * Sets up renewal for subscriptions managed by Subscriptions.
	 *
	 * This function is hooked early on the scheduled subscription payment hook.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function prepare_renewal( $subscription_id ) {

		$order_note = _x( 'Subscription renewal payment due:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' );

		$renewal_order = self::process_renewal( $subscription_id, 'active', $order_note );

		// Backward compatibility with Subscriptions < 2.2.12 where we returned false for an unknown reason
		if ( false === $renewal_order ) {
			return $renewal_order;
		}
	}

	/**
	 * Process renewal for a subscription.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post
	 * @param string $required_status The subscription status required to process a renewal order
	 * @param string $order_note Reason for subscription status change
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.12
	 */
	public static function process_renewal( $subscription_id, $required_status, $order_note ) {

		$subscription = wcs_get_subscription( $subscription_id );

		// If the subscription is using manual payments, the gateway isn't active or it manages scheduled payments
		if ( ! empty( $subscription ) && $subscription->has_status( $required_status ) && ( 0 == $subscription->get_total() || $subscription->is_manual() || '' == $subscription->get_payment_method() || ! $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) ) {

			// Always put the subscription on hold in case something goes wrong while trying to process renewal
			$subscription->update_status( 'on-hold', $order_note );

			// Generate a renewal order for payment gateways to use to record the payment (and determine how much is due)
			$renewal_order = wcs_create_renewal_order( $subscription );

			if ( is_wp_error( $renewal_order ) ) {
				// let's try this again
				$renewal_order = wcs_create_renewal_order( $subscription );

				if ( is_wp_error( $renewal_order ) ) {
					// translators: placeholder is an order note.
					throw new Exception( sprintf( __( 'Error: Unable to create renewal order with note "%s"', 'woocommerce-subscriptions' ), $order_note ) );
				}
			}

			if ( 0 == $renewal_order->get_total() ) {
				$renewal_order->payment_complete(); // We don't need to reactivate the subscription here because calling payment complete on the order will do that for us.
			} else {

				if ( $subscription->is_manual() ) {
					do_action( 'woocommerce_generated_manual_renewal_order', wcs_get_objects_property( $renewal_order, 'id' ), $subscription );
					$renewal_order->add_order_note( __( 'Manual renewal order awaiting customer payment.', 'woocommerce-subscriptions' ) );
				} else {
					$renewal_order->set_payment_method( wc_get_payment_gateway_by_order( $subscription ) ); // We need to pass the payment gateway instance to be compatible with WC < 3.0, only WC 3.0+ supports passing the string name

					if ( is_callable( array( $renewal_order, 'save' ) ) ) { // WC 3.0+ We need to save the payment method.
						$renewal_order->save();
					}
				}
			}
		} else {
			$renewal_order = false;
		}

		return $renewal_order;
	}

	/**
	 * Expires a single subscription on a users account.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function expire_subscription( $subscription_id, $deprecated = null ) {

		if ( null !== $deprecated ) {
			_deprecated_argument( __METHOD__, '2.0', 'The subscription key is deprecated. Use a subscription post ID' );
			$subscription = wcs_get_subscription_from_key( $deprecated );
		} else {
			$subscription = wcs_get_subscription( $subscription_id );
		}

		if ( false === $subscription ) {
			// translators: placeholder is a subscription ID.
			throw new InvalidArgumentException( sprintf( __( 'Subscription doesn\'t exist in scheduled action: %d', 'woocommerce-subscriptions' ), $subscription_id ) );
		}

		$subscription->update_status( 'expired' );
	}

	/**
	 * Fires when a cancelled subscription reaches the end of its prepaid term.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 */
	public static function subscription_end_of_prepaid_term( $subscription_id, $deprecated = null ) {

		if ( null !== $deprecated ) {
			_deprecated_argument( __METHOD__, '2.0', 'The subscription key is deprecated. Use a subscription post ID' );
			$subscription = wcs_get_subscription_from_key( $deprecated );
		} else {
			$subscription = wcs_get_subscription( $subscription_id );
		}

		if ( $subscription ) {
			$subscription->update_status( 'cancelled' );
		}
	}

	/**
	 * Trigger action hook after a subscription's trial period has ended.
	 *
	 * @since 5.5.0
	 *
	 * @param int $subscription_id
	 */
	public static function trigger_subscription_trial_ended_hook( $subscription_id ) {
		do_action( 'woocommerce_subscription_trial_ended', $subscription_id );
	}

	/**
	 * Records a payment on a subscription.
	 *
	 * @param int $user_id The id of the user who owns the subscription.
	 * @param string $subscription_key A subscription key of the form obtained by @see get_subscription_key( $order_id, $product_id )
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function process_subscription_payment( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::payment_complete()' );

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		$subscription->payment_complete();

		// Reset failed payment count & suspension count
		$subscription = array(); // we only want to reset the failed payments and susp count
		$subscription['failed_payments'] = $subscription['suspension_count'] = 0;
		self::update_users_subscriptions( $user_id, array( $subscription_key => $subscription ) );
	}

	/**
	 * Processes a failed payment on a subscription by recording the failed payment and cancelling the subscription if it exceeds the
	 * maximum number of failed payments allowed on the site.
	 *
	 * @param int $user_id The id of the user who owns the expiring subscription.
	 * @param string $subscription_key A subscription key of the form obtained by @see get_subscription_key( $order_id, $product_id )
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function process_subscription_payment_failure( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::payment_failed()' );

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		// Allow a short circuit for plugins & payment gateways to force max failed payments exceeded
		if ( apply_filters( 'woocommerce_subscriptions_max_failed_payments_exceeded', false, $user_id, $subscription_key ) ) {
			$new_status = 'cancelled';
		} else {
			$new_status = 'on-hold';
		}

		$subscription->payment_failed( $new_status );

		// Reset failed payment count & suspension count
		$subscription = array(); // we only want to reset the failed payments and susp count
		$subscription['failed_payments'] = $subscription['failed_payments'] + 1;
		self::update_users_subscriptions( $user_id, array( $subscription_key => $subscription ) );
	}

	/**
	 * This function should be called whenever a subscription payment is made on an order. This includes
	 * when the subscriber signs up and for a recurring payment.
	 *
	 * The function is a convenience wrapper for @see self::process_subscription_payment(), so if calling that
	 * function directly, do not call this function also.
	 *
	 * @param WC_Order|int $order The order or ID of the order for which subscription payments should be marked against.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function process_subscription_payments_on_order( $order, $product_id = '' ) {
		wcs_deprecated_function( __METHOD__, '2.6.0' );
		$subscriptions = wcs_get_subscriptions_for_order( $order );

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {
				$subscription->payment_complete();
			}

			do_action( 'processed_subscription_payments_for_order', $order );
		}
	}

	/**
	 * This function should be called whenever a subscription payment has failed on a parent order.
	 *
	 * The function is a convenience wrapper for @see self::process_subscription_payment_failure(), so if calling that
	 * function directly, do not call this function also.
	 *
	 * @param int|WC_Order $order The order or ID of the order for which subscription payments should be marked against.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function process_subscription_payment_failure_on_order( $order, $product_id = '' ) {
		wcs_deprecated_function( __METHOD__, '2.6.0' );
		$subscriptions = wcs_get_subscriptions_for_order( $order );

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {
				$subscription->payment_failed();
			}

			do_action( 'processed_subscription_payment_failure_for_order', $order );
		}
	}

	/**
	 * Activates all the subscriptions created by a given order.
	 *
	 * @param WC_Order|int $order The order or ID of the order for which subscriptions should be marked as activated.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function activate_subscriptions_for_order( $order ) {

		$subscriptions = wcs_get_subscriptions_for_order( $order );

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {

				try {
					$subscription->update_status( 'active' );
				} catch ( Exception $e ) {
					// translators: $1: order number, $2: error message
					$subscription->add_order_note( sprintf( __( 'Failed to activate subscription status for order #%1$s: %2$s', 'woocommerce-subscriptions' ), is_object( $order ) ? $order->get_order_number() : $order, $e->getMessage() ) );
				}
			}

			do_action( 'subscriptions_activated_for_order', $order );
		}
	}

	/**
	 * Suspends all the subscriptions on an order by changing their status to "on-hold".
	 *
	 * @param WC_Order|int $order The order or ID of the order for which subscriptions should be marked as activated.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function put_subscription_on_hold_for_order( $order ) {

		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {

				try {
					if ( ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) {
						$subscription->update_status( 'on-hold' );
					}
				} catch ( Exception $e ) {
					// translators: $1: order number, $2: error message
					$subscription->add_order_note( sprintf( __( 'Failed to update subscription status after order #%1$s was put on-hold: %2$s', 'woocommerce-subscriptions' ), is_object( $order ) ? $order->get_order_number() : $order, $e->getMessage() ) );
				}
			}

			do_action( 'subscriptions_put_on_hold_for_order', $order );
		}
	}

	/**
	 * Mark all subscriptions in an order as cancelled on the user's account.
	 *
	 * @param WC_Order|int $order The order or ID of the order for which subscriptions should be marked as cancelled.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function cancel_subscriptions_for_order( $order ) {

		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {

				try {
					if ( ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) {
						$subscription->cancel_order();
					}
				} catch ( Exception $e ) {
					// translators: $1: order number, $2: error message
					$subscription->add_order_note( sprintf( __( 'Failed to cancel subscription after order #%1$s was cancelled: %2$s', 'woocommerce-subscriptions' ), is_object( $order ) ? $order->get_order_number() : $order, $e->getMessage() ) );
				}
			}

			do_action( 'subscriptions_cancelled_for_order', $order );
		}
	}

	/**
	 * Marks all the subscriptions in an order as expired
	 *
	 * @param WC_Order|int $order The order or ID of the order for which subscriptions should be marked as expired.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function expire_subscriptions_for_order( $order ) {

		$subscriptions = wcs_get_subscriptions_for_order( $order );

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {

				try {
					if ( ! $subscription->has_status( wcs_get_subscription_ended_statuses() ) ) {
						$subscription->update_status( 'expired' );
					}
				} catch ( Exception $e ) {
					// translators: $1: order number, $2: error message
					$subscription->add_order_note( sprintf( __( 'Failed to set subscription as expired for order #%1$s: %2$s', 'woocommerce-subscriptions' ), is_object( $order ) ? $order->get_order_number() : $order, $e->getMessage() ) );
				}
			}

			do_action( 'subscriptions_expired_for_order', $order );
		}
	}

	/**
	 * Called when a sign up fails during the payment processing step.
	 *
	 * @param WC_Order|int $order The order or ID of the order for which subscriptions should be marked as failed.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function failed_subscription_sign_ups_for_order( $order ) {

		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );

		if ( ! empty( $subscriptions ) ) {

			if ( ! is_object( $order ) ) {
				$order = wc_get_order( $order );
			}

			// Set subscription status to failed and log failure
			if ( $order->has_status( 'failed' ) ) {
				$order->update_status( 'failed', __( 'Subscription sign up failed.', 'woocommerce-subscriptions' ) );
			}

			foreach ( $subscriptions as $subscription ) {

				try {
					$subscription->payment_failed();

				} catch ( Exception $e ) {
					// translators: $1: order number, $2: error message
					$subscription->add_order_note( sprintf( __( 'Failed to process failed payment on subscription for order #%1$s: %2$s', 'woocommerce-subscriptions' ), is_object( $order ) ? $order->get_order_number() : $order, $e->getMessage() ) );
				}
			}

			do_action( 'failed_subscription_sign_ups_for_order', $order );
		}
	}

	/**
	 * Uses the details of an order to create a pending subscription on the customers account
	 * for a subscription product, as specified with $product_id.
	 *
	 * @param int|WC_Order $order The order ID or WC_Order object to create the subscription from.
	 * @param int $product_id The ID of the subscription product on the order, if a variation, it must be the variation's ID.
	 * @param array $args An array of name => value pairs to customise the details of the subscription, including:
	 *     'start_date' A MySQL formatted date/time string on which the subscription should start, in UTC timezone
	 *     'expiry_date' A MySQL formatted date/time string on which the subscription should expire, in UTC timezone
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 */
	public static function create_pending_subscription_for_order( $order, $product_id, $args = array() ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_create_subscription()' );

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		if ( ! WC_Subscriptions_Product::is_subscription( $product_id ) ) {
			return;
		}

		$args = wp_parse_args( $args, array(
			'start_date'  => wcs_get_datetime_utc_string( wcs_get_objects_property( $order, 'date_created' ) ), // get_date_created() can return null, but if it does, we have an error anyway
			'expiry_date' => '',
		) );

		$billing_period   = WC_Subscriptions_Product::get_period( $product_id );
		$billing_interval = WC_Subscriptions_Product::get_interval( $product_id );

		// Support passing timestamps
		$args['start_date'] = is_numeric( $args['start_date'] ) ? gmdate( 'Y-m-d H:i:s', $args['start_date'] ) : $args['start_date'];

		$product = wc_get_product( $product_id );

		// Check if there is already a subscription for this product and order
		$subscriptions = wcs_get_subscriptions(
			array(
				'order_id'   => wcs_get_objects_property( $order, 'id' ),
				'product_id' => $product_id,
			)
		);

		if ( ! empty( $subscriptions ) ) {

			$subscription = array_pop( $subscriptions );

			// Make sure the subscription is pending and start date is set correctly
			wp_update_post( array(
				'ID'          => $subscription->get_id(),
				'post_status' => 'wc-' . apply_filters( 'woocommerce_default_subscription_status', 'pending' ),
				'post_date'   => get_date_from_gmt( $args['start_date'] ),
			) );

		} else {

			$subscription = wcs_create_subscription( array(
				'start_date'       => get_date_from_gmt( $args['start_date'] ),
				'order_id'         => wcs_get_objects_property( $order, 'id' ),
				'customer_id'      => $order->get_user_id(),
				'billing_period'   => $billing_period,
				'billing_interval' => $billing_interval,
				'customer_note'    => wcs_get_objects_property( $order, 'customer_note' ),
			) );

			if ( is_wp_error( $subscription ) ) {
				throw new Exception( __( 'Error: Unable to create subscription. Please try again.', 'woocommerce-subscriptions' ) );
			}

			$item_id = $subscription->add_product(
				$product,
				1,
				array(
					'variation' => ( method_exists( $product, 'get_variation_attributes' ) ) ? $product->get_variation_attributes() : array(),
					'totals'    => array(
						'subtotal'     => $product->get_price(),
						'subtotal_tax' => 0,
						'total'        => $product->get_price(),
						'tax'          => 0,
						'tax_data'     => array(
							'subtotal' => array(),
							'total'    => array(),
						),
					),
				)
			);

			if ( ! $item_id ) {
				throw new Exception( __( 'Error: Unable to add product to created subscription. Please try again.', 'woocommerce-subscriptions' ) );
			}
		}

		// Make sure some of the meta is copied form the order rather than the store's defaults
		if ( wcs_get_objects_property( $order, 'prices_include_tax' ) ) {
			$prices_include_tax = 'yes';
		} else {
			$prices_include_tax = 'no';
		}
		update_post_meta( $subscription->get_id(), '_order_currency', wcs_get_objects_property( $order, 'currency' ) );
		update_post_meta( $subscription->get_id(), '_prices_include_tax', $prices_include_tax );

		// Adding a new subscription so set the expiry date/time from the order date
		if ( ! empty( $args['expiry_date'] ) ) {
			if ( is_numeric( $args['expiry_date'] ) ) {
				$args['expiry_date'] = gmdate( 'Y-m-d H:i:s', $args['expiry_date'] );
			}

			$expiration = $args['expiry_date'];
		} else {
			$expiration = WC_Subscriptions_Product::get_expiration_date( $product_id, $args['start_date'] );
		}

		// Adding a new subscription so set the expiry date/time from the order date
		$trial_expiration = WC_Subscriptions_Product::get_trial_expiration_date( $product_id, $args['start_date'] );

		$dates_to_update = array();

		if ( $trial_expiration > 0 ) {
			$dates_to_update['trial_end'] = $trial_expiration;
		}

		if ( $expiration > 0 ) {
			$dates_to_update['end'] = $expiration;
		}

		if ( ! empty( $dates_to_update ) ) {
			$subscription->update_dates( $dates_to_update );
		}

		// Set the recurring totals on the subscription
		$subscription->set_total( 0, 'tax' );
		$subscription->set_total( $product->get_price(), 'total' );

		$subscription->add_order_note( __( 'Pending subscription created.', 'woocommerce-subscriptions' ) );

		do_action( 'pending_subscription_created_for_order', $order, $product_id );
	}

	/**
	 * Creates subscriptions against a users account with a status of pending when a user creates
	 * an order containing subscriptions.
	 *
	 * @param int|WC_Order $order The order ID or WC_Order object to create the subscription from.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function process_subscriptions_on_checkout( $order ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions_Checkout::process_checkout()' );

		if ( ! empty( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'woocommerce-process_checkout' ) ) {
			WC_Subscriptions_Checkout::process_checkout( $order, $_POST );
		}
	}

	/**
	 * Updates a user's subscriptions for each subscription product in the order.
	 *
	 * @param WC_Order $order The order to get subscriptions and user details from.
	 * @param string $status (optional) A status to change the subscriptions in an order to. Default is 'active'.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function update_users_subscriptions_for_order( $order, $status = 'pending' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions::update_status()' );

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		if ( 'suspend' === $status ) {
			$status = 'on-hold';
			_deprecated_argument( __METHOD__, '1.2', 'The "suspend" status value is deprecated. Use "on-hold"' );
		}

		foreach ( wcs_get_subscriptions_for_order( wcs_get_objects_property( $order, 'id' ), array( 'order_type' => 'parent' ) ) as $subscription_id => $subscription ) {

			switch ( $status ) {
				case 'cancelled':
					$subscription->cancel_order();
					break;
				case 'active':
				case 'expired':
				case 'on-hold':
					$subscription->update_status( $status );
					break;
				case 'failed':
					_deprecated_argument( __METHOD__, '2.0', 'The "failed" status value is deprecated.' );
					self::failed_subscription_signup( $order->get_user_id(), $subscription_id );
					break;
				case 'pending':
					_deprecated_argument( __METHOD__, '2.0', 'The "pending" status value is deprecated.' );
				default:
					self::create_pending_subscription_for_order( $order );
					break;
			}
		}

		do_action( 'updated_users_subscriptions_for_order', $order, $status );
	}

	/**
	 * Takes a user ID and array of subscription details and updates the users subscription details accordingly.
	 *
	 * @uses wp_parse_args To allow only part of a subscription's details to be updated, like status.
	 * @param int $user_id The ID of the user for whom subscription details should be updated
	 * @param array $subscriptions An array of arrays with a subscription key and corresponding 'detail' => 'value' pair. Can alter any of these details:
	 *        'start_date'          The date the subscription was activated
	 *        'expiry_date'         The date the subscription expires or expired, false if the subscription will never expire
	 *        'failed_payments'     The date the subscription's trial expires or expired, false if the subscription has no trial period
	 *        'end_date'            The date the subscription ended, false if the subscription has not yet ended
	 *        'status'              Subscription status can be: cancelled, active, expired or failed
	 *        'completed_payments'  An array of MySQL formatted dates for all payments that have been made on the subscription
	 *        'failed_payments'     An integer representing a count of failed payments
	 *        'suspension_count'    An integer representing a count of the number of times the subscription has been suspended for this billing period
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function update_users_subscriptions( $user_id, $subscriptions ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions API methods' );

		foreach ( $subscriptions as $subscription_key => $new_subscription_details ) {

			$subscription = wcs_get_subscription_from_key( $subscription_key );

			if ( isset( $new_subscription_details['status'] ) && 'deleted' == $new_subscription_details['status'] ) {
				wp_delete_post( $subscription->get_id() );
			} else {
				// There is no direct analog for this in WC_Subscription, so we need to call the deprecated method
				self::update_subscription( $subscription_key, $new_subscription_details );
			}
		}

		do_action( 'updated_users_subscriptions', $user_id, $subscriptions );

		return self::get_users_subscriptions( $user_id ); // We need to call this deprecated method to preserve the return value in the deprecated array structure
	}

	/**
	 * Takes a subscription key and array of subscription details and updates the users subscription details accordingly.
	 *
	 * @uses wp_parse_args To allow only part of a subscription's details to be updated, like status.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param array $new_subscription_details An array of arrays with a subscription key and corresponding 'detail' => 'value' pair. Can alter any of these details:
	 *        'start_date'          The date the subscription was activated
	 *        'expiry_date'         The date the subscription expires or expired, false if the subscription will never expire
	 *        'failed_payments'     The date the subscription's trial expires or expired, false if the subscription has no trial period
	 *        'end_date'            The date the subscription ended, false if the subscription has not yet ended
	 *        'status'              Subscription status can be: cancelled, active, expired or failed
	 *        'completed_payments'  An array of MySQL formatted dates for all payments that have been made on the subscription
	 *        'failed_payments'     An integer representing a count of failed payments
	 *        'suspension_count'    An integer representing a count of the number of times the subscription has been suspended for this billing period
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4
	 */
	public static function update_subscription( $subscription_key, $new_subscription_details ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions API methods' );

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		if ( isset( $new_subscription_details['status'] ) && 'deleted' == $new_subscription_details['status'] ) {

			wp_delete_post( $subscription->get_id() );

		} else {

			foreach ( $new_subscription_details as $meta_key => $meta_value ) {
				switch ( $meta_key ) {
					case 'start_date':
						$subscription->update_dates( array( 'date_created' => $meta_value ) );
						break;
					case 'trial_expiry_date':
						$subscription->update_dates( array( 'trial_end' => $meta_value ) );
						break;
					case 'expiry_date':
						$subscription->update_dates( array( 'end' => $meta_value ) );
						break;
					case 'failed_payments':
						_deprecated_argument( __METHOD__, '2.0', 'The "failed_payments" meta value is deprecated. Create a renewal order with "failed" status instead.' );
						break;
					case 'completed_payments':
						_deprecated_argument( __METHOD__, '2.0', 'The "completed_payments" meta value is deprecated. Create a renewal order with completed payment instead.' );
						break;
					case 'suspension_count':
						$subscription->set_suspension_count( $subscription->get_suspension_count() + 1 );
						break;
				}
			}
		}

		do_action( 'updated_users_subscription', $subscription_key, $new_subscription_details );

		return wcs_get_subscription_in_deprecated_structure( $subscription );
	}

	/**
	 * Takes a user ID and cancels any subscriptions that user has.
	 *
	 * @uses wp_parse_args To allow only part of a subscription's details to be updated, like status.
	 * @param int $user_id The ID of the user for whom subscription details should be updated
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.8
	 */
	public static function cancel_users_subscriptions( $user_id ) {

		$subscriptions = wcs_get_users_subscriptions( $user_id );

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->can_be_updated_to( 'cancelled' ) ) {
					$subscription->update_status( 'cancelled' );
				}
			}

			do_action( 'cancelled_users_subscriptions', $user_id );
		}
	}

	/**
	 * Takes a user ID and cancels any subscriptions that user has on any site in a WordPress network
	 *
	 * @uses wp_parse_args To allow only part of a subscription's details to be updated, like status.
	 * @param int $user_id The ID of the user for whom subscription details should be updated
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.8
	 */
	public static function cancel_users_subscriptions_for_network( $user_id ) {

		$sites = get_blogs_of_user( $user_id );

		if ( ! empty( $sites ) ) {

			foreach ( $sites as $site ) {

				switch_to_blog( $site->userblog_id );

				self::cancel_users_subscriptions( $user_id );

				restore_current_blog();
			}
		}

		do_action( 'cancelled_users_subscriptions_for_network', $user_id );
	}

	/**
	 * Clear all subscriptions for a given order.
	 *
	 * @param WC_Order $order The order for which subscriptions should be cleared.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function clear_users_subscriptions_from_order( $order ) {

		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription_id => $subscription ) {
			$subscription->delete( true );
		}

		do_action( 'cleared_users_subscriptions_from_order', $order );
	}

	/**
	 * Trash all subscriptions attached to an order when it's trashed.
	 *
	 * Also make sure all related scheduled actions are cancelled when deleting a subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 *
	 * @param int $order_id The order ID of the WC Subscription or WC Order being trashed
	 */
	public static function maybe_trash_subscription( $order_id ) {
		if ( 'shop_order' === WC_Data_Store::load( 'order' )->get_order_type( $order_id ) ) {

			// delete subscription
			foreach ( wcs_get_subscriptions_for_order( $order_id, [ 'order_type' => 'parent' ] ) as $subscription ) {
				$subscription->delete();
			}
		}
	}

	/**
	 * Untrash all subscriptions attached to an order when it's restored.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.17
	 *
	 * @param int $order_id The Order ID of the order being restored
	 */
	public static function maybe_untrash_subscription( $order_id ) {
		if ( 'shop_order' !== WC_Data_Store::load( 'order' )->get_order_type( $order_id ) ) {
			return;
		}

		$data_store      = WC_Data_Store::load( 'subscription' );
		$use_crud_method = method_exists( $data_store, 'has_callable' ) && $data_store->has_callable( 'untrash_order' );
		$subscriptions   = wcs_get_subscriptions_for_order(
			$order_id,
			[
				'order_type'          => 'parent',
				'subscription_status' => [ 'trash' ],
			]
		);

		foreach ( $subscriptions as $subscription ) {
			if ( $use_crud_method ) {
				$data_store->untrash_order( $subscription );
			} else {
				wp_untrash_post( $subscription->get_id() );
			}
		}
	}

	/**
	 * Delete related subscriptions when an order is deleted.
	 *
	 * @param int $order_id The post ID being deleted.
	 */
	public static function maybe_delete_subscription( $order_id ) {
		if ( 'shop_order' !== WC_Data_Store::load( 'order' )->get_order_type( $order_id ) ) {
			return;
		}

		/** @var WC_Subscription[] $subscriptions */
		$subscriptions = wcs_get_subscriptions_for_order(
			$order_id,
			[
				'order_type'          => 'parent',
				'subscription_status' => [ 'any', 'trash' ],
			]
		);

		foreach ( $subscriptions as $subscription ) {
			$subscription->delete( true );
		}
	}

	/**
	 * Make sure a subscription is cancelled before it is trashed or deleted
	 *
	 * @param int $id
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_cancel_subscription( $id ) {

		$subscription = wcs_get_subscription( $id );
		if ( ! $subscription ) {
			return;
		}

		if ( $subscription->get_type() !== 'shop_subscription' ) {
			return;
		}

		if ( $subscription->can_be_updated_to( 'cancelled' ) ) {

			$subscription->update_status( 'cancelled' );

		}
	}

	/**
	 * When an order is trashed, store the '_wp_trash_meta_status' meta value with a cancelled subscription status
	 * to prevent subscriptions being restored with an active status.
	 *
	 * When WordPress and WooCommerce set this meta value, they use the status of the order in memory.
	 * If that status is changed on the before trashed or before deleted hooks,
	 * as is the case with a subscription, which is cancelled before being trashed if it is active or on-hold,
	 * then the '_wp_trash_meta_status' value will be incorrectly set to its status before being trashed.
	 *
	 * This function fixes that by setting '_wp_trash_meta_status' to 'wc-cancelled' whenever its former status
	 * is something that can not be restored.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 *
	 * @param int $id
	 */
	public static function fix_trash_meta_status( $id ) {
		$subscription = wcs_get_subscription( $id );

		if ( ! $subscription ) {
			return;
		}

		if ( $subscription->get_type() !== 'shop_subscription' ) {
			return;
		}

		$data_store = $subscription->get_data_store();
		$meta_data  = $data_store->read_meta( $subscription );

		foreach ( $meta_data as $meta ) {
			if ( '_wp_trash_meta_status' === $meta->meta_key && ! in_array( $meta->meta_value, [ 'wc-pending', 'wc-expired', 'wc-cancelled' ], true ) ) {
				$new_meta = (object) [
					'id'    => $meta->meta_id,
					'key'   => $meta->meta_key,
					'value' => 'wc-cancelled',
				];
				$data_store->update_meta( $subscription, $new_meta );
				break;
			}
		}
	}

	/**
	 * Trigger action hook after a subscription has been trashed.
	 *
	 * @param int $id
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function trigger_subscription_trashed_hook( $id ) {

		if ( 'shop_subscription' === WC_Data_Store::load( 'subscription' )->get_order_type( $id ) ) {
			do_action( 'woocommerce_subscription_trashed', $id );
		}
	}

	/**
	 * Takes a user ID and trashes any subscriptions that user has.
	 *
	 * @param int $user_id The ID of the user whose subscriptions will be trashed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function trash_users_subscriptions( $user_id ) {
		$subscriptions = wcs_get_users_subscriptions( $user_id );
		$current_user  = is_user_logged_in() ? wp_get_current_user() : null;

		if ( ! empty( $subscriptions ) ) {

			foreach ( $subscriptions as $subscription ) {
				$subscription_number = $subscription->get_order_number();

				// Before deleting the subscription, add an order note to the related orders.
				foreach ( $subscription->get_related_orders( 'all', array( 'parent', 'renewal', 'switch' ) ) as $order ) {
					if ( $current_user ) {
						// Translators: 1: The subscription ID number. 2: The current user's username.
						$order->add_order_note( sprintf( __( 'The related subscription #%1$s has been deleted after the customer was deleted by %2$s.', 'woocommerce-subscriptions' ), $subscription_number, $current_user->display_name ) );
					} else {
						// Translators: Placeholder is the subscription ID number.
						$order->add_order_note( sprintf( __( 'The related subscription #%s has been deleted after the customer was deleted.', 'woocommerce-subscriptions' ), $subscription_number ) );
					}
				}

				$subscription->delete( true );
			}
		}
	}

	/**
	 * Takes a user ID and trashes any subscriptions that user has on any site in a WordPress network
	 *
	 * @param int $user_id The ID of the user whose subscriptions will be trashed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function trash_users_subscriptions_for_network( $user_id ) {

		$sites = get_blogs_of_user( $user_id );

		if ( ! empty( $sites ) ) {

			foreach ( $sites as $site ) {

				switch_to_blog( $site->userblog_id );

				self::trash_users_subscriptions( $user_id );

				restore_current_blog();
			}
		}
	}

	/**
	 * Trigger action hook after a subscription has been deleted.
	 *
	 * @param int $id
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function trigger_subscription_deleted_hook( $id ) {

		if ( 'shop_subscription' === WC_Data_Store::load( 'subscription' )->get_order_type( $id ) ) {
			do_action( 'woocommerce_subscription_deleted', $id );
		}
	}

	/**
	 * Checks if the current request is by a user to change the status of their subscription, and if it is
	 * validate the subscription cancellation request and maybe processes the cancellation.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_change_users_subscription() {
		_deprecated_function( __METHOD__, '2.0', 'WCS_User_Change_Status_Handler::maybe_change_users_subscription()' );
		WCS_User_Change_Status_Handler::maybe_change_users_subscription();
	}

	/**
	 * Check if a given subscription can be changed to a given a status.
	 *
	 * The function checks the subscription's current status and if the payment gateway used to purchase the
	 * subscription allows for the given status to be set via its API.
	 *
	 * @param string $new_status_or_meta The status or meta data you want to change th subscription to. Can be 'active', 'on-hold', 'cancelled', 'expired', 'trash', 'deleted', 'failed', 'new-payment-date' or some other value attached to the 'woocommerce_can_subscription_be_changed_to' filter.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function can_subscription_be_changed_to( $new_status_or_meta, $subscription_key, $user_id = '' ) {

		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::can_be_updated_to( $new_status_or_meta )' );

		if ( 'new-payment-date' == $new_status_or_meta ) {
			_deprecated_argument( __METHOD__, '2.0', 'The "new-payment-date" parameter value is deprecated. Use WC_Subscription::can_date_be_updated( "next_payment" ) method instead.' );
		} elseif ( 'suspended' == $new_status_or_meta ) {
			_deprecated_argument( __METHOD__, '2.0', 'The "suspended" parameter value is deprecated. Use "on-hold" instead.' );
			$new_status_or_meta = 'on-hold';
		}

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );

			switch ( $new_status_or_meta ) {
				case 'new-payment-date':
					$subscription_can_be_changed = $subscription->can_date_be_updated( 'next_payment' );
					break;
				case 'active':
				case 'on-hold':
				case 'cancelled':
				case 'expired':
				case 'trash':
				case 'deleted':
				case 'failed':
				default:
					$subscription_can_be_changed = $subscription->can_be_updated_to( $new_status_or_meta );
					break;
			}
		} catch ( Exception $e ) {
			$subscription_can_be_changed = false;
		}

		return $subscription_can_be_changed;
	}

	/*
	 * Subscription Getters & Property functions
	 */

	/**
	 * Return an associative array of a given subscriptions details (if it exists).
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param deprecated don't use
	 * @return array Subscription details
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 */
	public static function get_subscription( $subscription_key, $deprecated = null ) {

		if ( null != $deprecated ) {
			_deprecated_argument( __METHOD__, '1.4', 'Second parameter is deprecated' );
		}

		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscription( $subscription_id )' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );
			$subscription = wcs_get_subscription_in_deprecated_structure( $subscription );
		} catch ( Exception $e ) {
			$subscription = array();
		}

		return apply_filters( 'woocommerce_get_subscription', $subscription, $subscription_key, $deprecated );
	}

	/**
	 * Return an i18n'ified string for a given subscription status.
	 *
	 * @param string $status An subscription status of it's internal form.
	 * @return string A translated subscription status string for display.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2.3
	 */
	public static function get_status_to_display( $status, $subscription_key = '', $user_id = 0 ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscription_statuses()' );

		switch ( $status ) {
			case 'active':
				$status_string = _x( 'Active', 'Subscription status', 'woocommerce-subscriptions' );
				break;
			case 'cancelled':
				$status_string = _x( 'Cancelled', 'Subscription status', 'woocommerce-subscriptions' );
				break;
			case 'expired':
				$status_string = _x( 'Expired', 'Subscription status', 'woocommerce-subscriptions' );
				break;
			case 'pending':
				$status_string = _x( 'Pending', 'Subscription status', 'woocommerce-subscriptions' );
				break;
			case 'failed':
				$status_string = _x( 'Failed', 'Subscription status', 'woocommerce-subscriptions' );
				break;
			case 'on-hold':
			case 'suspend': // Backward compatibility
				$status_string = _x( 'On-hold', 'Subscription status', 'woocommerce-subscriptions' );
				break;
			default:
				$status_string = apply_filters( 'woocommerce_subscriptions_custom_status_string', ucfirst( $status ), $subscription_key, $user_id );
		}

		return apply_filters( 'woocommerce_subscriptions_status_string', $status_string, $status, $subscription_key, $user_id );
	}

	/**
	 * Return an i18n'ified associative array of all possible subscription periods.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscription_period_strings( $number = 1, $period = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscription_period_strings()' );
		return wcs_get_subscription_period_strings( $number, $period );
	}

	/**
	 * Return an i18n'ified associative array of all possible subscription periods.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscription_period_interval_strings( $interval = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscription_period_interval_strings()' );
		return wcs_get_subscription_period_interval_strings( $interval );
	}

	/**
	 * Returns an array of subscription lengths.
	 *
	 * PayPal Standard Allowable Ranges
	 * D – for days; allowable range is 1 to 90
	 * W – for weeks; allowable range is 1 to 52
	 * M – for months; allowable range is 1 to 24
	 * Y – for years; allowable range is 1 to 5
	 *
	 * @param subscription_period string (optional) One of day, week, month or year. If empty, all subscription ranges are returned.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscription_ranges( $subscription_period = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscription_ranges()' );
		return wcs_get_subscription_ranges( $subscription_period );
	}

	/**
	 * Returns an array of allowable trial periods.
	 *
	 * @see self::get_subscription_ranges()
	 * @param subscription_period string (optional) One of day, week, month or year. If empty, all subscription ranges are returned.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscription_trial_lengths( $subscription_period = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscription_trial_lengths( $subscription_period )' );
		return wcs_get_subscription_trial_lengths( $subscription_period );
	}

	/**
	 * Return an i18n'ified associative array of all possible subscription trial periods.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscription_trial_period_strings( $number = 1, $period = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscription_trial_period_strings( $number, $period )' );
		return wcs_get_subscription_trial_period_strings( $number, $period );
	}

	/**
	 * Return an i18n'ified associative array of all time periods allowed for subscriptions.
	 *
	 * @param string $form Either 'singular' for singular trial periods or 'plural'.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_available_time_periods( $form = 'singular' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_available_time_periods( $form )' );
		return wcs_get_available_time_periods( $form );
	}

	/**
	 * Returns the string key for a subscription purchased in an order specified by $order_id
	 *
	 * @param order_id int The ID of the order in which the subscription was purchased.
	 * @param product_id int The ID of the subscription product.
	 * @return string The key representing the given subscription.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function get_subscription_key( $order_id, $product_id = '' ) {

		_deprecated_function( __METHOD__, '2.0', 'wcs_get_old_subscription_key( WC_Subscription $subscription )' );

		// If we have a child renewal order, we need the parent order's ID
		if ( wcs_order_contains_renewal( $order_id ) ) {
			$order_id = WC_Subscriptions_Renewal_Order::get_parent_order_id( $order_id );
		}

		// Get the ID of the first order item in a subscription created by this order
		if ( empty( $product_id ) ) {

			$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );

			foreach ( $subscriptions as $subscription ) {
				$subscription_items = $subscription->get_items();
				if ( ! empty( $subscription_items ) ) {
					break;
				}
			}

			if ( ! empty( $subscription_items ) ) {
				$first_item = reset( $subscription_items );
				$product_id = WC_Subscriptions_Order::get_items_product_id( $first_item );
			} else {
				$product_id = '';
			}
		}

		$subscription_key = $order_id . '_' . $product_id;

		return apply_filters( 'woocommerce_subscription_key', $subscription_key, $order_id, $product_id );
	}

	/**
	 * Returns the number of failed payments for a given subscription.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @return int The number of outstanding failed payments on the subscription, if any.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscriptions_failed_payment_count( $subscription_key, $user_id = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_failed_payment_count()' );
		return apply_filters( 'woocommerce_subscription_failed_payment_count', wcs_get_subscription_from_key( $subscription_key )->get_failed_payment_count(), $user_id, $subscription_key );
	}

	/**
	 * Returns the number of completed payments for a given subscription (including the initial payment).
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @return int The number of outstanding failed payments on the subscription, if any.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.4
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscriptions_completed_payment_count( $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_payment_count()' );
		return apply_filters( 'woocommerce_subscription_completed_payment_count', wcs_get_subscription_from_key( $subscription_key )->get_payment_count(), $subscription_key );
	}

	/**
	 * Takes a subscription key and returns the date on which the subscription is scheduled to expire
	 * or 0 if it is cancelled, expired, or never going to expire.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param string $type (optional) The format for the Either 'mysql' or 'timestamp'.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_subscription_expiration_date( $subscription_key, $user_id = '', $type = 'mysql' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_date( "end" )' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		$expiration_date = ( 'mysql' == $type ) ? $subscription->get_date( 'end' ) : $subscription->get_time( 'end' );
		return apply_filters( 'woocommerce_subscription_expiration_date', $expiration_date, $subscription_key, $user_id );
	}

	/**
	 * Updates a subscription's expiration date as scheduled in WP-Cron and in the subscription details array.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id (optional) The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param (optional) $next_payment string | int The date and time the next payment is due, either as MySQL formatted datetime string or a Unix timestamp. If empty, @see self::calculate_subscription_expiration_date() will be called.
	 * @return mixed If the expiration does not get set, returns false, otherwise it will return a MySQL datetime formatted string for the new date when the subscription will expire
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2.4
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function set_expiration_date( $subscription_key, $user_id = '', $expiration_date = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::update_dates( array( "end" => $expiration_date ) )' );
		if ( is_int( $expiration_date ) ) {
			$expiration_date = gmdate( 'Y-m-d H:i:s', $expiration_date );
		}
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		return apply_filters( 'woocommerce_subscriptions_set_expiration_date', $subscription->update_dates( array( 'end' => $expiration_date ) ), $subscription->get_date( 'end' ), $subscription_key, $user_id );
	}

	/**
	 * A subscription now either has an end date or it doesn't, there is no way to calculate it based on the original subsciption
	 * product (because a WC_Subscription object can have more than one product and syncing length with expiration date was both
	 * cumbersome and error prone).
	 *
	 * Takes a subscription key and calculates the date on which the subscription is scheduled to expire
	 * or 0 if it will never expire.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param string $type (optional) The format for the Either 'mysql' or 'timestamp'.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function calculate_subscription_expiration_date( $subscription_key, $user_id = '', $type = 'mysql' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_date( "end" )' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		$expiration_date = ( 'mysql' == $type ) ? $subscription->get_date( 'end' ) : $subscription->get_time( 'end' );
		return apply_filters( 'woocommerce_subscription_calculated_expiration_date', $expiration_date, $subscription_key, $user_id );
	}

	/**
	 * Takes a subscription key and returns the date on which the next recurring payment is to be billed, if any.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param string $type (optional) The format for the Either 'mysql' or 'timestamp'.
	 * @return mixed If there is no future payment set, returns 0, otherwise it will return a date of the next payment in the form specified by $type
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_next_payment_date( $subscription_key, $user_id = '', $type = 'mysql' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_date( "next_payment" )' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		$next_payment = ( 'mysql' == $type ) ? $subscription->get_date( 'next_payment' ) : $subscription->get_time( 'next_payment' );
		return apply_filters( 'woocommerce_subscription_next_payment_date', $next_payment, $subscription_key, $user_id, $type );
	}

	/**
	 * Clears the payment schedule for a subscription and schedules a new date for the next payment.
	 *
	 * If updating the an existing next payment date (instead of setting a new date, you should use @see self::update_next_payment_date() instead
	 * as it will validate the next payment date and update the WP-Cron lock.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id (optional) The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param (optional) $next_payment string | int The date and time the next payment is due, either as MySQL formatted datetime string or a Unix timestamp. If empty, @see self::calculate_next_payment_date() will be called.
	 * @return mixed If there is no future payment set, returns 0, otherwise it will return a MySQL datetime formatted string for the date of the next payment
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function set_next_payment_date( $subscription_key, $user_id = '', $next_payment = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::update_dates( array( "next_payment" => $next_payment ) )' );

		if ( is_int( $next_payment ) ) {
			$next_payment = gmdate( 'Y-m-d H:i:s', $next_payment );
		}

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		return apply_filters( 'woocommerce_subscription_set_next_payment_date', $subscription->update_dates( array( 'next_payment' => $next_payment ) ), $subscription->get_date( 'next_payment' ), $subscription_key, $user_id );
	}

	/**
	 * Takes a subscription key and returns the date on which the next recurring payment is to be billed, if any.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param string $type (optional) The format for the Either 'mysql' or 'timestamp'.
	 * @return mixed If there is no future payment set, returns 0, otherwise it will return a date of the next payment in the form specified by $type
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_last_payment_date( $subscription_key, $user_id = '', $type = 'mysql' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_date( "last_payment" )' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		$last_payment_date = ( 'mysql' == $type ) ? $subscription->get_date( 'last_order_date_created' ) : $subscription->get_time( 'last_order_date_created' );
		return apply_filters( 'woocommerce_subscription_last_payment_date', $last_payment_date, $subscription_key, $user_id, $type );
	}

	/**
	 * Changes the transient used to safeguard against firing scheduled_subscription_payments during a payment period.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $lock_time The amount of time to lock for in seconds from now, the lock will be set 1 hour before this time
	 * @param int $user_id (optional) The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function update_wp_cron_lock( $subscription_key, $lock_time, $user_id = '' ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Clears the payment schedule for a subscription and sets a net date
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id (optional) The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param string $type (optional) The format for the Either 'mysql' or 'timestamp'.
	 * @return mixed If there is no future payment set, returns 0, otherwise it will return a date of the next payment of the type specified with $type
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function calculate_next_payment_date( $subscription_key, $user_id = '', $type = 'mysql', $from_date = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::calculate_date( "next_payment" )' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		$next_payment = $subscription->calculate_date( 'next_payment' );
		return ( 'mysql' == $type ) ? $next_payment : wcs_date_to_time( $next_payment );
	}

	/**
	 * Takes a subscription key and returns the date on which the trial for the subscription ended or is going to end, if any.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @return mixed If the subscription has no trial period, returns 0, otherwise it will return the date the trial period ends or ended in the form specified by $type
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_trial_expiration_date( $subscription_key, $user_id = '', $type = 'mysql' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_date( "trial_end" )' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		$trial_end_date = ( 'mysql' == $type ) ? $subscription->get_date( 'trial_end' ) : $subscription->get_time( 'trial_end' );
		return apply_filters( 'woocommerce_subscription_trial_expiration_date', $trial_end_date, $subscription_key, $user_id, $type );
	}

	/**
	 * Updates the trial expiration date as scheduled in WP-Cron and in the subscription details array.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id (optional) The ID of the user who owns the subscription. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param (optional) $next_payment string | int The date and time the next payment is due, either as MySQL formatted datetime string or a Unix timestamp. If empty, @see self::calculate_next_payment_date() will be called.
	 * @return mixed If the trial expiration does not get set, returns false, otherwise it will return a MySQL datetime formatted string for the new date when the trial will expire
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2.4
	 */
	public static function set_trial_expiration_date( $subscription_key, $user_id = '', $trial_expiration_date = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::update_dates( array( "trial_end" => $expiration_date ) )' );
		if ( is_int( $trial_expiration_date ) ) {
			$trial_expiration_date = gmdate( 'Y-m-d H:i:s', $trial_expiration_date );
		}
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		return apply_filters( 'woocommerce_subscriptions_set_trial_expiration_date', $subscription->update_dates( array( 'trial_end' => $trial_expiration_date ) ), $subscription->get_date( 'trial_end' ), $subscription_key, $user_id );
	}

	/**
	 * Takes a subscription key and calculates the date on which the subscription's trial should end
	 * or 0 if no trial is set.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @param string $type (optional) The format for the Either 'mysql' or 'timestamp'.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 */
	public static function calculate_trial_expiration_date( $subscription_key, $user_id = '', $type = 'mysql' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::calculate_date( "trial_end" )' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		$trial_end    = $subscription->calculate_date( 'trial_end' );
		$trial_end    = ( 'mysql' == $type ) ? $trial_end : wcs_date_to_time( $trial_end );
		return apply_filters( 'woocommerce_subscription_calculated_trial_expiration_date', $trial_end, $subscription_key, $user_id );
	}

	/**
	 * Takes a subscription key and returns the user who owns the subscription (based on the order ID in the subscription key).
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @return int The ID of the user who owns the subscriptions, or 0 if no user can be found with the subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function get_user_id_from_subscription_key( $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::get_user_id()' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		return $subscription->get_user_id();
	}

	/**
	 * Checks if a subscription requires manual payment because the payment gateway used to purchase the subscription
	 * did not support automatic payments at the time of the subscription sign up.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @return bool | null True if the subscription exists and requires manual payments, false if the subscription uses automatic payments, null if the subscription doesn't exist.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function requires_manual_renewal( $subscription_key, $user_id = '' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::is_manual()' );
		return wcs_get_subscription_from_key( $subscription_key )->is_manual();
	}

	/**
	 * Checks if a subscription has an unpaid renewal order.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The ID of the user who owns the subscriptions. Although this parameter is optional, if you have the User ID you should pass it to improve performance.
	 * @return bool True if the subscription has an unpaid renewal order, false if the subscription has no unpaid renewal orders.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function subscription_requires_payment( $subscription_key, $user_id ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::needs_payment()' );
		$subscription = wcs_get_subscription_from_key( $subscription_key );
		return apply_filters( 'woocommerce_subscription_requires_payment', $subscription->needs_payment(), wcs_get_subscription_in_deprecated_structure( $subscription ), $subscription_key, $user_id );
	}

	/*
	 * User API Functions
	 */

	/**
	 * Check if a user owns a subscription, as specified with $subscription_key.
	 *
	 * If no user is specified, the currently logged in user will be used.
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id (optional) int The ID of the user to check against. Defaults to the currently logged in user.
	 * @return bool True if the user has the subscription (or any subscription if no subscription specified), otherwise false.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function user_owns_subscription( $subscription_key, $user_id = 0 ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions::get_user_id()' );

		if ( 0 === $user_id || empty( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		if ( $subscription->get_user_id() == $user_id ) {
			$owns_subscription = true;
		} else {
			$owns_subscription = false;
		}

		return apply_filters( 'woocommerce_user_owns_subscription', $owns_subscription, $subscription_key, $user_id );
	}

	/**
	 * Check if a user has a subscription, optionally specified with $product_id.
	 *
	 * @param int $user_id (optional) The id of the user whose subscriptions you want. Defaults to the currently logged in user.
	 * @param product_id int (optional) The ID of a subscription product.
	 * @param status string (optional) A subscription status to check against. For example, for a $status of 'active', a subscriber must have an active subscription for a return value of true.
	 * @return bool True if the user has the subscription (or any subscription if no subscription specified), otherwise false.
	 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.5
	 */
	public static function user_has_subscription( $user_id = 0, $product_id = '', $status = 'any' ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_user_has_subscription()' );
		return wcs_user_has_subscription( $user_id, $product_id, $status );
	}

	/**
	 * Gets all the active and inactive subscriptions for all users.
	 *
	 * @return array An associative array containing all users with subscriptions and the details of their subscriptions: 'user_id' => $subscriptions
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function get_all_users_subscriptions() {
		_deprecated_function( __METHOD__, '2.0' );

		foreach ( get_users() as $user ) {
			foreach ( wcs_get_users_subscriptions( $user->ID ) as $subscription ) {
				$subscriptions_in_old_format[ wcs_get_old_subscription_key( $subscription ) ] = wcs_get_subscription_in_deprecated_structure( $subscription );
			}
		}

		return apply_filters( 'woocommerce_all_users_subscriptions', $subscriptions_in_old_format );
	}

	/**
	 * Gets all the active and inactive subscriptions for a user, as specified by $user_id
	 *
	 * @param int $user_id (optional) The id of the user whose subscriptions you want. Defaults to the currently logged in user.
	 * @param array $order_ids (optional) An array of post_ids of WC_Order objects as a way to get only subscriptions for certain orders. Defaults to null, which will return subscriptions for all orders.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function get_users_subscriptions( $user_id = 0, $order_ids = array() ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_users_subscriptions( $user_id )' );

		$subscriptions_in_old_format = array();

		foreach ( wcs_get_users_subscriptions( $user_id ) as $subscription ) {
			$subscriptions_in_old_format[ wcs_get_old_subscription_key( $subscription ) ] = wcs_get_subscription_in_deprecated_structure( $subscription );
		}

		return apply_filters( 'woocommerce_users_subscriptions', $subscriptions_in_old_format, $user_id );
	}

	/**
	 * Gets all the subscriptions for a user that have been trashed, as specified by $user_id
	 *
	 * @param int $user_id (optional) The id of the user whose subscriptions you want. Defaults to the currently logged in user.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function get_users_trashed_subscriptions( $user_id = '' ) {

		$subscriptions = self::get_users_subscriptions( $user_id );

		foreach ( $subscriptions as $key => $subscription ) {
			if ( 'trash' != $subscription['status'] ) {
				unset( $subscriptions[ $key ] );
			}
		}

		return apply_filters( 'woocommerce_users_trashed_subscriptions', $subscriptions, $user_id );
	}

	/**
	 * A convenience wrapper to assign the inactive subscriber role to a user.
	 *
	 * @param int $user_id The id of the user whose role should be changed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function make_user_inactive( $user_id ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_make_user_inactive()' );
		wcs_make_user_inactive( $user_id );
	}

	/**
	 * A convenience wrapper to assign the cancelled subscriber role to a user.
	 *
	 * Hooked to 'subscription_end_of_prepaid_term' hook.
	 *
	 * @param int $user_id The id of the user whose role should be changed
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_assign_user_cancelled_role( $user_id ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_maybe_make_user_inactive()' );
		wcs_maybe_make_user_inactive( $user_id );
	}

	/**
	 * A convenience wrapper for changing a users role.
	 *
	 * @param int $user_id The id of the user whose role should be changed
	 * @param string $role_name Either a WordPress role or one of the WCS keys: 'default_subscriber_role' or 'default_cancelled_role'
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function update_users_role( $user_id, $role_name ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_update_users_role()' );
		wcs_update_users_role( $user_id, $role_name );
	}

	/**
	 * Marks a customer as a paying customer when their subscription is activated.
	 *
	 * A wrapper for the @see woocommerce_paying_customer() function.
	 *
	 * @param int $order_id The id of the order for which customers should be pulled from and marked as paying.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function mark_paying_customer( $order ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		update_user_meta( $order->get_user_id(), 'paying_customer', 1 );
	}

	/**
	 * Unlike someone making a once-off payment, a subscriber can cease to be a paying customer. This function
	 * changes a user's status to non-paying.
	 *
	 * Deprecated as orders now take care of the customer's status as paying or not paying
	 *
	 * @param object $order The order for which a customer ID should be pulled from and marked as paying.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function mark_not_paying_customer( $order ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( ! is_object( $order ) ) {
			$order = new WC_Order( $order );
		}

		if ( $order->get_user_id() > 0 ) {
			update_user_meta( $order->get_user_id(), 'paying_customer', 0 );
		}
	}

	/**
	 * Return a link for subscribers to change the status of their subscription, as specified with $status parameter
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function get_users_change_status_link( $subscription_key, $status ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_users_change_status_link( $subscription_id, $status )' );

		if ( 'suspended' == $status ) {
			_deprecated_argument( __METHOD__, '2.0', 'The "suspended" parameter value is deprecated. Use "on-hold" instead.' );
			$status = 'on-hold';
		}

		$subscription_id = wcs_get_subscription_id_from_key( $subscription_key );

		$current_status = '';
		$subscription = wcs_get_subscription( $subscription_id );
		if ( $subscription instanceof WC_Subscription ) {
			$current_status = $subscription->get_status();
		}

		return apply_filters( 'woocommerce_subscriptions_users_action_link', wcs_get_users_change_status_link( $subscription_id, $status, $current_status ), $subscription_key, $status );
	}

	/**
	 * Change a subscription's next payment date.
	 *
	 * @param mixed $new_payment_date Either a MySQL formatted Date/time string or a Unix timestamp.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $timezone Either 'server' or 'user' to describe the timezone of the $new_payment_date.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function update_next_payment_date( $new_payment_date, $subscription_key, $user_id = '', $timezone = 'server' ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::update_dates( array( "next_payment" => $new_payment_date ) )' );

		$new_payment_timestamp = ( is_numeric( $new_payment_date ) ) ? $new_payment_date : wcs_date_to_time( $new_payment_date );

		// The date needs to be converted to GMT/UTC
		if ( 'server' != $timezone ) {
			$new_payment_timestamp = $new_payment_timestamp - ( get_option( 'gmt_offset' ) * 3600 );
		}

		$new_payment_date = gmdate( 'Y-m-d H:i:s', $new_payment_timestamp );

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		try {
			$subscription->update_dates( array( 'next_payment' => $new_payment_date ) );
			$response = $subscription->get_time( 'next_payment' );

		} catch ( Exception $e ) {
			$response = new WP_Error( 'invalid-date', $e->getMessage() );
		}

		return $response;
	}

	/*
	 * Helper Functions
	 */

	/**
	 * Because neither PHP nor WP include a real array merge function that works recursively.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function array_merge_recursive_for_real( $first_array, $second_array ) {

		$merged = $first_array;

		if ( is_array( $second_array ) ) {
			foreach ( $second_array as $key => $val ) {
				if ( is_array( $second_array[ $key ] ) ) {
					$merged[ $key ] = ( isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) ? self::array_merge_recursive_for_real( $merged[ $key ], $second_array[ $key ] ) : $second_array[ $key ];
				} else {
					$merged[ $key ] = $val;
				}
			}
		}

		return $merged;
	}

	/**
	 * Takes a total and calculates the recurring proportion of that based on $proportion and then fixes any rounding bugs to
	 * make sure the totals add up.
	 *
	 * Used mainly to calculate the recurring amount from a total which may also include a sign up fee.
	 *
	 * @param float $total The total amount
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @return float $proportion A proportion of the total (e.g. 0.5 is half of the total)
	 */
	public static function get_amount_from_proportion( $total, $proportion ) {

		$sign_up_fee_proprotion = 1 - $proportion;

		$sign_up_total    = round( $total * $sign_up_fee_proprotion, 2 );
		$recurring_amount = round( $total * $proportion, 2 );

		// Handle any rounding bugs
		if ( $sign_up_total + $recurring_amount != $total ) {
			$recurring_amount = $recurring_amount - ( $sign_up_total + $recurring_amount - $total );
		}

		return $recurring_amount;
	}

	/**
	 * Creates a subscription price string from an array of subscription details. For example, ""$5 / month for 12 months".
	 *
	 * @param array $subscription_details A set of name => value pairs for the subscription details to include in the string. Available keys:
	 *     'initial_amount': The upfront payment for the subscription, including sign up fees, as a string from the @see woocommerce_price(). Default empty string (no initial payment)
	 *     'initial_description': The word after the initial payment amount to describe the amount. Examples include "now" or "initial payment". Defaults to "up front".
	 *     'recurring_amount': The amount charged per period. Default 0 (no recurring payment).
	 *     'subscription_interval': How regularly the subscription payments are charged. Default 1, meaning each period e.g. per month.
	 *     'subscription_period': The temporal period of the subscription. Should be one of {day|week|month|year} as used by @see self::get_subscription_period_strings()
	 *     'subscription_length': The total number of periods the subscription should continue for. Default 0, meaning continue indefinitely.
	 *     'trial_length': The total number of periods the subscription trial period should continue for.  Default 0, meaning no trial period.
	 *     'trial_period': The temporal period for the subscription's trial period. Should be one of {day|week|month|year} as used by @see self::get_subscription_period_strings()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 * @return float $proportion A proportion of the total (e.g. 0.5 is half of the total)
	 */
	public static function get_subscription_price_string( $subscription_details ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_price_string()' );
		return wcs_price_string( $subscription_details );
	}


	/**
	 * Copy of the WordPress "touch_time" template function for use with a variety of different times
	 *
	 * @param array $args A set of name => value pairs to customise how the function operates. Available keys:
	 *     'date': (string) the date to display in the selector in MySQL format ('Y-m-d H:i:s'). Required.
	 *     'tab_index': (int) the tab index for the element. Optional. Default 0.
	 *     'multiple': (bool) whether there will be multiple instances of the element on the same page (determines whether to include an ID or not). Default false.
	 *     'echo': (bool) whether to return and print the element or simply return it. Default true.
	 *     'include_time': (bool) whether to include a specific time for the selector. Default true.
	 *     'include_year': (bool) whether to include a the year field. Default true.
	 *     'include_buttons': (bool) whether to include submit buttons on the selector. Default true.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function touch_time( $args = array() ) {
		global $wp_locale;

		$args = wp_parse_args(
			$args,
			array(
				'date'            => true,
				'tab_index'       => 0,
				'multiple'        => false,
				'echo'            => true,
				'include_time'    => true,
				'include_buttons' => true,
			)
		);

		if ( empty( $args['date'] ) ) {
			return;
		}

		$tab_index_attribute = ( (int) $args['tab_index'] > 0 ) ? ' tabindex="' . $args['tab_index'] . '"' : '';

		$month = mysql2date( 'n', $args['date'], false );

		$month_input = '<select ' . ( $args['multiple'] ? '' : 'id="edit-month" ' ) . 'name="edit-month"' . $tab_index_attribute . '>';
		for ( $i = 1; $i < 13; $i = $i + 1 ) {
			$month_numeral = zeroise( $i, 2 );
			$month_input .= '<option value="' . $month_numeral . '"';
			$month_input .= ( $i == $month ) ? ' selected="selected"' : '';
			// translators: 1$: month number (e.g. "01"), 2$: month abbreviation (e.g. "Jan")
			$month_input .= '>' . sprintf( _x( '%1$s-%2$s', 'used in a select box', 'woocommerce-subscriptions' ), $month_numeral, $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) ) ) . "</option>\n";
		}
		$month_input .= '</select>';

		$day_input  = '<input type="text" ' . ( $args['multiple'] ? '' : 'id="edit-day" ' ) . 'name="edit-day" value="' . mysql2date( 'd', $args['date'], false ) . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" />';
		$year_input = '<input type="text" ' . ( $args['multiple'] ? '' : 'id="edit-year" ' ) . 'name="edit-year" value="' . mysql2date( 'Y', $args['date'], false ) . '" size="4" maxlength="4"' . $tab_index_attribute . ' autocomplete="off" />';

		if ( $args['include_time'] ) {

			$hour_input   = '<input type="text" ' . ( $args['multiple'] ? '' : 'id="edit-hour" ' ) . 'name="edit-hour" value="' . mysql2date( 'H', $args['date'], false ) . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" />';
			$minute_input = '<input type="text" ' . ( $args['multiple'] ? '' : 'id="edit-minute" ' ) . 'name="edit-minute" value="' . mysql2date( 'i', $args['date'], false ) . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" />';

			// translators: all fields are full html nodes: 1$: month input, 2$: day input, 3$: year input, 4$: hour input, 5$: minute input. Change the order if you'd like
			$touch_time = sprintf( __( '%1$s%2$s, %3$s @ %4$s : %5$s', 'woocommerce-subscriptions' ), $month_input, $day_input, $year_input, $hour_input, $minute_input );

		} else {
			// translators: all fields are full html nodes: 1$: month input, 2$: day input, 3$: year input. Change the order if you'd like
			$touch_time = sprintf( __( '%1$s%2$s, %3$s', 'woocommerce-subscriptions' ), $month_input, $day_input, $year_input );
		}

		if ( $args['include_buttons'] ) {
			$touch_time .= '<p>';
			$touch_time .= '<a href="#edit_timestamp" class="save-timestamp hide-if-no-js button">' . __( 'Change', 'woocommerce-subscriptions' ) . '</a>';
			$touch_time .= '<a href="#edit_timestamp" class="cancel-timestamp hide-if-no-js">' . _x( 'Cancel', 'an action on a subscription', 'woocommerce-subscriptions' ) . '</a>';
			$touch_time .= '</p>';
		}

		$allowed_html = array(
			'select' => array(
				'id'       => array(),
				'name'     => array(),
				'tabindex' => array(),
			),
			'option' => array(
				'value'    => array(),
				'selected' => array(),
			),
			'input'  => array(
				'type'         => array(),
				'id'           => array(),
				'name'         => array(),
				'value'        => array(),
				'size'         => array(),
				'tabindex'     => array(),
				'maxlength'    => array(),
				'autocomplete' => array(),
			),
			'p'      => array(),
			'a'      => array(
				'href'  => array(),
				'title' => array(),
				'class' => array(),
			),
		);

		if ( $args['echo'] ) {
			echo wp_kses( $touch_time, $allowed_html );
		}

		return $touch_time;
	}

	/**
	 * If a gateway doesn't manage payment schedules, then we should suspend the subscription until it is paid (i.e. for manual payments
	 * or token gateways like Stripe). If the gateway does manage the scheduling, then we shouldn't suspend the subscription because a
	 * gateway may use batch processing on the time payments are charged and a subscription could end up being incorrectly suspended.
	 *
	 * @param int $user_id The id of the user whose subscription should be put on-hold.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_put_subscription_on_hold( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::update_status()' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );

			if ( $subscription->has_status( 'on-hold' ) ) {
				return false;
			}
		} catch ( Exception $e ) {
			return false;
		}

		// If the subscription is using manual payments, the gateway isn't active or it manages scheduled payments
		if ( 0 == $subscription->get_total() || $subscription->is_manual() || '' == $subscription->get_payment_method() || ! $subscription->payment_method_supports( 'gateway_scheduled_payments' ) ) {
			$subscription->update_status( 'on-hold', _x( 'Subscription renewal payment due:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' ) );
		}
	}

	/**
	 * Check if the subscription needs to use the failed payment process to repair its status after it incorrectly expired due to a date migration
	 * bug in upgrade process for 2.0.0 of Subscriptions (i.e. not 2.0.1 or newer). See WCS_Repair_2_0_2::maybe_repair_status() for more details.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.2
	 */
	public static function maybe_process_failed_renewal_for_repair( $subscription_id ) {

		if ( 'true' == get_post_meta( $subscription_id, '_wcs_repaired_2_0_2_needs_failed_payment', true ) ) {

			$subscription = wcs_get_subscription( $subscription_id );

			// Always put the subscription on hold in case something goes wrong while trying to process renewal
			$subscription->update_status( 'on-hold', _x( 'Subscription renewal payment due:', 'used in order note as reason for why subscription status changed', 'woocommerce-subscriptions' ) );

			// Create a renewal order to record the failed payment which can then be used by the customer to reactivate the subscription
			$renewal_order = wcs_create_renewal_order( $subscription );

			// Mark the payment as failed so the customer can login to fix up the failed payment
			$subscription->payment_failed();

			// Only force the failed payment once
			update_post_meta( $subscription_id, '_wcs_repaired_2_0_2_needs_failed_payment', 'false' );

			// We've already processed the renewal
			remove_action( 'woocommerce_scheduled_subscription_payment', __CLASS__ . '::prepare_renewal' );
			remove_action( 'woocommerce_scheduled_subscription_payment', 'WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment', 10 );
		}
	}

	/* Deprecated Functions */

	/**
	 * When a scheduled subscription payment hook is fired, automatically process the subscription payment
	 * if the amount is for $0 (and therefore, there is no payment to be processed by a gateway, and likely
	 * no gateway used on the initial order).
	 *
	 * If a subscription has a $0 recurring total and is not already active (after being actived by something else
	 * handling the 'scheduled_subscription_payment' with the default priority of 10), then this function will call
	 * @see self::process_subscription_payment() to reactive the subscription, generate a renewal order etc.
	 *
	 * @param int $user_id The id of the user who the subscription belongs to
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.3.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_process_subscription_payment( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::prepare_renewal( $subscription_id )' );
		self::prepare_renewal( wcs_get_subscription_id_from_key( $subscription_key ) );
	}

	/**
	 * Return a link for subscribers to change the status of their subscription, as specified with $status parameter
	 *
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function current_user_can_suspend_subscription( $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_can_user_put_subscription_on_hold( $subscription, $user )' );
		return wcs_can_user_put_subscription_on_hold( wcs_get_subscription_from_key( $subscription_key ) );
	}

	/**
	 * Return a multi-dimensional associative array of subscriptions with a certain value, grouped by user ID.
	 *
	 * A slow PHP based search routine which can't use the speed of MySQL because subscription details. If you
	 * know the key for the value you are search by, use @see self::get_subscriptions() for better performance.
	 *
	 * @param string $search_query The query to search the database for.
	 * @return array Subscription details
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function search_subscriptions( $search_query ) {
		_deprecated_function( __METHOD__, '2.0', 'wcs_get_subscriptions()' );
		global $wpdb;

		$subscriptions_to_search = self::get_all_users_subscriptions();

		$subscriptions_found = array();

		$search_terms = explode( ' ', $search_query );

		foreach ( $subscriptions_to_search as $user_id => $subscriptions ) {

			$user = get_user_by( 'id', $user_id );

			if ( false === $user || ! is_object( $user ) ) {
				continue;
			}

			$user = $user->data;

			foreach ( $search_terms as $search_term ) {

				// If the search query is found in the user's details, add all of their subscriptions, otherwise add only subscriptions with a matching item
				if ( false !== stripos( $user->user_nicename, $search_term ) || false !== stripos( $user->display_name, $search_term ) ) {
					$subscriptions_found[ $user_id ] = $subscriptions;
				} elseif ( false !== stripos( $user->user_login, $search_term ) || false !== stripos( $user->user_email, $search_term ) ) {
					$subscriptions_found[ $user_id ] = $subscriptions;
				} else {
					foreach ( $subscriptions as $subscription_key => $subscription ) {

						$product_title = get_the_title( $subscription['product_id'] );

						if ( in_array( $search_term, $subscription, true ) || false != preg_match( "/$search_term/i", $product_title ) ) {
							$subscriptions_found[ $user_id ][ $subscription_key ] = $subscription;
						}
					}
				}
			}
		}

		return apply_filters( 'woocommerce_search_subscriptions', $subscriptions_found, $search_query );
	}

	/**
	 * Marks a single subscription as active on a users account.
	 *
	 * @param int $user_id The id of the user whose subscription is to be activated.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function activate_subscription( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );

			if ( $subscription->has_status( 'active' ) ) {
				return false;
			}
		} catch ( Exception $e ) {
			return false;
		}

		if ( ! $subscription->has_status( 'pending' ) && ! $subscription->can_be_updated_to( 'active' ) ) {

			do_action( 'unable_to_activate_subscription', $user_id, $subscription_key );

			$activated_subscription = false;

		} else {

			$subscription->update_status( 'active' );

			do_action( 'activated_subscription', $user_id, $subscription_key );

			$activated_subscription = true;

		}

		return $activated_subscription;
	}

	/**
	 * Changes a single subscription from on-hold to active on a users account.
	 *
	 * @param int $user_id The id of the user whose subscription is to be activated.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function reactivate_subscription( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0' );

		if ( false !== self::activate_subscription( $user_id, $subscription_key ) ) {
			do_action( 'reactivated_subscription', $user_id, $subscription_key );
		}
	}

	/**
	 * Suspends a single subscription on a users account by placing it in the "on-hold" status.
	 *
	 * @param int $user_id The id of the user whose subscription should be put on-hold.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function put_subscription_on_hold( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscription::update_status( "on-hold" )' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );

			if ( $subscription->has_status( 'on-hold' ) ) {
				return false;
			}
		} catch ( Exception $e ) {
			return false;
		}

		if ( ! $subscription->can_be_updated_to( 'on-hold' ) ) {

			do_action( 'unable_to_put_subscription_on-hold', $user_id, $subscription_key );
			do_action( 'unable_to_suspend_subscription', $user_id, $subscription_key );

		} else {

			$subscription->update_status( 'on-hold' );

			do_action( 'subscription_put_on-hold', $user_id, $subscription_key );
			// Backward, backward compatibility
			do_action( 'suspended_subscription', $user_id, $subscription_key );
		}
	}

	/**
	 * Cancels a single subscription on a users account.
	 *
	 * @param int $user_id The id of the user whose subscription should be cancelled.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function cancel_subscription( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'WC_Subscriptions::cancel_order()' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );

			if ( $subscription->has_status( array( 'pending-cancel', 'cancelled' ) ) ) {
				return false;
			}
		} catch ( Exception $e ) {
			return false;
		}

		if ( ! $subscription->can_be_updated_to( 'cancelled' ) ) {

			do_action( 'unable_to_cancel_subscription', $user_id, $subscription_key );

		} else {

			$subscription->update_status( 'cancelled' );

			do_action( 'cancelled_subscription', $user_id, $subscription_key );

		}
	}

	/**
	 * Sets a single subscription on a users account to be 'on-hold' and keeps a record of the failed sign up on an order.
	 *
	 * @param int $user_id The id of the user whose subscription should be cancelled.
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function failed_subscription_signup( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );

			if ( $subscription->has_status( 'on-hold' ) ) {
				return false;
			}
		} catch ( Exception $e ) {
			return false;
		}

		// Place the subscription on-hold
		$subscription->update_status( 'on-hold' );

		// Log failure on order
		// translators: placeholder is subscription ID
		$subscription->get_parent()->add_order_note( sprintf( __( 'Failed sign-up for subscription %s.', 'woocommerce-subscriptions' ), $subscription->get_id() ) );

		do_action( 'subscription_sign_up_failed', $user_id, $subscription_key );
	}

	/**
	 * Trashes a single subscription on a users account.
	 *
	 * @param int $user_id The ID of the user who the subscription belongs to
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 */
	public static function trash_subscription( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'wp_trash_post()' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );

			if ( $subscription->has_status( 'trash' ) ) {
				return false;
			}
		} catch ( Exception $e ) {
			return false;
		}

		if ( ! $subscription->can_be_updated_to( 'cancelled' ) ) {

			do_action( 'unable_to_trash_subscription', $user_id, $subscription_key );

		} else {

			// Run all cancellation related functions on the subscription
			if ( ! $subscription->has_status( array( 'cancelled', 'expired', 'trash' ) ) ) {
				$subscription->update_status( 'cancelled' );
			}

			wp_trash_post( $subscription->get_id(), true );

			do_action( 'subscription_trashed', $user_id, $subscription_key );
		}
	}

	/**
	 * Permanently deletes a single subscription on a users account.
	 *
	 * @param int $user_id The ID of the user who the subscription belongs to
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 */
	public static function delete_subscription( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0', 'wp_delete_post()' );

		try {
			$subscription = wcs_get_subscription_from_key( $subscription_key );
		} catch ( Exception $e ) {
			return false;
		}

		if ( ! $subscription->can_be_updated_to( 'deleted' ) && ! $subscription->can_be_updated_to( 'cancelled' ) ) {

			do_action( 'unable_to_delete_subscription', $user_id, $subscription_key );

		} else {

			// Run all cancellation related functions on the subscription
			if ( ! $subscription->has_status( array( 'cancelled', 'expired', 'trash' ) ) ) {
				$subscription->update_status( 'cancelled' );
			}

			wp_delete_post( $subscription->get_id(), true );

			do_action( 'subscription_deleted', $user_id, $subscription_key, $subscription, $item );
		}
	}


	/**
	 * Processes an ajax request to change a subscription's next payment date.
	 *
	 * Deprecated because editing a subscription's next payment date is now done from the Edit Subscription screen.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function ajax_update_next_payment_date() {
		_deprecated_function( __METHOD__, '2.0', 'wp_delete_post()' );

		$response = array( 'status' => 'error' );

		if ( ! wp_verify_nonce( $_POST['wcs_nonce'], 'woocommerce-subscriptions' ) ) {

			$response['message'] = '<div class="error">' . __( 'Invalid security token, please reload the page and try again.', 'woocommerce-subscriptions' ) . '</div>';

		} elseif ( ! current_user_can( 'manage_woocommerce' ) ) {

			$response['message'] = '<div class="error">' . __( 'Only store managers can edit payment dates.', 'woocommerce-subscriptions' ) . '</div>';

		} elseif ( empty( $_POST['wcs_day'] ) || empty( $_POST['wcs_month'] ) || empty( $_POST['wcs_year'] ) ) {

			$response['message'] = '<div class="error">' . __( 'Please enter all date fields.', 'woocommerce-subscriptions' ) . '</div>';

		} else {

			$new_payment_date      = sprintf( '%s-%s-%s %s', (int) $_POST['wcs_year'], zeroise( (int) $_POST['wcs_month'], 2 ), zeroise( (int) $_POST['wcs_day'], 2 ), gmdate( 'H:i:s', current_time( 'timestamp' ) ) );
			$new_payment_timestamp = self::update_next_payment_date( $new_payment_date, $_POST['wcs_subscription_key'], self::get_user_id_from_subscription_key( $_POST['wcs_subscription_key'] ), 'user' );

			if ( is_wp_error( $new_payment_timestamp ) ) {

				$response['message'] = sprintf( '<div class="error">%s</div>', $new_payment_timestamp->get_error_message() );

			} else {

				$new_payment_timestamp_user_time = $new_payment_timestamp + ( get_option( 'gmt_offset' ) * 3600 ); // The timestamp is returned in server time

				$time_diff = $new_payment_timestamp - gmdate( 'U' );

				if ( $time_diff > 0 && $time_diff < 7 * 24 * 60 * 60 ) {
					// translators: placeholder is human time diff (e.g. "3 weeks")
					$date_to_display = sprintf( __( 'In %s', 'woocommerce-subscriptions' ), human_time_diff( gmdate( 'U' ), $new_payment_timestamp ) );
				} else {
					$date_to_display = date_i18n( wc_date_format(), $new_payment_timestamp_user_time );
				}

				$response['status']        = 'success';
				$response['message']       = '<div class="updated">' . __( 'Date Changed', 'woocommerce-subscriptions' ) . '</div>';
				$response['dateToDisplay'] = $date_to_display;
				$response['timestamp']     = $new_payment_timestamp_user_time;

			}
		}

		echo wcs_json_encode( $response );

		exit();
	}

	/**
	 * WP-Cron occasionally gets itself into an infinite loop on scheduled events, this function is
	 * designed to create a non-cron related safeguard against payments getting caught up in such a loop.
	 *
	 * When the scheduled subscription payment hook is fired by WP-Cron, this function is attached before
	 * any other to make sure the hook hasn't already fired for this period.
	 *
	 * A transient is used to keep a record of any payment for each period. The transient expiration is
	 * set to one billing period in the future, minus 1 hour, if there is a future payment due, otherwise,
	 * it is set to 23 hours in the future. This later option provides a safeguard in case a subscription's
	 * data is corrupted and the @see self::calculate_next_payment_date() is returning an
	 * invalid value. As no subscription can charge a payment more than once per day, the 23 hours is a safe
	 * throttle period for billing that still removes the possibility of a catastrophic failure (payments
	 * firing every few seconds until a credit card is maxed out).
	 *
	 * The transient keys use both the user ID and subscription key to ensure it is unique per subscription
	 * (even on multisite)
	 *
	 * @param int $user_id The id of the user who purchased the subscription
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1.2
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function safeguard_scheduled_payments( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * When a subscription payment hook is fired, reschedule the hook to run again on the
	 * time/date of the next payment (if any).
	 *
	 * WP-Cron's built in wp_schedule_event() function can not be used because the recurrence
	 * must be a timestamp, which creates inaccurate schedules for month and year billing periods.
	 *
	 * @param int $user_id The id of the user who the subscription belongs to
	 * @param string $subscription_key A subscription key of the form created by @see self::get_subscription_key()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.1.5
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_reschedule_subscription_payment( $user_id, $subscription_key ) {
		_deprecated_function( __METHOD__, '2.0' );

		$subscription = wcs_get_subscription_from_key( $subscription_key );

		// Don't reschedule for cancelled, suspended or expired subscriptions
		if ( ! $subscription->has_status( 'expired', 'cancelled', 'on-hold' ) ) {

			// Reschedule the 'scheduled_subscription_payment' hook
			if ( $subscription->can_date_be_updated( 'next_payment' ) ) {
				$subscription->update_dates( array( 'next_payment' => $subscription->calculate_date( 'next_payment' ) ) );
				do_action( 'rescheduled_subscription_payment', $user_id, $subscription_key );
			}
		}
	}

	/**
	 * Fires when the trial period for a subscription has completed.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v1.0
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function subscription_trial_end( $subscription_id, $deprecated = null ) {
		_deprecated_function( __METHOD__, '2.0' );
	}
}
