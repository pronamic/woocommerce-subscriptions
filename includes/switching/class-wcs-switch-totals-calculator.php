<?php
/**
 * WooCommerce Subscriptions Switch Totals Calculator.
 *
 * A class to assist in calculating the upgrade cost, and next payment dates for switch items in the cart.
 *
 * @package WooCommerce Subscriptions
 * @author  Prospress
 * @since   2.6.0
 */
class WCS_Switch_Totals_Calculator {

	/**
	 * Reference to the cart object.
	 *
	 * @var WC_Cart
	 */
	protected $cart = null;

	/**
	 * Whether to prorate the recurring price for all product types ('yes', 'yes-upgrade') or only for virtual products ('virtual', 'virtual-upgrade').
	 *
	 * @var string
	 */
	protected $apportion_recurring_price = '';

	/**
	 * Whether to charge the full sign-up fee, a prorated sign-up fee or no sign-up fee.
	 *
	 * @var string Can be 'full', 'yes', or 'no'.
	 */
	protected $apportion_sign_up_fee = '';

	/**
	 * Whether to take into account the number of payments completed when determining how many payments the subscriber needs to make for the new subscription.
	 *
	 * @var string Can be 'virtual' (for virtual products only), 'yes', or 'no'
	 */
	protected $apportion_length = '';

	/**
	 * Whether store prices include tax.
	 *
	 * @var bool
	 */
	protected $prices_include_tax;

	/**
	 * A cache of the cart item switch objects after they have had their totals calculated.
	 *
	 * @var WCS_Switch_Cart_Item[]
	 */
	protected $calculated_switch_items = array();

	/**
	 * Constructor.
	 *
	 * @since 2.6.0
	 *
	 * @param WC_Cart $cart Cart object to calculate totals for.
	 * @throws Exception If $cart is invalid WC_Cart object.
	 */
	public function __construct( &$cart = null ) {
		if ( ! is_a( $cart, 'WC_Cart' ) ) {
			throw new InvalidArgumentException( 'A valid WC_Cart object parameter is required for ' . __METHOD__ );
		}

		$this->cart = $cart;
		$this->load_settings();
	}

	/**
	 * Loads the store's switch settings.
	 *
	 * @since 2.6.0
	 */
	protected function load_settings() {
		$this->apportion_recurring_price = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_recurring_price', 'no' );
		$this->apportion_sign_up_fee     = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_sign_up_fee', 'no' );
		$this->apportion_length          = get_option( WC_Subscriptions_Admin::$option_prefix . '_apportion_length', 'no' );
		$this->prices_include_tax        = 'yes' === get_option( 'woocommerce_prices_include_tax' );
	}

	/**
	 * Calculates the upgrade cost, and next payment dates for switch cart items.
	 *
	 * @since 2.6.0
	 */
	public function calculate_prorated_totals() {
		foreach ( $this->get_switches_from_cart() as $cart_item_key => $switch_item ) {
			$this->set_first_payment_timestamp( $cart_item_key, $switch_item->next_payment_timestamp );
			$this->set_end_timestamp( $cart_item_key, $switch_item->end_timestamp );

			$this->apportion_sign_up_fees( $switch_item );

			$switch_type = $switch_item->get_switch_type();
			$this->set_switch_type_in_cart( $cart_item_key, $switch_type );

			if ( $this->should_prorate_recurring_price( $switch_item ) ) {

				// Switching to a product with only 1 payment means no next payment can be collected and so we calculate a gap payment in that scenario.
				if ( 'upgrade' === $switch_type || $switch_item->is_switch_to_one_payment_subscription() ) {
					if ( $this->should_reduce_prepaid_term( $switch_item ) ) {
						$this->reduce_prepaid_term( $cart_item_key, $switch_item );
					} else {
						// Reset any previously calculated prorated price so we don't double the amounts
						$this->reset_prorated_price( $switch_item );

						$upgrade_cost = $this->calculate_upgrade_cost( $switch_item );

						// If a negative upgrade cost has been calculated. Have the customer pay a full price minus what they are owed and set the next payment to be the new products first payment date.
						if ( $upgrade_cost < 0 ) {
							$upgrade_cost = $this->calculate_fully_reduced_upgrade_cost( $cart_item_key, $switch_item );
						}

						$this->set_upgrade_cost( $switch_item, $upgrade_cost );
					}
				}

				if ( apply_filters( 'wcs_switch_should_extend_prepaid_term', 'downgrade' === $switch_type && $this->should_extend_prepaid_term(), $switch_item ) ) {
					$this->extend_prepaid_term( $cart_item_key, $switch_item );
				}

				// Set a flag if the prepaid term has been adjusted.
				if ( $this->get_first_payment_timestamp( $cart_item_key ) !== $switch_item->next_payment_timestamp ) {
					$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['recurring_payment_prorated'] = true;
				}
			}

			if ( $this->should_apportion_length( $switch_item ) ) {
				$this->apportion_length( $switch_item );
			}

			do_action( 'wcs_switch_calculations_completed', $switch_item, $cart_item_key );

			if ( defined( 'WCS_DEBUG' ) && WCS_DEBUG && ! wcs_doing_ajax() ) {
				$this->log_switch( $switch_item );
			}

			// Cache the calculated switched item so we can log it later.
			$this->calculated_switch_items[ $cart_item_key ] = $switch_item;
		}
	}

