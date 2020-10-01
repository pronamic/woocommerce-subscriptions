<?php
/**
 * Line Item (product) Pending Switch
 *
 * Line items added to a subscription to record a switch are first given this line item type before transitioning to a fully fledged WC_Order_Item_Product.
 *
 * @author   Prospress
 * @category Class
 * @package  WooCommerce Subscriptions
 * @since    2.2.0
 */

class WC_Order_Item_Pending_Switch extends WC_Order_Item_Product {

	/**
	 * Get item type.
	 *
	 * @return string
	 * @since 2.2.0
	 */
	public function get_type() {
		return 'line_item_pending_switch';
	}
}
