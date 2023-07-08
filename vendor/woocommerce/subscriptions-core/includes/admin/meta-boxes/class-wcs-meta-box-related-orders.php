<?php
/**
 * Related Orders Meta Box
 *
 * Display the related orders table on the Edit Order and Edit Subscription screens.
 *
 * @category Admin
 * @package  WooCommerce Subscriptions/Admin/Meta Boxes
 * @version  1.0.0 - Migrated from WooCommerce Subscriptions v2.0
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
	 * @param  WP_Post|WC_Order $post_or_order_object The post object or order object currently being edited.
	 */
	public static function output( $post_or_order_object ) {
		$order = ( $post_or_order_object instanceof WP_Post ) ? wc_get_order( $post_or_order_object->ID ) : $post_or_order_object;
		$post  = ( $post_or_order_object instanceof WP_Post ) ? $post_or_order_object : get_post( $order->get_id() );

		add_action( 'wcs_related_orders_meta_box_rows', __CLASS__ . '::output_rows', 10 );

		include_once dirname( __FILE__ ) . '/views/html-related-orders-table.php';

		if ( has_action( 'woocommerce_subscriptions_related_orders_meta_box' ) ) {
			wcs_deprecated_hook( 'woocommerce_subscriptions_related_orders_meta_box', 'subscriptions-core 5.1.0', 'wcs_related_orders_meta_box' );

			/**
			 * Fires after the Related Orders meta box has been displayed.
			 *
			 * This action is deprecated in favour of 'wcs_related_orders_meta_box'.
			 *
			 * @deprecated subscriptions-core 5.1.0
			 *
			 * @param WC_Order|WC_Subscription $order The order or subscription that is being displayed.
			 * @param WP_Post $post The post object that is being displayed.
			 */
			do_action( 'woocommerce_subscriptions_related_orders_meta_box', $order, $post );
		}

		/**
		 * Fires after the Related Orders meta box has been displayed.
		 *
		 * @since subscriptions-core 5.1.0
		 *
		 * @param WC_Order|WC_Subscription $order The order or subscription that is being displayed.
		 */
		do_action( 'wcs_related_orders_meta_box', $order );
	}

	/**
	 * Displays the renewal orders in the Related Orders meta box.
	 *
	 * @param WC_Order|WC_Subscription $order The order or subscription object being used to display the related orders.
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.0
	 */
	public static function output_rows( $order ) {
		$orders_to_display     = array();
		$subscriptions         = array();
		$initial_subscriptions = array();
		$orders_by_type        = array();
		$unknown_orders        = array(); // Orders which couldn't be loaded.
		$is_subscription       = wcs_is_subscription( $order );
		$this_order            = $order;

		// If this is a subscriptions screen,
		if ( $is_subscription ) {
			$subscription    = wcs_get_subscription( $order );
			$subscriptions[] = $subscription;

			// Resubscribed subscriptions and orders.
			$initial_subscriptions         = wcs_get_subscriptions_for_resubscribe_order( $subscription );
			$orders_by_type['resubscribe'] = WCS_Related_Order_Store::instance()->get_related_order_ids( $subscription, 'resubscribe' );
		} else {
			$subscriptions         = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) );
			$initial_subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'resubscribe' ) ) );
		}

		foreach ( $subscriptions as $subscription ) {
			// If we're on a single subscription or renewal order's page, display the parent orders
			if ( 1 === count( $subscriptions ) && $subscription->get_parent_id() ) {
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

		if ( has_filter( 'woocommerce_subscriptions_admin_related_orders_to_display' ) ) {
			wcs_deprecated_hook( 'woocommerce_subscriptions_admin_related_orders_to_display', 'subscriptions-core 5.1.0', 'wcs_admin_subscription_related_orders_to_display' );

			/**
			 * Filters the orders to display in the Related Orders meta box.
			 *
			 * This filter is deprecated in favour of 'wcs_admin_subscription_related_orders_to_display'.
			 *
			 * @deprecated subscriptions-core 5.1.0
			 *
			 * @param array   $orders_to_display An array of orders to display in the Related Orders meta box.
			 * @param array   $subscriptions An array of subscriptions related to the order.
			 * @param WP_Post $post The order post object.
			 */
			$orders_to_display = apply_filters( 'woocommerce_subscriptions_admin_related_orders_to_display', $orders_to_display, $subscriptions, get_post( $this_order->get_id() ) );
		}

		/**
		 * Filters the orders to display in the Related Orders meta box.
		 *
		 * @since subscriptions-core 5.1.0
		 *
		 * @param array    $orders_to_display An array of orders to display in the Related Orders meta box.
		 * @param array    $subscriptions An array of subscriptions related to the order.
		 * @param WC_Order $order The order object.
		 */
		$orders_to_display = apply_filters( 'wcs_admin_subscription_related_orders_to_display', $orders_to_display, $subscriptions, $this_order );

		wcs_sort_objects( $orders_to_display, 'date_created', 'descending' );

		foreach ( $orders_to_display as $order ) {
			// Skip the current order or subscription being viewed.
			if ( $order->get_id() === $this_order->get_id() ) {
				continue;
			}

			include dirname( __FILE__ ) . '/views/html-related-orders-row.php';
		}

		foreach ( $unknown_orders as $order_and_relationship ) {
			$order_id     = $order_and_relationship['order_id'];
			$relationship = $order_and_relationship['relation'];

			include dirname( __FILE__ ) . '/views/html-unknown-related-orders-row.php';
		}
	}
}
