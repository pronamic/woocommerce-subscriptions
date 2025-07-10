<?php
/**
 * Download Handler for WooCommerce Subscriptions
 *
 * Functions for download related things within the Subscription Extension.
 *
 * @package    WooCommerce Subscriptions
 * @subpackage WCS_Download_Handler
 * @category   Class
 * @author     Prospress
 * @since      1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Download_Handler {

	/**
	 * Initialize filters and hooks for class.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function init() {
		add_action( 'woocommerce_grant_product_download_permissions', __CLASS__ . '::save_downloadable_product_permissions' );

		add_filter( 'woocommerce_get_item_downloads', __CLASS__ . '::get_item_downloads', 10, 3 );

		add_action( 'woocommerce_process_shop_order_meta', __CLASS__ . '::repair_permission_data', 60, 1 );

		add_action( 'woocommerce_admin_created_subscription', array( __CLASS__, 'grant_download_permissions' ) );

		add_action( 'woocommerce_loaded', [ __CLASS__, 'attach_wc_dependent_hooks' ] );

		add_action( 'woocommerce_process_product_file_download_paths', __CLASS__ . '::grant_new_file_product_permissions', 11, 3 );
	}

	/**
	 * Attach hooks that depend on WooCommerce being loaded.
	 *
	 * @since 5.2
	 */
	public static function attach_wc_dependent_hooks() {
		if ( wcs_is_custom_order_tables_usage_enabled() ) {
			add_action( 'woocommerce_delete_subscription', [ __CLASS__, 'delete_subscription_download_permissions' ] );
		} else {
			add_action( 'deleted_post', [ __CLASS__, 'delete_subscription_permissions' ] );
		}
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
					$_product = $item->get_product();

					if ( $_product && $_product->exists() && $_product->is_downloadable() ) {
						$downloads  = wcs_get_objects_property( $_product, 'downloads' );
						$product_id = wcs_get_canonical_product_id( $item );

						foreach ( array_keys( $downloads ) as $download_id ) {
							// grant access on subscription if it does not already exist
							if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT download_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE `order_id` = %d AND `product_id` = %d AND `download_id` = %s", $subscription->get_id(), $product_id, $download_id ) ) ) {
								wc_downloadable_file_permission( $download_id, $product_id, $subscription, $item['qty'] );
							}
							self::revoke_downloadable_file_permission( $product_id, $order_id, $order->get_user_id() );
						}
					}
				}
			}

			$subscription->get_data_store()->set_download_permissions_granted( $subscription, true );
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
	 * passed in here is in that subscription, it creates the correct download link to be passed to the email.
	 *
	 * @param array $files List of files already included in the list
	 * @param array $item An item (you get it by doing $order->get_items())
	 * @param WC_Order $order The original order
	 * @return array List of files with correct download urls
	 */
	public static function get_item_downloads( $files, $item, $order ) {
		global $wpdb;

		if ( wcs_order_contains_subscription( $order, array( 'parent', 'renewal', 'switch' ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal', 'switch' ) ) );
		} else {
			return $files;
		}

		$product_id = wcs_get_canonical_product_id( $item );

		foreach ( $subscriptions as $subscription ) {
			foreach ( $subscription->get_items() as $subscription_item ) {
				if ( wcs_get_canonical_product_id( $subscription_item ) === $product_id ) {
					if ( is_callable( array( $subscription_item, 'get_item_downloads' ) ) ) { // WC 3.0+
						$files = $subscription_item->get_item_downloads( $subscription_item );
					} else { // WC < 3.0
						$files = $subscription->get_item_downloads( $subscription_item );
					}
				}
			}
		}

		return $files;
	}

	/**
	 * Repairs a glitch in WordPress's save function. You cannot save a null value on update, see
	 * https://github.com/woocommerce/woocommerce/issues/7861 for more info on this.
	 *
	 * @param integer $id The ID of the subscription
	 */
	public static function repair_permission_data( $id ) {
		if ( absint( $id ) !== $id ) {
			return;
		}

		if ( 'shop_subscription' !== WC_Data_Store::load( 'subscription' )->get_order_type( $id ) ) {
			return;
		}

		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"
				UPDATE {$wpdb->prefix}woocommerce_downloadable_product_permissions
				SET access_expires = null
				WHERE order_id = %d
				AND access_expires = %s
				",
				$id,
				'0000-00-00 00:00:00'
			)
		);
	}

	/**
	 * Gives customers access to downloadable products in a subscription.
	 * Hooked into 'woocommerce_admin_created_subscription' to grant permissions to admin created subscriptions.
	 *
	 * @param WC_Subscription $subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.2
	 */
	public static function grant_download_permissions( $subscription ) {
		wc_downloadable_product_permissions( $subscription->get_id() );
	}

	/**
	 * Remove download permissions attached to a subscription when it is permanently deleted.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 *
	 * @param $id The ID of the subscription whose downloadable product permission being deleted.
	 */
	public static function delete_subscription_permissions( $id ) {
		if ( 'shop_subscription' === WC_Data_Store::load( 'subscription' )->get_order_type( $id ) ) {
			self::delete_subscription_download_permissions( $id );
		}
	}

	/**
	 * Remove download permissions attached to a subscription when it is permanently deleted.
	 *
	 * @since 5.2.0
	 *
	 * @param $id The ID of the subscription whose downloadable product permission being deleted.
	 */
	public static function delete_subscription_download_permissions( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE order_id = %d", $id ) );
	}

	/**
	 * Grant downloadable file access to any newly added files on any existing subscriptions
	 * which don't have existing permissions pre WC3.0 and all subscriptions post WC3.0.
	 *
	 * @param int $product_id
	 * @param int $variation_id
	 * @param array $downloadable_files product downloadable files
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0.18
	 */
	public static function grant_new_file_product_permissions( $product_id, $variation_id, $downloadable_files ) {
		global $wpdb;

		$product_id            = ( $variation_id ) ? $variation_id : $product_id;
		$product               = wc_get_product( $product_id );
		$existing_download_ids = array_keys( (array) wcs_get_objects_property( $product, 'downloads' ) );
		$downloadable_ids      = array_keys( (array) $downloadable_files );
		$new_download_ids      = array_filter( array_diff( $downloadable_ids, $existing_download_ids ) );

		if ( ! empty( $new_download_ids ) ) {

			$existing_permissions = $wpdb->get_results( $wpdb->prepare( "SELECT order_id, download_id from {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE product_id = %d", $product_id ) );
			$subscriptions        = wcs_get_subscriptions_for_product( $product_id );

			// Arrange download id permissions by order id
			$permissions_by_order_id = array();

			foreach ( $existing_permissions as $permission_data ) {

				$permissions_by_order_id[ $permission_data->order_id ][] = $permission_data->download_id;
			}

			foreach ( $subscriptions as $subscription_id ) {

				// Grant permissions to subscriptions which have no permissions for this product, pre WC3.0, or all subscriptions, post WC3.0, as WC doesn't grant them retrospectively anymore.
				if ( ! in_array( $subscription_id, array_keys( $permissions_by_order_id ) ) || false === wcs_is_woocommerce_pre( '3.0' ) ) {
					$subscription = wcs_get_subscription( $subscription_id );

					foreach ( $new_download_ids as $download_id ) {

						$has_permission = isset( $permissions_by_order_id[ $subscription_id ] ) && in_array( $download_id, $permissions_by_order_id[ $subscription_id ] );

						if ( $subscription && ! $has_permission && apply_filters( 'woocommerce_process_product_file_download_paths_grant_access_to_new_file', true, $download_id, $product_id, $subscription ) ) {
							wc_downloadable_file_permission( $download_id, $product_id, $subscription );
						}
					}
				}
			}
		}
	}

	/**
	 * When adding new downloadable content to a subscription product, check if we don't
	 * want to automatically add the new downloadable files to the subscription or initial and renewal orders.
	 *
	 * @deprecated 1.0.0 - Migrated from WooCommerce Subscriptions v4.0.0
	 *
	 * @param bool $grant_access
	 * @param string $download_id
	 * @param int $product_id
	 * @param WC_Order $order
	 * @return bool
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function maybe_revoke_immediate_access( $grant_access, $download_id, $product_id, $order ) {
		wcs_deprecated_function( __METHOD__, '4.0.0', 'WCS_Drip_Downloads_Manager::maybe_revoke_immediate_access() if available' );

		if ( class_exists( 'WCS_Drip_Downloads_Manager' ) ) {
			return WCS_Drip_Downloads_Manager::maybe_revoke_immediate_access( $grant_access, $download_id, $product_id, $order );
		}

		return $grant_access;
	}
}
