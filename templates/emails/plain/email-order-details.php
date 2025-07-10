<?php
/**
 * Order/Subscription details table shown in emails.
 *
 * @author  Prospress
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

do_action( 'woocommerce_email_before_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email );

if ( 'order' == $order_type ) {
	echo esc_html( sprintf( __( 'Order number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) ) . "\n";
	echo esc_html( sprintf( __( 'Order date: %s', 'woocommerce-subscriptions' ), wcs_format_datetime( wcs_get_objects_property( $order, 'date_created' ) ) ) ) . "\n";
} else {
	echo esc_html( sprintf( __( 'Subscription Number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) ) . "\n";
}
echo "\n" . esc_html( WC_Subscriptions_Email::email_order_items_table( $order, $order_items_table_args ) );

echo "----------\n\n";

if ( $totals = $order->get_order_item_totals() ) {
	foreach ( $totals as $total ) {
		echo esc_html( $total['label'] ) . "\t " . esc_html( $total['value'] ) . "\n";
	}
}

do_action( 'woocommerce_email_after_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email );
