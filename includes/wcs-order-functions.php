<?php
/**
 * WooCommerce Subscriptions Order Functions
 *
 * @author 		Prospress
 * @category 	Core
 * @package 	WooCommerce Subscriptions/Functions
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * A wrapper for @see wcs_get_subscriptions() which accepts simply an order ID
 *
 * @param int|WC_Order $order_id The post_id of a shop_order post or an instance of a WC_Order object
 * @param array $args A set of name value pairs to filter the returned value.
 *		'subscriptions_per_page' The number of subscriptions to return. Default set to -1 to return all.
 *		'offset' An optional number of subscription to displace or pass over. Default 0.
 *		'orderby' The field which the subscriptions should be ordered by. Can be 'start_date', 'trial_end_date', 'end_date', 'status' or 'order_id'. Defaults to 'start_date'.
 *		'order' The order of the values returned. Can be 'ASC' or 'DESC'. Defaults to 'DESC'
 *		'customer_id' The user ID of a customer on the site.
 *		'product_id' The post ID of a WC_Product_Subscription, WC_Product_Variable_Subscription or WC_Product_Subscription_Variation object
 *		'order_id' The post ID of a shop_order post/WC_Order object which was used to create the subscription
 *		'subscription_status' Any valid subscription status. Can be 'any', 'active', 'cancelled', 'suspended', 'expired', 'pending' or 'trash'. Defaults to 'any'.
 *		'order_type' Get subscriptions for the any order type in this array. Can include 'any', 'parent', 'renewal' or 'switch', defaults to parent.
 * @return array Subscription details in post_id => WC_Subscription form.
 * @since  2.0
 */
function wcs_get_subscriptions_for_order( $order_id, $args = array() ) {

	if ( is_object( $order_id ) ) {
		$order_id = wcs_get_objects_property( $order_id, 'id' );
	}

	$args = wp_parse_args( $args, array(
			'order_id'               => $order_id,
			'subscriptions_per_page' => -1,
			'order_type'             => array( 'parent', 'switch' ),
		)
	);

	// Accept either an array or string (to make it more convenient for singular types, like 'parent' or 'any')
	if ( ! is_array( $args['order_type'] ) ) {
		$args['order_type'] = array( $args['order_type'] );
	}

	$subscriptions = array();
	$get_all       = ( in_array( 'any', $args['order_type'] ) ) ? true : false;

	if ( $order_id && in_array( 'parent', $args['order_type'] ) || $get_all ) {
		$subscriptions = wcs_get_subscriptions( $args );
	}

	if ( wcs_order_contains_resubscribe( $order_id ) && ( in_array( 'resubscribe', $args['order_type'] ) || $get_all ) ) {
		$subscriptions += wcs_get_subscriptions_for_resubscribe_order( $order_id );
	}

	if ( wcs_order_contains_renewal( $order_id ) && ( in_array( 'renewal', $args['order_type'] ) || $get_all ) ) {
		$subscriptions += wcs_get_subscriptions_for_renewal_order( $order_id );
	}

	if ( wcs_order_contains_switch( $order_id ) && ( in_array( 'switch', $args['order_type'] ) || $get_all ) ) {
		$subscriptions += wcs_get_subscriptions_for_switch_order( $order_id );
	}

	return $subscriptions;
}

/**
 * Copy the billing, shipping or all addresses from one order to another (including custom order types, like the
 * WC_Subscription order type).
 *
 * @param WC_Order $to_order The WC_Order object to copy the address to.
 * @param WC_Order $from_order The WC_Order object to copy the address from.
 * @param string $address_type The address type to copy, can be 'shipping', 'billing' or 'all'
 * @return WC_Order The WC_Order object with the new address set.
 * @since  2.0
 */
