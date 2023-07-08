<?php
/**
 * Admin payment retry email
 *
 * Email sent to admins when an attempt to automatically process a subscription renewal payment has failed
 * and a retry rule has been applied to retry the payment in the future.
 *
 * @author  Prospress
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: %1$s: an order number, %2$s: the customer's full name, %3$s: lowercase human time diff in the form returned by wcs_get_human_time_diff(), e.g. 'in 12 hours' */ ?>
<p><?php echo esc_html( sprintf( _x( 'The automatic recurring payment for order #%d from %s has failed. The payment will be retried %3$s.', 'In customer renewal invoice email', 'woocommerce-subscriptions' ), $order->get_order_number(), $order->get_formatted_billing_full_name(), wcs_get_human_time_diff( $retry->get_time() ) ) ); ?></p>
<p><?php esc_html_e( 'The renewal order is as follows:', 'woocommerce-subscriptions' ); ?></p>

<?php

/**
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v2.5.0
 */
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );

/**
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/**
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', $email );
