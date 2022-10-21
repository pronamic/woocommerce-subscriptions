<?php
/**
 * WooCommerce Subscriptions WC Admin Manager.
 *
 * @package  WooCommerce Subscriptions/Admin
 * @version  3.0.2
 */

use Automattic\WooCommerce\Admin\Loader;
use Automattic\WooCommerce\Admin\Features\Navigation\Menu;
use Automattic\WooCommerce\Admin\Features\Navigation\Screen;

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
		add_action( 'admin_menu', array( __CLASS__, 'register_navigation_items' ), 6 );
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

	/**
	 * Register the navigation items in the WooCommerce navigation.
	 *
	 * @since 3.0.12
	 */
	public static function register_navigation_items() {
		if (
			! class_exists( '\Automattic\WooCommerce\Admin\Features\Navigation\Menu' ) ||
			! class_exists( '\Automattic\WooCommerce\Admin\Features\Navigation\Screen' )
		) {
			return;
		}

		$subscription_items = Menu::get_post_type_items(
			'shop_subscription',
			array(
				'title' => __( 'Subscriptions', 'woocommerce-subscriptions' ),
			)
		);

		Menu::add_plugin_item( $subscription_items['all'] );
		Screen::register_post_type( 'shop_subscription' );
	}
}