function wcs_copy_order_address( $from_order, $to_order, $address_type = 'all' ) {

	if ( in_array( $address_type, array( 'shipping', 'all' ) ) ) {
		$to_order->set_address( array(
			'first_name' => wcs_get_objects_property( $from_order, 'shipping_first_name' ),
			'last_name'  => wcs_get_objects_property( $from_order, 'shipping_last_name' ),
			'company'    => wcs_get_objects_property( $from_order, 'shipping_company' ),
			'address_1'  => wcs_get_objects_property( $from_order, 'shipping_address_1' ),
			'address_2'  => wcs_get_objects_property( $from_order, 'shipping_address_2' ),
			'city'       => wcs_get_objects_property( $from_order, 'shipping_city' ),
			'state'      => wcs_get_objects_property( $from_order, 'shipping_state' ),
			'postcode'   => wcs_get_objects_property( $from_order, 'shipping_postcode' ),
			'country'    => wcs_get_objects_property( $from_order, 'shipping_country' ),
		), 'shipping' );
	}

	if ( in_array( $address_type, array( 'billing', 'all' ) ) ) {
		$to_order->set_address( array(
			'first_name' => wcs_get_objects_property( $from_order, 'billing_first_name' ),
			'last_name'  => wcs_get_objects_property( $from_order, 'billing_last_name' ),
			'company'    => wcs_get_objects_property( $from_order, 'billing_company' ),
			'address_1'  => wcs_get_objects_property( $from_order, 'billing_address_1' ),
			'address_2'  => wcs_get_objects_property( $from_order, 'billing_address_2' ),
			'city'       => wcs_get_objects_property( $from_order, 'billing_city' ),
			'state'      => wcs_get_objects_property( $from_order, 'billing_state' ),
			'postcode'   => wcs_get_objects_property( $from_order, 'billing_postcode' ),
			'country'    => wcs_get_objects_property( $from_order, 'billing_country' ),
			'email'      => wcs_get_objects_property( $from_order, 'billing_email' ),
			'phone'      => wcs_get_objects_property( $from_order, 'billing_phone' ),
		), 'billing' );
	}

	return apply_filters( 'woocommerce_subscriptions_copy_order_address', $to_order, $from_order, $address_type );
}

/**
 * Utility function to copy order meta between two orders. Originally intended to copy meta between
 * first order and subscription object, then between subscription and renewal orders.
 *
 * The hooks used here in those cases are
 * - wcs_subscription_meta_query
 * - wcs_subscription_meta
 * - wcs_renewal_order_meta_query
 * - wcs_renewal_order_meta
 *
 * @param  WC_Order $from_order Order to copy meta from
 * @param  WC_Order $to_order   Order to copy meta to
 * @param  string $type type of copy
 */
function wcs_copy_order_meta( $from_order, $to_order, $type = 'subscription' ) {
	global $wpdb;

	if ( ! is_a( $from_order, 'WC_Abstract_Order' ) || ! is_a( $to_order, 'WC_Abstract_Order' ) ) {
		throw new InvalidArgumentException( _x( 'Invalid data. Orders expected aren\'t orders.', 'In wcs_copy_order_meta error message. Refers to origin and target order objects.', 'woocommerce-subscriptions' ) );
	}

	if ( ! is_string( $type ) ) {
		throw new InvalidArgumentException( _x( 'Invalid data. Type of copy is not a string.', 'Refers to the type of the copy being performed: "copy_order", "subscription", "renewal_order", "resubscribe_order"', 'woocommerce-subscriptions' ) );
	}

	if ( ! in_array( $type, array( 'subscription', 'renewal_order', 'resubscribe_order' ) ) ) {
		$type = 'copy_order';
	}

	$meta_query = $wpdb->prepare(
		"SELECT `meta_key`, `meta_value`
		 FROM {$wpdb->postmeta}
		 WHERE `post_id` = %d
		 AND `meta_key` NOT LIKE '_schedule_%%'
		 AND `meta_key` NOT IN (
			 '_paid_date',
			 '_date_paid',
			 '_completed_date',
			 '_date_completed',
			 '_edit_last',
			 '_subscription_switch_data',
			 '_order_key',
			 '_edit_lock',
			 '_wc_points_earned',
			 '_transaction_id',
			 '_billing_interval',
			 '_billing_period',
			 '_subscription_resubscribe',
			 '_subscription_renewal',
			 '_subscription_switch',
			 '_payment_method',
			 '_payment_method_title'
		 )",
		wcs_get_objects_property( $from_order, 'id' )
	);

	if ( 'renewal_order' == $type ) {
		$meta_query .= " AND `meta_key` NOT LIKE '_download_permissions_granted' ";
	}

	// Allow extensions to add/remove order meta
	$meta_query = apply_filters( 'wcs_' . $type . '_meta_query', $meta_query, $to_order, $from_order );
	$meta       = $wpdb->get_results( $meta_query, 'ARRAY_A' );
	$meta       = apply_filters( 'wcs_' . $type . '_meta', $meta, $to_order, $from_order );

	// Pre WC 3.0 we need to save each meta individually, post 3.0 we can save the object once
	$save = WC_Subscriptions::is_woocommerce_pre( '3.0' ) ? 'save' : 'set_prop_only';

	foreach ( $meta as $meta_item ) {
		wcs_set_objects_property( $to_order, $meta_item['meta_key'], maybe_unserialize( $meta_item['meta_value'] ), $save, '', 'omit_key_prefix' );
	}

	if ( is_callable( array( $to_order, 'save' ) ) ) {
		$to_order->save();
	}
}

