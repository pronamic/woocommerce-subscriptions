<?php
/**
 * Personal data exporters.
 *
 * @package  WooCommerce Subscriptions Gifting\Privacy
 * @version  2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gifting information exporter.
 */
class WCSG_Privacy_Exporters extends WCS_Privacy_Exporters {

	/**
	 * Finds and exports subscription data linked to a user via recipient meta.
	 *
	 * Subscriptions are exported in blocks of 10 to avoid timeouts.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs.
	 */
	public static function subscription_data_exporter( $email_address, $page ) {
		$done           = true;
		$user           = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.
		$data_to_export = array();

		// If we didn't get a user, exit early.
		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => $data_to_export,
				'done' => $done,
			);
		}

		$subscriptions = WCSG_Recipient_Management::get_recipient_subscriptions(
			$user->ID,
			0,
			array(
				'posts_per_page' => 10,
				'paged'          => (int) $page,
			)
		);

		$subscriptions = array_filter( array_map( 'wcs_get_subscription', $subscriptions ) );

		// Filter the properties exported so only the recipient's personal data stored on the subscription is exported.
		add_filter( 'woocommerce_privacy_export_subscription_personal_data_props', array( __CLASS__, 'remove_purchaser_properties' ) );

		if ( 0 < count( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				$data_to_export[] = array(
					'group_id'    => 'woocommerce_gifted_subscriptions',
					'group_label' => __( 'Subscriptions Purchased for You', 'woocommerce-subscriptions' ),
					'item_id'     => 'subscription-' . $subscription->get_id(),
					'data'        => self::get_subscription_personal_data( $subscription ),
				);
			}
			$done = 10 > count( $subscriptions );
		}

		remove_filter( 'woocommerce_privacy_export_subscription_personal_data_props', array( __CLASS__, 'remove_purchaser_properties' ) );

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Remove the personal data properties which belong to the purchaser.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param  array $exported_data_properties The subscription properties to export.
	 * @return array The recipient personal data properties to export.
	 */
	public static function remove_purchaser_properties( $exported_data_properties ) {
		$purchaser_properties = array(
			'total',
			'customer_ip_address',
			'customer_user_agent',
			'formatted_billing_address',
			'billing_phone',
			'billing_email',
		);

		// Remove the properties which belong to the purchaser.
		foreach ( $purchaser_properties as $property ) {
			unset( $exported_data_properties[ $property ] );
		}

		return $exported_data_properties;
	}

	/**
	 * Finds and exports order data linked to a user via recipient line item meta.
	 *
	 * Orders are exported in blocks of 10 to avoid timeouts.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of personal data in name value pairs.
	 */
	public static function order_data_exporter( $email_address, $page ) {
		$done           = true;
		$user           = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.
		$data_to_export = array();
		$export_limit   = 10;

		// If we didn't find get a user, exit early.
		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => $data_to_export,
				'done' => $done,
			);
		}

		$recipients_line_items = WCS_Gifting::get_recipient_order_items( $user->ID );

		if ( 0 < count( $recipients_line_items ) ) {
			$recipient_order_items = array();

			// Gather all the line items by their order ID so we have an array of line item IDs per order.
			foreach ( $recipients_line_items as $item_data ) {
				if ( isset( $item_data['order_id'], $item_data['order_item_id'] ) ) {
					$recipient_order_items[ $item_data['order_id'] ][ $item_data['order_item_id'] ] = $item_data['order_item_id'];
				}
			}

			// Sort the orders by their ID so we can apply pagination between requests in a consistent way.
			ksort( $recipient_order_items );

			// Apply a poor man's pagination.
			$recipient_order_items = array_slice( $recipient_order_items, ( (int) $page - 1 ) * $export_limit, $export_limit, true );

			foreach ( $recipient_order_items as $order_id => $line_item_ids ) {
				$order = wc_get_order( $order_id );

				if ( ! wcs_is_order( $order ) ) {
					continue;
				}

				$order_items = $order->get_items();

				// Check if we need to export shipping address details by checking if all line items belong to the recipient and if the order is a renewal, switch or resubscribe.
				$export_shipping = ( array_keys( $order_items ) == array_keys( $line_item_ids ) ) && wcs_order_contains_subscription( $order, array( 'renewal', 'switch', 'resubscribe' ) ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison

				// Get the order items from the order which belong to this recipient.
				$order_items = array_intersect_key( $order_items, $line_item_ids );

				$data_to_export[] = array(
					'group_id'    => 'woocommerce_gifted_subscription_orders',
					'group_label' => __( 'Orders Purchased for You', 'woocommerce-subscriptions' ),
					'item_id'     => 'order-' . $order_id,
					'data'        => self::get_recipient_order_personal_data( $order, $order_items, $export_shipping ),
				);
			}

			$done = $export_limit > count( $recipient_order_items );
		}

		return array(
			'data' => $data_to_export,
			'done' => $done,
		);
	}

	/**
	 * Get the recipient's personal data (key/value pairs) for an order object.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param  WC_Order $order The order object.
	 * @param  array    $items The order line item objects which belong to the recipient in the order.
	 * @param  bool     $export_shipping Whether to export shipping address data.
	 * @return array The recipient's personal data.
	 */
	protected static function get_recipient_order_personal_data( $order, $items, $export_shipping ) {
		$personal_data   = array();
		$props_to_export = apply_filters(
			'woocommerce_privacy_export_recipient_order_personal_data_props',
			array(
				'order_number'               => __( 'Order Number', 'woocommerce-subscriptions' ),
				'date_created'               => __( 'Order Date', 'woocommerce-subscriptions' ),
				'items'                      => __( 'Items Purchased', 'woocommerce-subscriptions' ),
				'formatted_shipping_address' => __( 'Shipping Address', 'woocommerce-subscriptions' ),
			),
			$order
		);

		if ( ! $export_shipping ) {
			unset( $props_to_export['formatted_shipping_address'] );
		}

		foreach ( $props_to_export as $prop => $name ) {
			$value = '';

			switch ( $prop ) {
				case 'items':
					$item_names = array();
					foreach ( $items as $item ) {
						$item_names[] = $item->get_name() . ' x ' . $item->get_quantity();
					}
					$value = implode( ', ', $item_names );
					break;
				case 'date_created':
					$value = wc_format_datetime( $order->get_date_created(), get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
					break;
				case 'formatted_shipping_address':
					$value = preg_replace( '#<br\s*/?>#i', ', ', $order->{"get_$prop"}() );
					break;
				default:
					if ( is_callable( array( $order, 'get_' . $prop ) ) ) {
						$value = $order->{"get_$prop"}();
					}
					break;
			}

			$value = apply_filters( 'woocommerce_privacy_export_recipient_order_personal_data_prop', $value, $prop, $order );

			if ( $value ) {
				$personal_data[] = array(
					'name'  => $name,
					'value' => $value,
				);
			}
		}

		/**
		 * Allow extensions to register their own personal data for this order for the export.
		 *
		 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
		 * @param array    $personal_data Array of name value pairs to expose in the export.
		 * @param WC_Order $order An order object.
		 */
		return apply_filters( 'woocommerce_privacy_export_recipient_order_personal_data', $personal_data, $order );
	}
}
