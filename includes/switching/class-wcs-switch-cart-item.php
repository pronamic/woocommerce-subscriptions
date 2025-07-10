<?php
/**
 * WooCommerce Subscriptions Switch Cart Item.
 *
 * A class to assist in the calculations required to record a switch.
 *
 * @package WooCommerce Subscriptions
 * @author  Prospress
 * @since   2.6.0
 */
class WCS_Switch_Cart_Item {

	/**
	 * The cart item.
	 * @var array
	 */
	public $cart_item;

	/**
	 * The subscription being switched.
	 * @var WC_Subscription
	 */
	public $subscription;

	/**
	 * The existing subscription line item being switched.
	 * @var WC_Order_Item_Product
	 */
	public $existing_item;

	/**
	 * The instance of the new product in the cart.
	 * @var WC_Product
	 */
	public $product;

	/**
	 * The new product's variation or product ID.
	 * @var int
	 */
	public $canonical_product_id;

	/**
	 * The subscription's next payment timestamp.
	 * @var int
	 */
	public $next_payment_timestamp;

	/**
	 * The subscription's end timestamp.
	 * @var int
	 */
	public $end_timestamp;

	/**
	 * The subscription's last non-early renewal or parent order paid timestamp.
	 * @var int
	 */
	public $last_order_paid_time;

	/**
	 * The number of days since the @see $last_order_created_time.
	 * @var int
	 */
	public $days_since_last_payment;

	/**
	 * The number of days until the @see $next_payment_timestamp.
	 * @var int
	 */
	public $days_until_next_payment;

	/**
	 * The number of days in the old subscription's billing cycle.
	 * @var int
	 */
	public $days_in_old_cycle;

	/**
	 * The total paid for the existing item (@see $existing_item) in early renewals and switch orders since the last non-early renewal or parent order.
	 * @var float
	 */
	public $total_paid_for_current_period;

	/**
	 * The existing subscription item's price per day.
	 * @var float
	 */
	public $old_price_per_day;

	/**
	 * The number of days in the new subscription's billing cycle.
	 * @var float
	 */
	public $days_in_new_cycle;

	/**
	 * The new subscription product's price per day.
	 * @var float
	 */
	public $new_price_per_day;

	/**
	 * The switch type.
	 * @var string Can be upgrade, downgrade or crossgrade.
	 */
	public $switch_type;

	/**
	 * Whether the last order was a switch and was a fully reduced pre-paid term.
	 * @var bool
	 */
	public $is_switch_after_fully_reduced_prepaid_term;

	/**
	 * The last switch order for this subscription.
	 * @var WC_Order|null
	 */
	private $switch_order = null;

	/**
	 * Constructor.
	 *
	 * @since 2.6.0
	 *
	 * @param array $cart_item              The cart item.
	 * @param WC_Subscription $subscription The subscription being switched.
	 * @param WC_Order_Item $existing_item  The subscription line item being switched.
	 *
	 * @throws Exception If WC_Subscriptions_Product::get_expiration_date() returns an invalid date.
	 */
	public function __construct( $cart_item, $subscription, $existing_item ) {
		$this->cart_item               = $cart_item;
		$this->subscription            = $subscription;
		$this->existing_item           = $existing_item;
		$this->canonical_product_id    = wcs_get_canonical_product_id( $cart_item );
		$this->product                 = $cart_item['data'];
		$this->next_payment_timestamp  = $cart_item['subscription_switch']['next_payment_timestamp'];
		$this->end_timestamp           = wcs_date_to_time( WC_Subscriptions_Product::get_expiration_date( $this->canonical_product_id, $this->subscription->get_date( 'last_order_date_created' ) ) );
	}

	/** Getters */