/**
 * Function to create an order from a subscription. It can be used for a renewal or for a resubscribe
 * order creation. It is the common in both of those instances.
 *
 * @param  WC_Subscription|int $subscription Subscription we're basing the order off of
 * @param  string $type        Type of new order. Default values are 'renewal_order'|'resubscribe_order'
 * @return WC_Order            New order
 */
function wcs_create_order_from_subscription( $subscription, $type ) {

	$type = wcs_validate_new_order_type( $type );

	if ( is_wp_error( $type ) ) {
		return $type;
	}

	global $wpdb;

	try {

		$wpdb->query( 'START TRANSACTION' );

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		$new_order = wc_create_order( array(
			'customer_id'   => $subscription->get_user_id(),
			'customer_note' => $subscription->get_customer_note(),
		) );

		wcs_copy_order_meta( $subscription, $new_order, $type );

		// Copy over line items and allow extensions to add/remove items or item meta
		$items = apply_filters( 'wcs_new_order_items', $subscription->get_items( array( 'line_item', 'fee', 'shipping', 'tax', 'coupon' ) ), $new_order, $subscription );
		$items = apply_filters( 'wcs_' . $type . '_items', $items, $new_order, $subscription );

		foreach ( $items as $item_index => $item ) {

			$item_name = apply_filters( 'wcs_new_order_item_name', $item['name'], $item, $subscription );
			$item_name = apply_filters( 'wcs_' . $type . '_item_name', $item_name, $item, $subscription );

			// Create order line item on the renewal order
			$order_item_id = wc_add_order_item( wcs_get_objects_property( $new_order, 'id' ), array(
				'order_item_name' => $item_name,
				'order_item_type' => $item['type'],
			) );

			// Remove recurring line items and set item totals based on recurring line totals
			if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
				foreach ( $item['item_meta'] as $meta_key => $meta_values ) {
					foreach ( $meta_values as $meta_value ) {
						wc_add_order_item_meta( $order_item_id, $meta_key, maybe_unserialize( $meta_value ) );
					}
				}
			} else {
				$order_item = $new_order->get_item( $order_item_id );

				wcs_copy_order_item( $item, $order_item );
				$order_item->save();
			}

			// If the line item we're adding is a product line item and that product still exists, trigger the 'woocommerce_order_add_product' hook
			if ( 'line_item' == $item['type'] && isset( $item['product_id'] ) ) {

				$product_id = wcs_get_canonical_product_id( $item );
				$product    = wc_get_product( $product_id );

				if ( false !== $product ) {

					$args = array(
						'totals' => array(
							'subtotal'     => $item['line_subtotal'],
							'total'        => $item['line_total'],
							'subtotal_tax' => $item['line_subtotal_tax'],
							'tax'          => $item['line_tax'],
							'tax_data'     => maybe_unserialize( $item['line_tax_data'] ),
						),
					);

					// If we have a variation, get the attribute meta data from teh item to pass to callbacks
					if ( ! empty( $item['variation_id'] ) && null !== ( $variation_data = wcs_get_objects_property( $product, 'variation_data' ) ) ) {
						foreach ( $variation_data as $attribute => $variation ) {
							if ( isset( $item[ str_replace( 'attribute_', '', $attribute ) ] ) ) {
								$args['variation'][ $attribute ] = $item[ str_replace( 'attribute_', '', $attribute ) ];
							}
						}
					}

					// Backorders
					if ( isset( $order_item ) && is_callable( array( $order_item, 'set_backorder_meta' ) ) ) { // WC 3.0
						$order_item->set_backorder_meta();
						$order_item->save();
					} elseif ( $product->backorders_require_notification() && $product->is_on_backorder( $item['qty'] ) ) { // WC 2.6
						wc_add_order_item_meta( $order_item_id, apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'woocommerce-subscriptions' ) ), $item['qty'] - max( 0, $product->get_total_stock() ) );
					}

					if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
						// WC 3.0+ will also trigger the 'woocommerce_order_add_product when 'woocommerce_new_order_item', which is triggered in wc_add_order_item_meta()
						do_action( 'woocommerce_order_add_product', wcs_get_objects_property( $new_order, 'id' ), $order_item_id, $product, $item['qty'], $args );
					}
				}
			}
		}

		// If we got here, the subscription was created without problems
		$wpdb->query( 'COMMIT' );

		return apply_filters( 'wcs_new_order_created', $new_order, $subscription, $type );

	} catch ( Exception $e ) {
		// There was an error adding the subscription
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'new-order-error', $e->getMessage() );
	}
}

