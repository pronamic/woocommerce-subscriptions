<?php
/**
 * Customer renewal invoice email (plain text)
 *
 * @author	Brent Shepherd
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.4
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

if ( $order->status == 'pending' ) {
	// translators: %1$s: name of the blog, %2$s: link to checkout payment url, note: no full stop due to url at the end
	printf( esc_html_x( 'An invoice has been created for you to renew your subscription with %1$s. To pay for this invoice please use the following link: %2$s', 'In customer renewal invoice email', 'woocommerce-subscriptions' ), esc_html( get_bloginfo( 'name' ) ), esc_attr( $order->get_checkout_payment_url() ) ) . "\n\n";
} elseif ( 'failed' == $order->status ) {
	// translators: %1$s: name of the blog, %2$s: link to checkout payment url, note: no full stop due to url at the end
	printf( esc_html_x( 'The automatic payment to renew your subscription with %1$s has failed. To reactivate the subscription, please login and pay for the renewal from your account page: %2$s', 'In customer renewal invoice email', 'woocommerce-subscriptions' ), esc_html( get_bloginfo( 'name' ) ), esc_attr( $order->get_checkout_payment_url() ) );
}

echo "****************************************************\n\n";

do_action( 'woocommerce_email_before_order_table', $order, false, true );

printf( __( 'Order number: %s', 'woocommerce-subscriptions' ), $order->get_order_number() ) . "\n";
printf( __( 'Order date: %s', 'woocommerce-subscriptions' ), date_i18n( wc_date_format(), strtotime( $order->order_date ) ) ) . "\n";

do_action( 'woocommerce_email_order_meta', $order, false, true );

echo "\n";

switch ( $order->status ) {
	case 'completed' :
		echo WC_Subscriptions_Email::email_order_items_table( $order, array(
			'show_download_links' => $order->is_download_permitted(),
			'show_sku'            => false,
			'show_purchase_note'  => true,
			'show_image'          => '',
			'image_size'          => '',
			'plain_text'          => true,
			) );
	break;
	case 'processing' :
		echo WC_Subscriptions_Email::email_order_items_table( $order, array(
			'show_download_links' => $order->is_download_permitted(),
			'show_sku'            => true,
			'show_purchase_note'  => true,
			'show_image'          => '',
			'image_size'          => '',
			'plain_text'          => true,
			) );
	break;
	default :
		echo WC_Subscriptions_Email::email_order_items_table( $order, array(
			'show_download_links' => $order->is_download_permitted(),
			'show_sku'            => true,
			'show_purchase_note'  => false,
			'show_image'          => '',
			'image_size'          => '',
			'plain_text'          => true,
			) );
	break;
}

echo "----------\n\n";

if ( $totals = $order->get_order_item_totals() ) {
	foreach ( $totals as $total ) {
		echo $total['label'] . "\t " . $total['value'] . "\n";
	}
}

echo "\n****************************************************\n\n";

do_action( 'woocommerce_email_after_order_table', $order, false, true );

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
