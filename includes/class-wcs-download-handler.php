<?php
/**
 * Download Handler for WooCommerce Subscriptions
 *
 * Functions for download related things within the Subscription Extension.
 *
 * @package		WooCommerce Subscriptions
 * @subpackage	WCS_Download_Handler
 * @category	Class
 * @author		Prospress
 * @since		2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Download_Handler {

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 2.0
	 */
	public static function init() {

		add_filter( 'woocommerce_process_product_file_download_paths_grant_access_to_new_file', __CLASS__ . '::maybe_revoke_immediate_access', 10, 4 );

		add_action( 'woocommerce_grant_product_download_permissions', __CLASS__ . '::save_downloadable_product_permissions' );

		add_filter( 'woocommerce_get_item_downloads', __CLASS__ . '::get_item_downloads', 10, 3 );

		add_action( 'woocommerce_process_shop_order_meta', __CLASS__ . '::repair_permission_data', 60, 1 );

		add_action( 'deleted_post', __CLASS__ . '::delete_subscription_permissions' );
	}

	/**
	 * When adding new downloadable content to a subscription product, check if we don't
	 * want to automatically add the new downloadable files to the subscription or initial and renewal orders.
	 *
	 * @param bool $grant_access
	 * @param string $download_id
	 * @param int $product_id
	 * @param WC_Order $order
	 * @return bool
	 * @since 2.0
	 */
	public static function maybe_revoke_immediate_access( $grant_access, $download_id, $product_id, $order ) {

		if ( 'yes' == get_option( WC_Subscriptions_Admin::$option_prefix . '_drip_downloadable_content_on_renewal', 'no' ) && ( wcs_is_subscription( $order->id ) || wcs_order_contains_subscription( $order, 'any' ) ) ) {
			$grant_access = false;
		}
		return $grant_access;
	}

	/**
	 * Save the download permissions on the individual subscriptions as well as the order. Hooked into
	 * 'woocommerce_grant_product_download_permissions', which is strictly after the order received all the info
	 * it needed, so we don't need to play with priorities.
	 *
	 * @param integer $order_id the ID of the order. At this point it is guaranteed that it has files in it and that it hasn't been granted permissions before
	 */
	public static function save_downloadable_product_permissions( $order_id ) {
		global $wpdb;
		$order = wc_get_order( $order_id );

		if ( wcs_order_contains_subscription( $order, 'any' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
		} else {
			return;
		}

		foreach ( $subscriptions as $subscription ) {
			if ( sizeof( $subscription->get_items() ) > 0 ) {
				foreach ( $subscription->get_items() as $item ) {
					$_product = $subscription->get_product_from_item( $item );

					if ( $_product && $_product->exists() && $_product->is_downloadable() ) {
						$downloads  = $_product->get_files();
						$product_id = wcs_get_canonical_product_id( $item );

						foreach ( array_keys( $downloads ) as $download_id ) {
							// grant access on subscription if it does not already exist
							if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT download_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE `order_id` = %d AND `product_id` = %d AND `download_id` = '%s'", $subscription->id, $product_id, $download_id ) ) ) {
								wc_downloadable_file_permission( $download_id, $product_id, $subscription, $item['qty'] );
							}
							self::revoke_downloadable_file_permission( $product_id, $order_id, $order->user_id );
						}
					}
				}
			}
			update_post_meta( $subscription->id, '_download_permissions_granted', 1 );
		}
	}

	/**
	 * Revokes download permissions from permissions table if a file has permissions on a subscription. If a product has
	 * multiple files, all permissions will be revoked from the original order.
	 *
	 * @param int $product_id the ID for the product (the downloadable file)
	 * @param int $order_id the ID for the original order
	 * @param int $user_id the user we're removing the permissions from
	 * @return boolean true on success, false on error
	 */
	public static function revoke_downloadable_file_permission( $product_id, $order_id, $user_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';

		$where = array(
			'product_id' => $product_id,
			'order_id'   => $order_id,
			'user_id'    => $user_id,
		);

		$format = array( '%d', '%d', '%d' );

		return $wpdb->delete( $table, $where, $format );
	}

	/**
	 * WooCommerce's function receives the original order ID, the item and the list of files. This does not work for
	 * download permissions stored on the subscription rather than the original order as the URL would have the wrong order
	 * key. This function takes the same parameters, but queries the database again for download ids belonging to all the
	 * subscriptions that were in the original order. Then for all subscriptions, it checks all items, and if the item
	 * passed in here is in that subscription, it creates the correct download link to be passsed to the email.
	 *
	 * @param array $files List of files already included in the list
	 * @param array $item An item (you get it by doing $order->get_items())
	 * @param WC_Order $order The original order
	 * @return array List of files with correct download urls
	 */
	public static function get_item_downloads( $files, $item, $order ) {
		global $wpdb;

		if ( wcs_order_contains_subscription( $order, 'any' ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'any' ) ) );
		} else {
			return $files;
		}

		$product_id = wcs_get_canonical_product_id( $item );

		foreach ( $subscriptions as $subscription ) {
			foreach ( $subscription->get_items() as $subscription_item ) {
				if ( wcs_get_canonical_product_id( $subscription_item ) === $product_id ) {
					$files = $subscription->get_item_downloads( $subscription_item );
				}
			}
		}

		return $files;
	}

	/**
	 * Repairs a glitch in WordPress's save function. You cannot save a null value on update, see
	 * https://github.com/woothemes/woocommerce/issues/7861 for more info on this.
	 *
	 * @param integer $post_id The ID of the subscription
	 */
	public static function repair_permission_data( $post_id ) {
		if ( absint( $post_id ) !== $post_id ) {
			return;
		}

		if ( 'shop_subscription' !== get_post_type( $post_id ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query( $wpdb->prepare( "
			UPDATE {$wpdb->prefix}woocommerce_downloadable_product_permissions
			SET access_expires = null
			WHERE order_id = %d
			AND access_expires = %s
		", $post_id, '0000-00-00 00:00:00' ) );
	}

	/**
	 * Remove download permissions attached to a subscription when it is permenantly deleted.
	 *
	 * @since 2.0
	 */
	public static function delete_subscription_permissions( $post_id ) {
		global $wpdb;

		if ( 'shop_subscription' == get_post_type( $post_id ) ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d", $post_id ) );
		}
	}
}
WCS_Download_Handler::init();