/**
 * Function to create a post title based on the type and the current date and time for new orders. By
 * default it's either renewal or resubscribe orders.
 *
 * @param  string $type type of new order. By default 'renewal_order'|'resubscribe_order'
 * @return string       new title for a post
 */
function wcs_get_new_order_title( $type ) {
	wcs_deprecated_function( __FUNCTION__, '2.2.0' );

	$type = wcs_validate_new_order_type( $type );

	$order_date = strftime( _x( '%b %d, %Y @ %I:%M %p', 'Used in subscription post title. "Subscription renewal order - <this>"', 'woocommerce-subscriptions' ) );

	switch ( $type ) {
		case 'renewal_order':
			$title = sprintf( __( 'Subscription Renewal Order &ndash; %s', 'woocommerce-subscriptions' ), $order_date );
			break;
		case 'resubscribe_order':
			$title = sprintf( __( 'Resubscribe Order &ndash; %s', 'woocommerce-subscriptions' ), $order_date );
			break;
		default:
			$title = '';
			break;
	}

	return apply_filters( 'wcs_new_order_title', $title, $type, $order_date );
}

/**
 * Utility function to check type. Filterable. Rejects if not in allowed new order types, rejects
 * if not actually string.
 *
 * @param  string $type type of new order
 * @return string       the same type thing if no problems are found
 */
function wcs_validate_new_order_type( $type ) {
	if ( ! is_string( $type ) ) {
		return new WP_Error( 'order_from_subscription_type_type', sprintf( __( '$type passed to the function was not a string.', 'woocommerce-subscriptions' ), $type ) );

	}

	if ( ! in_array( $type, apply_filters( 'wcs_new_order_types', array( 'renewal_order', 'resubscribe_order' ) ) ) ) {
		return new WP_Error( 'order_from_subscription_type', sprintf( __( '"%s" is not a valid new order type.', 'woocommerce-subscriptions' ), $type ) );
	}

	return $type;
}

/**
 * Wrapper function to get the address from an order / subscription in array format
 * @param  WC_Order $order The order / subscription we want to get the order from
 * @param  string $address_type shipping|billing. Default is shipping
 * @return array
 */
