<?php
/**
 * Related Orders Meta Box
 *
 * Display the related orders table on the Edit Order and Edit Subscription screens.
 *
 * @author   Prospress
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Meta Boxes
 * @version  2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WCS_Meta_Box_Related_Orders Class
 */
class WCS_Meta_Box_Related_Orders {

	/**
	 * Output the metabox
	 */
	public static function output( $post ) {

		if ( wcs_is_subscription( $post->ID ) ) {
			$subscription = wcs_get_subscription( $post->ID );
			$order = ( false == $subscription->get_parent_id() ) ? $subscription : $subscription->get_parent();
		} else {
			$order = wc_get_order( $post->ID );
		}

		add_action( 'woocommerce_subscriptions_related_orders_meta_box_rows', __CLASS__ . '::output_rows', 10 );

		include_once( dirname( __FILE__ ) . '/views/html-related-orders-table.php' );

		do_action( 'woocommerce_subscriptions_related_orders_meta_box', $order, $post );
	}

	/**
	 * Displays the renewal orders in the Related Orders meta box.
	 *
	 * @param object $post A WordPress post
	 * @since 2.0
	 */
	public static function output_rows( $post ) {
		$orders_to_display      = array();
		$subscriptions          = array();
		$initial_subscriptions  = array();
		$orders_by_type         = array();
		$unknown_orders         = array(); // Orders which couldn't be loaded.

		// If this is a subscriptions screen,
		if ( wcs_is_subscription( $post->ID ) ) {
			$this_subscription = wcs_get_subscription( $post->ID );
			$subscriptions[]   = $this_subscription;

			// Resubscribed subscriptions and orders.
			$initial_subscriptions         = wcs_get_subscriptions_for_resubscribe_order( $this_subscription );
			$orders_by_type['resubscribe'] = WCS_Related_Order_Store::instance()->get_related_order_ids( $this_subscription, 'resubscribe' );
		} else {
			$subscriptions         = wcs_get_subscriptions_for_order( $post->ID, array( 'order_type' => array( 'parent', 'renewal' ) ) );
			$initial_subscriptions = wcs_get_subscriptions_for_order( $post->ID, array( 'order_type' => array( 'resubscribe' ) ) );
		}

		foreach ( $subscriptions as $subscription ) {
			// If we're on a single subscription or renewal order's page, display the parent orders
			if ( 1 == count( $subscriptions ) && $subscription->get_parent_id() ) {
				$orders_by_type['parent'][] = $subscription->get_parent_id();
			}

			// Finally, display the renewal orders
			$orders_by_type['renewal'] = $subscription->get_related_orders( 'ids', 'renewal' );

			// Build the array of subscriptions and orders to display.
			$subscription->update_meta_data( '_relationship', _x( 'Subscription', 'relation to order', 'woocommerce-subscriptions' ) );
			$orders_to_display[] = $subscription;
		}

		foreach ( $initial_subscriptions as $subscription ) {
			$subscription->update_meta_data( '_relationship', _x( 'Initial Subscription', 'relation to order', 'woocommerce-subscriptions' ) );
			$orders_to_display[] = $subscription;
		}

		// Assign all order and subscription relationships and filter out non-objects.
		foreach ( $orders_by_type as $order_type => $orders ) {
			foreach ( $orders as $order_id ) {
				$order = wc_get_order( $order_id );

				switch ( $order_type ) {
					case 'renewal':
						$relation = _x( 'Renewal Order', 'relation to order', 'woocommerce-subscriptions' );
						break;
					case 'parent':
						$relation = _x( 'Parent Order', 'relation to order', 'woocommerce-subscriptions' );
						break;
					case 'resubscribe':
						$relation = wcs_is_subscription( $order ) ? _x( 'Resubscribed Subscription', 'relation to order', 'woocommerce-subscriptions' ) : _x( 'Resubscribe Order', 'relation to order', 'woocommerce-subscriptions' );
						break;
					default:
						$relation = _x( 'Unknown Order Type', 'relation to order', 'woocommerce-subscriptions' );
						break;
				}

				if ( $order ) {
					$order->update_meta_data( '_relationship', $relation );
					$orders_to_display[] = $order;
				} else {
					$unknown_orders[] = array(
						'order_id' => $order_id,
						'relation' => $relation,
					);
				}
			}
		}

		$orders_to_display = apply_filters( 'woocommerce_subscriptions_admin_related_orders_to_display', $orders_to_display, $subscriptions, $post );

		wcs_sort_objects( $orders_to_display, 'date_created', 'descending' );

		foreach ( $orders_to_display as $order ) {
			// Skip the order being viewed.
			if ( $order->get_id() === (int) $post->ID ) {
				continue;
			}

			include( dirname( __FILE__ ) . '/views/html-related-orders-row.php' );
		}

		foreach ( $unknown_orders as $order_and_relationship ) {
			$order_id     = $order_and_relationship['order_id'];
			$relationship = $order_and_relationship['relation'];

			include( dirname( __FILE__ ) . '/views/html-unknown-related-orders-row.php' );
		}
	}
}