	/**
	 * Gets the number of days until the next payment.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	public function get_days_until_next_payment() {
		if ( ! isset( $this->days_until_next_payment ) ) {
			$this->days_until_next_payment = ceil( ( $this->next_payment_timestamp - (int) gmdate( 'U' ) ) / DAY_IN_SECONDS );
		}

		return $this->days_until_next_payment;
	}

	/**
	 * Gets the number of days in the old billing cycle.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	public function get_days_in_old_cycle() {
		if ( ! isset( $this->days_in_old_cycle ) ) {
			$this->days_in_old_cycle = $this->calculate_days_in_old_cycle();
		}

		return $this->days_in_old_cycle;
	}

	/**
	 * Gets the old subscription's price per day.
	 *
	 * @since 2.6.0
	 * @return float
	 */
	public function get_old_price_per_day() {
		if ( ! isset( $this->old_price_per_day ) ) {
			$days_in_old_cycle = $this->get_days_in_old_cycle();

			$total_paid_for_current_period = $this->get_total_paid_for_current_period();

			$old_price_per_day       = $days_in_old_cycle > 0 ? $total_paid_for_current_period / $days_in_old_cycle : $total_paid_for_current_period;
			$this->old_price_per_day = apply_filters( 'wcs_switch_proration_old_price_per_day', $old_price_per_day, $this->subscription, $this->cart_item, $total_paid_for_current_period, $days_in_old_cycle );
		}

		return $this->old_price_per_day;
	}

