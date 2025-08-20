<?php
/**
 * Recipient customer new account email
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

echo sprintf( esc_html__( 'Hi there,', 'woocommerce-subscriptions' ) ) . "\n\n";
// Translators: 1) is the purchaser's name, 2) is the blog's name.
echo sprintf( esc_html__( '%1$s just purchased a subscription for you at %2$s so we\'ve created an account for you to manage the subscription.', 'woocommerce-subscriptions' ), esc_html( $subscription_purchaser ), esc_html( $blogname ) ) . "\n\n";

// Translators: placeholder is a username.
echo sprintf( esc_html__( 'Your username is: %s', 'woocommerce-subscriptions' ), esc_html( $user_login ) ) . "\n";

// Translators: placeholder is the URL for resetting the password.
echo sprintf( esc_html__( 'Go here to set your password: %s', 'woocommerce-subscriptions' ), esc_url( add_query_arg( array( 'key' => $reset_key, 'id' => $user_id ), wc_get_endpoint_url( 'lost-password', '', wc_get_page_permalink( 'myaccount' ) ) ) ) ) . "\n\n"; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

// Translators: placeholder is the URL for setting up the recipient's details.
echo sprintf( esc_html__( 'To complete your account we just need you to fill in your shipping address and you to change your password here: %s.', 'woocommerce-subscriptions' ), esc_url( wc_get_endpoint_url( 'new-recipient-account', '', wc_get_page_permalink( 'myaccount' ) ) ) ) . "\n\n";
// Translators: placeholder is the URL for "My Account".
echo sprintf( esc_html__( 'Once completed you may access your account area to view your subscription here: %s.', 'woocommerce-subscriptions' ), esc_url( wc_get_page_permalink( 'myaccount' ) ) ) . "\n\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