function wcs_get_order_address( $order, $address_type = 'shipping' ) {
	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		return array();
	}

	if ( 'billing' == $address_type ) {
		$address = array(
			'first_name' => wcs_get_objects_property( $order, 'billing_first_name' ),
			'last_name'  => wcs_get_objects_property( $order, 'billing_last_name' ),
			'company'    => wcs_get_objects_property( $order, 'billing_company' ),
			'address_1'  => wcs_get_objects_property( $order, 'billing_address_1' ),
			'address_2'  => wcs_get_objects_property( $order, 'billing_address_2' ),
			'city'       => wcs_get_objects_property( $order, 'billing_city' ),
			'state'      => wcs_get_objects_property( $order, 'billing_state' ),
			'postcode'   => wcs_get_objects_property( $order, 'billing_postcode' ),
			'country'    => wcs_get_objects_property( $order, 'billing_country' ),
			'email'      => wcs_get_objects_property( $order, 'billing_email' ),
			'phone'      => wcs_get_objects_property( $order, 'billing_phone' ),
		);
	} else {
		$address = array(
			'first_name' => wcs_get_objects_property( $order, 'shipping_first_name' ),
			'last_name'  => wcs_get_objects_property( $order, 'shipping_last_name' ),
			'company'    => wcs_get_objects_property( $order, 'shipping_company' ),
			'address_1'  => wcs_get_objects_property( $order, 'shipping_address_1' ),
			'address_2'  => wcs_get_objects_property( $order, 'shipping_address_2' ),
			'city'       => wcs_get_objects_property( $order, 'shipping_city' ),
			'state'      => wcs_get_objects_property( $order, 'shipping_state' ),
			'postcode'   => wcs_get_objects_property( $order, 'shipping_postcode' ),
			'country'    => wcs_get_objects_property( $order, 'shipping_country' ),
		);
	}

	return apply_filters( 'wcs_get_order_address', $address, $address_type, $order );
}

/**
 * Checks an order to see if it contains a subscription.
 *
 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
 * @param array|string $order_type Can include 'parent', 'renewal', 'resubscribe' and/or 'switch'. Defaults to 'parent', 'resubscribe' and 'switch' orders.
 * @return bool True if the order contains a subscription that belongs to any of the given order types, otherwise false.
 * @since 2.0
 */
function wcs_order_contains_subscription( $order, $order_type = array( 'parent', 'resubscribe', 'switch' ) ) {

	// Accept either an array or string (to make it more convenient for singular types, like 'parent' or 'any')
	if ( ! is_array( $order_type ) ) {
		$order_type = array( $order_type );
	}

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );
	}

	$contains_subscription = false;
	$get_all               = ( in_array( 'any', $order_type ) ) ? true : false;

	if ( ( in_array( 'parent', $order_type ) || $get_all ) && count( wcs_get_subscriptions_for_order( wcs_get_objects_property( $order, 'id' ), array( 'order_type' => 'parent' ) ) ) > 0 ) {
		$contains_subscription = true;

	} elseif ( ( in_array( 'renewal', $order_type ) || $get_all ) && wcs_order_contains_renewal( $order ) ) {
		$contains_subscription = true;

	} elseif ( ( in_array( 'resubscribe', $order_type ) || $get_all ) && wcs_order_contains_resubscribe( $order ) ) {
		$contains_subscription = true;

	} elseif ( ( in_array( 'switch', $order_type ) || $get_all )&& wcs_order_contains_switch( $order ) ) {
		$contains_subscription = true;

	}

	return $contains_subscription;
}

/**
 * Get all the orders that relate to a subscription in some form (rather than only the orders associated with
 * a specific subscription).
 *
 * @param string $return_fields The columns to return, either 'all' or 'ids'
 * @param array|string $order_type Can include 'any', 'parent', 'renewal', 'resubscribe' and/or 'switch'. Defaults to 'parent'.
 * @return array The orders that relate to a subscription, if any. Will contain either as just IDs or WC_Order objects depending on $return_fields value.
 * @since 2.1
 */