	/**
	 * Gets the number of days in the new billing cycle.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	public function get_days_in_new_cycle() {
		if ( ! isset( $this->days_in_new_cycle ) ) {
			$this->days_in_new_cycle = $this->calculate_days_in_new_cycle();
		}

		return $this->days_in_new_cycle;
	}

	/**
	 * Gets the number of days in the new billing cycle.
	 *
	 * @since 2.6.0
	 * @return float
	 */
	public function get_new_price_per_day() {
		if ( ! isset( $this->new_price_per_day ) ) {
			$days_in_new_cycle = $this->get_days_in_new_cycle();

			if ( $this->is_switch_during_trial() && $this->trial_periods_match() ) {
				$new_price_per_day = 0;
			} else {
				// We need to use the cart items price to ensure we include extras added by extensions like Product Add-ons, but we don't want the sign-up fee accounted for in the price, so make sure WC_Subscriptions_Cart::set_subscription_prices_for_calculation() isn't adding that.
				remove_filter( 'woocommerce_product_get_price', 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation', 100 );
				$new_price_per_day = ( WC_Subscriptions_Product::get_price( $this->product ) * $this->cart_item['quantity'] ) / $days_in_new_cycle;
				add_filter( 'woocommerce_product_get_price', 'WC_Subscriptions_Cart::set_subscription_prices_for_calculation', 100, 2 );
			}

			$this->new_price_per_day = apply_filters( 'wcs_switch_proration_new_price_per_day', $new_price_per_day, $this->subscription, $this->cart_item, $days_in_new_cycle );
		}

		return $this->new_price_per_day;
	}

	/**
	 * Gets the subscription's last order paid time.
	 *
	 * @since 2.6.0
	 * @return int The paid timestamp of the subscription's last non-early renewal or parent order. If none of those are present, the subscription's start time will be returned.
	 */
	public function get_last_order_paid_time() {
		if ( ! isset( $this->last_order_paid_time ) ) {
			$last_order = wcs_get_last_non_early_renewal_order( $this->subscription );

			// If there haven't been any non-early renewals yet, use the parent
			if ( ! $last_order ) {
				$last_order = $this->subscription->get_parent();
			}

			// If there aren't any renewals or a parent order, use the subscription's created date.
			if ( ! $last_order ) {
				$this->last_order_paid_time = $this->subscription->get_time( 'start' );
			} else {
				$order_date = $last_order->get_date_paid();

				// If the order hasn't been paid, use the created date. This shouldn't occur because only active (paid) subscriptions can be switched. However, we provide a fallback just in case.
				if ( ! $order_date ) {
					$order_date = $last_order->get_date_created();
				}

				$this->last_order_paid_time = $order_date->getTimestamp();
			}
		}

		return $this->last_order_paid_time;
	}

	/**
	 * Gets the total paid for the existing item (@see $this->existing_item) in early renewals and switch orders since the last non-early renewal or parent order.
	 *
	 * @since 2.6.0
	 * @return float
	 */
	public function get_total_paid_for_current_period() {

		if ( ! isset( $this->total_paid_for_current_period ) ) {
			$orders_to_include = array();

			// If the last order was a switch with a fully reduced pre-paid term, the amount the customer has paid is just the total in that order.
			if ( $this->is_switch_after_fully_reduced_prepaid_term() ) {
				$orders_to_include[] = $this->get_last_switch_order();
			}

			$this->total_paid_for_current_period = WC_Subscriptions_Switcher::calculate_total_paid_since_last_order(
				$this->subscription,
				$this->existing_item,
				'exclude_sign_up_fees',
				$orders_to_include
			);
		}

		return apply_filters( 'wcs_switch_total_paid_for_current_period', $this->total_paid_for_current_period, $this->subscription, $this->existing_item );
	}

	/**
	 * Gets the number of days since the last payment.
	 *
	 * @since 2.6.0
	 * @return int The number of days since the last non-early renewal or parent payment - rounded down.
	 */
	public function get_days_since_last_payment() {
		if ( ! isset( $this->days_since_last_payment ) ) {
			// Use the timestamp for the last non-early renewal order or parent order to avoid date miscalculations which early renewing creates.
			$this->days_since_last_payment = floor( ( (int) gmdate( 'U' ) - $this->get_last_order_paid_time() ) / DAY_IN_SECONDS );
		}

		return $this->days_since_last_payment;
	}

	/**
	 * Gets the switch type.
	 *
	 * @since 2.6.0
	 * @return string Can be upgrade, downgrade or crossgrade.
	 */
	public function get_switch_type() {
		if ( ! isset( $this->switch_type ) ) {
			$old_price_per_day = $this->get_old_price_per_day();
			$new_price_per_day = $this->get_new_price_per_day();

			if ( $old_price_per_day < $new_price_per_day ) {
				$switch_type = 'upgrade';
			} elseif ( $old_price_per_day > $new_price_per_day && $new_price_per_day >= 0 ) {
				$switch_type = 'downgrade';
			} else {
				$switch_type = 'crossgrade';
			}

			$switch_type = apply_filters( 'wcs_switch_proration_switch_type', $switch_type, $this->subscription, $this->cart_item, $old_price_per_day, $new_price_per_day );

			if ( ! in_array( $switch_type, array( 'upgrade', 'downgrade', 'crossgrade' ) ) ) {
				// translators: placeholder is a switch type.
				throw new UnexpectedValueException( sprintf( __( 'Invalid switch type "%s". Switch must be one of: "upgrade", "downgrade" or "crossgrade".', 'woocommerce-subscriptions' ), $switch_type ) );
			}

			$this->switch_type = $switch_type;
		}

		return $this->switch_type;
	}

	/** Calculator functions */

	/**
	 * Calculates the number of days in the old cycle.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	public function calculate_days_in_old_cycle() {
		$method_to_use = 'days_between_payments';

		// If the subscription contains a synced product with no proration on signup and the next payment is actually the first payment, determine the days in the "old" cycle from the subscription object
		if ( WC_Subscriptions_Synchroniser::subscription_contains_synced_product( $this->subscription ) ) {
			$first_synced_payment = WC_Subscriptions_Synchroniser::calculate_first_payment_date( wc_get_product( $this->canonical_product_id ), 'timestamp', $this->subscription->get_date( 'start' ) );

			if ( $first_synced_payment === $this->next_payment_timestamp && 0 === $this->get_total_paid_for_current_period() ) {
				$method_to_use = 'days_in_billing_cycle';
			}
		}

		// We need the product's billing cycle, not the trial length if the customer hasn't paid anything and it's still on trial.
		if ( $this->is_switch_during_trial() && 0 === $this->get_total_paid_for_current_period() ) {
			$method_to_use = 'days_in_billing_cycle';
		}

		// If the last order was a switch order with a fully reduced pre-paid term.
		if ( $this->is_switch_after_fully_reduced_prepaid_term() ) {
			$method_to_use = 'days_between_switch_and_next_payment';
		}

		// Find the number of days between the last payment and the next
		if ( 'days_between_payments' === $method_to_use ) {
			$days_in_old_cycle = round( ( $this->next_payment_timestamp - $this->get_last_order_paid_time() ) / DAY_IN_SECONDS );
		} elseif ( 'days_between_switch_and_next_payment' === $method_to_use ) {
			$days_in_old_cycle = round( ( $this->next_payment_timestamp - $this->get_last_switch_order()->get_date_paid()->getTimestamp() ) / DAY_IN_SECONDS );
		} else {
			$days_in_old_cycle = wcs_get_days_in_cycle( $this->subscription->get_billing_period(), $this->subscription->get_billing_interval() );
		}

		return apply_filters( 'wcs_switch_proration_days_in_old_cycle', $days_in_old_cycle, $this->subscription, $this->cart_item );
	}

	/**
	 * Calculates the number of days in the new cycle.
	 *
	 * @since 2.6.0
	 * @return int
	 */
	public function calculate_days_in_new_cycle() {
		if ( $this->is_switch_after_fully_reduced_prepaid_term() ) {
			$last_order_time = $this->get_last_switch_order()->get_date_paid()->getTimestamp();
		} else {
			$last_order_time = $this->get_last_order_paid_time();
		}

		$new_billing_period   = WC_Subscriptions_Product::get_period( $this->product );
		$new_billing_interval = WC_Subscriptions_Product::get_interval( $this->product );

		// Calculate the number of days in the new cycle by finding what the renewal date would have been if the customer purchased the (new) product at the last payment date.
		// This gives us the most accurate number of days in the new cycle and a value that is similar to the number of days in the old cycle which is usually calculated by the the number of days between the last order and the next payment date.
		$days_in_new_cycle = ( wcs_add_time( $new_billing_interval, $new_billing_period, $last_order_time ) - $last_order_time ) / DAY_IN_SECONDS;

		// Find if the days in new cycle match the days in the old cycle,ignoring any rounding.
		$days_in_old_cycle = $this->get_days_in_old_cycle();
		$days_in_new_and_old_cycle_match = ceil( $days_in_new_cycle ) == $days_in_old_cycle || floor( $days_in_new_cycle ) == $days_in_old_cycle;

		// Set the days in each cycle to match if they are equal (ignoring any rounding discrepancy) or if the subscription is switched during a trial and has a matching trial period.
		if ( $days_in_new_and_old_cycle_match || ( $this->is_switch_during_trial() && $this->trial_periods_match() ) ) {
			$days_in_new_cycle = $days_in_old_cycle;
		}

		return apply_filters( 'wcs_switch_proration_days_in_new_cycle', $days_in_new_cycle, $this->subscription, $this->cart_item, $days_in_old_cycle );
	}

	/** Helper functions */

	/**
	 * Determines whether the new product is virtual or not.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public function is_virtual_product() {
		return $this->product->is_virtual();
	}

	/**
	 * Determines whether the new product's trial period matches the old product's trial period.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public function trial_periods_match() {
		$existing_product = $this->existing_item->get_product();

		/**
		 * We need to cast the returned trial lengths as sometimes they may be strings.
		 * We also need to pass the new product's ID so the raw product's trial is used, not the filtered trial set by @see WC_Subscriptions_Switcher::maybe_unset_free_trial() && WC_Subscriptions_Switcher::maybe_set_free_trial().
		 */
		$matching_length = (int) WC_Subscriptions_Product::get_trial_length( $this->product->get_id() ) === (int) WC_Subscriptions_Product::get_trial_length( $existing_product );
		$matching_period = WC_Subscriptions_Product::get_trial_period( $this->product->get_id() ) === WC_Subscriptions_Product::get_trial_period( $existing_product );

		return $matching_period && $matching_length;
	}

