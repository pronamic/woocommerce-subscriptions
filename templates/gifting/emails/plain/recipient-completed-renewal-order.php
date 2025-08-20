<?php
/**
 * Recipient e-mail: completed renewal order.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

// translators: placeholder is the name of the site.
printf( esc_html__( 'Hi there. Your subscription renewal order with %s has been completed. Your order details are shown below for your reference:', 'woocommerce-subscriptions' ), esc_html( get_option( 'blogname' ) ) );

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