	/**
	 * Gets all the switch items in the cart as instances of @see WCS_Switch_Cart_Item.
	 *
	 * @since 2.6.0
	 * @return WCS_Switch_Cart_Item[]
	 */
	protected function get_switches_from_cart() {
		$switches = array();

		foreach ( $this->cart->get_cart() as $cart_item_key => $cart_item ) {

			// This item may not exist if its linked to an item that got removed with 'remove_cart_item' below.
			if ( empty( $this->cart->cart_contents[ $cart_item_key ] ) ) {
				continue;
			}

			if ( ! isset( $cart_item['subscription_switch']['subscription_id'] ) ) {
				continue;
			}

			$subscription = wcs_get_subscription( $cart_item['subscription_switch']['subscription_id'] );

			if ( empty( $subscription ) ) {
				$this->cart->remove_cart_item( $cart_item_key );
				continue;
			}

			if ( ! empty( $cart_item['subscription_switch']['item_id'] ) ) {

				$existing_item = wcs_get_order_item( $cart_item['subscription_switch']['item_id'], $subscription );

				if ( empty( $existing_item ) ) {
					$this->cart->remove_cart_item( $cart_item_key );
					continue;
				}

				$switch_item = new WCS_Switch_Cart_Item( $cart_item, $subscription, $existing_item );
			} else {
				$switch_item = new WCS_Add_Cart_Item( $cart_item, $subscription );
			}

			/**
			 * Allow third-parties to filter the switch item and its properties.
			 *
			 * @since 3.0.0
			 *
			 * @param WCS_Switch_Cart_Item $switch_item The switch item.
			 * @param array $cart_item The item in the cart the switch item was created for.
			 * @param string $cart_item_key The cart item key.
			 */
			$switches[ $cart_item_key ] = apply_filters( 'wcs_proration_switch_item_from_cart_item', $switch_item, $cart_item, $cart_item_key );

			// Ensure the filtered item is the correct object type.
			if ( ! is_a( $switches[ $cart_item_key ], 'WCS_Switch_Cart_Item' ) ) {
				unset( $switches[ $cart_item_key ] );
				WC()->cart->remove_cart_item( $cart_item_key );

				$error_notice = __( 'Your cart contained an invalid subscription switch request. It has been removed from your cart.', 'woocommerce-subscriptions' );
				if ( ! wc_has_notice( $error_notice, 'error' ) ) {
					wc_add_notice( $error_notice, 'error' );
				}
			}
		}

		return $switches;
	}

	/** Logic Functions */

	/**
	 * Determines whether the recurring price should be prorated based on the store's switch settings.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 * @return bool
	 */
	protected function should_prorate_recurring_price( $switch_item ) {
		$prorate_all     = in_array( $this->apportion_recurring_price, array( 'yes', 'yes-upgrade' ) );
		$prorate_virtual = in_array( $this->apportion_recurring_price, array( 'virtual', 'virtual-upgrade' ) );

		return apply_filters( 'wcs_switch_should_prorate_recurring_price', $prorate_all || ( $prorate_virtual && $switch_item->is_virtual_product() ), $switch_item );
	}

