<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\Utilities;

use WC_Cache_Helper;
use WC_Coupon;
use WC_Data_Store;
use WP_Post;

/**
 * Utilities for working with coupons.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Coupons {

	/**
	 * Get a coupon by a code stored on a subscription or order, discarding a stale code-to-ID cache entry first.
	 *
	 * This is a compatibility shim for WooCommerce 11.2 and earlier. Those versions cache the coupon code-to-ID
	 * lookup without an expiry and only clear it when a coupon is deleted or unpublished through the coupon data
	 * store. A coupon removed another way, such as "Delete permanently" or "Empty Trash" on the Coupons list,
	 * leaves the cached ID behind. On a store with a persistent object cache, new WC_Coupon( $code ) then throws
	 * "Invalid coupon." for that code on every request until the cache is flushed, and a trashed coupon leaves an
	 * ID that still resolves to the trashed post.
	 *
	 * WooCommerce 11.3 and later clear and validate the entry themselves, so there this method only builds the
	 * coupon. It can be retired once the minimum supported WooCommerce version reaches 11.3.
	 *
	 * @since 9.4.0
	 *
	 * @param string $code The coupon code.
	 *
	 * @return WC_Coupon The coupon, with an ID of 0 when the code does not resolve to a published coupon.
	 */
	public static function get_by_code( $code ): WC_Coupon {
		$code = (string) $code;

		if ( ! class_exists( '\Automattic\WooCommerce\Internal\Caches\CouponCodeLookupInvalidator' ) ) {
			self::discard_stale_lookup_entry( $code );
		}

		return new WC_Coupon( $code );
	}

	/**
	 * Delete the cached code-to-ID entry for a coupon code when any coupon it points at is gone or unpublished.
	 *
	 * Only the cache is read, so a code with no cached entry, such as a virtual coupon served through the
	 * 'woocommerce_get_shop_coupon_data' filter, costs no database query.
	 *
	 * @param string $code The coupon code.
	 *
	 * @return void
	 */
	private static function discard_stale_lookup_entry( string $code ): void {
		// The same key wc_get_coupon_id_by_code() reads and WC_Coupon_Data_Store_CPT::delete() clears.
		$cache_key = WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_' . md5( wc_strtolower( $code ) );
		$ids       = wp_cache_get( $cache_key, 'coupons' );

		if ( false === $ids ) {
			return;
		}

		// The staleness rule describes what the core data store caches. Entries written by any other coupon data store are left alone.
		if ( 'WC_Coupon_Data_Store_CPT' !== WC_Data_Store::load( 'coupon' )->get_current_class_name() ) {
			return;
		}

		if ( self::is_lookup_entry_stale( (array) $ids ) ) {
			wp_cache_delete( $cache_key, 'coupons' );
		}
	}

	/**
	 * Whether a cached code-to-ID entry points at any coupon that no longer resolves as a published coupon.
	 *
	 * Mirrors WooCommerce 11.3's CouponCodeLookupInvalidator::is_lookup_entry_stale(): every ID in the entry
	 * must be a published shop_coupon post, and an entry with no usable ID is stale.
	 *
	 * @param array $ids The cached coupon IDs.
	 *
	 * @return bool
	 */
	private static function is_lookup_entry_stale( array $ids ): bool {
		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return true;
		}

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( ! $post instanceof WP_Post || 'shop_coupon' !== $post->post_type || 'publish' !== $post->post_status ) {
				return true;
			}
		}

		return false;
	}
}
