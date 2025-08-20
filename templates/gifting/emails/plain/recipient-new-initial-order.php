<?php
/**
 * Recipient customer new account email.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
echo sprintf( esc_html__( 'Hi there,', 'woocommerce-subscriptions' ) ) . "\n";
// translators: 1$: Purchaser's name and email, 2$ The name of the site.
echo sprintf( esc_html__( '%1$s just purchased %2$s for you at %3$s.', 'woocommerce-subscriptions' ), wp_kses( $subscription_purchaser, wp_kses_allowed_html( 'user_description' ) ), esc_html( _n( 'a subscription', 'subscriptions', count( $subscriptions ), 'woocommerce-subscriptions' ) ), esc_html( $blogname ) );
// translators: placeholder is the singular or plural form of "subscription".
echo sprintf( esc_html__( ' Details of the %s are shown below.', 'woocommerce-subscriptions' ), esc_html( _n( 'subscription', 'subscriptions', count( $subscriptions ), 'woocommerce-subscriptions' ) ) ) . "\n\n";

$new_recipient = get_user_meta( $recipient_user->ID, 'wcsg_update_account', true );

if ( 'true' == $new_recipient ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
	echo esc_html__( 'We noticed you didn\'t have an account so we created one for you. Your account login details will have been sent to you in a separate email.', 'woocommerce-subscriptions' ) . "\n\n";
} else {
	// translators: 1) is the singular or plural form of "subscription", 2) is a link to "My Account".
	echo sprintf( esc_html__( 'You may access your account area to view your new %1$s here: %2$s.', 'woocommerce-subscriptions' ), esc_html( _n( 'subscription', 'subscriptions', count( $subscriptions ), 'woocommerce-subscriptions' ) ), esc_url( wc_get_page_permalink( 'myaccount' ) ) ) . "\n\n";
}

foreach ( $subscriptions as $subscription_id ) {
	$subscription = wcs_get_subscription( $subscription_id );

	do_action( 'wcs_gifting_email_order_details', $subscription, $sent_to_admin, $plain_text, $email );

	if ( is_callable( array( 'WC_Subscriptions_Email', 'order_download_details' ) ) ) {
		WC_Subscriptions_Email::order_download_details( $subscription, $sent_to_admin, $plain_text, $email );
	}
}

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n----------------------------------------\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