	/**
	 * Determines whether the current subscription's prepaid term should reduced.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 * @return bool
	 */
	protected function should_reduce_prepaid_term( $switch_item ) {
		$days_in_old_cycle = $switch_item->get_days_in_old_cycle();
		$days_in_new_cycle = $switch_item->get_days_in_new_cycle();

		$is_switch_out_of_trial = 0 == $switch_item->get_total_paid_for_current_period() && ! $switch_item->trial_periods_match() && $switch_item->is_switch_during_trial();

		$should_reduce_current_subscription_prepaid_term = $is_switch_out_of_trial || $days_in_old_cycle > $days_in_new_cycle;

		$subscription      = $switch_item->subscription;
		$cart_item         = $switch_item->cart_item;
		$old_price_per_day = $switch_item->get_old_price_per_day();
		$new_price_per_day = $switch_item->get_new_price_per_day();

		/**
		 * Allow third-parties to filter whether to reduce the prepaid term or not.
		 *
		 * By default, reduce the prepaid term if:
		 *  - The customer is leaving a free trial, this occurs if:
		 *     - The subscription is still on trial,
		 *     - The customer hasn't paid anything in sign-up fees or early renewals since sign-up.
		 *     - The old trial period and length doesn't match the new one.
		 *  - Or there are more days in the in old cycle as there are in the in new cycle (for example switching from yearly to monthly)
		 *
		 * @param bool            $should_reduce_current_subscription_prepaid_term Whether the switch should reduce the current subscription's prepaid term.
		 * @param WC_Subscription $subscription The subscription being switched.
		 * @param array           $cart_item The cart item recording the switch.
		 * @param int             $days_in_old_cycle The number of days in the current subscription's billing cycle.
		 * @param int             $days_in_new_cycle The number of days in the new product's billing cycle.
		 * @param float           $old_price_per_day The current subscription's price per day.
		 * @param float           $new_price_per_day The new product's price per day.
		 */
		return (bool) apply_filters( 'wcs_switch_proration_reduce_pre_paid_term', $should_reduce_current_subscription_prepaid_term, $subscription, $cart_item, $days_in_old_cycle, $days_in_new_cycle, $old_price_per_day, $new_price_per_day );
	}

	/**
	 * Determines whether the current subscription's prepaid term should extended based on the store's switch settings.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	protected function should_extend_prepaid_term() {
		return in_array( $this->apportion_recurring_price, array( 'virtual', 'yes' ) );
	}

	/**
	 * Determines whether the subscription length should be apportioned based on the store's switch settings and product type.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 * @return bool
	 */
	protected function should_apportion_length( $switch_item ) {

		return apply_filters( 'wcs_switch_should_prorate_length', 'yes' == $this->apportion_length || ( 'virtual' == $this->apportion_length && $switch_item->is_virtual_product() ), $switch_item );
	}

	/** Total Calculators */

	/**
	 * Apportions any sign-up fees if required.
	 *
	 * Implements the store's apportion sign-up fee setting (@see $this->apportion_sign_up_fee).
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 */
	protected function apportion_sign_up_fees( $switch_item ) {
		$should_apportion_sign_up_fee = apply_filters( 'wcs_switch_should_prorate_sign_up_fee', 'yes' === $this->apportion_sign_up_fee, $switch_item );

		if ( $should_apportion_sign_up_fee && $switch_item->existing_item ) {
			$product = wc_get_product( $switch_item->canonical_product_id );

			// Make sure we get a fresh copy of the product's meta to avoid prorating an already prorated sign-up fee
			$product->read_meta_data( true );

			// Because product add-ons etc. don't apply to sign-up fees, it's safe to use the product's sign-up fee value rather than the cart item's
			$sign_up_fee_due  = WC_Subscriptions_Product::get_sign_up_fee( $product );
			$sign_up_fee_paid = $switch_item->subscription->get_items_sign_up_fee( $switch_item->existing_item, $this->prices_include_tax ? 'inclusive_of_tax' : 'exclusive_of_tax' );

			// Make sure total prorated sign-up fee is prorated across total amount of sign-up fee so that customer doesn't get extra discounts
			if ( $switch_item->cart_item['quantity'] > $switch_item->existing_item['qty'] ) {
				$sign_up_fee_paid = ( $sign_up_fee_paid * $switch_item->existing_item['qty'] ) / $switch_item->cart_item['quantity'];
			}

			// Allowing third parties to customize the applied sign-up fee
			$subscription_sign_up_fee = apply_filters( 'wcs_switch_sign_up_fee', max( $sign_up_fee_due - $sign_up_fee_paid, 0 ), $switch_item );

			$switch_item->product->update_meta_data( '_subscription_sign_up_fee',  $subscription_sign_up_fee );
			$switch_item->product->update_meta_data( '_subscription_sign_up_fee_prorated', WC_Subscriptions_Product::get_sign_up_fee( $switch_item->product ) );
		} elseif ( 'no' === $this->apportion_sign_up_fee ) {
			// Allowing third parties to force the application of a sign-up fee
			$subscription_sign_up_fee = apply_filters( 'wcs_switch_sign_up_fee', 0, $switch_item );

			$switch_item->product->update_meta_data( '_subscription_sign_up_fee',  $subscription_sign_up_fee );
		}
	}

