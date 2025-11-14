<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Point of entry for our 'linked downloadable files' functionality.
 *
 * Along with other classes in this directory, this used to exist as a standalone plugin (important to note because, for
 * backwards compatibility reasons, that may place limits on future refactoring).
 *
 * The overall goal is to let merchants associate individual downloadable products with subscription products.
 * Customers who purchase the subscription product then automatically are granted acccess to the relevant downloadable
 * files.
 *
 * This class sets up the functionality, and also provides a high-level interface through methods such as
 * get_order_downloads( $order ) and get_subscriptions( $product_id ).
 *
 * @since 8.1.0
 */
class WC_Subscription_Downloads {
	/**
	 * Initialize the various subsystems that drive 'linked downloadable files' functionality.
	 */
	public static function setup(): void {

		if ( WC_Subscription_Downloads_Settings::is_enabled() ) {
			new WC_Subscription_Downloads_Order();

			if ( is_admin() ) {
				new WC_Subscription_Downloads_Products();
				new WC_Subscription_Downloads_Ajax();
			}
		}

		// Needs to load on admin even when downloads is disabled.
		// To ensure the settings are added to the subscription settings page.
		if ( is_admin() ) {
			new WC_Subscription_Downloads_Settings();
		}
	}

	/**
	 * Install the plugin.
	 *
	 * @return void
	 */
	public static function install() {
		new WC_Subscription_Downloads_Install();
	}

	/**
	 * Get subscriptions from a downloadable product.
	 *
	 * @param  int $product_id
	 *
	 * @return array
	 */
	public static function get_subscriptions( $product_id ) {
		global $wpdb;

		$query = $wpdb->get_results( $wpdb->prepare( "SELECT subscription_id FROM {$wpdb->prefix}woocommerce_subscription_downloads WHERE product_id = %d", $product_id ), ARRAY_A );

		$subscriptions = array();
		foreach ( $query as $item ) {
			$subscriptions[] = $item['subscription_id'];
		}

		return $subscriptions;
	}

	/**
	 * Get downloadable products from a subscription.
	 *
	 * @param  int $subscription_id
	 *
	 * @return array
	 */
	public static function get_downloadable_products( $subscription_id, $subscription_variable_id = '' ) {
		global $wpdb;

		$query = $wpdb->get_results( $wpdb->prepare( "SELECT product_id FROM {$wpdb->prefix}woocommerce_subscription_downloads WHERE subscription_id = %d OR subscription_id = %d", $subscription_id, $subscription_variable_id ), ARRAY_A );

		$products = array();
		foreach ( $query as $item ) {
			$products[] = $item['product_id'];
		}

		return $products;
	}

	/**
	 * Get order download files.
	 *
	 * @param  WC_Order $order Order data.
	 *
	 * @return array           Download data (name, file and download_url).
	 */
	public static function get_order_downloads( $order ) {
		$downloads = array();

		if ( class_exists( 'WC_Subscriptions_Core_Plugin' ) || version_compare( WC_Subscriptions::$version, '2.0.0', '>=' ) ) {
			$contains_subscription = wcs_order_contains_subscription( $order );
		} else {
			$contains_subscription = WC_Subscriptions_Order::order_contains_subscription( $order );
		}

		if ( 0 < count( $order->get_items() ) && $contains_subscription && $order->is_download_permitted() ) {
			foreach ( $order->get_items() as $item ) {

				// Gets the downloadable products.
				$downloadable_products = self::get_downloadable_products( $item['product_id'], $item['variation_id'] );

				if ( $downloadable_products ) {
					foreach ( $downloadable_products as $product_id ) {
						$_item = array(
							'product_id'   => $product_id,
							'variation_id' => '',
						);

						// Get the download data.
						if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
							$_downloads = $order->get_item_downloads( $_item );
						} else {
							$order_item = new WC_Order_Item_Product();
							$product    = wc_get_product( $product_id );
							if ( empty( $product ) ) {
								continue;
							}
							$order_item->set_product( $product );
							$order_item->set_order_id( $order->get_id() );
							$_downloads = $order_item->get_item_downloads();
						}

						if ( empty( $_downloads ) ) {
							continue;
						}

						foreach ( $_downloads as $download ) {
							$downloads[] = $download;
						}
					}
				}
			}
		}

		return $downloads;
	}
}
