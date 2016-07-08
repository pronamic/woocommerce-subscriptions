<?php
/**
 * Cancelled Subscription email (plain text)
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
printf( __( 'A subscription belonging to %1$s %2$s has been cancelled. Their subscription\'s details are as follows:', 'woocommerce-subscriptions' ), $subscription->billing_first_name, $subscription->billing_last_name );

echo "\n\n****************************************************\n";

echo strtoupper( sprintf( __( 'Subscription Number: %s', 'woocommerce-subscriptions' ), $subscription->get_order_number() ) ) . "\n";
// translators: placeholder is last time subscription was paid
printf( __( 'Last Payment: %s', 'woocommerce-subscriptions' ), $subscription->get_date_to_display( 'last_payment' ) ) . "\n";

$end_time = $subscription->get_time( 'end', 'site' );

if ( ! empty( $end_time ) ) {
	// translators: placeholder is localised date string
	printf( __( 'End of Prepaid Term: %s', 'woocommerce-subscriptions' ), date_i18n( wc_date_format(), $end_time ) ) . "\n";
}

do_action( 'woocommerce_email_order_meta', $subscription, true, true );

echo "\n\n****************************************************\n\n";

do_action( 'woocommerce_email_before_subscription_table', $subscription, true, true );
echo WC_Subscriptions_Email::email_order_items_table( $subscription, array(
	'show_download_links' => false,
	'show_sku'            => true,
	'show_purchase_note'  => '',
	'show_image'          => '',
	'image_size'          => '',
	'plain_text'          => true,
) );
echo "***********\n\n";

if ( $totals = $subscription->get_order_item_totals() ) {
	foreach ( $totals as $total ) {
		echo $total['label'] . "\t " . $total['value'] . "\n";
	}
}
// translators: placeholder is edit post link for the subscription
echo "\n" . sprintf( _x( 'View Subscription: %s', 'in plain emails for subscription information', 'woocommerce-subscriptions' ), wcs_get_edit_post_link( $subscription->id ) ) . "\n";
do_action( 'woocommerce_email_after_subscription_table', $subscription, true, true );

echo "\n***************************************************\n\n";

do_action( 'woocommerce_email_customer_details', $subscription, true, true );

echo "\n****************************************************\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
