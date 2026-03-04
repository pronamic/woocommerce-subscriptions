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
	 * Given the ID of a downloadable product, returns an array of linked subscription product IDs.
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

	/**
	 * Get linked downloadable items for a subscription in the format expected by WC's order-downloads.php template.
	 *
	 * This queries the subscription downloads mapping table to find linked downloadable products,
	 * rather than relying on the subscription's line items. This is necessary when downloadable
	 * products are not added as line items on the subscription (for performance reasons).
	 *
	 * When $limit is set, only that many product IDs are loaded and processed, avoiding the
	 * performance cost of loading all linked products. The total count of linked products is
	 * always returned to allow callers to show "View all N downloads" links.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 * @param int             $limit        Maximum number of products to load. 0 for unlimited.
	 *
	 * @since 8.5.0
	 *
	 * @return array {
	 *     @type array[] $downloads   Downloads in the get_downloadable_items() format.
	 *     @type int     $total_products Total number of linked downloadable product IDs (before limit).
	 * }
	 */
	public static function get_subscription_linked_downloads( $subscription, $limit = 0 ) {
		$downloads = array();

		if ( ! $subscription->is_download_permitted() ) {
			return array(
				'downloads'      => $downloads,
				'total_products' => 0,
			);
		}

		// Collect all unique linked product IDs first (cheap DB queries only).
		$all_product_ids = self::get_all_linked_product_ids( $subscription );
		$total_products  = count( $all_product_ids );

		// Apply limit to avoid loading all products when only a subset is needed.
		$product_ids_to_load = $limit > 0 ? array_slice( $all_product_ids, 0, $limit ) : $all_product_ids;

		if ( empty( $product_ids_to_load ) ) {
			return array(
				'downloads'      => $downloads,
				'total_products' => $total_products,
			);
		}

		// Batch-load products in a single query instead of individual wc_get_product() calls.
		$products_map = self::batch_load_products( $product_ids_to_load );

		foreach ( $product_ids_to_load as $product_id ) {
			if ( ! isset( $products_map[ $product_id ] ) ) {
				continue;
			}

			$product = $products_map[ $product_id ];

			if ( ! $product->is_downloadable() ) {
				continue;
			}

			// Create a temporary order item to check download permissions.
			$order_item = new WC_Order_Item_Product();
			$order_item->set_product( $product );
			$order_item->set_order_id( $subscription->get_id() );
			$item_downloads = $order_item->get_item_downloads();

			if ( empty( $item_downloads ) ) {
				continue;
			}

			foreach ( $item_downloads as $file ) {
				$downloads[] = array(
					'download_url'        => $file['download_url'],
					'download_id'         => $file['id'],
					'product_id'          => $product->get_id(),
					'product_name'        => $product->get_name(),
					'product_url'         => $product->is_visible() ? $product->get_permalink() : '',
					'download_name'       => $file['name'],
					'order_id'            => $subscription->get_id(),
					'order_key'           => $subscription->get_order_key(),
					'downloads_remaining' => $file['downloads_remaining'],
					'access_expires'      => $file['access_expires'],
					'file'                => array(
						'name' => $file['name'],
						'file' => $file['file'],
					),
				);
			}
		}

		return array(
			'downloads'      => $downloads,
			'total_products' => $total_products,
		);
	}

	/**
	 * Collect all unique linked downloadable product IDs for a subscription.
	 *
	 * This only runs the lightweight mapping table queries, without loading any product objects.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 *
	 * @since 8.5.0
	 *
	 * @return int[] Unique product IDs.
	 */
	private static function get_all_linked_product_ids( $subscription ) {
		$all_product_ids = array();

		foreach ( $subscription->get_items() as $item ) {
			$downloadable_products = self::get_downloadable_products( $item['product_id'], $item['variation_id'] );

			foreach ( $downloadable_products as $product_id ) {
				$all_product_ids[ $product_id ] = true;
			}
		}

		return array_keys( $all_product_ids );
	}

	/**
	 * Batch-load product objects by ID in a single query.
	 *
	 * @param int[] $product_ids Product IDs to load.
	 *
	 * @since 8.5.0
	 *
	 * @return WC_Product[] Map of product_id => WC_Product.
	 */
	private static function batch_load_products( $product_ids ) {
		$products_map = array();

		if ( empty( $product_ids ) ) {
			return $products_map;
		}

		$products = wc_get_products(
			array(
				'include' => $product_ids,
				'limit'   => count( $product_ids ),
				'status'  => 'publish',
			)
		);

		foreach ( $products as $product ) {
			$products_map[ $product->get_id() ] = $product;
		}

		return $products_map;
	}
}
