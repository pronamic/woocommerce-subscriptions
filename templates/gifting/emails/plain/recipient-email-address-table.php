<?php
/**
 * Recipient e-mail: address table.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! wc_ship_to_billing_address_only() && $order->needs_shipping_address() && ( $shipping = $order->get_formatted_shipping_address() ) ) {
	echo "\n" . esc_html( strtoupper( __( 'Shipping address', 'woocommerce-subscriptions' ) ) ) . "\n\n";
	echo esc_html( preg_replace( '#<br\s*/?>#i', "\n", $shipping ) ) . "\n";
}
