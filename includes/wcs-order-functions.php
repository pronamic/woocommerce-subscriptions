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
		$order_id = $order_id->id;
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
			'first_name' => $from_order->shipping_first_name,
			'last_name'  => $from_order->shipping_last_name,
			'company'    => $from_order->shipping_company,
			'address_1'  => $from_order->shipping_address_1,
			'address_2'  => $from_order->shipping_address_2,
			'city'       => $from_order->shipping_city,
			'state'      => $from_order->shipping_state,
			'postcode'   => $from_order->shipping_postcode,
			'country'    => $from_order->shipping_country,
		), 'shipping' );
	}

	if ( in_array( $address_type, array( 'billing', 'all' ) ) ) {
		$to_order->set_address( array(
			'first_name' => $from_order->billing_first_name,
			'last_name'  => $from_order->billing_last_name,
			'company'    => $from_order->billing_company,
			'address_1'  => $from_order->billing_address_1,
			'address_2'  => $from_order->billing_address_2,
			'city'       => $from_order->billing_city,
			'state'      => $from_order->billing_state,
			'postcode'   => $from_order->billing_postcode,
			'country'    => $from_order->billing_country,
			'email'      => $from_order->billing_email,
			'phone'      => $from_order->billing_phone,
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
			 '_completed_date',
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
		$from_order->id
	);

	if ( 'renewal_order' == $type ) {
		$meta_query .= " AND `meta_key` NOT LIKE '_download_permissions_granted' ";
	}

	// Allow extensions to add/remove order meta
	$meta_query = apply_filters( 'wcs_' . $type . '_meta_query', $meta_query, $to_order, $from_order );
	$meta       = $wpdb->get_results( $meta_query, 'ARRAY_A' );
	$meta       = apply_filters( 'wcs_' . $type . '_meta', $meta, $to_order, $from_order );

	foreach ( $meta as $meta_item ) {
		update_post_meta( $to_order->id, $meta_item['meta_key'], maybe_unserialize( $meta_item['meta_value'] ) );
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
			'customer_note' => $subscription->customer_note,
		) );

		$new_order->post->post_title = wcs_get_new_order_title( $type );

		wcs_copy_order_meta( $subscription, $new_order, $type );

		// Copy over line items and allow extensions to add/remove items or item meta
		$items = apply_filters( 'wcs_new_order_items', $subscription->get_items( array( 'line_item', 'fee', 'shipping', 'tax' ) ), $new_order, $subscription );
		$items = apply_filters( 'wcs_' . $type . '_items', $items, $new_order, $subscription );

		foreach ( $items as $item_index => $item ) {

			$item_name = apply_filters( 'wcs_new_order_item_name', $item['name'], $item, $subscription );
			$item_name = apply_filters( 'wcs_' . $type . '_item_name', $item_name, $item, $subscription );

			// Create order line item on the renewal order
			$recurring_item_id = wc_add_order_item( $new_order->id, array(
				'order_item_name' => $item_name,
				'order_item_type' => $item['type'],
			) );

			// Remove recurring line items and set item totals based on recurring line totals
			foreach ( $item['item_meta'] as $meta_key => $meta_values ) {
				foreach ( $meta_values as $meta_value ) {
					wc_add_order_item_meta( $recurring_item_id, $meta_key, maybe_unserialize( $meta_value ) );
				}
			}
		}

		// If we got here, the subscription was created without problems
		$wpdb->query( 'COMMIT' );

		return apply_filters( 'wcs_new_order_created', $new_order, $subscription );

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
	if ( ! is_object( $order ) ) {
		return array();
	}

	if ( 'billing' == $address_type ) {
		$address = array(
			'first_name' => $order->billing_first_name,
			'last_name'  => $order->billing_last_name,
			'company'    => $order->billing_company,
			'address_1'  => $order->billing_address_1,
			'address_2'  => $order->billing_address_2,
			'city'       => $order->billing_city,
			'state'      => $order->billing_state,
			'postcode'   => $order->billing_postcode,
			'country'    => $order->billing_country,
			'email'      => $order->billing_email,
			'phone'      => $order->billing_phone,
		);
	} else {
		$address = array(
			'first_name' => $order->shipping_first_name,
			'last_name'  => $order->shipping_last_name,
			'company'    => $order->shipping_company,
			'address_1'  => $order->shipping_address_1,
			'address_2'  => $order->shipping_address_2,
			'city'       => $order->shipping_city,
			'state'      => $order->shipping_state,
			'postcode'   => $order->shipping_postcode,
			'country'    => $order->shipping_country,
		);
	}

	return apply_filters( 'wcs_get_order_address', $address, $address_type, $order );
}

/**
 * Checks an order to see if it contains a subscription.
 *
 * @param mixed $order A WC_Order object or the ID of the order which the subscription was purchased in.
 * @param array|string $order_type Can include 'parent', 'renewal', 'resubscribe' and/or 'switch'. Defaults to 'parent'.
 * @return bool True if the order contains a subscription that belongs to any of the given order types, otherwise false.
 * @since 2.0
 */
function wcs_order_contains_subscription( $order, $order_type = array( 'parent', 'resubscribe', 'switch' ) ) {

	// Accept either an array or string (to make it more convenient for singular types, like 'parent' or 'any')
	if ( ! is_array( $order_type ) ) {
		$order_type = array( $order_type );
	}

	if ( ! is_object( $order ) ) {
		$order = new WC_Order( $order );
	}

	$contains_subscription = false;
	$get_all               = ( in_array( 'any', $order_type ) ) ? true : false;

	if ( ( in_array( 'parent', $order_type ) || $get_all ) && count( wcs_get_subscriptions_for_order( $order->id, array( 'order_type' => 'parent' ) ) ) > 0 ) {
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
 * Get an instance of WC_Order_Item_Meta for an order item
 *
 * @param array
 * @return WC_Order_Item_Meta
 * @since 2.0
 */
function wcs_get_order_item_meta( $item, $product = null ) {

	if ( WC_Subscriptions::is_woocommerce_pre( '2.4' ) ) {
		$item_meta = new WC_Order_Item_Meta( $item['item_meta'], $product );
	} else {
		$item_meta = new WC_Order_Item_Meta( $item, $product );
	}

	return $item_meta;
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

			$meta_value = $meta_value[0];

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

			// Skip serialised meta
			if ( is_serialized( $meta_value ) ) {
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

		$meta_value = $meta_value[0];

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

		// Skip serialised meta
		if ( is_serialized( $meta_value ) ) {
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
