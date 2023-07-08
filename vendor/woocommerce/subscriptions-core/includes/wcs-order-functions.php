<?php
/**
 * WooCommerce Subscriptions Order Functions
 *
 * @author   Prospress
 * @category Core
 * @package  WooCommerce Subscriptions/Functions
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Get the subscription related to an order, if any.
 *
 * @param WC_Order|int $order An instance of a WC_Order object or the ID of an order
 * @param array $args A set of name value pairs to filter the returned value.
 *    'subscriptions_per_page' The number of subscriptions to return. Default set to -1 to return all.
 *    'offset' An optional number of subscription to displace or pass over. Default 0.
 *    'orderby' The field which the subscriptions should be ordered by. Can be 'start_date', 'trial_end_date', 'end_date', 'status' or 'order_id'. Defaults to 'start_date'.
 *    'order' The order of the values returned. Can be 'ASC' or 'DESC'. Defaults to 'DESC'
 *    'customer_id' The user ID of a customer on the site.
 *    'product_id' The post ID of a WC_Product_Subscription, WC_Product_Variable_Subscription or WC_Product_Subscription_Variation object
 *    'order_id' The post ID of a shop_order post/WC_Order object which was used to create the subscription
 *    'subscription_status' Any valid subscription status. Can be 'any', 'active', 'cancelled', 'on-hold', 'expired', 'pending' or 'trash'. Defaults to 'any'.
 *    'order_type' Get subscriptions for the any order type in this array. Can include 'any', 'parent', 'renewal' or 'switch', defaults to parent.
 * @return WC_Subscription[] Subscription details in post_id => WC_Subscription form.
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_subscriptions_for_order( $order, $args = array() ) {

	$subscriptions = array();

	if ( ! is_a( $order, 'WC_Order' ) ) {
		$order = wc_get_order( $order );
	}

	if ( ! is_a( $order, 'WC_Order' ) ) {
		return $subscriptions;
	}

	$args = wp_parse_args(
		$args,
		array(
			'subscriptions_per_page' => -1,
			'order_type'             => array( 'parent', 'switch' ),
		)
	);

	// Accept either an array or string (to make it more convenient for singular types, like 'parent' or 'any')
	if ( ! is_array( $args['order_type'] ) ) {
		$args['order_type'] = array( $args['order_type'] );
	}

	$get_all = in_array( 'any', $args['order_type'] );

	if ( $get_all || in_array( 'parent', $args['order_type'] ) ) {

		$get_subscriptions_args = array_merge( $args, array(
			'order_id' => $order->get_id(),
		) );

		$subscriptions = wcs_get_subscriptions( $get_subscriptions_args );

	}

	$all_relation_types = WCS_Related_Order_Store::instance()->get_relation_types();
	$relation_types     = $get_all ? $all_relation_types : array_intersect( $all_relation_types, $args['order_type'] );

	foreach ( $relation_types as $relation_type ) {

		$subscription_ids = WCS_Related_Order_Store::instance()->get_related_subscription_ids( $order, $relation_type );

		foreach ( $subscription_ids as $subscription_id ) {
			if ( wcs_is_subscription( $subscription_id ) ) {
				$subscriptions[ $subscription_id ] = wcs_get_subscription( $subscription_id );
			}
		}
	}

	return $subscriptions;
}

/**
 * Copy the billing, shipping or all addresses from one order or subscription to another.
 *
 * @since 2.0.0
 *
 * @param WC_Order $to_order     The WC_Order object to copy the address to.
 * @param WC_Order $from_order   The WC_Order object to copy the address from.
 * @param string   $address_type The address type to copy, can be 'shipping', 'billing' or 'all'. Optional. Default is "all".
 *
 * @return WC_Order The WC_Order object with the new address set.
 */
