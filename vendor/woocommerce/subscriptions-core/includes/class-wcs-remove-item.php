<?php
/**
 * Subscriptions Remove Item
 *
 *
 * @author   Prospress
 * @since    2.0
 */
class WCS_Remove_Item {

	/**
	 * Initialise class hooks & filters when the file is loaded
	 *
	 * @since 2.0
	 */
	public static function init() {

		// Check if a user is requesting to remove or re-add an item to their subscription
		add_action( 'init', __CLASS__ . '::maybe_remove_or_add_item_to_subscription', 100 );
	}

	/**
	 * Returns the link used to remove an item from a subscription
	 *
	 * @param int $subscription_id
	 * @param int $order_item_id
	 * @since 2.0
	 */
	public static function get_remove_url( $subscription_id, $order_item_id ) {

		$remove_link = add_query_arg(
			array(
				'subscription_id' => $subscription_id,
				'remove_item'     => $order_item_id,
			)
		);
		$remove_link = wp_nonce_url( $remove_link, $subscription_id );

		return $remove_link;
	}

	/**
	 * Returns the link to undo removing an item from a subscription
	 *
	 * @param int $subscription_id
	 * @param int $order_item_id
	 * @param string $base_url
	 * @since 2.0
	 */
	public static function get_undo_remove_url( $subscription_id, $order_item_id, $base_url ) {

		$undo_link = add_query_arg(
			array(
				'subscription_id'  => $subscription_id,
				'undo_remove_item' => $order_item_id,
			),
			$base_url
		);
		$undo_link = wp_nonce_url( $undo_link, $subscription_id );

		return $undo_link;
	}

	/**
	 * Process the remove or re-add a line item from a subscription request.
	 *
	 * @since 2.0
	 */
	public static function maybe_remove_or_add_item_to_subscription() {

		if ( isset( $_GET['subscription_id'] ) && ( isset( $_GET['remove_item'] ) || isset( $_GET['undo_remove_item'] ) ) && isset( $_GET['_wpnonce'] ) ) {

			$subscription = wcs_is_subscription( $_GET['subscription_id'] ) ? wcs_get_subscription( $_GET['subscription_id'] ) : false;
			$undo_request = isset( $_GET['undo_remove_item'] );
			$item_id      = $undo_request ? $_GET['undo_remove_item'] : $_GET['remove_item'];

			if ( false === $subscription ) {
				// translators: %d: subscription ID.
				wc_add_notice( sprintf( _x( 'Subscription #%d does not exist.', 'hash before subscription ID', 'woocommerce-subscriptions' ), $_GET['subscription_id'] ), 'error' );
				wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
				exit;
			}

			if ( self::validate_remove_items_request( $subscription, $item_id, $undo_request ) ) {

				if ( $undo_request ) {
					// handle undo request
					$removed_item = WC()->session->get( 'removed_subscription_items', array() );

					if ( ! empty( $removed_item[ $item_id ] ) && $subscription->get_id() == $removed_item[ $item_id ] ) {

						// restore the item
						wcs_update_order_item_type( $item_id, 'line_item', $subscription->get_id() );

						WC()->session->set( 'removed_subscription_items', $removed_item );

						// restore download permissions for this item
						$subscription = wcs_get_subscription( $subscription->get_id() );
						$line_items = $subscription->get_items();
						$line_item  = $line_items[ $item_id ];
						$_product   = $line_item->get_product();
						$product_id = wcs_get_canonical_product_id( $line_item );

						if ( $_product && $_product->exists() && $_product->is_downloadable() ) {

							$downloads = wcs_get_objects_property( $_product, 'downloads' );

							foreach ( array_keys( $downloads ) as $download_id ) {
								wc_downloadable_file_permission( $download_id, $product_id, $subscription, $line_item['qty'] );
							}
						}

						// translators: 1$: product name, 2$: product id
						$subscription->add_order_note( sprintf( _x( 'Customer added "%1$s" (Product ID: #%2$d) via the My Account page.', 'used in order note', 'woocommerce-subscriptions' ), wcs_get_line_item_name( $line_item ), $product_id ) );

						do_action( 'wcs_user_readded_item', $line_item, $subscription );

					} else {
						wc_add_notice( __( 'Your request to undo your previous action was unsuccessful.', 'woocommerce-subscriptions' ) );
					}
				} else {

					// handle remove item requests
					WC()->session->set( 'removed_subscription_items', array( $item_id => $subscription->get_id() ) );

					// remove download access for the item
					$line_items = $subscription->get_items();
					$line_item  = $line_items[ $item_id ];
					$product_id = wcs_get_canonical_product_id( $line_item );

					WCS_Download_Handler::revoke_downloadable_file_permission( $product_id, $subscription->get_id(), $subscription->get_user_id() );

					// remove the line item from subscription but preserve its data in the DB
					wcs_update_order_item_type( $item_id, 'line_item_removed', $subscription->get_id() );

					// translators: 1$: product name, 2$: product id
					$subscription->add_order_note( sprintf( _x( 'Customer removed "%1$s" (Product ID: #%2$d) via the My Account page.', 'used in order note', 'woocommerce-subscriptions' ), wcs_get_line_item_name( $line_item ), $product_id ) );

					// translators: placeholders are 1$: item name, and, 2$: opening and, 3$: closing link tags
					wc_add_notice( sprintf( __( 'You have successfully removed "%1$s" from your subscription. %2$sUndo?%3$s', 'woocommerce-subscriptions' ), $line_item['name'], '<a href="' . esc_url( self::get_undo_remove_url( $subscription->get_id(), $item_id, $subscription->get_view_order_url() ) ) . '" >', '</a>' ) );

					do_action( 'wcs_user_removed_item', $line_item, $subscription );
				}
			}

			/**
			 * In WooCommerce 3.0 the subscription object and its items override the database with their current content,
			 * so we lost the changes we just did with `wc_update_order_item`. Re-reading the object fixes this problem.
			 */
			$subscription = wcs_get_subscription( $subscription->get_id() );
			$subscription->calculate_totals();
			wp_safe_redirect( $subscription->get_view_order_url() );
			exit;

		}

	}

	/**
	 * Validate the incoming request to either remove an item or add and item back to a subscription that was previously removed.
	 * Add an descriptive notice to the page whether or not the request was validated or not.
	 *
	 * @since 2.0
	 * @param WC_Subscription $subscription
	 * @param int $order_item_id
	 * @param bool $undo_request bool
	 * @return bool
	 */
	private static function validate_remove_items_request( $subscription, $order_item_id, $undo_request = false ) {

		$subscription_items = $subscription->get_items();
		$response           = false;

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], $_GET['subscription_id'] ) ) {

			wc_add_notice( __( 'Security error. Please contact us if you need assistance.', 'woocommerce-subscriptions' ), 'error' );

		} elseif ( ! current_user_can( 'edit_shop_subscription_line_items', $subscription->get_id() ) ) {

			wc_add_notice( __( 'You cannot modify a subscription that does not belong to you.', 'woocommerce-subscriptions' ), 'error' );

		} elseif ( ! $undo_request && ! isset( $subscription_items[ $order_item_id ] ) ) { // only need to validate the order item id when removing

			wc_add_notice( __( 'You cannot remove an item that does not exist. ', 'woocommerce-subscriptions' ), 'error' );

		} elseif ( ! $subscription->payment_method_supports( 'subscription_amount_changes' ) ) {

			wc_add_notice( __( 'The item was not removed because this Subscription\'s payment method does not support removing an item.', 'woocommerce-subscriptions' ) );

		} else {

			$response = true;
		}

		return $response;
	}

}
