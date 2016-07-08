<?php
/**
 * Admin new renewal order email (plain text)
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.4
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

// translators: $1: customer's billing first name, $2: customer's billing last name
printf( _x( 'You have received a subscription renewal order from %1$s %2$s. Their order is as follows:', 'Used in admin email: new renewal order', 'woocommerce-subscriptions' ), $order->billing_first_name , $order->billing_last_name );

echo "\n\n";

echo "****************************************************\n\n";

do_action( 'woocommerce_email_before_order_table', $order, true, true );

printf( __( 'Order number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) . "\n";
printf( __( 'Order date: %s', 'woocommerce-subscriptions' ), date_i18n( _x( 'jS F Y', 'date format for order date in notification emails', 'woocommerce-subscriptions' ), strtotime( $order->order_date ) ) ) . "\n";

do_action( 'woocommerce_email_order_meta', $order, true, true );

echo "\n" . WC_Subscriptions_Email::email_order_items_table( $order, array(
	'show_download_links' => false,
	'show_sku'            => true,
	'show_purchase_note'  => '',
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

do_action( 'woocommerce_email_after_order_table', $order, true, true );

echo __( 'Customer details', 'woocommerce-subscriptions' ) . "\n";

if ( $order->billing_email ) {
	// translators: placeholder is customer's billing email
	echo sprintf( __( 'Email: %s', 'woocommerce-subscriptions' ), $order->billing_email ) . "\n";
}

if ( $order->billing_phone ) {
	// translators: placeholder is customer's billing phone number
	echo sprintf( __( 'Tel: %s', 'woocommerce-subscriptions' ), $order->billing_phone ) . "\n";
}

wc_get_template( 'emails/plain/email-addresses.php', array( 'order' => $order ) );

echo "\n****************************************************\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