	/**
	 * Calculates the number of days the customer is entitled to at the new product's price per day and reduce the subscription's prepaid term to match.
	 *
	 * @since 2.6.0
	 * @param string $cart_item_key
	 * @param WCS_Switch_Cart_Item $switch_item
	 */
	protected function reduce_prepaid_term( $cart_item_key, $switch_item ) {
		// Find out how many days at the new price per day the customer would receive for the total amount already paid
		// (e.g. if the customer paid $10 / month previously, and was switching to a $5 / week subscription, she has pre-paid 14 days at the new price)
		$pre_paid_days = $this->calculate_pre_paid_days( $switch_item->get_total_paid_for_current_period(), $switch_item->get_new_price_per_day() );

		// If the total amount the customer has paid entitles her to more days at the new price than she has received, there is no gap payment, just shorten the pre-paid term the appropriate number of days
		if ( $switch_item->get_days_since_last_payment() < $pre_paid_days ) {
			$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $switch_item->get_last_order_paid_time() + ( $pre_paid_days * DAY_IN_SECONDS );
		} else {
			// If the total amount the customer has paid entitles her to the same or fewer days at the new price then start the new subscription from today
			$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = 0;
		}
	}

	/**
	 * Calculates the upgrade cost for a given switch.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 * @return float The amount to pay for the upgrade.
	 */
	protected function calculate_upgrade_cost( $switch_item ) {
		$extra_to_pay = $switch_item->get_days_until_next_payment() * ( $switch_item->get_new_price_per_day() - $switch_item->get_old_price_per_day() );

		// When calculating a subscription with one length (no more next payment date and the end date may have been pushed back) we need to pay for those extra days at the new price per day between the old next payment date and new end date
		if ( ! $switch_item->is_switch_during_trial() && 1 == WC_Subscriptions_Product::get_length( $switch_item->product ) ) {
			$days_to_new_end = floor( ( $switch_item->end_timestamp - $switch_item->next_payment_timestamp ) / DAY_IN_SECONDS );

			if ( $days_to_new_end > 0 ) {
				$extra_to_pay += $days_to_new_end * $switch_item->get_new_price_per_day();
			}
		}

		// We need to find the per item extra to pay so we can set it as the sign-up fee (WC will then multiply it by the quantity)
		$extra_to_pay = $extra_to_pay / $switch_item->cart_item['quantity'];
		return apply_filters( 'wcs_switch_proration_extra_to_pay', $extra_to_pay, $switch_item->subscription, $switch_item->cart_item, $switch_item->get_days_in_old_cycle() );
	}

	/**
	 * Calculates the number of days that have already been paid.
	 *
	 * @since 2.6.0
	 * @param int $old_total_paid The amount paid previously, such as the old recurring total
	 * @param int $new_price_per_day The amount per day price for the new subscription
	 * @return int $pre_paid_days The number of days paid for already
	 */
	protected function calculate_pre_paid_days( $old_total_paid, $new_price_per_day ) {
		$pre_paid_days = 0;

		if ( 0 != $new_price_per_day ) {
			// PHP says you cannot trust floats (http://php.net/float), and they do not lie. A calculation of 25/(25/31) doesn't equal 31. It equals 31.000000000000004.
			// This is then rounded up to 32 :see-no-evil:. To get around this, round the result of the division to 8 decimal places. This should be more than enough.
			$pre_paid_days = ceil( round( $old_total_paid / $new_price_per_day, 8 ) );
		}

		return $pre_paid_days;
	}