	/**
	 * Determines whether the switch is happening while the subscription is still on trial.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public function is_switch_during_trial() {
		return $this->subscription->get_time( 'trial_end' ) > gmdate( 'U' );
	}

	/**
	 * Retrieves the subscription's last switch order.
	 *
	 * @since 3.0.7
	 * @return WC_Order|Null The last switch order or null if one doesn't exist.
	 */
	protected function get_last_switch_order() {
		if ( ! $this->switch_order ) {
			$this->switch_order = $this->subscription->get_last_order( 'all', 'switch', array( 'checkout-draft' ) );
		}

		return $this->switch_order;
	}

	/**
	 * Determines if the last order was a switch and the outcome of that was a fully reduced pre-paid term.
	 *
	 * A fully reduced pre-paid term occurs when the amount the customer has paid (in total including switches) doesn't cover the amount of time that has elapsed already at the new price per day.
	 *
	 * For example:
	 * - Original purchase of a $70 / week subscription.
	 * - 5 days into the subscription the customer switches to a $120 / 3 days. The lower frequency triggers the pre-paid term to be reduced.
	 * - The $70 paid at $40 a day only entitles the customer to 1.75 days.
	 * - Because they are already 5 days into the subscription, that $70 is fully absorbed at the new price and no time is 'owed'.
	 * - The subscription starts today and the customer pays full price.
	 *
	 * @see https://woocommerce.com/document/subscriptions/switching-guide/switching-process-and-costs/#upgrades
	 * @see WCS_Switch_Totals_Calculator::reduce_prepaid_term()
	 *
	 * @since 3.0.7
	 * @return bool Whether the last order was a switch and it fully reduced the prepaid term.
	 */
	protected function is_switch_after_fully_reduced_prepaid_term() {

		if ( ! isset( $this->is_switch_after_fully_reduced_prepaid_term ) ) {
			$this->is_switch_after_fully_reduced_prepaid_term = $this->calculate_is_switch_after_fully_reduced_prepaid_term();
		}

		return $this->is_switch_after_fully_reduced_prepaid_term;
	}

