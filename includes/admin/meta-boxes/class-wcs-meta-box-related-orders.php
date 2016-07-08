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
			$order = ( false == $subscription->order ) ? $subscription : $subscription->order;
		} else {
			$order = wc_get_order( $post->ID );
		}

		add_action( 'woocommerce_subscriptions_related_orders_meta_box_rows', __CLASS__ . '::output_rows', 10 );

		include_once( 'views/html-related-orders-table.php' );

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

		// On the subscription page, just show related orders
		if ( wcs_is_subscription( $post->ID ) ) {
			$subscriptions[] = wcs_get_subscription( $post->ID );
		} elseif ( wcs_order_contains_subscription( $post->ID, array( 'parent', 'renewal' ) ) ) {
			$subscriptions = wcs_get_subscriptions_for_order( $post->ID, array( 'order_type' => array( 'parent', 'renewal' ) ) );
		}

		// First, display all the subscriptions
		foreach ( $subscriptions as $subscription ) {
			$subscription->relationship = __( 'Subscription', 'woocommerce-subscriptions' );
			$orders[] = $subscription;
		}

		//Resubscribed
		$initial_subscriptions = array();

		if ( wcs_is_subscription( $post->ID ) ) {

			$initial_subscriptions = wcs_get_subscriptions_for_resubscribe_order( $post->ID );

			$resubscribed_subscriptions = get_posts( array(
				'meta_key'       => '_subscription_resubscribe',
				'meta_value'     => $post->ID,
				'post_type'      => 'shop_subscription',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			) );

			foreach ( $resubscribed_subscriptions as $subscription ) {
				$subscription = wcs_get_subscription( $subscription );
				$subscription->relationship = _x( 'Resubscribed Subscription', 'relation to order', 'woocommerce-subscriptions' );
				$orders[] = $subscription;
			}
		} else if ( wcs_order_contains_subscription( $post->ID, array( 'resubscribe' ) ) ) {
			$initial_subscriptions = wcs_get_subscriptions_for_order( $post->ID, array( 'order_type' => array( 'resubscribe' ) ) );
		}

		foreach ( $initial_subscriptions as $subscription ) {
			$subscription->relationship = _x( 'Initial Subscription', 'relation to order', 'woocommerce-subscriptions' );
			$orders[] = $subscription;
		}

		// Now, if we're on a single subscription or renewal order's page, display the parent orders
		if ( 1 == count( $subscriptions ) ) {
			foreach ( $subscriptions as $subscription ) {
				if ( false !== $subscription->order ) {
					$subscription->order->relationship = _x( 'Parent Order', 'relation to order', 'woocommerce-subscriptions' );
					$orders[] = $subscription->order;
				}
			}
		}

		// Finally, display the renewal orders
		foreach ( $subscriptions as $subscription ) {

			foreach ( $subscription->get_related_orders( 'all', 'renewal' ) as $order ) {
				$order->relationship = _x( 'Renewal Order', 'relation to order', 'woocommerce-subscriptions' );
				$orders[] = $order;
			}
		}

		foreach ( $orders as $order ) {
			if ( $order->id == $post->ID ) {
				continue;
			}
			include( 'views/html-related-orders-row.php' );
		}
	}
}
