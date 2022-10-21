<?php
/**
 * Update Subscriptions to 1.2.0
 *
 * Version 1.2 introduced a massive change to the order meta data schema. This goes through
 * and upgrades the existing data on all orders to the new schema.
 *
 * The upgrade process is timeout safe as it keeps a record of the orders upgraded and only
 * deletes this record once all orders have been upgraded successfully. If operating on a huge
 * number of orders and the upgrade process times out, only the orders not already upgraded
 * will be upgraded in future requests that trigger this function.
 *
 * @author      Prospress
 * @category    Admin
 * @package     WooCommerce Subscriptions/Admin/Upgrades
 * @version     1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WCS_Upgrade_1_2 {

	public static function init() {
		global $wpdb;

		// Get IDs only and use a direct DB query for efficiency
		$orders_to_upgrade = $wpdb->get_col( "SELECT ID FROM $wpdb->posts WHERE post_type = 'shop_order' AND post_parent = 0" );

		$upgraded_orders = get_option( 'wcs_1_2_upgraded_order_ids', array() );

		// Transition deprecated subscription status if we aren't in the middle of updating orders
		if ( empty( $upgraded_orders ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->usermeta SET meta_value = replace( meta_value, 's:9:\"suspended\"', 's:7:\"on-hold\"' ) WHERE meta_key LIKE %s", '%_woocommerce_subscriptions' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->usermeta SET meta_value = replace( meta_value, 's:6:\"failed\"', 's:9:\"cancelled\"' ) WHERE meta_key LIKE %s", '%_woocommerce_subscriptions' ) );
		}

		$orders_to_upgrade = array_diff( $orders_to_upgrade, $upgraded_orders );

		// Upgrade all _sign_up_{field} order meta to new order data format
		foreach ( $orders_to_upgrade as $order_id ) {

			$order = wc_get_order( $order_id );

			// Manually check if a product in an order is a subscription, we can't use wcs_order_contains_subscription( $order ) because it relies on the new data structure
			$contains_subscription = false;
			foreach ( $order->get_items() as $order_item ) {
				if ( WC_Subscriptions_Product::is_subscription( WC_Subscriptions_Order::get_items_product_id( $order_item ) ) ) {
					$contains_subscription = true;
					break;
				}
			}

			if ( ! $contains_subscription ) {
				continue;
			}

			$trial_lengths = WC_Subscriptions_Order::get_meta( $order, '_order_subscription_trial_lengths', array() );
			$trial_length = array_pop( $trial_lengths );

			$has_trial = ! empty( $trial_length ) && $trial_length > 0;

			$sign_up_fee_total = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_total', 0 );

			// Create recurring_* meta data from existing cart totals
			$cart_discount = $order->get_total_discount();
			update_post_meta( $order_id, '_order_recurring_discount_cart', $cart_discount );

			$order_discount = $order->get_total_discount();
			update_post_meta( $order_id, '_order_recurring_discount_total', $order_discount );

			$order_shipping_tax = get_post_meta( $order_id, '_order_shipping_tax', true );
			update_post_meta( $order_id, '_order_recurring_shipping_tax_total', $order_shipping_tax );

			$order_tax = get_post_meta( $order_id, '_order_tax', true ); // $order->get_total_tax() includes shipping tax
			update_post_meta( $order_id, '_order_recurring_tax_total', $order_tax );

			$order_total = $order->get_total();
			update_post_meta( $order_id, '_order_recurring_total', $order_total );

			// Set order totals to include sign up fee fields, if there was a sign up fee on the order and a trial period (other wise, the recurring totals are correct)
			if ( $sign_up_fee_total > 0 ) {

				// Order totals need to be changed to be equal to sign up fee totals
				if ( $has_trial ) {

					$cart_discount  = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_cart', 0 );
					$order_discount = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_total', 0 );
					$order_tax      = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 );
					$order_total    = $sign_up_fee_total;

				} else { // No trial, sign up fees need to be added to order totals

					$cart_discount  += WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_cart', 0 );
					$order_discount += WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_total', 0 );
					$order_tax      += WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 );
					$order_total    += $sign_up_fee_total;

				}

				update_post_meta( $order_id, '_order_total', $order_total );
				update_post_meta( $order_id, '_cart_discount', $cart_discount );
				update_post_meta( $order_id, '_order_discount', $order_discount );
				update_post_meta( $order_id, '_order_tax', $order_tax );

			}

			// Make sure we get order taxes in WC 1.x format
			if ( false == WC_Subscriptions_Upgrader::$is_wc_version_2 ) {

				$order_taxes = $order->get_taxes();

			} else {

				$order_tax_row = $wpdb->get_row( $wpdb->prepare( "
					SELECT * FROM {$wpdb->postmeta}
					WHERE meta_key = '_order_taxes_old'
					AND post_id = %s
					", $order_id )
				);

				$order_taxes = (array) maybe_unserialize( $order_tax_row->meta_value );
			}

			// Set recurring taxes to order taxes, if using WC 2.0, this will be migrated to the new format in @see WC_Subscriptions_Upgrader::upgrade_to_latest_wc()
			update_post_meta( $order_id, '_order_recurring_taxes', $order_taxes );

			$sign_up_fee_taxes = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_taxes', array() );

			// Update order taxes to include sign up fee taxes
			foreach ( $sign_up_fee_taxes as $index => $sign_up_tax ) {

				if ( $has_trial && $sign_up_fee_total > 0 ) { // Order taxes need to be set to the same as the sign up fee taxes

					if ( isset( $sign_up_tax['cart_tax'] ) && $sign_up_tax['cart_tax'] > 0 ) {
						$order_taxes[ $index ]['cart_tax'] = $sign_up_tax['cart_tax'];
					}
				} elseif ( ! $has_trial && $sign_up_fee_total > 0 ) { // Sign up fee taxes need to be added to order taxes

					if ( isset( $sign_up_tax['cart_tax'] ) && $sign_up_tax['cart_tax'] > 0 ) {
						$order_taxes[ $index ]['cart_tax'] += $sign_up_tax['cart_tax'];
					}
				}
			}

			if ( false == WC_Subscriptions_Upgrader::$is_wc_version_2 ) { // Doing it right: updated Subs *before* updating WooCommerce, the WooCommerce updater will take care of data migration

				update_post_meta( $order_id, '_order_taxes', $order_taxes );

			} else { // Doing it wrong: updated Subs *after* updating WooCommerce, need to store in WC2.0 tax structure

				$index = 0;
				$new_order_taxes = $order->get_taxes();

				foreach ( $new_order_taxes as $item_id => $order_tax ) {

					$index = $index + 1;

					if ( ! isset( $order_taxes[ $index ]['label'] ) || ! isset( $order_taxes[ $index ]['cart_tax'] ) || ! isset( $order_taxes[ $index ]['shipping_tax'] ) ) {
						continue;
					}

					// Add line item meta
					if ( $item_id ) {
						wc_update_order_item_meta( $item_id, 'compound', absint( isset( $order_taxes[ $index ]['compound'] ) ? $order_taxes[ $index ]['compound'] : 0 ) );
						wc_update_order_item_meta( $item_id, 'tax_amount', wc_format_decimal( $order_taxes[ $index ]['cart_tax'] ) );
						wc_update_order_item_meta( $item_id, 'shipping_tax_amount', wc_format_decimal( $order_taxes[ $index ]['shipping_tax'] ) );
					}
				}
			}

			/* Upgrade each order item to use new Item Meta schema */
			$order_subscription_periods       = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_periods', array() );
			$order_subscription_intervals     = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_intervals', array() );
			$order_subscription_lengths       = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_lengths', array() );
			$order_subscription_trial_lengths = WC_Subscriptions_Order::get_meta( $order_id, '_order_subscription_trial_lengths', array() );

			$order_items = $order->get_items();

			foreach ( $order_items as $index => $order_item ) {

				$product_id = WC_Subscriptions_Order::get_items_product_id( $order_item );
				$item_meta  = new WC_Order_Item_Meta( $order_item['item_meta'] );

				$subscription_interval     = ( isset( $order_subscription_intervals[ $product_id ] ) ) ? $order_subscription_intervals[ $product_id ] : 1;
				$subscription_length       = ( isset( $order_subscription_lengths[ $product_id ] ) ) ? $order_subscription_lengths[ $product_id ] : 0;
				$subscription_trial_length = ( isset( $order_subscription_trial_lengths[ $product_id ] ) ) ? $order_subscription_trial_lengths[ $product_id ] : 0;

				$subscription_sign_up_fee  = WC_Subscriptions_Order::get_meta( $order, '_cart_contents_sign_up_fee_total', 0 );

				if ( $sign_up_fee_total > 0 ) {

					// Discounted price * Quantity
					$sign_up_fee_line_total = WC_Subscriptions_Order::get_meta( $order, '_cart_contents_sign_up_fee_total', 0 );
					$sign_up_fee_line_tax   = WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 );

					// Base price * Quantity
					$sign_up_fee_line_subtotal     = WC_Subscriptions_Order::get_meta( $order, '_cart_contents_sign_up_fee_total', 0 ) + WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_discount_cart', 0 );
					$sign_up_fee_propotion         = ( $sign_up_fee_line_total > 0 ) ? $sign_up_fee_line_subtotal / $sign_up_fee_line_total : 0;
					$sign_up_fee_line_subtotal_tax = WC_Subscriptions_Manager::get_amount_from_proportion( WC_Subscriptions_Order::get_meta( $order, '_sign_up_fee_tax_total', 0 ), $sign_up_fee_propotion );

					if ( $has_trial ) { // Set line item totals equal to sign up fee totals

						$order_item['line_subtotal']     = $sign_up_fee_line_subtotal;
						$order_item['line_subtotal_tax'] = $sign_up_fee_line_subtotal_tax;
						$order_item['line_total']        = $sign_up_fee_line_total;
						$order_item['line_tax']          = $sign_up_fee_line_tax;

					} else { // No trial period, sign up fees need to be added to order totals

						$order_item['line_subtotal']     += $sign_up_fee_line_subtotal;
						$order_item['line_subtotal_tax'] += $sign_up_fee_line_subtotal_tax;
						$order_item['line_total']        += $sign_up_fee_line_total;
						$order_item['line_tax']          += $sign_up_fee_line_tax;

					}
				}

				// Upgrading with WC 1.x
				if ( is_callable( array( $item_meta, 'add' ) ) ) {

					$item_meta->add( '_subscription_period', $order_subscription_periods[ $product_id ] );
					$item_meta->add( '_subscription_interval', $subscription_interval );
					$item_meta->add( '_subscription_length', $subscription_length );
					$item_meta->add( '_subscription_trial_length', $subscription_trial_length );

					$item_meta->add( '_subscription_recurring_amount', $order_item['line_subtotal'] ); // WC_Subscriptions_Product::get_price() would return a price without filters applied
					$item_meta->add( '_subscription_sign_up_fee', $subscription_sign_up_fee );

					// Set recurring amounts for the item
					$item_meta->add( '_recurring_line_total', $order_item['line_total'] );
					$item_meta->add( '_recurring_line_tax', $order_item['line_tax'] );
					$item_meta->add( '_recurring_line_subtotal', $order_item['line_subtotal'] );
					$item_meta->add( '_recurring_line_subtotal_tax', $order_item['line_subtotal_tax'] );

					$order_item['item_meta'] = $item_meta->meta;

					$order_items[ $index ] = $order_item;

				} else { // Ignoring all advice, upgrading 4 months after version 1.2 was released, and doing it with WC 2.0 installed

					wc_add_order_item_meta( $index, '_subscription_period', $order_subscription_periods[ $product_id ] );
					wc_add_order_item_meta( $index, '_subscription_interval', $subscription_interval );
					wc_add_order_item_meta( $index, '_subscription_length', $subscription_length );
					wc_add_order_item_meta( $index, '_subscription_trial_length', $subscription_trial_length );
					wc_add_order_item_meta( $index, '_subscription_trial_period', $order_subscription_periods[ $product_id ] );

					wc_add_order_item_meta( $index, '_subscription_recurring_amount', $order_item['line_subtotal'] );
					wc_add_order_item_meta( $index, '_subscription_sign_up_fee', $subscription_sign_up_fee );

					// Calculated recurring amounts for the item
					wc_add_order_item_meta( $index, '_recurring_line_total', $order_item['line_total'] );
					wc_add_order_item_meta( $index, '_recurring_line_tax', $order_item['line_tax'] );
					wc_add_order_item_meta( $index, '_recurring_line_subtotal', $order_item['line_subtotal'] );
					wc_add_order_item_meta( $index, '_recurring_line_subtotal_tax', $order_item['line_subtotal_tax'] );

					if ( $sign_up_fee_total > 0 ) { // Order totals have changed
						wc_update_order_item_meta( $index, '_line_subtotal', wc_format_decimalw( $order_item['line_subtotal'] ) );
						wc_update_order_item_meta( $index, '_line_subtotal_tax', wc_format_decimal( $order_item['line_subtotal_tax'] ) );
						wc_update_order_item_meta( $index, '_line_total', wc_format_decimal( $order_item['line_total'] ) );
						wc_update_order_item_meta( $index, '_line_tax', wc_format_decimal( $order_item['line_tax'] ) );
					}
				}
			}

			// Save the new meta on the order items for WC 1.x (the API functions already saved the data for WC2.x)
			if ( false == WC_Subscriptions_Upgrader::$is_wc_version_2 ) {
				update_post_meta( $order_id, '_order_items', $order_items );
			}

			$upgraded_orders[] = $order_id;

			update_option( 'wcs_1_2_upgraded_order_ids', $upgraded_orders );

		}
	}
}
