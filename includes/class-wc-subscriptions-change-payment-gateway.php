<?php
/**
 * Make it possible for customers to change the payment gateway used for an existing subscription.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WC_Subscriptions_Change_Payment_Gateway
 * @category	Class
 * @author		Brent Shepherd
 * @since		1.4
 */
class WC_Subscriptions_Change_Payment_Gateway {

	public static $is_request_to_change_payment = false;

	private static $woocommerce_messages = array();

	private static $woocommerce_errors = array();

	private static $original_order_dates = array();

	/**
	 * Bootstraps the class and hooks required actions & filters.
	 *
	 * @since 1.4
	 */
	public static function init() {

		// Maybe allow for a recurring payment method to be changed
		add_action( 'plugins_loaded', __CLASS__ . '::set_change_payment_method_flag' );

		// Keep a record of any messages or errors that should be displayed
		add_action( 'before_woocommerce_pay', __CLASS__ . '::store_pay_shortcode_mesages', 100 );

		// Hijack the default pay shortcode
		add_action( 'after_woocommerce_pay', __CLASS__ . '::maybe_replace_pay_shortcode', 100 );

		// Maybe allow for a recurring payment method to be changed
		add_filter( 'wcs_view_subscription_actions', __CLASS__ . '::change_payment_method_button', 10, 2 );

		// Maybe allow for a recurring payment method to be changed
		add_action( 'wp_loaded', __CLASS__ . '::change_payment_method_via_pay_shortcode', 20 );

		// Filter the available payment gateways to only show those which support acting as the new payment method
		add_filter( 'woocommerce_available_payment_gateways', __CLASS__ . '::get_available_payment_gateways' );

		// If we're changing the payment method, we want to make sure a number of totals return $0 (to prevent payments being processed now)
		add_filter( 'woocommerce_subscriptions_total_initial_payment', __CLASS__ . '::maybe_zero_total', 11, 2 );
		add_filter( 'woocommerce_subscriptions_sign_up_fee', __CLASS__ . '::maybe_zero_total', 11, 2 );
		add_filter( 'woocommerce_order_amount_total', __CLASS__ . '::maybe_zero_total', 11, 2 );

		// Redirect to My Account page after changing payment method
		add_filter( 'woocommerce_get_return_url', __CLASS__ . '::get_return_url', 11 );

		// Update the recurring payment method when a customer has completed the payment for a renewal payment which previously failed
		add_action( 'woocommerce_subscriptions_paid_for_failed_renewal_order', __CLASS__ . '::change_failing_payment_method', 10, 2 );

		// Add a 'new-payment-method' handler to the WC_Subscription::can_be_updated_to() function
		add_filter( 'woocommerce_can_subscription_be_updated_to_new-payment-method', __CLASS__ . '::can_subscription_be_updated_to_new_payment_method', 10, 2 );

		// Change the "Pay for Order" page title to "Change Payment Method"
		add_filter( 'the_title', __CLASS__ . '::change_payment_method_page_title', 100 );

		// Maybe filter subscriptions_needs_payment to return false when processing change-payment-gateway requests
		add_filter( 'woocommerce_subscription_needs_payment', __CLASS__ . '::maybe_override_needs_payment', 10, 1 );
	}

	/**
	 * Set a flag to indicate that the current request is for changing payment. Better than requiring other extensions
	 * to check the $_GET global as it allows for the flag to be overridden.
	 *
	 * @since 1.4
	 */
	public static function set_change_payment_method_flag() {
		if ( isset( $_GET['change_payment_method'] ) ) {
			self::$is_request_to_change_payment = true;
		}
	}

	/**
	 * Store any messages or errors added by other plugins, particularly important for those occasions when the new payment
	 * method caused and error or failure.
	 *
	 * @since 1.4
	 */
	public static function store_pay_shortcode_mesages() {

		if ( wc_notice_count( 'notice' ) > 0 ) {
			self::$woocommerce_messages  = wc_get_notices( 'success' );
			self::$woocommerce_messages += wc_get_notices( 'notice' );
		}

		if ( wc_notice_count( 'error' ) > 0 ) {
			self::$woocommerce_errors = wc_get_notices( 'error' );
		}
	}