function wcs_get_subscription_orders( $return_fields = 'ids', $order_type = 'parent' ) {
	global $wpdb;

	// Accept either an array or string (to make it more convenient for singular types, like 'parent' or 'any')
	if ( ! is_array( $order_type ) ) {
		$order_type = array( $order_type );
	}

	$any_order_type = in_array( 'any', $order_type ) ? true : false;
	$return_fields  = ( 'ids' == $return_fields ) ? $return_fields : 'all';

	$orders    = array();
	$order_ids = array();

	if ( $any_order_type || in_array( 'parent', $order_type ) ) {
		$order_ids = array_merge( $order_ids, $wpdb->get_col(
			"SELECT DISTINCT post_parent FROM {$wpdb->posts}
			 WHERE post_type = 'shop_subscription'
			 AND post_parent <> 0"
		) );
	}

	if ( $any_order_type || in_array( 'renewal', $order_type ) || in_array( 'resubscribe', $order_type ) || in_array( 'switch', $order_type ) ) {

		$meta_query = array(
			'relation' => 'OR',
		);

		if ( $any_order_type || in_array( 'renewal', $order_type ) ) {
			$meta_query[] = array(
				'key'     => '_subscription_renewal',
				'compare' => 'EXISTS',
			);
		}

		if ( $any_order_type || in_array( 'switch', $order_type ) ) {
			$meta_query[] = array(
				'key'     => '_subscription_switch',
				'compare' => 'EXISTS',
			);
		}

		// $any_order_type handled by 'parent' query above as all resubscribe orders are all parent orders
		if ( in_array( 'resubscribe', $order_type ) && ! in_array( 'parent', $order_type ) ) {
			$meta_query[] = array(
				'key'     => '_subscription_resubscribe',
				'compare' => 'EXISTS',
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$order_ids = array_merge( $order_ids, get_posts( array(
				'posts_per_page' => -1,
				'post_type'      => 'shop_order',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_query'     => $meta_query,
			) ) );
		}
	}

	if ( 'all' == $return_fields ) {
		foreach ( $order_ids as $order_id ) {
			$orders[ $order_id ] = wc_get_order( $order_id );
		}
	} else {
		foreach ( $order_ids as $order_id ) {
			$orders[ $order_id ] = $order_id;
		}
	}

	return apply_filters( 'wcs_get_subscription_orders', $orders, $return_fields, $order_type );
}

/**
 * A wrapper for getting a specific item from a subscription.
 *
 * WooCommerce has a wc_add_order_item() function, wc_update_order_item() function and wc_delete_order_item() function,
 * but no `wc_get_order_item()` function, so we need to add our own (for now).
 *
 * @param int $item_id The ID of an order item
 * @return WC_Subscription Subscription details in post_id => WC_Subscription form.
 * @since 2.0
 */
function wcs_get_order_item( $item_id, $order ) {

	$item = array();

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		throw new InvalidArgumentException( __( 'Invalid data. No valid subscription / order was passed in.', 'woocommerce-subscriptions' ), 422 );
	}

	if ( ! absint( $item_id ) ) {
		throw new InvalidArgumentException( __( 'Invalid data. No valid item id was passed in.', 'woocommerce-subscriptions' ), 422 );
	}

	foreach ( $order->get_items() as $line_item_id => $line_item ) {
		if ( $item_id == $line_item_id ) {
			$item = $line_item;
			break;
		}
	}

	return $item;
}

/**
 * A wrapper for wc_update_order_item() which consistently deletes the cached item after update, unlike WC.
 *
 * @param int $item_id The ID of an order item
 * @param string $new_type The new type to set as the 'order_item_type' value on the order item.
 * @param int $order_or_subscription_id The order or subscription ID the line item belongs to - optional. Deletes the order item cache if provided.
 * @since 2.2.12
 */
function wcs_update_order_item_type( $item_id, $new_type, $order_or_subscription_id = 0 ) {
	wc_update_order_item( $item_id, array( 'order_item_type' => $new_type ) );
	wp_cache_delete( 'item-' . $item_id, 'order-items' );

	// When possible, also clear the order items' cache for the object to which this item relates (double cache :sob:)
	if ( ! empty( $order_or_subscription_id ) ) {
		wp_cache_delete( 'order-items-' . $order_or_subscription_id, 'orders' );
	}
}

