<?php
/**
 * Order/Subscription details table shown in emails.
 *
 * @author Prospress
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 2.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

do_action( 'woocommerce_email_before_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email );

if ( 'order' == $order_type ) {
	echo sprintf( __( 'Order number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) . "\n";
	echo sprintf( __( 'Order date: %s', 'woocommerce-subscriptions' ), date_i18n( _x( 'jS F Y', 'date format for order date in notification emails', 'woocommerce-subscriptions' ), wcs_date_to_time( $order->order_date ) ) ) . "\n";
} else {
	echo sprintf( __( 'Subscription Number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) . "\n";
}
echo "\n" . WC_Subscriptions_Email::email_order_items_table( $order, $order_items_table_args );

echo "----------\n\n";

if ( $totals = $order->get_order_item_totals() ) {
	foreach ( $totals as $total ) {
		echo $total['label'] . "\t " . $total['value'] . "\n";
	}
}

do_action( 'woocommerce_email_after_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email );
