<?php
/**
 * Upgrade subscriptions data to v2.0
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_2_0 {

	/* Cache of order item meta keys that were used to store subscription data in v1.5 */
	private static $subscription_item_meta_keys = array(
		'_recurring_line_total',
		'_recurring_line_tax',
		'_recurring_line_subtotal',
		'_recurring_line_subtotal_tax',
		'_recurring_line_tax_data',
		'_subscription_suspension_count',
		'_subscription_period',
		'_subscription_interval',
		'_subscription_trial_length',
		'_subscription_trial_period',
		'_subscription_length',
		'_subscription_sign_up_fee',
		'_subscription_failed_payments',
		'_subscription_recurring_amount',
		'_subscription_start_date',
		'_subscription_trial_expiry_date',
		'_subscription_expiry_date',
		'_subscription_end_date',
		'_subscription_status',
		'_subscription_completed_payments',
	);

	/**
	 * Migrate subscriptions out of order item meta and into post/post meta tables for their own post type.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function upgrade_subscriptions( $batch_size ) {
		global $wpdb;

		WC()->payment_gateways();

		WCS_Upgrade_Logger::add( sprintf( 'Upgrading batch of %d subscriptions', $batch_size ) );

		$upgraded_subscription_count = 0;

		$execution_time_start = time();

		foreach ( self::get_subscriptions( $batch_size ) as $original_order_item_id => $old_subscription ) {

			try {

				$old_subscription = WCS_Repair_2_0::maybe_repair_subscription( $old_subscription, $original_order_item_id );

				// don't allow data to be half upgraded on a subscription (but we need the subscription to be the atomic level, not the whole batch, to ensure that resubscribe and switch updates in the same batch have the new subscription available)
				$wpdb->query( 'START TRANSACTION' );

				WCS_Upgrade_Logger::add( sprintf( 'For order %d: beginning subscription upgrade process', $old_subscription['order_id'] ) );

				$original_order = wc_get_order( $old_subscription['order_id'] );

				// If we're still in a prepaid term, the new subscription has the new pending cancellation status
				if ( 'cancelled' == $old_subscription['status'] && false != as_next_scheduled_action( 'scheduled_subscription_end_of_prepaid_term', array( 'user_id' => $old_subscription['user_id'], 'subscription_key' => $old_subscription['subscription_key'] ) ) ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					$subscription_status = 'pending-cancel';
				} elseif ( 'trash' == $old_subscription['status'] ) {
					$subscription_status = 'cancelled'; // we'll trash it properly after migrating it
				} else {
					$subscription_status = $old_subscription['status'];
				}

				// Create a new subscription for this user
				$new_subscription = wcs_create_subscription( array(
					'status'           => $subscription_status,
					'order_id'         => $old_subscription['order_id'],
					'customer_id'      => $old_subscription['user_id'],
					'start_date'       => $old_subscription['start_date'],
					'customer_note'    => ( '' !== wcs_get_objects_property( $original_order, 'customer_note' ) ) ? wcs_get_objects_property( $original_order, 'customer_note' ) : '',
					'billing_period'   => $old_subscription['period'],
					'billing_interval' => $old_subscription['interval'],
					'order_version'    => ( '' !== wcs_get_objects_property( $original_order, 'version' ) ) ? wcs_get_objects_property( $original_order, 'version' ) : '',  // Subscriptions will default to WC_Version if order's version is not set, but we want the version set at the time of the order
				) );

				if ( ! is_wp_error( $new_subscription ) ) {

					WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: post created', $new_subscription->get_id() ) );

					// Set the order to be manual
					if ( isset( $original_order->wcs_requires_manual_renewal ) && 'true' == $original_order->wcs_requires_manual_renewal ) {
						$new_subscription->set_requires_manual_renewal( true );
					}

					// Add the line item from the order
					$subscription_item_id = self::add_product( $new_subscription, $original_order_item_id, wcs_get_order_item( $original_order_item_id, $original_order ) );

					// Add the line item from the order
					self::migrate_download_permissions( $new_subscription, $subscription_item_id, $original_order );

					// Set dates on the subscription
					self::migrate_dates( $new_subscription, $old_subscription );

					// Set some meta from order meta
					self::migrate_post_meta( $new_subscription->get_id(), $original_order );

					// Copy over order notes which are now logged on the subscription
					self::migrate_order_notes( $new_subscription->get_id(), wcs_get_objects_property( $original_order, 'id' ) );

					// Migrate recurring tax, shipping and coupon line items to be plain line items on the subscription
					self::migrate_order_items( $new_subscription->get_id(), wcs_get_objects_property( $original_order, 'id' ) );

					// Update renewal orders to link via post meta key instead of post_parent column
					self::migrate_renewal_orders( $new_subscription, wcs_get_objects_property( $original_order, 'id' ) );

					// Make sure the resubscribe meta data is migrated to use the new subscription ID + meta key
					self::migrate_resubscribe_orders( $new_subscription, $original_order );

					// If the order for this subscription contains a switch, make sure the switch meta data is migrated to use the new subscription ID + meta key
					self::migrate_switch_meta( $new_subscription, $original_order, $subscription_item_id );

					// If the subscription was in the trash, now that we've set on the meta on it, we need to trash it
					if ( 'trash' == $old_subscription['status'] ) {
						wp_trash_post( $new_subscription->get_id() );
					}

					WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: upgrade complete', $new_subscription->get_id() ) );

				} else {

					self::deprecate_item_meta( $original_order_item_id );

					self::deprecate_post_meta( $old_subscription['order_id'] );

					WCS_Upgrade_Logger::add( sprintf( '!!! For order %d: unable to create subscription. Error: %s', $old_subscription['order_id'], $new_subscription->get_error_message() ) );

				}

				// If we got here, the batch was upgraded without problems
				$wpdb->query( 'COMMIT' );

				$upgraded_subscription_count++;

			} catch ( Exception $e ) {

				// We can still recover from here.
				if ( 422 == $e->getCode() ) {

					self::deprecate_item_meta( $original_order_item_id );

					self::deprecate_post_meta( $old_subscription['order_id'] );

					WCS_Upgrade_Logger::add( sprintf( '!!! For order %d: unable to create subscription. Error: %s', $old_subscription['order_id'], $e->getMessage() ) );

					$wpdb->query( 'COMMIT' );

					$upgraded_subscription_count++;

				} else {
					// we couldn't upgrade this subscription don't commit the query
					$wpdb->query( 'ROLLBACK' );

					throw $e;
				}
			}

			if ( $upgraded_subscription_count >= $batch_size || ( array_key_exists( 'WPENGINE_ACCOUNT', $_SERVER ) && ( time() - $execution_time_start ) > 50 ) ) {
				break;
			}
		}

		// Double check we actually have no more subscriptions to upgrade as sometimes they can fall through the cracks
		if ( $upgraded_subscription_count < $batch_size && $upgraded_subscription_count > 0 && ! array_key_exists( 'WPENGINE_ACCOUNT', $_SERVER ) ) {
			$upgraded_subscription_count += self::upgrade_subscriptions( $batch_size );
		}

		WCS_Upgrade_Logger::add( sprintf( 'Upgraded batch of %d subscriptions', $upgraded_subscription_count ) );

		return $upgraded_subscription_count;
	}

	/**
	 * Gets an array of subscriptions from the v1.5 database structure and returns them in the in the v1.5 structure of
	 * 'order_item_id' => subscription details array().
	 *
	 * The subscription will be orders from oldest to newest, which is important because self::migrate_resubscribe_orders()
	 * method expects a subscription to exist in order to migrate the resubscribe meta data correctly.
	 *
	 * @param int $batch_size The number of subscriptions to return.
	 * @return array Subscription details in the v1.5 structure of 'order_item_id' => array()
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function get_subscriptions( $batch_size ) {
		global $wpdb;

		$query = WC_Subscriptions_Upgrader::get_subscription_query( $batch_size );

		$wpdb->query( 'SET SQL_BIG_SELECTS = 1;' );

		$raw_subscriptions = $wpdb->get_results( $query );

		$subscriptions = array();

		// Create a backward compatible structure
		foreach ( $raw_subscriptions as $raw_subscription ) {

			if ( ! isset( $raw_subscription->order_item_id ) ) {
				continue;
			}

			if ( ! array_key_exists( $raw_subscription->order_item_id, $subscriptions ) ) {
				$subscriptions[ $raw_subscription->order_item_id ] = array(
					'order_id' => $raw_subscription->order_id,
					'name'     => $raw_subscription->order_item_name,
				);

				$subscriptions[ $raw_subscription->order_item_id ]['user_id'] = (int) get_post_meta( $raw_subscription->order_id, '_customer_user', true );
			}

			$meta_key = str_replace( '_subscription', '', $raw_subscription->meta_key );
			$meta_key = wcs_maybe_unprefix_key( $meta_key );

			if ( 'product_id' === $meta_key ) {
				$subscriptions[ $raw_subscription->order_item_id ]['subscription_key'] = $subscriptions[ $raw_subscription->order_item_id ]['order_id'] . '_' . $raw_subscription->meta_value;
			}

			$subscriptions[ $raw_subscription->order_item_id ][ $meta_key ] = maybe_unserialize( $raw_subscription->meta_value );
		}

		return $subscriptions;
	}

	/**
	 * Add the details of an order item to a subscription as a product line item.
	 *
	 * When adding a product to a subscription, we can't use WC_Abstract_Order::add_product() because it requires a product object
	 * and the details of the product may have changed since it was purchased so we can't simply instantiate an instance of the
	 * product based on ID.
	 *
	 * @param WC_Subscription $new_subscription A subscription object
	 * @param int $order_item_id ID of the subscription item on the original order
	 * @param array $order_item An array of order item data in the form returned by WC_Abstract_Order::get_items()
	 * @return int Subscription $item_id The order item id of the new line item added to the subscription.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function add_product( $new_subscription, $order_item_id, $order_item ) {
		global $wpdb;

		$item_id = wc_add_order_item( $new_subscription->get_id(), array(
			'order_item_name' => $order_item['name'],
			'order_item_type' => 'line_item',
		) );

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: new line item ID %d added', $new_subscription->get_id(), $item_id ) );

		$order_item = WCS_Repair_2_0::maybe_repair_order_item( $order_item );

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO `{$wpdb->prefix}woocommerce_order_itemmeta` (`order_item_id`, `meta_key`, `meta_value`)
			 VALUES
				(%d, '_qty', %s),
				(%d, '_tax_class', %s),
				(%d, '_product_id', %s),
				(%d, '_variation_id', %s),
				(%d, '_line_subtotal', %s),
				(%d, '_line_total', %s),
				(%d, '_line_subtotal_tax', %s),
				(%d, '_line_tax', %s)",
			// The substitutions
			$item_id, $order_item['qty'],
			$item_id, $order_item['tax_class'],
			$item_id, $order_item['product_id'],
			$item_id, $order_item['variation_id'],
			$item_id, $order_item['recurring_line_subtotal'],
			$item_id, $order_item['recurring_line_total'],
			$item_id, $order_item['recurring_line_subtotal_tax'],
			$item_id, $order_item['recurring_line_tax']
		) );

		// Save tax data array added in WC 2.2 (so it won't exist for all orders/subscriptions)
		self::add_line_tax_data( $item_id, $order_item_id, $order_item );

		if ( isset( $order_item['subscription_trial_length'] ) && $order_item['subscription_trial_length'] > 0 ) {
			wc_add_order_item_meta( $item_id, '_has_trial', 'true' );
		}

		// Don't copy item meta already copied
		$reserved_item_meta_keys = array(
			'_item_meta',
			'_qty',
			'_tax_class',
			'_product_id',
			'_variation_id',
			'_line_subtotal',
			'_line_total',
			'_line_tax',
			'_line_tax_data',
			'_line_subtotal_tax',
		);

		$meta_keys_to_copy = array_diff( array_keys( $order_item['item_meta'] ), array_merge( $reserved_item_meta_keys, self::$subscription_item_meta_keys ) );

		// Add variation and any other meta
		foreach ( $meta_keys_to_copy as $meta_key ) {
			foreach ( $order_item['item_meta'][ $meta_key ] as $meta_value ) {
				wc_add_order_item_meta( $item_id, $meta_key, $meta_value );
			}
		}

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: for item %d added %s', $new_subscription->get_id(), $item_id, implode( ', ', $meta_keys_to_copy ) ) );

		// Now that we've copied over the old data, prefix some the subscription meta keys with _wcs_migrated to deprecate it without deleting it (yet)
		$rows_affected = self::deprecate_item_meta( $order_item_id );

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: %s rows of line item meta deprecated', $new_subscription->get_id(), $rows_affected ) );

		return $item_id;
	}

	/**
	 * Copy or recreate line tax data to the new subscription.
	 *
	 * @param int $new_order_item_id ID of the line item on the new subscription post type
	 * @param int $old_order_item_id ID of the line item on the original order that in v1.5 represented the subscription
	 * @param array $order_item The line item on the original order that in v1.5 represented the subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function add_line_tax_data( $new_order_item_id, $old_order_item_id, $order_item ) {

		// If we have _recurring_line_tax_data, use that
		if ( isset( $order_item['item_meta']['_recurring_line_tax_data'] ) ) {

			$line_tax_data      = maybe_unserialize( $order_item['item_meta']['_recurring_line_tax_data'][0] );
			$recurring_tax_data = array(
				'total'    => array(),
				'subtotal' => array(),
			);
			$tax_data_keys      = array( 'total', 'subtotal' );

			foreach ( $tax_data_keys as $tax_data_key ) {
				foreach ( $line_tax_data[ $tax_data_key ] as $tax_index => $tax_value ) {
					$recurring_tax_data[ $tax_data_key ][ $tax_index ] = wc_format_decimal( $tax_value );
				}
			}

		// Otherwise try to calculate the recurring values from _line_tax_data
		} elseif ( isset( $order_item['item_meta']['_line_tax_data'] ) ) {

			// Copy line tax data if the order doesn't have a '_recurring_line_tax_data' (for backward compatibility)
			$line_tax_data        = maybe_unserialize( $order_item['item_meta']['_line_tax_data'][0] );
			$line_total           = maybe_unserialize( $order_item['item_meta']['_line_total'][0] );
			$recurring_line_total = maybe_unserialize( $order_item['item_meta']['_recurring_line_total'][0] );

			// There will only be recurring tax data if the recurring amount is > 0 and we can only retroactively calculate recurring amount from initial amount if it is > 0
			if ( $line_total > 0 && $recurring_line_total > 0 ) {

				// Make sure we account for any sign-up fees by determining what proportion of the initial amount the recurring total represents
				$recurring_ratio = $recurring_line_total / $line_total;

				$recurring_tax_data = array(
					'total'    => array(),
					'subtotal' => array(),
				);
				$tax_data_keys      = array( 'total', 'subtotal' );

				foreach ( $tax_data_keys as $tax_data_key ) {
					foreach ( $line_tax_data[ $tax_data_key ] as $tax_index => $tax_value ) {

						if ( $line_total != $recurring_line_total ) {
							// Use total tax amount for both total and subtotal because we don't want any initial discounts to be applied to recurring amounts
							$total_tax_amount = $line_tax_data['total'][ $tax_index ];
						} else {
							$total_tax_amount = $line_tax_data[ $tax_data_key ][ $tax_index ];
						}

						$recurring_tax_data[ $tax_data_key ][ $tax_index ] = wc_format_decimal( $recurring_ratio * $total_tax_amount );
					}
				}
			} elseif ( 0 == $line_total && $recurring_line_total > 0 ) { // free trial, we don't have the tax data but we can use 100% of line taxes

				// Can we derive the tax rate key from the line tax data?
				if ( ! empty( $line_tax_data ) && ! empty( $line_tax_data['total'] ) ) {
					$tax_rate_key = key( $line_tax_data['total'] );
				} else {
					// we have no way of knowing what the tax rate key is
					$tax_rate_key = 0;
				}

				$recurring_tax_data = array(
					'subtotal' => array( $tax_rate_key => $order_item['item_meta']['_recurring_line_subtotal_tax'][0] ),
					'total'    => array( $tax_rate_key => $order_item['item_meta']['_recurring_line_tax'][0] ),
				);
			} else {
				$recurring_tax_data = array(
					'total'    => array(),
					'subtotal' => array(),
				);
			}
		} else {
			$recurring_tax_data = array(
				'total'    => array(),
				'subtotal' => array(),
			);
		}

		return wc_add_order_item_meta( $new_order_item_id, '_line_tax_data', $recurring_tax_data, true );
	}

	/**
	 * Deprecate order item meta data stored on the original order that used to make up the subscription by prefixing it with with '_wcs_migrated'
	 *
	 * @param int $order_item_id ID of the subscription item on the original order
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function deprecate_item_meta( $order_item_id ) {
		global $wpdb;

		// Now that we've copied over the old data, prefix some the subscription meta keys with _wcs_migrated to deprecate it without deleting it (yet)
		$subscription_item_meta_key_string = implode( "','", esc_sql( self::$subscription_item_meta_keys ) );

		$rows_affected = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$wpdb->prefix}woocommerce_order_itemmeta` SET `meta_key` = concat( '_wcs_migrated', `meta_key` )
			WHERE `order_item_id` = %d AND `meta_key` IN ('{$subscription_item_meta_key_string}')",
			$order_item_id
		) );

		return $rows_affected;
	}

	/**
	 * Move download permissions from original order to the new subscription created for the order.
	 *
	 * @param WC_Subscription $subscription A subscription object
	 * @param int $subscription_item_id ID of the product line item on the subscription
	 * @param WC_Order $original_order The original order that was created to purchase the subscription
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_download_permissions( $subscription, $subscription_item_id, $order ) {
		global $wpdb;

		$product_id = wcs_get_canonical_product_id( wcs_get_order_item( $subscription_item_id, $subscription ) );

		$rows_affected = $wpdb->update(
			$wpdb->prefix . 'woocommerce_downloadable_product_permissions',
			array(
				'order_id'  => $subscription->get_id(),
				'order_key' => $subscription->get_order_key(),
			),
			array(
				'order_id'   => wcs_get_objects_property( $order, 'id' ),
				'order_key'  => wcs_get_objects_property( $order, 'order_key' ),
				'product_id' => $product_id,
				'user_id'    => absint( $subscription->get_user_id() ),
			),
			array( '%d', '%s' ),
			array( '%d', '%s', '%d', '%d' )
		);

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: migrated %d download permissions for product %d', $subscription->get_id(), $rows_affected, $product_id ) );
	}

	/**
	 * Migrate the trial expiration, next payment and expiration/end dates to a new subscription.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_dates( $new_subscription, $old_subscription ) {
		global $wpdb;

		$dates_to_update = array();

		// old hook => new hook
		$date_keys = array(
			'trial_end'           => array(
				'old_subscription_key' => 'trial_expiry_date',
				'old_scheduled_hook'   => 'scheduled_subscription_trial_end',
			),
			'end'                 => array(
				'old_subscription_key' => 'expiry_date',
				'old_scheduled_hook'   => 'scheduled_subscription_expiration',
			),
			'end_date'            => array(
				'old_subscription_key' => '_subscription_end_date', // this is the actual end date, not just the date it was scheduled to expire
				'old_scheduled_hook'   => '',
			),
			'next_payment'        => array(
				'old_subscription_key' => '',
				'old_scheduled_hook'   => 'scheduled_subscription_payment',
			),
			'end_of_prepaid_term' => array(
				'old_subscription_key' => '',
				'old_scheduled_hook'   => 'scheduled_subscription_end_of_prepaid_term',
			),
		);

		$old_hook_args = array(
			'user_id'          => $old_subscription['user_id'],
			'subscription_key' => $old_subscription['subscription_key'],
		);

		foreach ( $date_keys as $new_key => $old_keys ) {

			// First check if there is a date stored on the subscription, and if so, use that
			if ( ! empty( $old_keys['old_subscription_key'] ) && ( isset( $old_subscription[ $old_keys['old_subscription_key'] ] ) && 0 !== $old_subscription[ $old_keys['old_subscription_key'] ] ) ) {

				$dates_to_update[ $new_key ] = $old_subscription[ $old_keys['old_subscription_key'] ];

			} elseif ( ! empty( $old_keys['old_scheduled_hook'] ) ) {

				// Now check if there is a scheduled date, this is for next payment and end of prepaid term dates
				$next_scheduled = as_next_scheduled_action( $old_keys['old_scheduled_hook'], $old_hook_args );

				if ( $next_scheduled > 0 ) {

					if ( 'end_of_prepaid_term' == $new_key ) {
						as_schedule_single_action( $next_scheduled, 'woocommerce_scheduled_subscription_end_of_prepaid_term', array( 'subscription_id' => $new_subscription->get_id() ) );
					} else {
						$dates_to_update[ $new_key ] = gmdate( 'Y-m-d H:i:s', $next_scheduled );
					}
				}
			}
		}

		// Trash all the hooks in one go to save write requests
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_status' => 'trash',
			),
			array(
				'post_type'    => ActionScheduler_wpPostStore::POST_TYPE,
				'post_content' => wcs_json_encode( $old_hook_args ),
			),
			array( '%s', '%s' )
		);

		$dates_to_update['date_created'] = $new_subscription->post->post_date_gmt;

		// v2.0 enforces new rules for dates when they are being set, so we need to massage the old data to conform to these new rules
		foreach ( $dates_to_update as $date_type => $date ) {

			if ( 0 == $date ) {
				continue;
			}

			switch ( $date_type ) {
				case 'end':
					if ( array_key_exists( 'next_payment', $dates_to_update ) && $date <= $dates_to_update['next_payment'] ) {
						$dates_to_update[ $date_type ] = $date;
					}
				case 'next_payment':
					if ( array_key_exists( 'trial_end', $dates_to_update ) && $date < $dates_to_update['trial_end'] ) {
						$dates_to_update[ $date_type ] = $date;
					}
				case 'trial_end':
					if ( array_key_exists( 'date_created', $dates_to_update ) && $date <= $dates_to_update['date_created'] ) {
						$dates_to_update[ $date_type ] = $date;
					}
			}
		}

		try {

			if ( ! empty( $dates_to_update ) ) {
				$new_subscription->update_dates( $dates_to_update );
			}

			WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: updated dates = %s', $new_subscription->get_id(), str_replace( array( '{', '}', '"' ), '', wcs_json_encode( $dates_to_update ) ) ) );

		} catch ( Exception $e ) {
			WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: unable to update dates, exception "%s"', $new_subscription->get_id(), $e->getMessage() ) );
		}
	}

	/**
	 * Copy an assortment of meta data from the original order's post meta table to the new subscription's post meta table.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post type
	 * @param WC_Order $order The original order used to purchase a subscription
	 * @return null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_post_meta( $subscription_id, $order ) {
		global $wpdb;

		// Form: new meta key => old meta key
		$post_meta_with_new_key = array(
			// Order totals
			'_order_total'                  => '_order_recurring_total',
			'_order_tax'                    => '_order_recurring_tax_total',
			'_order_shipping'               => '_order_recurring_shipping_total',
			'_order_shipping_tax'           => '_order_recurring_shipping_tax_total',
			'_cart_discount'                => '_order_recurring_discount_cart',
			'_cart_discount_tax'            => '_order_recurring_discount_cart_tax',
			'_order_discount'               => '_order_recurring_discount_total', // deprecated since WC 2.3

			// Misc meta data
			'_payment_method'               => '_recurring_payment_method',
			'_payment_method_title'         => '_recurring_payment_method_title',
			'_suspension_count'             => '_subscription_suspension_count',
			'_contains_synced_subscription' => '_order_contains_synced_subscription',
			'_paypal_subscription_id'       => 'PayPal Subscriber ID',
		);

		$order_meta = get_post_meta( wcs_get_objects_property( $order, 'id' ) );

		foreach ( $post_meta_with_new_key as $subscription_meta_key => $order_meta_key ) {

			$order_meta_value = get_post_meta( wcs_get_objects_property( $order, 'id' ), $order_meta_key, true );

			if ( isset( $order_meta[ $order_meta_key ] ) && '' !== $order_meta[ $order_meta_key ] ) {
				update_post_meta( $subscription_id, $subscription_meta_key, $order_meta_value );
			}
		}

		// Don't copy any of the data we've already copied or known data which isn't relevant to a subscription
		$meta_keys_to_ignore = array_merge( array_values( $post_meta_with_new_key ), array_keys( $post_meta_with_new_key ), array(
			'_completed_date',
			'_customer_ip_address',
			'_customer_user_agent',
			'_customer_user',
			'_order_currency',
			'_order_key',
			'_paid_date',
			'_recorded_sales',
			'_transaction_id',
			'_transaction_id_original',
			'_switched_subscription_first_payment_timestamp',
			'_switched_subscription_new_order',
			'_switched_subscription_key',
			'_old_recurring_payment_method',
			'_old_recurring_payment_method_title',
			'_wc_points_earned',
			'_wcs_requires_manual_renewal',
		) );

		// Also allow extensions to unset or modify data that will be copied
		$order_meta = apply_filters( 'wcs_upgrade_subscription_meta_to_copy', $order_meta, $subscription_id, $order );

		// Prepare the meta data for a bulk insert
		$query_meta_values  = array();
		$query_placeholders = array();

		foreach ( $order_meta as $meta_key => $meta_value ) {
			if ( ! in_array( $meta_key, $meta_keys_to_ignore ) ) {
				$query_meta_values = array_merge( $query_meta_values, array(
					$subscription_id,
					$meta_key,
					$meta_value[0],
				) );
				$query_placeholders[] = '(%d, %s, %s)';
			}
		}

		// Do a single bulk insert instead of using update_post_meta() to massively reduce query time
		if ( ! empty( $query_meta_values ) ) {
			$rows_affected = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
				 VALUES " . implode( ', ', $query_placeholders ),
				$query_meta_values
			) );

			WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: %d rows of post meta added', $subscription_id, $rows_affected ) );
		}

		// Now that we've copied over the old data, deprecate it
		$rows_affected = self::deprecate_post_meta( wcs_get_objects_property( $order, 'id' ) );

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: %d rows of post meta deprecated', $subscription_id, $rows_affected ) );
	}

	/**
	 * Deprecate post meta data stored on the original order that used to make up the subscription by prefixing it with with '_wcs_migrated'
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post type
	 * @param WC_Order $order The original order used to purchase a subscription
	 * @return null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function deprecate_post_meta( $order_id ) {
		global $wpdb;

		$post_meta_to_deprecate = array(
			// Order totals
			'_order_recurring_total',
			'_order_recurring_tax_total',
			'_order_recurring_shipping_total',
			'_order_recurring_shipping_tax_total',
			'_order_recurring_discount_cart',
			'_order_recurring_discount_cart_tax',
			'_order_recurring_discount_total',
			'_recurring_payment_method',
			'_recurring_payment_method_title',
			'_old_paypal_subscriber_id',
			'_old_payment_method',
			'_paypal_ipn_tracking_ids',
			'_paypal_transaction_ids',
			'_paypal_first_ipn_ignored_for_pdt',
			'_order_contains_synced_subscription',
			'_subscription_suspension_count',
		);

		$post_meta_to_deprecate = implode( "','", esc_sql( $post_meta_to_deprecate ) );

		$rows_affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET `meta_key` = concat( '_wcs_migrated', `meta_key` )
			WHERE `post_id` = %d AND `meta_key` IN ('{$post_meta_to_deprecate}')",
			$order_id
		) );

		return $rows_affected;
	}

	/**
	 * Migrate order notes relating to subscription events to the new subscription as these are now logged on the subscription
	 * not the order.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post type
	 * @param WC_Order $order The original order used to purchase a subscription
	 * @return null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_order_notes( $subscription_id, $order_id ) {
		global $wpdb;

		$rows_affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->comments} SET `comment_post_ID` = %d
			WHERE `comment_post_id` = %d
			AND (
				`comment_content` LIKE '%%subscription%%'
				OR `comment_content` LIKE '%%Recurring%%'
				OR `comment_content` LIKE '%%Renewal%%'
				OR `comment_content` LIKE '%%Simplify payment error%%'
			)",
			$subscription_id, $order_id
		) );

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: migrated %d order notes', $subscription_id, $rows_affected ) );
	}

	/**
	 * Migrate recurring_tax, recurring_shipping and recurring_coupon line items to be plain tax, shipping and coupon line
	 * items on a subscription.
	 *
	 * @param int $subscription_id The ID of a 'shop_subscription' post type
	 * @param WC_Order $order The original order used to purchase a subscription
	 * @return null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_order_items( $subscription_id, $order_id ) {
		global $wpdb;

		foreach ( array( 'tax', 'shipping', 'coupon' ) as $line_item_type ) {
			$rows_affected = $wpdb->update(
				$wpdb->prefix . 'woocommerce_order_items',
				array(
					'order_item_type' => $line_item_type,
					'order_id'        => $subscription_id,
				),
				array(
					'order_item_type' => 'recurring_' . $line_item_type,
					'order_id'        => $order_id,
				),
				array( '%s', '%d' ),
				array( '%s', '%d' )
			);

			WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: migrated %d %s item/s', $subscription_id, $rows_affected, $line_item_type ) );
		}
	}

	/**
	 * The 'post_parent' column is no longer used to relate a renewal order with a subscription/order, instead, we use a
	 * '_subscription_renewal' post meta value, so the 'post_parent' of all renewal orders needs to be changed from the original
	 * order's ID, to 0, and then the new subscription's ID should be set as the '_subscription_renewal' post meta value on
	 * the renewal order.
	 *
	 * @param WC_Subscription $subscription An instance of a 'shop_subscription' post type
	 * @param int $order_id The ID of a 'shop_order' which created this susbcription
	 * @return null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_renewal_orders( $subscription, $order_id ) {
		global $wpdb;

		// Get the renewal order IDs
		$renewal_order_ids = get_posts( array(
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'post_type'      => 'shop_order',
			'post_parent'    => $order_id,
			'fields'         => 'ids',
		) );

		// Set the post meta
		foreach ( $renewal_order_ids as $renewal_order_id ) {
			WCS_Related_Order_Store::instance()->add_relation( wc_get_order( $renewal_order_id ), $subscription, 'renewal' );
		}

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: migrated data for renewal orders %s', $subscription->get_id(), implode( ', ', $renewal_order_ids ) ) );

		$rows_affected = $wpdb->update(
			$wpdb->posts,
			array(
				'post_parent' => 0,
			),
			array(
				'post_parent' => $order_id,
				'post_type'   => 'shop_order',
			),
			array( '%d' ),
			array( '%d', '%s' )
		);

		WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: %d rows of renewal order post_parent values changed', $subscription->get_id(), count( $renewal_order_ids ) ) );
	}

	/**
	 * The '_original_order' post meta value is no longer used to relate a resubscribe order with a subscription/order, instead, we use
	 * a '_subscription_resubscribe' post meta value, so the '_original_order' of all resubscribe orders needs to be changed from the
	 * original order's ID, to 0, and then the new subscription's ID should be set as the '_subscription_resubscribe' post meta value
	 * on the resubscribe order.
	 *
	 * @param WC_Subscription $new_subscription An instance of a 'shop_subscription' post type
	 * @param WC_Order $resubscribe_order An instance of a 'shop_order' post type which created this subscription
	 * @return null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_resubscribe_orders( $new_subscription, $resubscribe_order ) {
		global $wpdb;

		$resubscribe_order_id = wcs_get_objects_property( $resubscribe_order, 'id' );
		$new_subscription_id  = wcs_get_objects_property( $new_subscription, 'id' );

		// Set the post meta on the new subscription and old order
		foreach ( get_post_meta( $resubscribe_order_id, '_original_order', false ) as $original_order_id ) {

			// Because self::get_subscriptions() orders by order ID, it's safe to use wcs_get_subscriptions_for_order() here because the subscription in the new format will have been created for the original order (because its ID will be < the resubscribe order's ID)
			foreach ( wcs_get_subscriptions_for_order( $original_order_id ) as $old_subscription ) {
				WCS_Related_Order_Store::instance()->add_relation( $resubscribe_order, $old_subscription, 'resubscribe' );
				WCS_Related_Order_Store::instance()->add_relation( $new_subscription, $old_subscription, 'resubscribe' );
			}

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET `meta_key` = concat( '_wcs_migrated', `meta_key` )
				WHERE `post_id` = %d AND `meta_key` = '_original_order'",
				$resubscribe_order_id
			) );

			WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: migrated data for resubscribe order %d', $new_subscription_id, $original_order_id ) );
		}
	}

	/**
	 * The '_switched_subscription_key' and '_switched_subscription_new_order' post meta values are no longer used to relate orders
	 * and switched subscriptions, instead, we need to set a '_subscription_switch' value on the switch order and depreacted the old
	 * meta keys by prefixing them with '_wcs_migrated'.
	 *
	 * Subscriptions also sets a '_switched_subscription_item_id' value on the new line item of for the switched item and a item meta
	 * value of '_switched_subscription_new_item_id' on the old line item on the subscription, but the old switching process didn't
	 * change order items, it just created a new order with the new item, so we won't bother setting this as it is purely for record
	 * keeping.
	 *
	 * @param WC_Subscription $new_subscription A subscription object
	 * @param WC_Order $switch_order The original order used to purchase the subscription
	 * @param int $subscription_item_id The order item ID of the item added to the subscription by self::add_product()
	 * @return null
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	private static function migrate_switch_meta( $new_subscription, $switch_order, $subscription_item_id ) {
		global $wpdb;

		// If the order doesn't contain a switch, we don't need to do anything
		if ( '' == get_post_meta( wcs_get_objects_property( $switch_order, 'id' ), '_switched_subscription_key', true ) ) {
			return;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET `meta_key` = concat( '_wcs_migrated', `meta_key` )
			WHERE `post_id` = %d AND `meta_key` IN ('_switched_subscription_first_payment_timestamp','_switched_subscription_key')",
			wcs_get_objects_property( $switch_order, 'id' )
		) );

		// Select the orders which had the items which were switched by this order
		$previous_order_id = get_posts(
			array(
				'post_type'      => 'shop_order',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_switched_subscription_new_order',
						'value' => wcs_get_objects_property( $switch_order, 'id' ),
					),
				),
			)
		);

		if ( ! empty( $previous_order_id ) ) {

			$previous_order_id = $previous_order_id[0];

			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET `meta_key` = concat( '_wcs_migrated', `meta_key` )
				WHERE `post_id` = %d AND `meta_key` = '_switched_subscription_new_order'",
				$previous_order_id
			) );

			// Because self::get_subscriptions() orders by order ID, it's safe to use wcs_get_subscriptions_for_order() here because the subscription in the new format will have been created for the original order (because its ID will be < the switch order's ID)
			$old_subscriptions = wcs_get_subscriptions_for_order( $previous_order_id );
			$old_subscription  = array_shift( $old_subscriptions ); // there can be only one

			if ( wcs_is_subscription( $old_subscription ) ) {
				// Link the old subscription's ID to the switch order using the new switch meta key

				WCS_Related_Order_Store::instance()->add_relation( $switch_order, $old_subscription, 'switch' );

				// Now store the new/old item IDs for record keeping
				foreach ( $old_subscription->get_items() as $item_id => $item ) {
					wc_add_order_item_meta( $item_id, '_switched_subscription_new_item_id', $subscription_item_id, true );
					wc_add_order_item_meta( $subscription_item_id, '_switched_subscription_item_id', $item_id, true );
				}

				WCS_Upgrade_Logger::add( sprintf( 'For subscription %d: migrated switch data for subscription %d purchased in order %d', $new_subscription->get_id(), $old_subscription->get_id(), $previous_order_id ) );
			}
		}
	}
}