/**
 * Get an instance of WC_Order_Item_Meta for an order item
 *
 * @param array
 * @return WC_Order_Item_Meta
 * @since 2.0
 */
function wcs_get_order_item_meta( $item, $product = null ) {
	if ( false === WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
		wcs_deprecated_function( __FUNCTION__, '3.1 of WooCommerce and 2.2.9 of Subscriptions', 'WC_Order_Item_Product->get_formatted_meta_data() or wc_display_item_meta()' );
	}
	return new WC_Order_Item_Meta( $item, $product );
}

/**
 * Create a string representing an order item's name and optionally include attributes.
 *
 * @param array $order_item An order item.
 * @since 2.0
 */
function wcs_get_order_item_name( $order_item, $include = array() ) {

	$include = wp_parse_args( $include, array(
		'attributes' => false,
	) );

	$order_item_name = $order_item['name'];

	if ( $include['attributes'] && ! empty( $order_item['item_meta'] ) ) {

		$attribute_strings = array();

		foreach ( $order_item['item_meta'] as $meta_key => $meta_value ) {

			$meta_value = WC_Subscriptions::is_woocommerce_pre( 3.0 ) ? $meta_value[0] : $meta_value;

			// Skip hidden core fields
			if ( in_array( $meta_key, apply_filters( 'woocommerce_hidden_order_itemmeta', array(
				'_qty',
				'_tax_class',
				'_product_id',
				'_variation_id',
				'_line_subtotal',
				'_line_subtotal_tax',
				'_line_total',
				'_line_tax',
				'_switched_subscription_item_id',
			) ) ) ) {
				continue;
			}

			// Skip serialised or array meta values
			if ( is_serialized( $meta_value ) || is_array( $meta_value ) ) {
				continue;
			}

			// Get attribute data
			if ( taxonomy_exists( wc_sanitize_taxonomy_name( $meta_key ) ) ) {
				$term       = get_term_by( 'slug', $meta_value, wc_sanitize_taxonomy_name( $meta_key ) );
				$meta_key   = wc_attribute_label( wc_sanitize_taxonomy_name( $meta_key ) );
				$meta_value = isset( $term->name ) ? $term->name : $meta_value;
			} else {
				$meta_key   = apply_filters( 'woocommerce_attribute_label', wc_attribute_label( $meta_key ), $meta_key );
			}

			$attribute_strings[] = sprintf( '%s: %s', wp_kses_post( rawurldecode( $meta_key ) ), wp_kses_post( rawurldecode( $meta_value ) ) );
		}

		$order_item_name = sprintf( '%s (%s)', $order_item_name, implode( ', ', $attribute_strings ) );
	}

	return apply_filters( 'wcs_get_order_item_name', $order_item_name, $order_item, $include );
}

/**
 * Get the full name for a order/subscription line item, including the items non hidden meta
 * (i.e. attributes), as a flat string.
 *
 * @param array
 * @return string
 */
function wcs_get_line_item_name( $line_item ) {

	$item_meta_strings = array();

	foreach ( $line_item['item_meta'] as $meta_key => $meta_value ) {

		$meta_value = WC_Subscriptions::is_woocommerce_pre( 3.0 ) ? $meta_value[0] : $meta_value;

		// Skip hidden core fields
		if ( in_array( $meta_key, apply_filters( 'woocommerce_hidden_order_itemmeta', array(
			'_qty',
			'_tax_class',
			'_product_id',
			'_variation_id',
			'_line_subtotal',
			'_line_subtotal_tax',
			'_line_total',
			'_line_tax',
			'_line_tax_data',
		) ) ) ) {
			continue;
		}

		// Skip serialised or array meta values
		if ( is_serialized( $meta_value ) || is_array( $meta_value ) ) {
			continue;
		}

		// Get attribute data
		if ( taxonomy_exists( wc_sanitize_taxonomy_name( $meta_key ) ) ) {
			$term       = get_term_by( 'slug', $meta_value, wc_sanitize_taxonomy_name( $meta_key ) );
			$meta_key   = wc_attribute_label( wc_sanitize_taxonomy_name( $meta_key ) );
			$meta_value = isset( $term->name ) ? $term->name : $meta_value;
		} else {
			$meta_key   = apply_filters( 'woocommerce_attribute_label', wc_attribute_label( $meta_key ), $meta_key );
		}

		$item_meta_strings[] = sprintf( '%s: %s', rawurldecode( $meta_key ), rawurldecode( $meta_value ) );
	}

	if ( ! empty( $item_meta_strings ) ) {
		$line_item_name = sprintf( '%s (%s)', $line_item['name'], implode( ', ', $item_meta_strings ) );
	} else {
		$line_item_name = $line_item['name'];
	}

	return apply_filters( 'wcs_line_item_name', $line_item_name, $line_item );
}

