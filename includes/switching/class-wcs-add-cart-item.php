<?php
/**
 * WooCommerce Subscriptions Add Cart Item.
 *
 * A class to assist in the calculations required to add an item to an existing subscription.
 * To enable proration, adding a product to a subscription inherits all the switch item (@see WCS_Switch_Cart_Item) functionality, however, doesn't have an existing item (@see WCS_Switch_Cart_Item::$existing_item) to replace.
 *
 * @package WooCommerce Subscriptions
 * @author  Prospress
 * @since   2.6.0
 */
class WCS_Add_Cart_Item extends WCS_Switch_Cart_Item {

	/**
	 * Constructor.
	 *
	 * An item being added to a subscription is just a switch item, without an existing item.
	 *
	 * @since 2.6.0
	 *
	 * @param array $cart_item              The cart item.
	 * @param WC_Subscription $subscription The subscription being switched.
	 *
	 * @throws Exception If WC_Subscriptions_Product::get_expiration_date() returns an invalid date.
	 */
	public function __construct( $cart_item, $subscription ) {
		parent::__construct( $cart_item, $subscription, null );
	}

	/** Getters */

	/**
	 * Gets the old subscription's price per day.
	 *
	 * For items being added to a subscription, there is no old item's price and so 0 should be returned.
	 *
	 * @since 2.6.0
	 * @return float
	 */
	public function get_old_price_per_day() {
		if ( ! isset( $this->old_price_per_day ) ) {
			$this->old_price_per_day = apply_filters( 'wcs_switch_proration_old_price_per_day', 0, $this->subscription, $this->cart_item, 0, $this->get_days_in_old_cycle() );
		}

		return $this->old_price_per_day;
	}

	/**
	 * Gets the total paid for the current period.
	 *
	 * For items being added to a subscription there isn't anything paid which needs to be honoured and so 0 has been paid.
	 *
	 * @since 2.6.0
	 * @return float
	 */
	public function get_total_paid_for_current_period() {
		return 0;
	}

	/**
	 * Determines if the last order was a switch and the outcome of that was a fully reduced pre-paid term.
	 * Since the last order didn't contain this item, we can safely return false here.
	 *
	 * @since 3.0.7
	 * @return bool Whether the last order was a switch and it fully reduced the prepaid term.
	 */
	protected function is_switch_after_fully_reduced_prepaid_term() {
		return false;
	}

	/** Helper functions */

	/**
	 * Determines whether the new product's trial period matches the old product's trial period.
	 *
	 * For items being added to a subscription there isn't an existing item to match so false is returned.
	 *
	 * @since 2.6.0
	 * @return bool
	 */
	public function trial_periods_match() {
		return false;
	}
}