	/**
	 * If requesting a payment method change, replace the woocommerce_pay_shortcode() with a change payment form.
	 *
	 * @since 1.4
	 */
	public static function maybe_replace_pay_shortcode() {
		global $wp;
		$valid_request = false;

		// if the request to pay for the order belongs to a subscription but there's no GET params for changing payment method, show receipt page.
		if ( ! self::$is_request_to_change_payment && isset( $wp->query_vars['order-pay'] ) && wcs_is_subscription( absint( $wp->query_vars['order-pay'] ) ) ) {

			$valid_request = true;

			ob_clean();

			do_action( 'before_woocommerce_pay' );

			$subscription_key = isset( $_GET['key'] ) ? wc_clean( $_GET['key'] ) : '';
			$subscription     = wcs_get_subscription( absint( $wp->query_vars['order-pay'] ) );

			if ( $subscription->id == absint( $wp->query_vars['order-pay'] ) && $subscription->order_key == $subscription_key ) {

				?>
			<div class="woocommerce">
				<ul class="order_details">
					<li class="order">
						<?php
						// translators: placeholder is the subscription order number wrapped in <strong> tags
						echo wp_kses( sprintf( esc_html__( 'Subscription Number: %s', 'woocommerce-subscriptions' ), '<strong>' . esc_html( $subscription->get_order_number() ) . '</strong>' ), array( 'strong' => true ) );
						?>
					</li>
					<li class="date">
						<?php
						// translators: placeholder is the subscription's next payment date (either human readable or normal date) wrapped in <strong> tags
						echo wp_kses( sprintf( esc_html__( 'Next Payment Date: %s', 'woocommerce-subscriptions' ), '<strong>' . esc_html( $subscription->get_date_to_display( 'next_payment' ) ) . '</strong>' ), array( 'strong' => true ) );
						?>
					</li>
					<li class="total">
						<?php
						// translators: placeholder is the formatted total to be paid for the subscription wrapped in <strong> tags
						echo wp_kses_post( sprintf( esc_html__( 'Total: %s', 'woocommerce-subscriptions' ), '<strong>' . $subscription->get_formatted_order_total() . '</strong>' ) );
						?>
					</li>
					<?php if ( $subscription->payment_method_title ) : ?>
						<li class="method">
							<?php
							// translators: placeholder is the display name of the payment method
							echo wp_kses( sprintf( esc_html__( 'Payment Method: %s', 'woocommerce-subscriptions' ), '<strong>' . esc_html( $subscription->get_payment_method_to_display() ) . '</strong>' ), array( 'strong' => true ) );
							?>
						</li>
					<?php endif; ?>
				</ul>

				<?php do_action( 'woocommerce_receipt_' . $subscription->payment_method, $subscription->id ); ?>

				<div class="clear"></div>
				<?php

			} else {
				wc_add_notice( __( 'Sorry, this subscription change payment method request is invalid and cannot be processed.', 'woocommerce-subscriptions' ), 'error' );
			}

			wc_print_notices();

		} elseif ( ! self::$is_request_to_change_payment ) {
			return;

		} else {

			ob_clean();

			do_action( 'before_woocommerce_pay' );

			echo '<div class="woocommerce">';

			if ( ! empty( self::$woocommerce_errors ) ) {
				foreach ( self::$woocommerce_errors as $error ) {
					WC_Subscriptions::add_notice( $error, 'error' );
				}
			}

			if ( ! empty( self::$woocommerce_messages ) ) {
				foreach ( self::$woocommerce_messages as $message ) {
					WC_Subscriptions::add_notice( $message, 'success' );
				}
			}

			$subscription = wcs_get_subscription( absint( $_GET['change_payment_method'] ) );

			if ( wp_verify_nonce( $_GET['_wpnonce'], __FILE__ ) === false ) {

				WC_Subscriptions::add_notice( __( 'There was an error with your request. Please try again.', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( empty( $subscription ) ) {

				WC_Subscriptions::add_notice( __( 'Invalid Subscription.', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( ! current_user_can( 'edit_shop_subscription_payment_method', $subscription->id ) ) {

				WC_Subscriptions::add_notice( __( 'That doesn\'t appear to be one of your subscriptions.', 'woocommerce-subscriptions' ), 'error' );

			} elseif ( ! $subscription->can_be_updated_to( 'new-payment-method' ) ) {

				WC_Subscriptions::add_notice( __( 'The payment method can not be changed for that subscription.', 'woocommerce-subscriptions' ), 'error' );

			} else {

				if ( $subscription->get_time( 'next_payment' ) > 0 ) {
					// translators: placeholder is next payment's date
					$next_payment_string = sprintf( __( ' Next payment is due %s.', 'woocommerce-subscriptions' ), $subscription->get_date_to_display( 'next_payment' ) );
				} else {
					$next_payment_string = '';
				}

				// translators: placeholder is either empty or "Next payment is due..."
				WC_Subscriptions::add_notice( sprintf( __( 'Choose a new payment method.%s', 'woocommerce-subscriptions' ), $next_payment_string ), 'notice' );
				WC_Subscriptions::print_notices();

				if ( $subscription->order_key == $_GET['key'] ) {

					// Set customer location to order location
					if ( $subscription->billing_country ) {
						WC()->customer->set_country( $subscription->billing_country );
					}
					if ( $subscription->billing_state ) {
						WC()->customer->set_state( $subscription->billing_state );
					}
					if ( $subscription->billing_postcode ) {
						WC()->customer->set_postcode( $subscription->billing_postcode );
					}

					wc_get_template( 'checkout/form-change-payment-method.php', array( 'subscription' => $subscription ), '', plugin_dir_path( WC_Subscriptions::$plugin_file ) . 'templates/' );

					$valid_request = true;

				} else {

					WC_Subscriptions::add_notice( __( 'Invalid order.', 'woocommerce-subscriptions' ), 'error' );

				}
			}
		}

		if ( false === $valid_request ) {
			WC_Subscriptions::print_notices();
		}
	}

	/**
	 * Add a "Change Payment Method" button to the "My Subscriptions" table.
	 *
	 * @param array $all_actions The $subscription_key => $actions array with all actions that will be displayed for a subscription on the "My Subscriptions" table
	 * @param array $subscriptions All of a given users subscriptions that will be displayed on the "My Subscriptions" table
	 * @since 1.4
	 */
	public static function change_payment_method_button( $actions, $subscription ) {

		if ( $subscription->can_be_updated_to( 'new-payment-method' ) ) {

			$actions['change_payment_method'] = array(
				'url'  => wp_nonce_url( add_query_arg( array( 'change_payment_method' => $subscription->id ), $subscription->get_checkout_payment_url() ), __FILE__ ),
				'name' => _x( 'Change Payment', 'label on button, imperative', 'woocommerce-subscriptions' ),
			);

		}

		return $actions;
	}

	/**
	 * Process the change payment form.
	 *
	 * Based on the @see woocommerce_pay_action() function.
	 *
	 * @access public
	 * @return void
	 * @since 1.4
	 */
	public static function change_payment_method_via_pay_shortcode() {

		if ( isset( $_POST['_wcsnonce'] ) && wp_verify_nonce( $_POST['_wcsnonce'], 'wcs_change_payment_method' ) ) {

			$subscription = wcs_get_subscription( absint( $_POST['woocommerce_change_payment'] ) );

			do_action( 'woocommerce_subscription_change_payment_method_via_pay_shortcode', $subscription );

			ob_start();

			if ( $subscription->order_key == $_GET['key'] ) {

				// Set customer location to order location
				if ( $subscription->billing_country ) {
					WC()->customer->set_country( $subscription->billing_country );
				}
				if ( $subscription->billing_state ) {
					WC()->customer->set_state( $subscription->billing_state );
				}
				if ( $subscription->billing_postcode ) {
					WC()->customer->set_postcode( $subscription->billing_postcode );
				}
				if ( $subscription->billing_city ) {
					WC()->customer->set_city( $subscription->billing_city );
				}

				// Update payment method
				$new_payment_method = wc_clean( $_POST['payment_method'] );

				// Allow some payment gateways which can't process the payment immediately, like PayPal, to do it later after the payment/sign-up is confirmed
				if ( apply_filters( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', true, $new_payment_method, $subscription ) ) {
					self::update_payment_method( $subscription, $new_payment_method );
				}

				$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

				// Validate
				$available_gateways[ $new_payment_method ]->validate_fields();

				// Process payment for the new method (with a $0 order total)
				if ( wc_notice_count( 'error' ) == 0 ) {

					$result = $available_gateways[ $new_payment_method ]->process_payment( $subscription->id );

					if ( 'success' == $result['result'] && wc_get_page_permalink( 'myaccount' ) == $result['redirect'] ) {
						$result['redirect'] = $subscription->get_view_order_url();
					}

					$result = apply_filters( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode', $result, $subscription );

					// Redirect to success/confirmation/payment page
					if ( 'success' == $result['result'] ) {
						WC_Subscriptions::add_notice( __( 'Payment method updated.', 'woocommerce-subscriptions' ), 'success' );
						wp_redirect( $result['redirect'] );
						exit;
					}
				}
			}
		}
	}

	/**
	 * Update the recurring payment method on a subscription order.
	 *
	 * @param array $available_gateways The payment gateways which are currently being allowed.
	 * @since 1.4
	 */
	public static function update_payment_method( $subscription, $new_payment_method ) {

		$old_payment_method       = $subscription->payment_method;
		$old_payment_method_title = $subscription->payment_method_title;
		$available_gateways       = WC()->payment_gateways->get_available_payment_gateways(); // Also inits all payment gateways to make sure that hooks are attached correctly

		do_action( 'woocommerce_subscriptions_pre_update_payment_method', $subscription, $new_payment_method, $old_payment_method );

		// Make sure the subscription is cancelled with the current gateway
		WC_Subscriptions_Payment_Gateways::trigger_gateway_status_updated_hook( $subscription, 'cancelled' );

		// Update meta
		update_post_meta( $subscription->id, '_old_payment_method', $old_payment_method );
		update_post_meta( $subscription->id, '_payment_method', $new_payment_method );

		if ( isset( $available_gateways[ $new_payment_method ] ) ) {
			$new_payment_method_title = $available_gateways[ $new_payment_method ]->get_title();
		} else {
			$new_payment_method_title = '';
		}

		update_post_meta( $subscription->id, '_old_payment_method_title', $old_payment_method_title );
		update_post_meta( $subscription->id, '_payment_method_title', $new_payment_method_title );

		if ( empty( $old_payment_method_title )  ) {
			$old_payment_method_title = $old_payment_method;
		}

		if ( empty( $new_payment_method_title )  ) {
			$new_payment_method_title = $new_payment_method;
		}

		// Log change on order
		$subscription->add_order_note( sprintf( _x( 'Payment method changed from "%1$s" to "%2$s" by the subscriber from their account page.', '%1$s: old payment title, %2$s: new payment title', 'woocommerce-subscriptions' ), $old_payment_method_title, $new_payment_method_title ) );

		do_action( 'woocommerce_subscription_payment_method_updated', $subscription, $new_payment_method, $old_payment_method );
		do_action( 'woocommerce_subscription_payment_method_updated_to_' . $new_payment_method, $subscription, $old_payment_method );
		do_action( 'woocommerce_subscription_payment_method_updated_from_' . $old_payment_method, $subscription, $new_payment_method );
	}

	/**
	 * Only display gateways which support changing payment method when paying for a failed renewal order or
	 * when requesting to change the payment method.
	 *
	 * @param array $available_gateways The payment gateways which are currently being allowed.
	 * @since 1.4
	 */
	public static function get_available_payment_gateways( $available_gateways ) {

		if ( isset( $_GET['change_payment_method'] ) || wcs_cart_contains_failed_renewal_order_payment() ) {
			foreach ( $available_gateways as $gateway_id => $gateway ) {
				if ( true !== $gateway->supports( 'subscription_payment_method_change_customer' ) ) {
					unset( $available_gateways[ $gateway_id ] );
				}
			}
		}

		return $available_gateways;
	}

	/**
	 * Make sure certain totals are set to 0 when the request is to change the payment method without charging anything.
	 *
	 * @since 1.4
	 */
	public static function maybe_zero_total( $total, $subscription ) {
		global $wp;

		if ( ! empty( $_POST['_wcsnonce'] ) && wp_verify_nonce( $_POST['_wcsnonce'], 'wcs_change_payment_method' ) && isset( $_POST['woocommerce_change_payment'] ) && $subscription->order_key == $_GET['key'] && $subscription->id == absint( $_POST['woocommerce_change_payment'] ) ) {
			$total = 0;
		} elseif ( ! self::$is_request_to_change_payment && isset( $wp->query_vars['order-pay'] ) && wcs_is_subscription( absint( $wp->query_vars['order-pay'] ) ) ) {
			// if the request to pay for the order belongs to a subscription but there's no GET params for changing payment method, the receipt page is being used to collect credit card details so we still need to $0 the total
			$total = 0;
		}

		return $total;
	}

	/**
	 * Redirect back to the "My Account" page instead of the "Thank You" page after changing the payment method.
	 *
	 * @since 1.4
	 */
	public static function get_return_url( $return_url ) {

		if ( ! empty( $_POST['_wcsnonce'] ) && wp_verify_nonce( $_POST['_wcsnonce'], 'wcs_change_payment_method' ) && isset( $_POST['woocommerce_change_payment'] ) ) {
			$return_url = get_permalink( wc_get_page_id( 'myaccount' ) );
		}

		return $return_url;
	}

	/**
	 * Update the recurring payment method for a subscription after a customer has paid for a failed renewal order
	 * (which usually failed because of an issue with the existing payment, like an expired card or token).
	 *
	 * Also trigger a hook for payment gateways to update any meta on the original order for a subscription.
	 *
	 * @param WC_Order $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @param WC_Order $original_order The original order in which the subscription was purchased.
	 * @since 1.4
	 */
	public static function change_failing_payment_method( $renewal_order, $subscription ) {

		if ( ! $subscription->is_manual() ) {

			if ( ! empty( $_POST['_wcsnonce'] ) && wp_verify_nonce( $_POST['_wcsnonce'], 'wcs_change_payment_method' ) && isset( $_POST['payment_method'] ) ) {
				$new_payment_method = wc_clean( $_POST['payment_method'] );
			} else {
				$new_payment_method = $renewal_order->payment_method;
			}

			self::update_payment_method( $subscription, $new_payment_method );

			do_action( 'woocommerce_subscription_failing_payment_method_updated', $subscription, $renewal_order );
			do_action( 'woocommerce_subscription_failing_payment_method_updated_' . $new_payment_method, $subscription, $renewal_order );
		}
	}

	/**
	 * Add a 'new-payment-method' handler to the @see WC_Subscription::can_be_updated_to() function
	 * to determine whether the recurring payment method on a subscription can be changed.
	 *
	 * For the recurring payment method to be changeable, the subscription must be active, have future (automatic) payments
	 * and use a payment gateway which allows the subscription to be cancelled.
	 *
	 * @param bool $subscription_can_be_changed Flag of whether the subscription can be changed to
	 * @param string $new_status_or_meta The status or meta data you want to change th subscription to. Can be 'active', 'on-hold', 'cancelled', 'expired', 'trash', 'deleted', 'failed', 'new-payment-date' or some other value attached to the 'woocommerce_can_subscription_be_changed_to' filter.
	 * @param object $args Set of values used in @see WC_Subscriptions_Manager::can_subscription_be_changed_to() for determining if a subscription can be changes, include:
	 *			'subscription_key'           string A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 *			'subscription'               array Subscription of the form returned by @see WC_Subscriptions_Manager::get_subscription()
	 *			'user_id'                    int The ID of the subscriber.
	 *			'order'                      WC_Order The order which recorded the successful payment (to make up for the failed automatic payment).
	 *			'payment_gateway'            WC_Payment_Gateway The subscription's recurring payment gateway
	 *			'order_uses_manual_payments' bool A boolean flag indicating whether the subscription requires manual renewal payment.
	 * @since 1.4
	 */
	public static function can_subscription_be_updated_to_new_payment_method( $subscription_can_be_changed, $subscription ) {

		if ( WC_Subscriptions_Payment_Gateways::one_gateway_supports( 'subscription_payment_method_change_customer' ) && $subscription->get_time( 'next_payment' ) > 0 && ! $subscription->is_manual() && $subscription->payment_method_supports( 'subscription_cancellation' ) && $subscription->has_status( 'active' ) ) {
			$subscription_can_be_changed = true;
		} else {
			$subscription_can_be_changed = false;
		}

		return $subscription_can_be_changed;
	}

	/**
	 * Replace a page title with the endpoint title
	 *
	 * @param  string $title
	 * @return string
	 * @since 2.0
	 */
	public static function change_payment_method_page_title( $title ) {

		if ( is_main_query() && in_the_loop() && is_page() && is_checkout_pay_page() && self::$is_request_to_change_payment ) {
			$title = _x( 'Change Payment Method', 'the page title of the change payment method form', 'woocommerce-subscriptions' );
		}

		return $title;
	}

	/**
	 * When processing a change_payment_method request on a subscription that has a failed or pending renewal,
	 * we don't want the `$order->needs_payment()` check inside WC_Shortcode_Checkout::order_pay() to pass.
	 * This is causing `$gateway->payment_fields()` to be called multiple times.
	 *
	 * @param bool $needs_payment
	 * @param WC_Subscription $subscription
	 * @return bool
	 * @since 2.0.7
	 */
	public static function maybe_override_needs_payment( $needs_payment ) {

		if ( $needs_payment && self::$is_request_to_change_payment ) {
			$needs_payment = false;
		}

		return $needs_payment;
	}

	/** Deprecated Functions **/

	/**
	 * Update the recurring payment method on a subscription order.
	 *
	 * @param array $available_gateways The payment gateways which are currently being allowed.
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function update_recurring_payment_method( $subscription_key, $order, $new_payment_method ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::update_payment_method()' );
		self::update_payment_method( wcs_get_subscription_from_key( $subscription_key ), $new_payment_method );
	}

	/**
	 * Keep a record of an order's dates if we're marking it as completed during a request to change the payment method.
	 *
	 * Deprecated as we now operate on a WC_Subscription object instead of the parent order, so we don't need to hack around date changes.
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function store_original_order_dates( $new_order_status, $subscription_id ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Restore an order's dates if we marked it as completed during a request to change the payment method.
	 *
	 * Deprecated as we now operate on a WC_Subscription object instead of the parent order, so we don't need to hack around date changes.
	 *
	 * @since 1.4
	 * @deprecated 2.0
	 */
	public static function restore_original_order_dates( $order_id ) {
		_deprecated_function( __METHOD__, '2.0' );
	}

	/**
	 * Add a 'new-payment-method' handler to the @see WC_Subscription::can_be_updated_to() function
	 * to determine whether the recurring payment method on a subscription can be changed.
	 *
	 * For the recurring payment method to be changeable, the subscription must be active, have future (automatic) payments
	 * and use a payment gateway which allows the subscription to be cancelled.
	 *
	 * @param bool $subscription_can_be_changed Flag of whether the subscription can be changed to
	 * @param string $new_status_or_meta The status or meta data you want to change th subscription to. Can be 'active', 'on-hold', 'cancelled', 'expired', 'trash', 'deleted', 'failed', 'new-payment-date' or some other value attached to the 'woocommerce_can_subscription_be_changed_to' filter.
	 * @param object $args Set of values used in @see WC_Subscriptions_Manager::can_subscription_be_changed_to() for determining if a subscription can be changes, include:
	 *			'subscription_key'           string A subscription key of the form created by @see WC_Subscriptions_Manager::get_subscription_key()
	 *			'subscription'               array Subscription of the form returned by @see WC_Subscriptions_Manager::get_subscription()
	 *			'user_id'                    int The ID of the subscriber.
	 *			'order'                      WC_Order The order which recorded the successful payment (to make up for the failed automatic payment).
	 *			'payment_gateway'            WC_Payment_Gateway The subscription's recurring payment gateway
	 *			'order_uses_manual_payments' bool A boolean flag indicating whether the subscription requires manual renewal payment.
	 * @since 1.4
	 */
	public static function can_subscription_be_changed_to( $subscription_can_be_changed, $new_status_or_meta, $args ) {
		_deprecated_function( __METHOD__, '2.0', __CLASS__ . '::can_subscription_be_updated_to_new_payment_method()' );

		if ( 'new-payment-method' === $new_status_or_meta ) {
			$subscription_can_be_changed = wcs_get_subscription_from_key( $args->subscription_key )->can_be_updated_to( 'new-payment-method' );
		}

		return $subscription_can_be_changed;
	}
}
WC_Subscriptions_Change_Payment_Gateway::init();