function wcs_copy_order_address( $from_order, $to_order, $address_type = 'all' ) {

	if ( 'all' === $address_type || 'shipping' === $address_type ) {
		$to_order->set_shipping_first_name( $from_order->get_shipping_first_name() );
		$to_order->set_shipping_last_name( $from_order->get_shipping_last_name() );
		$to_order->set_shipping_company( $from_order->get_shipping_company() );
		$to_order->set_shipping_address_1( $from_order->get_shipping_address_1() );
		$to_order->set_shipping_address_2( $from_order->get_shipping_address_2() );
		$to_order->set_shipping_city( $from_order->get_shipping_city() );
		$to_order->set_shipping_state( $from_order->get_shipping_state() );
		$to_order->set_shipping_postcode( $from_order->get_shipping_postcode() );
		$to_order->set_shipping_country( $from_order->get_shipping_country() );
	}

	if ( 'all' === $address_type || 'billing' === $address_type ) {
		$to_order->set_billing_first_name( $from_order->get_billing_first_name() );
		$to_order->set_billing_last_name( $from_order->get_billing_last_name() );
		$to_order->set_billing_company( $from_order->get_billing_company() );
		$to_order->set_billing_address_1( $from_order->get_billing_address_1() );
		$to_order->set_billing_address_2( $from_order->get_billing_address_2() );
		$to_order->set_billing_city( $from_order->get_billing_city() );
		$to_order->set_billing_state( $from_order->get_billing_state() );
		$to_order->set_billing_postcode( $from_order->get_billing_postcode() );
		$to_order->set_billing_country( $from_order->get_billing_country() );
		$to_order->set_billing_email( $from_order->get_billing_email() );
		$to_order->set_billing_phone( $from_order->get_billing_phone() );
	}

	$to_order->save();

	return apply_filters( 'woocommerce_subscriptions_copy_order_address', $to_order, $from_order, $address_type );
}

/**
 * Copies order meta between two order objects (orders or subscriptions).
 *
 * Intended to copy meta between first order and subscription object, then between subscription and renewal orders.
 *
 * @param WC_Order $from_order Order|Subscription to copy meta from.
 * @param WC_Order $to_order   Order|Subscription to copy meta to.
 * @param string   $type       The type of copy. Can be 'subscription' or 'renewal'. Optional. Default is 'subscription'.
 */
function wcs_copy_order_meta( $from_order, $to_order, $type = 'subscription' ) {

	if ( ! is_a( $from_order, 'WC_Abstract_Order' ) || ! is_a( $to_order, 'WC_Abstract_Order' ) ) {
		throw new InvalidArgumentException( _x( 'Invalid data. Orders expected aren\'t orders.', 'In wcs_copy_order_meta error message. Refers to origin and target order objects.', 'woocommerce-subscriptions' ) );
	}

	if ( ! is_string( $type ) ) {
		throw new InvalidArgumentException( _x( 'Invalid data. Type of copy is not a string.', 'Refers to the type of the copy being performed: "copy_order", "subscription", "renewal_order", "resubscribe_order"', 'woocommerce-subscriptions' ) );
	}

	if ( ! in_array( $type, array( 'subscription', 'parent', 'renewal_order', 'resubscribe_order' ) ) ) {
		$type = 'copy_order';
	}

	WC_Subscriptions_Data_Copier::copy( $from_order, $to_order, $type );
}

/**
 * Function to create an order from a subscription. It can be used for a renewal or for a resubscribe
 * order creation. It is the common in both of those instances.
 *
 * @param  WC_Subscription|int $subscription Subscription we're basing the order off of
 * @param  string $type        Type of new order. Default values are 'renewal_order'|'resubscribe_order'
 * @return WC_Order|WP_Error New order or error object.
 */
