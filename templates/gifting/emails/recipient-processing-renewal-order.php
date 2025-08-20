<?php
/**
 * Recipient e-mail: processing renewal order.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php esc_html_e( 'Your subscription renewal order has been received and is now being processed. Your order details are shown below for your reference:', 'woocommerce-subscriptions' ); ?></p>

<?php
if ( is_callable( array( 'WC_Subscriptions_Email', 'order_download_details' ) ) ) {
	WC_Subscriptions_Email::order_download_details( $order, $sent_to_admin, $plain_text, $email );
}
?>

<?php do_action( 'wcs_gifting_email_order_details', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php do_action( 'woocommerce_subscriptions_gifting_recipient_email_details', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
