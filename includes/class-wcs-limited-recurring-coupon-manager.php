<?php
/**
 * A class for managing the limited payment recurring coupon feature.
 *
 * @package WooCommerce Subscriptions
 * @since   4.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCS_Limited_Recurring_Coupon_Manager {

	/**
	 * The meta key used for the number of renewals.
	 *
	 * @var string
	 */
	private static $coupons_renewals = '_wcs_number_payments';

	/**
	 * Initialize the class hooks and callbacks.
	 */
	public static function init() {
		// Add custom coupon fields.
		add_action( 'woocommerce_coupon_options', array( __CLASS__, 'add_coupon_fields' ), 10 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save_coupon_fields' ), 10 );

		// Filter the available payment gateways.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'gateways_subscription_amount_changes' ), 20 );

		// Check coupons when a subscription is renewed.
		add_action( 'woocommerce_subscription_payment_complete', array( __CLASS__, 'check_coupon_usages' ) );

		// Add info to the Coupons list table.
		add_action( 'manage_shop_coupon_posts_custom_column', array( __CLASS__, 'add_limit_to_list_table' ), 20, 2 );

		// Must be hooked later to honour early callbacks choosing to bypass the coupon removal.
		add_filter( 'wcs_bypass_coupon_removal', array( __CLASS__, 'maybe_remove_coupons_from_recurring_cart' ), 1000, 5 );
	}

	/**
	 * Adds custom fields to the coupon data form.
	 *
	 * @since 4.0.0
	 */
	public static function add_coupon_fields( $id ) {
		$coupon = new WC_Coupon( $id );
		woocommerce_wp_text_input( array(
			'id'          => 'wcs_number_payments',
			'label'       => __( 'Active for x payments', 'woocommerce-subscriptions' ),
			'placeholder' => __( 'Unlimited payments', 'woocommerce-subscriptions' ),
			'description' => __( 'Coupon will be limited to the given number of payments. It will then be automatically removed from the subscription. "Payments" also includes the initial subscription payment.', 'woocommerce-subscriptions' ),
			'desc_tip'    => true,
			'data_type'   => 'decimal',
			'value'       => $coupon->get_meta( self::$coupons_renewals ),
		) );
	}

	/**
	 * Saves our custom coupon fields.
	 *
	 * @since 4.0.0
	 * @param int $id The coupon's ID.
	 */
	public static function save_coupon_fields( $id ) {
		// Check the nonce (again).
		if ( empty( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		$coupon = new WC_Coupon( $id );
		$coupon->add_meta_data( self::$coupons_renewals, wc_clean( $_POST['wcs_number_payments'] ), true );
		$coupon->save();
	}

	/**
	 * Get the number of renewals for a limited coupon.
	 *
	 * @since 4.0.0
	 * @param string|WC_Coupon $coupon The coupon or coupon code.
	 * @return false|int False for non-recurring coupons, or the limit number for recurring coupons.
	 *                   A value of 0 is for unlimited usage.
	 */
	public static function get_coupon_limit( $coupon ) {
		// If we have a coupon code, attempt to get the coupon object.
		if ( is_string( $coupon ) ) {
			$coupon = new WC_Coupon( $coupon );
		}

		if ( ! $coupon instanceof WC_Coupon ) {
			return false;
		}

		$coupon_type = $coupon->get_discount_type();

		// If we have a virtual coupon, attempt to get the original coupon.
		if ( WC_Subscriptions_Coupon::is_renewal_cart_coupon( $coupon_type ) ) {
			$coupon      = WC_Subscriptions_Coupon::map_virtual_coupon( $coupon->get_code() );
			$coupon_type = $coupon->get_discount_type();
		}

		if ( ! WC_Subscriptions_Coupon::is_recurring_coupon( $coupon_type ) ) {
			return false;
		}

		return intval( $coupon->get_meta( self::$coupons_renewals ) );
	}

	/**
	 * Determines if a given coupon is limited to a certain number of renewals.
	 *
	 * @since 4.0.0
	 *
	 * @param string $code The coupon code.
	 * @return bool
	 */
	public static function coupon_is_limited( $code ) {
		return (bool) self::get_coupon_limit( $code );
	}

	/**
	 * Determines whether the cart contains a recurring coupon with set number of renewals.
	 *
	 * @since 4.0.0
	 * @return bool Whether the cart contains a limited recurring coupon.
	 */
	public static function cart_contains_limited_recurring_coupon() {
		$has_coupon      = false;
		$applied_coupons = isset( WC()->cart->applied_coupons ) ? WC()->cart->applied_coupons : array();

		foreach ( $applied_coupons as $code ) {
			if ( self::coupon_is_limited( $code ) ) {
				$has_coupon = true;
				break;
			}
		}

		return $has_coupon;
	}

	/**
	 * Determines if a given order has a limited use coupon.
	 *
	 * @since 4.0.0
	 * @param WC_Order|WC_Subscription $order
	 *
	 * @return bool Whether the order contains a limited recurring coupon.
	 */
	public static function order_has_limited_recurring_coupon( $order ) {
		$has_coupon = false;

		foreach ( wcs_get_used_coupon_codes( $order ) as $code ) {
			if ( self::coupon_is_limited( $code ) ) {
				$has_coupon = true;
				break;
			}
		}

		return $has_coupon;
	}

	/**
	 * Limits payment gateways to those that support changing subscription amounts.
	 *
	 * @since 4.0.0
	 * @param WC_Payment_Gateway[] $gateways The current available gateways.
	 * @return WC_Payment_Gateway[]
	 */
	private static function limit_gateways_subscription_amount_changes( $gateways ) {
		foreach ( $gateways as $index => $gateway ) {
			if ( $gateway->supports( 'subscriptions' ) && ! $gateway->supports( 'subscription_amount_changes' ) ) {
				unset( $gateways[ $index ] );
			}
		}

		return $gateways;
	}

	/**
	 * Determines how many subscription renewals the coupon has been applied to and removes coupons which have reached their expiry.
	 *
	 * @since 4.0.0
	 * @param WC_Subscription $subscription The current subscription.
	 */
	public static function check_coupon_usages( $subscription ) {
		// If there aren't any coupons, there's nothing to do.
		$coupons = wcs_get_used_coupon_codes( $subscription );
		if ( empty( $coupons ) ) {
			return;
		}

		// Set up the coupons we're looking for, and an initial count.
		$limited_coupons = array();
		foreach ( $coupons as $code ) {
			if ( self::coupon_is_limited( $code ) ) {
				$limited_coupons[ $code ] = array(
					'code'  => $code,
					'count' => 0,
				);
			}
		}

		// Don't continue if we have no limited use coupons.
		if ( empty( $limited_coupons ) ) {
			return;
		}

		// Get all related orders, and count the number of uses for each coupon.
		$related = $subscription->get_related_orders( 'all' );

		/** @var WC_Order $order */
		foreach ( $related as $id => $order ) {
			// Unpaid orders don't count as usages.
			if ( $order->needs_payment() ) {
				continue;
			}

			/*
			 * If the order has been refunded, treat coupon as unused. We'll consider the order to be
			 * refunded when there is a non-null refund amount, and the order total equals the refund amount.
			 *
			 * The use of == instead of === is deliberate, to account for differences in amount formatting.
			 */
			$refunded = $order->get_total_refunded();
			$total    = $order->get_total();
			if ( $refunded && $total == $refunded ) {
				continue;
			}

			// If there was nothing discounted, then consider the coupon unused.
			if ( ! $order->get_discount_total() ) {
				continue;
			}

			// Check for limited coupons, and add them to the count if the provide a discount.
			$used_coupons = $order->get_items( 'coupon' );

			/** @var WC_Order_Item_Coupon $used_coupon */
			foreach ( $used_coupons as $used_coupon ) {
				if ( isset( $limited_coupons[ $used_coupon->get_code() ] ) && $used_coupon->get_discount() ) {
					$limited_coupons[ $used_coupon->get_code() ]['count']++;
				}
			}
		}

		// Check each coupon to see if it needs to be removed.
		foreach ( $limited_coupons as $limited_coupon ) {
			if ( self::get_coupon_limit( $limited_coupon['code'] ) <= $limited_coupon['count'] ) {
				$subscription->remove_coupon( $limited_coupon['code'] );
				$subscription->add_order_note( sprintf(
					/* translators: %1$s is the coupon code, %2$d is the number of payment usages */
					_n(
						'Limited use coupon "%1$s" removed from subscription. It has been used %2$d time.',
						'Limited use coupon "%1$s" removed from subscription. It has been used %2$d times.',
						$limited_coupon['count'],
						'woocommerce-subscriptions'
					),
					$limited_coupon['code'],
					number_format_i18n( $limited_coupon['count'] )
				) );
			}
		}
	}

	/**
	 * Add our limited coupon data to the Coupon list table.
	 *
	 * @since 4.0.0
	 *
	 * @param string $column_name The name of the current column in the table.
	 * @param int    $id          The coupon ID.
	 */
	public static function add_limit_to_list_table( $column_name, $id ) {
		global $the_coupon;

		if ( 'usage' !== $column_name ) {
			return;
		}

		// Confirm the global coupon object is the one we're looking for, otherwise fetch it.
		$coupon = empty( $the_coupon ) || $the_coupon->get_id() !== $id ? new WC_Coupon( $id ) : $the_coupon;
		$limit  = self::get_coupon_limit( $coupon );

		if ( false === $limit ) {
			return;
		}

		echo '<br>';
		if ( $limit ) {
			echo esc_html( sprintf(
				/* translators: %d refers to the number of payments the coupon can be used for. */
				_n( 'Active for %d payment', 'Active for %d payments', $limit, 'woocommerce-subscriptions' ),
				number_format_i18n( $limit )
			) );
		} else {
			esc_html_e( 'Active for unlimited payments', 'woocommerce-subscriptions' );
		}
	}

	/**
	 * Determines if a given recurring cart contains a limited use coupon which when applied to a subscription will reach its usage limit within the subscription's length.
	 *
	 * @since 4.0.0
	 *
	 * @param WC_Cart $recurring_cart The recurring cart object.
	 * @return bool
	 */
	public static function recurring_cart_contains_expiring_coupon( $recurring_cart ) {
		$limited_recurring_coupons = array();

		if ( isset( $recurring_cart->applied_coupons ) ) {
			$limited_recurring_coupons = array_filter( $recurring_cart->applied_coupons, array( __CLASS__, 'coupon_is_limited' ) );
		}

		// Bail early if there are no limited coupons applied to the recurring cart or if there is no discount provided.
		// @phpstan-ignore property.notFound
		if ( empty( $limited_recurring_coupons ) || ! $recurring_cart->discount_cart ) {
			return false;
		}

		$has_expiring_coupon   = false;
		$subscription_length   = wcs_cart_pluck( $recurring_cart, 'subscription_length' );
		$subscription_payments = (int) $subscription_length / (int) wcs_cart_pluck( $recurring_cart, 'subscription_period_interval' );

		// Limited recurring coupons will always expire at some point on subscriptions with no length.
		if ( empty( $subscription_length ) ) {
			$has_expiring_coupon = true;
		} else {
			foreach ( $limited_recurring_coupons as $code ) {
				if ( WC_Subscriptions_Coupon::get_coupon_limit( $code ) < $subscription_payments ) {
					$has_expiring_coupon = true;
					break;
				}
			}
		}

		return $has_expiring_coupon;
	}

	/**
	 * Filters the available gateways when there is a recurring coupon.
	 *
	 * @since 4.0.0
	 *
	 * @param WC_Payment_Gateway[] $gateways The available payment gateways.
	 * @return WC_Payment_Gateway[] The filtered payment gateways.
	 */
	public static function gateways_subscription_amount_changes( $gateways ) {

		// If there are already no gateways or we're on the order-pay screen, bail early.
		if ( empty( $gateways ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return $gateways;
		}

		// See if this is a request to change payment for an existing subscription.
		$change_payment     = isset( $_GET['change_payment_method'] ) ? wc_clean( $_GET['change_payment_method'] ) : 0;
		$has_limited_coupon = false;

		if ( $change_payment && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) ) ) {
			$subscription       = wcs_get_subscription( $change_payment );
			$has_limited_coupon = self::order_has_limited_recurring_coupon( $subscription );
		}

		// If the cart doesn't have a limited coupon, and a change payment doesn't have a limited coupon, bail early.
		if ( ! self::cart_contains_limited_recurring_coupon() && ! $has_limited_coupon ) {
			return $gateways;
		}

		// If we got this far, we should limit the gateways as needed.
		$gateways = self::limit_gateways_subscription_amount_changes( $gateways );

		// If there are no gateways now, it's because of the coupon. Filter the 'no available payment methods' message.
		if ( empty( $gateways ) ) {
			add_filter( 'woocommerce_no_available_payment_methods_message', array( __CLASS__, 'no_available_payment_methods_message' ), 20 );
		}

		return $gateways;
	}

	/**
	 * Filter the message for when no payment gateways are available.
	 *
	 * @since 4.0.0
	 *
	 * @return string The filtered message indicating there are no payment methods available.
	 */
	public static function no_available_payment_methods_message() {
		return __( 'Sorry, it seems there are no available payment methods which support the recurring coupon you are using. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce-subscriptions' );
	}

	/**
	 * Removes limited coupons from the recurring cart if the coupons limit is reached in the initial cart.
	 *
	 * @since 4.0.0
	 *
	 * @param bool      $bypass_default_checks Whether to bypass WC Subscriptions default conditions for removing a coupon.
	 * @param WC_Coupon $coupon                The coupon to check.
	 * @param string    $coupon_type           The coupon's type.
	 * @param string    $calculation_type      The WC Subscriptions cart calculation mode. Can be 'recurring_total' or 'none'. @see WC_Subscriptions_Cart::get_calculation_type()
	 *
	 * @return bool Whether to bypass WC Subscriptions default conditions for removing a coupon.
	 */
	public static function maybe_remove_coupons_from_recurring_cart( $bypass_default_checks, $coupon, $coupon_type, $calculation_type, $cart ) {

		// Bypass this check if a third-party has already opted to bypass default conditions.
		if ( $bypass_default_checks ) {
			return $bypass_default_checks;
		}

		if ( 'recurring_total' !== $calculation_type ) {
			return $bypass_default_checks;
		}

		if ( ! WC_Subscriptions_Coupon::is_recurring_coupon( $coupon_type ) ) {
			return $bypass_default_checks;
		}

		// Special handling for a single payment coupon.
		if ( 1 === self::get_coupon_limit( $coupon->get_code() ) && 0 < WC()->cart->get_coupon_discount_amount( $coupon->get_code() ) ) {
			$cart->remove_coupon( $coupon->get_code() );
		}

		return $bypass_default_checks;
	}
}
