<?php
/**
 * Customer completed renewal order email (plain text)
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.4
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

// translators: placeholder is the name of the site
printf( __( 'Hi there. Your subscription renewal order with %s has been completed. Your order details are shown below for your reference:', 'woocommerce-subscriptions' ), get_option( 'blogname' ) ) . "\n\n";

echo "****************************************************\n\n";

do_action( 'woocommerce_email_before_order_table', $order, false, true );

printf( __( 'Order number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) . "\n";
printf( __( 'Order date: %s', 'woocommerce-subscriptions' ), date_i18n( wc_date_format(), strtotime( $order->order_date ) ) ) . "\n";

do_action( 'woocommerce_email_order_meta', $order, false, true );

echo "\n" . WC_Subscriptions_Email::email_order_items_table( $order, array(
	'show_download_links' => true,
	'show_sku'            => false,
	'show_purchase_note'  => true,
	'show_image'          => '',
	'image_size'          => '',
	'plain_text'          => true,
) );

echo "----------\n\n";

if ( $totals = $order->get_order_item_totals() ) {
	foreach ( $totals as $total ) {
		echo $total['label'] . "\t " . $total['value'] . "\n";
	}
}

echo "\n****************************************************\n\n";

do_action( 'woocommerce_email_after_order_table', $order, false, true );

echo __( 'Your details', 'woocommerce-subscriptions' ) . "\n\n";

if ( $order->billing_email ) {
	// translators: placeholder is customer's billing email
	printf( __( 'Email: %s', 'woocommerce-subscriptions' ), $order->billing_email );
	echo "\n";
}

if ( $order->billing_phone ) {
	// translators: placeholder is customer's billing phone number
	printf( __( 'Tel: %s', 'woocommerce-subscriptions' ), $order->billing_phone );
	echo "\n";
}

wc_get_template( 'emails/plain/email-addresses.php', array( 'order' => $order ) );

echo "\n****************************************************\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
