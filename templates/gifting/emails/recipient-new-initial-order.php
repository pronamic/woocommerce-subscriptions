<?php
/**
 * Recipient new subscription(s) notification email.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<?php do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php printf( esc_html__( 'Hi there,', 'woocommerce-subscriptions' ) ); ?></p>
<p>
<?php
// Translators: 1) is the subscription's purchaser, 2) is either the singular or plural form of "subscription" and 3) is the blog's name.
printf( esc_html__( '%1$s just purchased %2$s for you at %3$s.', 'woocommerce-subscriptions' ), wp_kses( $subscription_purchaser, wp_kses_allowed_html( 'user_description' ) ), esc_html( _n( 'a subscription', 'subscriptions', count( $subscriptions ), 'woocommerce-subscriptions' ) ), esc_html( $blogname ) );
?>
<?php
// Translators: placeholder is either the singular or plural form of "subscription".
printf( esc_html__( ' Details of the %s are shown below.', 'woocommerce-subscriptions' ), esc_html( _n( 'subscription', 'subscriptions', count( $subscriptions ), 'woocommerce-subscriptions' ) ) );
?>
</p>
<?php

$new_recipient = get_user_meta( $recipient_user->ID, 'wcsg_update_account', true );

if ( 'true' == $new_recipient ) : // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
	?>

<p><?php esc_html_e( 'We noticed you didn\'t have an account so we created one for you. Your account login details will have been sent to you in a separate email.', 'woocommerce-subscriptions' ); ?></p>

<?php else : ?>

<p>
	<?php
	printf(
		/* Translators: 1) is either the singular or plural form of "subscription", 2) is an <a> tag pointing to "My Account", 3) is the closing </a> tag. */
		esc_html__( 'You may access your account area to view your new %1$s here: %2$sMy Account%3$s.', 'woocommerce-subscriptions' ),
		esc_html( _n( 'subscription', 'subscriptions', count( $subscriptions ), 'woocommerce-subscriptions' ) ),
		'<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">',
		'</a>'
	);
	?>
</p>

	<?php
	endif;

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
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