	/**
	 * Calculates whether the last order was a switch and it fully reduced the prepaid term.
	 *
	 * @since 7.6.0
	 * @return bool
	 */
	public function calculate_is_switch_after_fully_reduced_prepaid_term() {
		$last_switch_order = $this->get_last_switch_order();

		// If there is no last switch order or it hasn't been paid for, the customer hasn't switched before.
		// Therefore, this can't be a switch after a fully reduced prepaid term.
		if ( empty( $last_switch_order ) || ! $last_switch_order->get_date_paid() ) {
			return false;
		}

		$switch_paid_date = $last_switch_order->get_date_paid();

		// If the last switch order occurred before the last payment order (parent or renewal), then the last order wasn't a switch.
		if ( $switch_paid_date->getTimestamp() < $this->get_last_order_paid_time() ) {
			return false;
		}

		/**
		 * If the last switch resulted in the customer being charged the pull cost upfront, the customer must have been entitled to fewer days than had already elapsed - see reduce_prepaid_term().
		 * This means the subscription billing term would have started from that switch order's date, not the last order (parent/renewal) date.
		 */
		$first_payment_after_switch = WC_Subscriptions_Product::get_first_renewal_payment_time(
			$this->existing_item->get_product(),
			gmdate( 'Y-m-d H:i:s', $switch_paid_date->format( 'U' ) )
		);

		// Check if the first payment after switch is within 1 hour of the next payment timestamp.
		return abs( $first_payment_after_switch - $this->next_payment_timestamp ) <= HOUR_IN_SECONDS;
	}

	/**
	 * Determines whether the customer is switching to a subscription with a length of 1 - one off payment.
	 *
	 * @since 3.0.12
	 * @return bool
	 */
	public function is_switch_to_one_payment_subscription() {
		return 1 === absint( WC_Subscriptions_Product::get_length( $this->product ) );
	}
}