	/**
	 * Calculates the number of days the customer is owed at the new product's price per day and extend the subscription's prepaid term accordingly.
	 *
	 * @since 2.6.0
	 * @param string $cart_item_key
	 * @param WCS_Switch_Cart_Item $switch_item
	 */
	protected function extend_prepaid_term( $cart_item_key, $switch_item ) {
		$amount_still_owing = $switch_item->get_old_price_per_day() * $switch_item->get_days_until_next_payment();

		// Find how many more days at the new lower price it takes to exceed the amount owed
		$days_to_add = $this->calculate_pre_paid_days( $amount_still_owing, $switch_item->get_new_price_per_day() );

		// Subtract days until next payments only if days to add is not zero
		if ( 0 !== $days_to_add ) {
			$days_to_add -= $switch_item->get_days_until_next_payment();
		}

		$days_to_add = apply_filters( 'wcs_switch_days_to_extend_prepaid_term', $days_to_add, $cart_item_key, $switch_item );

		$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $switch_item->next_payment_timestamp + ( $days_to_add * DAY_IN_SECONDS );
	}

	/**
	 * Calculates the new subscription's remaining length based on the expected number of payments and the number of payments which have already occurred.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 */
	protected function apportion_length( $switch_item ) {
		$base_length = wcs_get_objects_property( $switch_item->product, 'subscription_base_length_prorated' );

		// Already modified the subscription length of this instance previously?
		if ( is_null( $base_length ) ) {
			// Get the length from the unmodified product instance, and save it for later.
			// A "lazier" way to do the same would have been to call 'WC_Subscriptions_Product::get_length( $switch_item->canonical_product_id )', but this breaks APFS, and is more expensive performance-wise.
			// See https://github.com/woocommerce/woocommerce-subscriptions/issues/3928
			$base_length = WC_Subscriptions_Product::get_length( $switch_item->product );
			wcs_set_objects_property( $switch_item->product, 'subscription_base_length_prorated', $base_length, 'set_prop_only' );
		}

		$completed_payments = $switch_item->subscription->get_payment_count();
		$length_remaining   = $base_length - $completed_payments;

		// Default to the base length if more payments have already been made than this subscription requires
		if ( $length_remaining <= 0 ) {
			$length_remaining = $base_length;
		}

		$length_remaining = apply_filters( 'wcs_switch_length_remaining', $length_remaining, $switch_item );

		$switch_item->product->update_meta_data( '_subscription_length', $length_remaining );
	}

	/** Setters */

	/**
	 * Sets the first payment timestamp on the cart item.
	 *
	 * @since 2.6.0
	 * @param string $cart_item_key The cart item key.
	 * @param int $first_payment_timestamp The first payment timestamp.
	 */
	public function set_first_payment_timestamp( $cart_item_key, $first_payment_timestamp ) {
		$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = $first_payment_timestamp;
	}

	/**
	 * Sets the end timestamp on the cart item.
	 *
	 * @since 2.6.0
	 * @param string $cart_item_key The cart item key.
	 * @param int $end_timestamp The subscription's end date timestamp.
	 */
	public function set_end_timestamp( $cart_item_key, $end_timestamp ) {
		$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['end_timestamp'] = $end_timestamp;
	}

	/**
	 * Sets the switch type on the cart item.
	 *
	 * To preserve past tense for backward compatibility 'd' will be appended to the $switch_type.
	 *
	 * @since 2.6.0
	 * @param string $cart_item_key The cart item's key.
	 * @param string $switch_type Can be upgrade, downgrade or crossgrade.
	 */
	public function set_switch_type_in_cart( $cart_item_key, $switch_type ) {
		$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['upgraded_or_downgraded'] = sprintf( '%sd', $switch_type );
	}

	/**
	 * Resets any previously calculated prorated price.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 */
	public function reset_prorated_price( $switch_item ) {
		if ( $switch_item->product->meta_exists( '_subscription_price_prorated' ) ) {
			$prorated_sign_up_fee = $switch_item->product->get_meta( '_subscription_sign_up_fee_prorated' );
			$switch_item->product->update_meta_data( '_subscription_sign_up_fee', $prorated_sign_up_fee );
		}
	}

