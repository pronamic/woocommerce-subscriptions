<?php
/**
 * Admin new switch order email (plain text)
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.5
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

$count = count( $subscriptions );

// translators: $1: customer's first name, $2: customer's last name, $3: how many subscriptions customer switched
printf( _nx( 'Customer %1$s %2$s has switched their subscription. The details of their new subscription are as follows:', 'Customer %1$s %2$s has switched %3$d of their subscriptions. The details of their new subscriptions are as follows:', $count, 'Used in switch notification admin email', 'woocommerce-subscriptions' ), $order->billing_first_name, $order->billing_last_name, $count );

echo "\n\n****************************************************\n\n";

do_action( 'woocommerce_email_before_order_table', $order, true, true );

echo strtoupper( sprintf( __( 'Order number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) ) . "\n";
echo date_i18n( _x( 'jS F Y', 'date format for order date in notification emails', 'woocommerce-subscriptions' ), strtotime( $order->order_date ) ) . "\n";

do_action( 'woocommerce_email_order_meta', $order, true, true );

echo "\n" . WC_Subscriptions_Email::email_order_items_table( $order, array(
	'show_download_links' => false,
	'show_sku'            => true,
	'show_purchase_note'  => '',
	'show_image'          => '',
	'image_size'          => '',
	'plain_text'          => true,
) );

echo "***********\n\n";

if ( $totals = $order->get_order_item_totals() ) {
	foreach ( $totals as $total ) {
		echo $total['label'] . "\t " . $total['value'] . "\n";
	}
}

// translators: placeholder is edit post link for the order
echo "\n" . sprintf( __( 'View order: %s', 'woocommerce-subscriptions' ), wcs_get_edit_post_link( $order->id ) ) . "\n";
echo "\n****************************************************\n\n";

do_action( 'woocommerce_email_after_order_table', $order, true, true );
remove_filter( 'woocommerce_order_item_meta_end', 'WC_Subscriptions_Switcher::print_switch_link', 10 );

foreach ( $subscriptions as $subscription ) {

	do_action( 'woocommerce_email_before_subscription_table', $subscription , true, true );

	echo strtoupper( sprintf( __( 'Subscription Number: %s', 'woocommerce-subscriptions' ), $subscription->get_order_number() ) ) . "\n";

	echo "\n" . WC_Subscriptions_Email::email_order_items_table( $subscription, array(
		'show_download_links' => false,
		'show_sku'            => true,
		'show_purchase_note'  => '',
		'show_image'          => '',
		'image_size'          => '',
		'plain_text'          => true,
	) );
	echo "***********\n";

	if ( $totals = $subscription->get_order_item_totals() ) {
		foreach ( $totals as $total ) {
			echo $total['label'] . "\t " . $total['value'] . "\n";
		}
	}
	// translators: placeholder is edit post link for the subscription
	echo "\n" . sprintf( _x( 'View Subscription: %s', 'in plain emails for subscription information', 'woocommerce-subscriptions' ), wcs_get_edit_post_link( $subscription->id ) ) . "\n";
	do_action( 'woocommerce_email_after_subscription_table', $subscription , true, true );
}

add_filter( 'woocommerce_order_item_meta_end', 'WC_Subscriptions_Switcher::print_switch_link', 10 );
echo "\n***************************************************\n\n";

do_action( 'woocommerce_email_customer_details', $order, true, true );

echo "\n****************************************************\n\n";
echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