/**
 * Display item meta data in a version compatible way.
 *
 * @since  2.2.0
 * @param  WC_Item $item
 * @param  WC_Order $order
 * @return void
 */
function wcs_display_item_meta( $item, $order ) {
	if ( function_exists( 'wc_display_item_meta' ) ) { // WC 3.0+
		wc_display_item_meta( $item );
	} else {
		$order->display_item_meta( $item );
	}
}

/**
 * Display item download links in a version compatible way.
 *
 * @since  2.2.0
 * @param  WC_Item $item
 * @param  WC_Order $order
 * @return void
 */
function wcs_display_item_downloads( $item, $order ) {
	if ( function_exists( 'wc_display_item_downloads' ) ) { // WC 3.0+
		wc_display_item_downloads( $item );
	} else {
		$order->display_item_downloads( $item );
	}
}

/**
 * Copy the order item data and meta data from one item to another.
 *
 * @since  2.2.0
 * @param  WC_Order_Item The order item to copy data from
 * @param  WC_Order_Item The order item to copy data to
 * @return void
 */
function wcs_copy_order_item( $from_item, &$to_item ) {

	if ( WC_Subscriptions::is_woocommerce_pre( '3.0' ) ) {
		wcs_doing_it_wrong( __FUNCTION__, 'This function uses data structures introduced in WC 3.0. To copy line item meta use $from_item[\'item_meta\'] and wc_add_order_item_meta().', '2.2' );
		return;
	}

	foreach ( $from_item->get_meta_data() as $meta_data ) {
		$to_item->update_meta_data( $meta_data->key, $meta_data->value );
	}

	switch ( $from_item->get_type() ) {
		case 'line_item':
			$to_item->set_props( array(
				'product_id'   => $from_item->get_product_id(),
				'variation_id' => $from_item->get_variation_id(),
				'quantity'     => $from_item->get_quantity(),
				'tax_class'    => $from_item->get_tax_class(),
				'subtotal'     => $from_item->get_subtotal(),
				'total'        => $from_item->get_total(),
				'taxes'        => $from_item->get_taxes(),
			) );
			break;
		case 'shipping':
			$to_item->set_props( array(
				'method_id' => $from_item->get_method_id(),
				'total'     => $from_item->get_total(),
				'taxes'     => $from_item->get_taxes(),
			) );
			break;
		case 'tax':
			$to_item->set_props( array(
				'rate_id'            => $from_item->get_rate_id(),
				'label'              => $from_item->get_label(),
				'compound'           => $from_item->get_compound(),
				'tax_total'          => $from_item->get_tax_total(),
				'shipping_tax_total' => $from_item->get_shipping_tax_total(),
			) );
			break;
		case 'fee':
			$to_item->set_props( array(
				'tax_class'  => $from_item->get_tax_class(),
				'tax_status' => $from_item->get_tax_status(),
				'total'      => $from_item->get_total(),
				'taxes'      => $from_item->get_taxes(),
			) );
			break;
		case 'coupon':
			$to_item->set_props( array(
				'discount'     => $from_item->get_discount(),
				'discount_tax' => $from_item->get_discount_tax(),
			) );
			break;
	}
}
