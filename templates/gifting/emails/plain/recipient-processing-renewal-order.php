<?php
/**
 * Recipient e-mail: processing renewal order.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

echo esc_html__( 'Your subscription renewal order has been received and is now being processed. Your order details are shown below for your reference:', 'woocommerce-subscriptions' );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

if ( is_callable( array( 'WC_Subscriptions_Email', 'order_download_details' ) ) ) {
	WC_Subscriptions_Email::order_download_details( $order, $sent_to_admin, $plain_text, $email );
}

do_action( 'wcs_gifting_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n";

do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_subscriptions_gifting_recipient_email_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
