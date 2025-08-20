<?php
/**
 * Recipient e-mail: completed renewal order.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p>
	<?php
	// translators: placeholder is the name of the site.
	printf( esc_html__( 'Hi there. Your subscription renewal order with %s has been completed. Your order details are shown below for your reference:', 'woocommerce-subscriptions' ), esc_html( get_option( 'blogname' ) ) );
	?>
</p>

<?php
if ( is_callable( array( 'WC_Subscriptions_Email', 'order_download_details' ) ) ) {
	WC_Subscriptions_Email::order_download_details( $order, $sent_to_admin, $plain_text, $email );
}
?>

<?php do_action( 'wcs_gifting_email_order_details', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php do_action( 'woocommerce_subscriptions_gifting_recipient_email_details', $order, $sent_to_admin, $plain_text, $email ); ?>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
