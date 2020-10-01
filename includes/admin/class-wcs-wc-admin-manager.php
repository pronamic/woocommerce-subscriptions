<?php
/**
 * WooCommerce Subscriptions WC Admin Manager.
 *
 * @author   WooCommerce
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin
 * @version  3.0.2
 */

defined( 'ABSPATH' ) || exit;

class WCS_WC_Admin_Manager {

	/**
	 * Initialise the class and attach hook callbacks.
	 */
	public static function init() {
		if ( ! defined( 'WC_ADMIN_PLUGIN_FILE' ) ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'register_subscription_admin_pages' ) );
	}

	/**
	 * Connects existing WooCommerce Subscription admin pages to WooCommerce Admin.
	 */
	public static function register_subscription_admin_pages() {
		// WooCommerce > Subscriptions.
		wc_admin_connect_page(
			array(
				'id'        => 'woocommerce-subscriptions',
				'screen_id' => 'edit-shop_subscription',
				'title'     => __( 'Subscriptions', 'woocommerce-subscriptions' ),
				'path'      => add_query_arg( 'post_type', 'shop_subscription', 'edit.php' ),
			)
		);

		// WooCommerce > Subscriptions > Add New.
		wc_admin_connect_page(
			array(
				'id'        => 'woocommerce-add-subscription',
				'parent'    => 'woocommerce-subscriptions',
				'screen_id' => 'shop_subscription-add',
				'title'     => __( 'Add New', 'woocommerce-subscriptions' ),
			)
		);

		// WooCommerce > Subscriptions > Edit Subscription.
		wc_admin_connect_page(
			array(
				'id'        => 'woocommerce-edit-subscription',
				'parent'    => 'woocommerce-subscriptions',
				'screen_id' => 'shop_subscription',
				'title'     => __( 'Edit Subscription', 'woocommerce-subscriptions' ),
			)
		);
	}
}
