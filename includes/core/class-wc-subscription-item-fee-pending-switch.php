<?php
/**
 * Subscription Fee Item Pending Switch
 *
 * Fee items which have been added during switch by a customer have the fee_pending_switch type. This class extends WC_Order_Item_Fee to implement this fee item type.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions
 * @since    1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

class WC_Subscription_Item_Fee_Pending_Switch extends WC_Order_Item_Fee {

	/**
	 * Get item type.
	 *
	 * @return string
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
	 */
	public function get_type() {
		return 'fee_pending_switch';
	}
}
