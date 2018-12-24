<?php
/**
 * Customer payment retry email (plain text)
 *
 * @author  Prospress
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 2.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

echo $email_heading . "\n\n";

// translators: %1$s: name of the blog, %2$s: lowercase human time diff in the form returned by wcs_get_human_time_diff(), e.g. 'in 12 hours'
echo sprintf( esc_html_x( 'The automatic payment to renew your subscription with %1$s has failed. We will retry the payment %2$s.', 'In customer renewal invoice email', 'woocommerce-subscriptions' ), esc_html( get_bloginfo( 'name' ) ), esc_attr( strtolower( wcs_get_human_time_diff( $retry->get_time() ) ) ) ) . "\n\n";

// translators: %1$s: link to checkout payment url, note: no full stop due to url at the end
echo sprintf( esc_html_x( 'To reactivate the subscription now, you can also login and pay for the renewal from your account page: %1$s', 'In customer renewal invoice email', 'woocommerce-subscriptions' ), esc_attr( $order->get_checkout_payment_url() ) );

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

do_action( 'woocommerce_subscriptions_email_order_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

echo apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) );