function wcs_create_order_from_subscription( $subscription, $type ) {

	$type = wcs_validate_new_order_type( $type );

	if ( is_wp_error( $type ) ) {
		return $type;
	}

	try {
		$transaction = new WCS_SQL_Transaction();
		$transaction->start();

		if ( ! is_object( $subscription ) ) {
			$subscription = wcs_get_subscription( $subscription );
		}

		$new_order = wc_create_order( array(
			'customer_id'   => $subscription->get_user_id(),
			'customer_note' => $subscription->get_customer_note(),
			'created_via'   => 'subscription',
		) );

		wcs_copy_order_meta( $subscription, $new_order, $type );

		// Copy over line items and allow extensions to add/remove items or item meta
		$items = apply_filters( 'wcs_new_order_items', $subscription->get_items( array( 'line_item', 'fee', 'shipping', 'tax', 'coupon' ) ), $new_order, $subscription );
		$items = apply_filters( "wcs_{$type}_items", $items, $new_order, $subscription );

		foreach ( $items as $item ) {
			$item_name = apply_filters( 'wcs_new_order_item_name', $item->get_name(), $item, $subscription );
			$item_name = apply_filters( "wcs_{$type}_item_name", $item_name, $item, $subscription );

			// Create order line item on the renewal order
			$order_item_id = wc_add_order_item( $new_order->get_id(), array(
				'order_item_name' => $item_name,
				'order_item_type' => $item->get_type(),
			) );

			$order_item = $new_order->get_item( $order_item_id );

			wcs_copy_order_item( $item, $order_item );
			$order_item->save();

			// If the line item we're adding is a product line item and that product still exists, set any applicable backorder meta.
			if ( $item->is_type( 'line_item' ) && $item->get_product() ) {
				$order_item->set_backorder_meta();
				$order_item->save();
			}
		}

		// If we got here, the subscription was created without problems
		$transaction->commit();

		/**
		 * Filters the new order created from the subscription.
		 *
		 * Fetches a fresh instance of the order because the current order instance has an empty line item cache generated before we had copied the line items.
		 * Fetching a new instance will ensure the line items are available via $new_order->get_items().
		 *
		 * @param WC_Order        $new_order    The new order created from the subscription.
		 * @param WC_Subscription $subscription The subscription the order was created from.
		 * @param string          $type         The type of order being created. Either 'renewal_order' or 'resubscribe_order'.
		 */
		return apply_filters( 'wcs_new_order_created', wc_get_order( $new_order->get_id() ), $subscription, $type );

	} catch ( Exception $e ) {
		// There was an error adding the subscription
		$transaction->rollback();
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

	// translators: placeholders are strftime() strings.
	$order_date = strftime( _x( '%b %d, %Y @ %I:%M %p', 'Used in subscription post title. "Subscription renewal order - <this>"', 'woocommerce-subscriptions' ) ); // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText

	switch ( $type ) {
		case 'renewal_order':
			// translators: placeholder is a date.
			$title = sprintf( __( 'Subscription Renewal Order &ndash; %s', 'woocommerce-subscriptions' ), $order_date );
			break;
		case 'resubscribe_order':
			// translators: placeholder is a date.
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
 * @return string|WP_Error the same type thing if no problems are found, or WP_Error.
 */
function wcs_validate_new_order_type( $type ) {
	if ( ! is_string( $type ) ) {
		return new WP_Error( 'order_from_subscription_type_type', sprintf( __( '$type passed to the function was not a string.', 'woocommerce-subscriptions' ), $type ) );
	}

	if ( ! in_array( $type, apply_filters( 'wcs_new_order_types', array( 'renewal_order', 'resubscribe_order', 'parent' ) ) ) ) {
		// translators: placeholder is an order type.
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
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_order_contains_subscription( $order, $order_type = array( 'parent', 'resubscribe', 'switch' ) ) {

	// Accept either an array or string (to make it more convenient for singular types, like 'parent' or 'any')
	if ( ! is_array( $order_type ) ) {
		$order_type = array( $order_type );
	}

	if ( ! is_a( $order, 'WC_Abstract_Order' ) ) {
		$order = wc_get_order( $order );

		if ( ! $order ) {
			return false;
		}
	}

	$contains_subscription = false;
	$get_all               = in_array( 'any', $order_type, true );

	if ( ( in_array( 'parent', $order_type, true ) || $get_all ) && count( wcs_get_subscriptions_for_order( $order->get_id(), array( 'order_type' => 'parent' ) ) ) > 0 ) {
		$contains_subscription = true;

	} elseif ( ( in_array( 'renewal', $order_type, true ) || $get_all ) && wcs_order_contains_renewal( $order ) ) {
		$contains_subscription = true;

	} elseif ( ( in_array( 'resubscribe', $order_type, true ) || $get_all ) && wcs_order_contains_resubscribe( $order ) ) {
		$contains_subscription = true;

	} elseif ( ( in_array( 'switch', $order_type, true ) || $get_all ) && wcs_order_contains_switch( $order ) ) {
		$contains_subscription = true;

	}

	return $contains_subscription;
}

/**
 * Fetches Orders and Subscriptions using wc_get_orders() with a built-in handler for the meta_query arg.
 *
 * This function is a replacement for the get_posts() function to help aid with transitioning over to using wc_get_orders.
 * Args and usage: https://github.com/woocommerce/woocommerce/wiki/wc_get_orders-and-WC_Order_Query
 *
 * @since 5.0.0
 *
 * @param array $args Accepts the same arguments as wc_get_orders().
 * @return array An array of WC_Order or WC_Subscription objects or IDs based on the args.
 */
function wcs_get_orders_with_meta_query( $args ) {
	$is_hpos_in_use = wcs_is_custom_order_tables_usage_enabled();

	// In CPT datastores, we have to hook into the orders query to insert any meta query args.
	if ( ! $is_hpos_in_use ) {
		$meta = $args['meta_query'] ?? [];
		unset( $args['meta_query'] );

		$handle_meta = function ( $query, $query_vars ) use ( $meta ) {
			if ( [] === $meta ) {
				return $query;
			}

			if ( ! isset( $query['meta_query'] ) ) {
				$query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			} else {
				$query['meta_query'] = array_merge( $query['meta_query'], $meta ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}

			return $query;
		};

		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $handle_meta, 10, 2 );
	}

	/**
	 * Map the 'any' status to wcs_get_subscription_statuses() in HPOS environments.
	 *
	 * In HPOS environments, the 'any' status now maps to wc_get_order_statuses() statuses. Whereas, in
	 * WP Post architecture 'any' meant any status except for ‘inherit’, ‘trash’ and ‘auto-draft’.
	 *
	 * If we're querying for subscriptions, we need to map 'any' to be all valid subscription statuses otherwise it would just search for order statuses.
	 */
	if ( isset( $args['status'], $args['type'] ) &&
		[ 'any' ] === (array) $args['status'] &&
		'shop_subscription' === $args['type'] &&
		$is_hpos_in_use
	) {
		$args['status'] = array_keys( wcs_get_subscription_statuses() );
	}

	$results = wc_get_orders( $args );

	if ( ! $is_hpos_in_use ) {
		remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $handle_meta, 10 );
	}

	return $results;
}

/**
 * Get all the orders that relate to a subscription in some form (rather than only the orders associated with
 * a specific subscription).
 *
 * @param string $return_fields The columns to return, either 'all' or 'ids'
 * @param array|string $order_type Can include 'any', 'parent', 'renewal', 'resubscribe' and/or 'switch'. Defaults to 'parent'.
 * @return array The orders that relate to a subscription, if any. Will contain either as just IDs or WC_Order objects depending on $return_fields value.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.1
 */
function wcs_get_subscription_orders( $return_fields = 'ids', $order_type = 'parent' ) {
	global $wpdb;

	// Accept either an array or string (to make it more convenient for singular types, like 'parent' or 'any')
	if ( ! is_array( $order_type ) ) {
		$order_type = array( $order_type );
	}

	$any_order_type = in_array( 'any', $order_type );
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
			$order_ids = array_merge(
				$order_ids,
				wcs_get_orders_with_meta_query(
					[
						'limit'      => -1,
						'type'       => 'shop_order',
						'status'     => 'any',
						'return'     => 'ids',
						'orderby'    => 'ID',
						'order'      => 'DESC',
						'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					]
				)
			);
		}
	}

	if ( 'all' === $return_fields ) {
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
 * A wrapper for getting a specific item from an order or subscription.
 *
 * WooCommerce has a wc_add_order_item() function, wc_update_order_item() function and wc_delete_order_item() function,
 * but no `wc_get_order_item()` function, so we need to add our own (for now).
 *
 * @param int                      $item_id The ID of an order item
 * @param WC_Order|WC_Subscription $order   The order or order object the item belongs to.
 *
 * @return WC_Order_Item|array The order item object or an empty array if the item doesn't exist.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
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
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.2.12
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
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_order_item_meta( $item, $product = null ) {
	if ( false === wcs_is_woocommerce_pre( '3.0' ) ) {
		wcs_deprecated_function( __FUNCTION__, '3.1 of WooCommerce and 2.2.9 of Subscriptions', 'WC_Order_Item_Product->get_formatted_meta_data() or wc_display_item_meta()' );
	}
	return new WC_Order_Item_Meta( $item, $product );
}

/**
 * Create a string representing an order item's name and optionally include attributes.
 *
 * @param array $order_item An order item.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
 */
function wcs_get_order_item_name( $order_item, $include = array() ) {

	$include = wp_parse_args( $include, array(
		'attributes' => false,
	) );

	$order_item_name = $order_item['name'];

	if ( $include['attributes'] && ! empty( $order_item['item_meta'] ) ) {

		$attribute_strings = array();

		foreach ( $order_item['item_meta'] as $meta_key => $meta_value ) {

			$meta_value = wcs_is_woocommerce_pre( 3.0 ) ? $meta_value[0] : $meta_value;

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
				$meta_key   = wc_attribute_label( $meta_key );
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

		$meta_value = wcs_is_woocommerce_pre( 3.0 ) ? $meta_value[0] : $meta_value;

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
			$meta_key   = wc_attribute_label( $meta_key );
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
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
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
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @param  WC_Item $item
 * @param  WC_Order $order
 * @return void
 */
function wcs_display_item_downloads( $item, $order ) {
	wcs_deprecated_function( __FUNCTION__, '2.5.0', 'wc_display_item_downloads( $item )' );

	if ( function_exists( 'wc_display_item_downloads' ) ) { // WC 3.0+
		wc_display_item_downloads( $item );
	} else {
		$order->display_item_downloads( $item );
	}
}

/**
 * Copy the order item data and meta data from one item to another.
 *
 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.2.0
 * @param  WC_Order_Item $from_item The order item to copy data from
 * @param  WC_Order_Item $to_item The order item to copy data to
 */
function wcs_copy_order_item( $from_item, &$to_item ) {

	if ( wcs_is_woocommerce_pre( '3.0' ) ) {
		wcs_doing_it_wrong( __FUNCTION__, 'This function uses data structures introduced in WC 3.0. To copy line item meta use $from_item[\'item_meta\'] and wc_add_order_item_meta().', '2.2' );
		return;
	}

	foreach ( $from_item->get_meta_data() as $meta_data ) {
		if ( '_reduced_stock' === $meta_data->key ) {
			continue;
		}

		$to_item->update_meta_data( $meta_data->key, $meta_data->value );
	}

	switch ( $from_item->get_type() ) {
		case 'line_item':
			/** @var WC_Order_Item_Product $from_item */
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
			/** @var WC_Order_Item_Shipping $from_item */
			$to_item->set_props( array(
				'method_id' => $from_item->get_method_id(),
				'total'     => $from_item->get_total(),
				'taxes'     => $from_item->get_taxes(),
			) );

			// Post WC 3.4 the instance ID is stored separately.
			if ( ! wcs_is_woocommerce_pre( '3.4' ) ) {
				$to_item->set_instance_id( $from_item->get_instance_id() );
			}

			break;
		case 'tax':
			/**
			 * @var WC_Order_Item_Tax $from_item
			 * @var WC_Order_Item_Tax $to_item
			 */
			$to_item->set_props( array(
				'rate_id'            => $from_item->get_rate_id(),
				'label'              => $from_item->get_label(),
				'compound'           => $from_item->get_compound(),
				'tax_total'          => $from_item->get_tax_total(),
				'shipping_tax_total' => $from_item->get_shipping_tax_total(),
			) );

			// WC 3.7.0+ Compatibility.
			if ( is_callable( array( $from_item, 'get_rate_percent' ) ) ) {
				$to_item->set_rate_percent( $from_item->get_rate_percent() );
			}

			break;
		case 'fee':
			/** @var WC_Order_Item_Fee $from_item */
			$to_item->set_props( array(
				'tax_class'  => $from_item->get_tax_class(),
				'tax_status' => $from_item->get_tax_status(),
				'total'      => $from_item->get_total(),
				'taxes'      => $from_item->get_taxes(),
			) );
			break;
		case 'coupon':
			/** @var WC_Order_Item_Coupon $from_item */
			$to_item->set_props( array(
				'discount'     => $from_item->get_discount(),
				'discount_tax' => $from_item->get_discount_tax(),
			) );
			break;
	}
}

/**
 * Checks an order to see if it contains a manual subscription.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.3
 * @param WC_Order|int $order The WC_Order object or ID to get related subscriptions from.
 * @param string|array $order_type The order relationship type(s). Can be single string or an array of order types. Optional. Default is 'any'.
 * @return bool
 */
function wcs_order_contains_manual_subscription( $order, $order_type = 'any' ) {
	$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => $order_type ) );
	$contains_manual_subscription = false;

	foreach ( $subscriptions as $subscription ) {
		if ( $subscription->is_manual() ) {
			$contains_manual_subscription = true;
			break;
		}
	}

	return $contains_manual_subscription;
}

/**
 * Copy payment method from a subscription to an order.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.4.3
 * @param WC_Subscription $subscription
 * @param WC_Order $order
 */
function wcs_copy_payment_method_to_order( $subscription, $order ) {
	// Set the order's payment method to match the subscription.
	if ( $order->get_payment_method() !== $subscription->get_payment_method() ) {
		$order->set_payment_method( $subscription->get_payment_method() );
	}

	// We only need to copy the subscription's post meta to the order. All other payment related meta should already exist.
	// Both post_meta and postmeta keys are supported by wcs_set_payment_meta().
	$payment_meta = array_intersect_key( $subscription->get_payment_method_meta(), array_flip( array( 'post_meta', 'postmeta' ) ) );
	$payment_meta = (array) apply_filters( 'wcs_copy_payment_meta_to_order', $payment_meta, $order, $subscription );

	if ( ! empty( $payment_meta ) ) {
		wcs_set_payment_meta( $order, $payment_meta );
	}

}

/**
 * Returns how many minutes ago the order was created.
 *
 * @param WC_Order $order
 *
 * @return int
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
 */
function wcs_minutes_since_order_created( $order ) {
	$now             = new WC_DateTime( 'now', $order->get_date_created()->getTimezone() );
	$diff_in_minutes = $now->diff( $order->get_date_created() );

	return absint( $diff_in_minutes->i );
}

/**
 * Returns how many seconds ago the order was created.
 *
 * @param WC_Order $order
 *
 * @return int
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.3
 */
function wcs_seconds_since_order_created( $order ) {
	return time() - $order->get_date_created()->getTimestamp();
}

/**
 * Finds a corresponding subscription line item on an order.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 *
 * @param WC_Abstract_Order $order         The order object to look for the item in.
 * @param WC_Order_Item $subscription_item The line item on the the subscription to find on the order.
 * @param string $match_type               Optional. The type of comparison to make. Can be 'match_product_ids' to compare product|variation IDs or 'match_attributes' to also compare by item attributes on top of matching product IDs. Default 'match_product_ids'.
 *
 * @return WC_Order_Item|bool The order item which matches the subscription item or false if one cannot be found.
 */
function wcs_find_matching_line_item( $order, $subscription_item, $match_type = 'match_product_ids' ) {
	$matching_item = false;

	if ( 'match_attributes' === $match_type ) {
		$subscription_item_attributes = wp_list_pluck( $subscription_item->get_formatted_meta_data( '_', true ), 'value', 'key' );
	}

	$subscription_item_canonical_product_id = wcs_get_canonical_product_id( $subscription_item );

	foreach ( $order->get_items() as $order_item ) {
		if ( wcs_get_canonical_product_id( $order_item ) !== $subscription_item_canonical_product_id ) {
			continue;
		}

		// Check if we have matching meta key and value pairs loosely - they can appear in any order,
		if ( 'match_attributes' === $match_type && wp_list_pluck( $order_item->get_formatted_meta_data( '_', true ), 'value', 'key' ) != $subscription_item_attributes ) {
			continue;
		}

		$matching_item = $order_item;
		break;
	}

	return $matching_item;
}

/**
 * Checks if an order contains a product.
 *
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 *
 * @param WC_Order $order     An order object
 * @param WC_Product $product A product object
 *
 * @return bool $order_has_product Whether the order contains a line item matching that product
 */
function wcs_order_contains_product( $order, $product ) {
	$order_has_product = false;
	$product_id        = wcs_get_canonical_product_id( $product );

	foreach ( $order->get_items() as $line_item ) {
		if ( wcs_get_canonical_product_id( $line_item ) === $product_id ) {
			$order_has_product = true;
			break;
		}
	}

	return $order_has_product;
}

/**
 * Check if a given order is a subscription renewal order.
 *
 * @param WC_Order|int $order The WC_Order object or ID of a WC_Order order.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
 * @return bool True if the order contains an early renewal, otherwise false.
 */
function wcs_order_contains_early_renewal( $order ) {

	if ( ! is_object( $order ) ) {
		$order = wc_get_order( $order );
	}

	$subscription_id  = absint( wcs_get_objects_property( $order, 'subscription_renewal_early' ) );
	$is_early_renewal = wcs_is_order( $order ) && $subscription_id > 0;

	/**
	 * Allow third-parties to filter whether this order contains the early renewal flag.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.3.0
	 * @param bool     $is_renewal True if early renewal meta was found on the order, otherwise false.
	 * @param WC_Order $order The WC_Order object.
	 */
	return apply_filters( 'woocommerce_subscriptions_is_early_renewal_order', $is_early_renewal, $order );
}