	/**
	 * Sets the upgrade cost on the cart item product instance as a sign up fee.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 * @param float $extra_to_pay The upgrade cost.
	 */
	public function set_upgrade_cost( $switch_item, $extra_to_pay ) {
		// Keep a record of the original sign-up fees
		$existing_sign_up_fee = WC_Subscriptions_Product::get_sign_up_fee( $switch_item->product );
		$switch_item->product->update_meta_data( '_subscription_sign_up_fee_prorated', $existing_sign_up_fee );

		$switch_item->product->update_meta_data( '_subscription_price_prorated', $extra_to_pay );
		$switch_item->product->update_meta_data( '_subscription_sign_up_fee', $existing_sign_up_fee + $extra_to_pay );
	}

	/** Getters */

	/**
	 * Gets the first payment timestamp.
	 *
	 * @since 2.6.0
	 * @param string $cart_item_key The cart item's key.
	 * @return int
	 */
	protected function get_first_payment_timestamp( $cart_item_key ) {
		return $this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'];
	}

	/**
	 * Calculates the cost of the upgrade when the customer pays the new product's full price minus the amount paid and still owing.
	 *
	 * This function is used when a switch results in a negative upgrade cost which typically occurs when stores use the `wcs_switch_proration_switch_type` filter to change the default switch type.
	 * For example, if a customer is switching from a monthly subscription to a yearly subscription, they will pay the yearly product's full price minus whatever is still owed on the monthly product's price.
	 *
	 * eg $20/month switched to a $200 yearly product. The upgrade cost would be 200 - ((20/30) * days-left-in-the-current-billing-term).
	 * Switching on the first day of the month would result in the following calculation: 200 - ((20/30) * 30) = 200 - 20 = 180. The full $20 is owed.
	 * Switching halfway through the month would result in the following calculation: 200 - ((20/30) * 15) = 200 - 10 = 190. The customer is owed $10 or half what they paid.
	 *
	 * @param string              $cart_item_key The switch item's cart item key.
	 * @param WCS_Switch_Cart_Item $switch_item  The switch item.
	 *
	 * @return float The upgrade cost.
	 */
	protected function calculate_fully_reduced_upgrade_cost( $cart_item_key, $switch_item ) {
		// When a customer pays the full new product price minus the amount already paid, we need to reduce the prepaid term and the subscription's next payment is 1 billing cycle away.
		$this->cart->cart_contents[ $cart_item_key ]['subscription_switch']['first_payment_timestamp'] = WC_Subscriptions_Product::get_first_renewal_payment_time( $switch_item->product );

		// The customer is owed whatever they didn't use. If they paid $100 for a monthly subscription and are switching half way through the month, they are owed $50.
		$remaining_amount_not_consumed = ( $switch_item->get_total_paid_for_current_period() / $switch_item->get_days_in_old_cycle() ) * $switch_item->get_days_until_next_payment();

		// The customer pays the full price of the new product minus the amount they didn't use.
		return ( WC_Subscriptions_Product::get_price( $switch_item->product ) * $switch_item->cart_item['quantity'] ) - ( $remaining_amount_not_consumed );
	}

	/** Helpers */

	/**
	 * Logs the switch item data to the wcs-switch-cart-items file.
	 *
	 * @since 2.6.0
	 * @param WCS_Switch_Cart_Item $switch_item
	 */
	protected function log_switch( $switch_item ) {
		static $logger       = null;
		static $items_logged = array(); // A cache of the switch items already logged in this request. Prevents multiple log entries for the same item.
		$messages            = array();

		if ( ! $logger ) {
			$logger = wc_get_logger();
		}

		$messages[] = sprintf( 'Switch details for subscription #%s (%s):', $switch_item->subscription->get_id(), $switch_item->existing_item ? $switch_item->existing_item->get_id() : 'new item' );

		foreach ( $switch_item as $property => $value ) {
			if ( is_scalar( $value ) ) {
				$messages[ $property ] = "$property: $value";
			}
		}

		// Prevent logging the same switch item to the log in the same request.
		$key = md5( serialize( $messages ) );

		if ( ! isset( $items_logged[ $key ] ) ) {
			// Add a separator to the bottom of the log entry.
			$messages[]           = str_repeat( '=', 60 ) . PHP_EOL;
			$items_logged[ $key ] = 1;

			$logger->info( implode( PHP_EOL, $messages ), array( 'source' => 'wcs-switch-cart-items' ) );
		}
	}

	/**
	 * Logs information about all the calculated switches currently in the cart.
	 *
	 * @since 2.6.0
	 */
	public function log_switches() {
		foreach ( $this->calculated_switch_items as $switch_item ) {
			$this->log_switch( $switch_item );
		}
	}
}
