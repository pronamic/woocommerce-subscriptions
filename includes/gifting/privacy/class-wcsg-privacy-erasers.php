<?php
/**
 * Personal data erasers.
 *
 * @package  WooCommerce Subscriptions Gifting\Privacy
 * @version  2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles erasing of Gifting information from WooCommerce.
 */
class WCSG_Privacy_Erasers {

	/**
	 * Find and erase personal data from subscriptions linked to a user via recipient meta.
	 *
	 * Subscriptions are erased in blocks of 10 to avoid timeouts.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of response data to return to the WP eraser.
	 */
	public static function subscription_data_eraser( $email_address, $page ) {
		$user          = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.
		$subscriptions = array();

		if ( $user instanceof WP_User ) {
			$subscriptions = WCSG_Recipient_Management::get_recipient_subscriptions(
				$user->ID,
				0,
				array(
					'posts_per_page' => 10,
					'paged'          => (int) $page,
				)
			);

			$subscriptions = array_filter( array_map( 'wcs_get_subscription', $subscriptions ) );
		}

		return WCS_Privacy_Erasers::erase_subscription_data_and_generate_response( $subscriptions );
	}

	/**
	 * Find and erase personal data from subscription orders linked to a user via recipient meta.
	 *
	 * Orders are erased in blocks of 10 to avoid timeouts.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param string $email_address The user email address.
	 * @param int    $page  Page.
	 * @return array An array of response data to return to the WP eraser.
	 */
	public static function order_data_eraser( $email_address, $page ) {
		$batch_size      = 10;
		$user            = get_user_by( 'email', $email_address ); // Check if user has an ID in the DB to load stored personal data.
		$erasure_enabled = wc_string_to_bool( get_option( 'woocommerce_erasure_request_removes_order_data', 'no' ) );
		$response        = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( ! $user instanceof WP_User ) {
			return $response;
		}

		$recipient_line_items = WCS_Gifting::get_recipient_order_items( $user->ID );

		if ( empty( $recipient_line_items ) ) {
			return $response;
		}

		$orders_ids = wp_list_pluck( $recipient_line_items, 'order_id', 'order_id' );

		// Sort the orders by their ID so we can apply pagination between requests in a consistent way.
		ksort( $orders_ids );

		// Apply pagination.
		$orders_ids = array_slice( $orders_ids, ( (int) $page - 1 ) * $batch_size, $batch_size );

		if ( 0 < count( $orders_ids ) ) {
			foreach ( $orders_ids as $order_id ) {
				$order = wc_get_order( $order_id );

				// We might have a subscription object here so make sure we have an order before continuing.
				if ( ! wcs_is_order( $order ) ) {
					continue;
				}

				if ( apply_filters( 'woocommerce_privacy_erase_order_personal_data', $erasure_enabled, $order ) ) {

					// We can only anonymise all personal data from the order if it's a renewal, resubscribe or switch order. Parent orders contain purchaser details.
					if ( wcs_order_contains_subscription( $order, array( 'renewal', 'switch', 'resubscribe' ) ) ) {
						WC_Privacy_Erasers::remove_order_personal_data( $order );

						/* Translators: %s Order number. */
						$response['messages'][]    = sprintf( __( 'Removed personal data from order %s.', 'woocommerce-subscriptions' ), $order->get_order_number() );
						$response['items_removed'] = true;
					} else {
						self::remove_personal_recipient_line_item_data( $order, $user->ID );

						/* Translators: %s Order number. */
						$response['messages'][]    = sprintf( __( 'Removed recipient personal data from order %s line items.', 'woocommerce-subscriptions' ), $order->get_order_number() );
						$response['items_removed'] = true;
					}
				} else {
					/* Translators: %s Order number. */
					$response['messages'][]     = sprintf( __( 'Personal data within order %s has been retained.', 'woocommerce-subscriptions' ), $order->get_order_number() );
					$response['items_retained'] = true;
				}
			}

			$response['done'] = $batch_size > count( $orders_ids );
		}

		return $response;
	}

	/**
	 * Remove personal recipient line item meta from an order or subscription.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param WC_Order|WC_Subscription $order        The order or subscription object to remove recipient line item meta from.
	 * @param int                      $recipient_id Optional. Default behaviour is to remove all recipient line item meta. Pass a user ID to only remove line item meta specific to that user.
	 */
	public static function remove_personal_recipient_line_item_data( $order, $recipient_id = 0 ) {
		if ( ! is_callable( array( $order, 'get_items' ) ) ) {
			return;
		}

		foreach ( $order->get_items() as $line_item ) {
			if ( ! $line_item->meta_exists( 'wcsg_recipient' ) ) {
				continue;
			}

			if ( 0 !== $recipient_id ) {
				// Get the user ID from the line item meta.
				$user_id = str_replace( 'wcsg_recipient_id_', '', $line_item->get_meta( 'wcsg_recipient', true ) );

				// Continue if we're not concerned with this user.
				if ( ! is_numeric( $user_id ) || (int) $user_id !== $recipient_id ) {
					continue;
				}
			}

			$line_item->delete_meta_data( 'wcsg_recipient' );
			$line_item->save();
		}
	}

	/**
	 * Remove a recipient from a subscription.
	 *
	 * @since 7.8.0 - Originally implemented in WooCommerce Subscriptions Gifting 2.0.1.
	 * @param WC_Subscription $subscription The subscription object.
	 */
	public static function remove_recipient_meta( $subscription ) {
		if ( is_callable( array( $subscription, 'delete_meta_data' ) ) ) {
			$subscription->delete_meta_data( '_recipient_user' );
			$subscription->save();
		}
	}
}
