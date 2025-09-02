<?php
/**
 * Edit order page integration.
 *
 * @package WooCommerce Subscriptions Gifting/Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class for edit order page.
 */
class WCSG_Admin_Order {

	public static function init() {
		add_filter( 'woocommerce_hidden_order_itemmeta', __CLASS__ . '::hide_gifting_meta' );
	}

	/**
	 * Hides the gifting meta from the order edit page.
	 * @param array $item_meta_names The item meta names to hide.
	 */
	public static function hide_gifting_meta( $item_meta_names ) {
		$item_meta_names[] = '_cart_item_key_subscription_renewal';

		return $item_meta_names;
	}
}
