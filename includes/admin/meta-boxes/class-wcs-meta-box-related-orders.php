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

		$subscriptions = array();
		$orders        = array();
		$is_subscription_screen = wcs_is_subscription( $post->ID );

		// On the subscription page, just show related orders
		if ( $is_subscription_screen ) {
			$this_subscription = wcs_get_subscription( $post->ID );
			$subscriptions[]   = $this_subscription;
		} elseif ( wcs_order_contains_subscription( $post->ID, array( 'parent', 'renewal' ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $post->ID, array( 'order_type' => array( 'parent', 'renewal' ) ) );
		}

		// First, display all the subscriptions
		foreach ( $subscriptions as $subscription ) {
			wcs_set_objects_property( $subscription, 'relationship', __( 'Subscription', 'woocommerce-subscriptions' ), 'set_prop_only' );
			$orders[] = $subscription;
		}

		//Resubscribed
		$initial_subscriptions = array();

		if ( $is_subscription_screen ) {

			$initial_subscriptions = wcs_get_subscriptions_for_resubscribe_order( $this_subscription );

			$resubscribe_order_ids = WCS_Related_Order_Store::instance()->get_related_order_ids( $this_subscription, 'resubscribe' );

			foreach ( $resubscribe_order_ids as $order_id ) {
				$order    = wc_get_order( $order_id );
				$relation = wcs_is_subscription( $order ) ? _x( 'Resubscribed Subscription', 'relation to order', 'woocommerce-subscriptions' ) : _x( 'Resubscribe Order', 'relation to order', 'woocommerce-subscriptions' );
				wcs_set_objects_property( $order, 'relationship', $relation, 'set_prop_only' );
				$orders[] = $order;
			}
		} else if ( wcs_order_contains_subscription( $post->ID, array( 'resubscribe' ) ) ) {
			$initial_subscriptions = wcs_get_subscriptions_for_order( $post->ID, array( 'order_type' => array( 'resubscribe' ) ) );
		}

		foreach ( $initial_subscriptions as $subscription ) {
			wcs_set_objects_property( $subscription, 'relationship', _x( 'Initial Subscription', 'relation to order', 'woocommerce-subscriptions' ), 'set_prop_only' );
			$orders[] = $subscription;
		}

		// Now, if we're on a single subscription or renewal order's page, display the parent orders
		if ( 1 == count( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				if ( $subscription->get_parent_id() ) {
					$order = $subscription->get_parent();
					wcs_set_objects_property( $order, 'relationship', _x( 'Parent Order', 'relation to order', 'woocommerce-subscriptions' ), 'set_prop_only' );
					$orders[] = $order;
				}
			}
		}

		// Finally, display the renewal orders
		foreach ( $subscriptions as $subscription ) {

			foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $order ) {
				wcs_set_objects_property( $order, 'relationship', _x( 'Renewal Order', 'relation to order', 'woocommerce-subscriptions' ), 'set_prop_only' );
				$orders[] = $order;
			}
		}

		$orders = apply_filters( 'woocommerce_subscriptions_admin_related_orders_to_display', $orders, $subscriptions, $post );

		foreach ( $orders as $order ) {

			if ( wcs_get_objects_property( $order, 'id' ) == $post->ID ) {
				continue;
			}
			include( dirname( __FILE__ ) . '/views/html-related-orders-row.php' );
		}
	}
}
