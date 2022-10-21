<?php
/**
 * Coupon Pending Switch
 *
 * Coupons which have been added during switch by a customer have the coupon_pending_switch type. This class extends WC_Order_Item_Coupon to implement this coupon item type.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions
 * @since    2.6.0
 */

class WC_Subscription_Item_Coupon_Pending_Switch extends WC_Order_Item_Coupon {

	/**
	 * Get item type.
	 *
	 * @return string
	 * @since 2.6.0
	 */
	public function get_type() {
		return 'coupon_pending_switch';
	}
}
