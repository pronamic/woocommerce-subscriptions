<?php
/**
 * Email addresses for the purchaser of a gifted subscription (plain text).
 *
 * Shows the purchaser their own billing details. The shipping address is the recipient's,
 * so a notice is shown in its place.
 *
 * @var WC_Order $order         The gifted subscription, or one of its renewal orders.
 * @var bool     $sent_to_admin Whether the email is sent to the store's admin.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 * @version 9.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo "\n" . esc_html( wc_strtoupper( esc_html__( 'Billing address', 'woocommerce-subscriptions' ) ) ) . "\n\n";
echo preg_replace( '#<br\s*/?>#i', "\n", $order->get_formatted_billing_address() ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ( $order->get_billing_phone() ) {
	echo $order->get_billing_phone() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

if ( $order->get_billing_email() ) {
	echo $order->get_billing_email() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Fires after the core address fields in emails. Documented in WooCommerce's templates/emails/plain/email-addresses.php.
 *
 * @since 8.6.0
 *
 * @param string   $type          Address type. Either 'billing' or 'shipping'.
 * @param WC_Order $order         Order instance.
 * @param bool     $sent_to_admin If this email is being sent to the admin or not.
 * @param bool     $plain_text    If this email is plain text or not.
 */
do_action( 'woocommerce_email_customer_address_section', 'billing', $order, $sent_to_admin, true );

if ( ! wc_ship_to_billing_address_only() && $order->needs_shipping_address() ) {
	echo "\n" . esc_html( wc_strtoupper( esc_html__( 'Shipping address', 'woocommerce-subscriptions' ) ) ) . "\n\n";
	echo esc_html( WCSG_Recipient_Addresses::get_withheld_shipping_address_message() ) . "\n";
}
