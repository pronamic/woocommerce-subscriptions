<?php
/**
 * Customer Cancelled Subscription email
 *
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 9.4.0
 *
 * @var string          $additional_content Additional content added by the user.
 * @var string          $date_received      The date and time the cancellation was received.
 * @var WC_Email        $email              Active instance of the email class associated with this template.
 * @var string          $email_heading      The email heading.
 * @var string          $end_date           The date and time the subscription ends.
 * @var bool            $plain_text         True if the email is being sent in plain text, false if HTML.
 * @var bool            $sent_to_admin      True if the email was sent to the admin, false if to the customer.
 * @var WC_Subscription $subscription       The subscription object.
 */

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = wcs_is_wc_feature_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
	<?php
	if ( $subscription->has_status( 'pending-cancel' ) ) {
		printf(
			/* translators: 1: subscription number, 2: date and time the cancellation was received, 3: date and time the subscription ends. */
			esc_html__( 'We received the cancellation of subscription #%1$s on %2$s. It stays active until %3$s. No more renewal payments are scheduled.', 'woocommerce-subscriptions' ),
			esc_html( $subscription->get_order_number() ),
			esc_html( $date_received ),
			esc_html( $end_date )
		);
	} else {
		printf(
			/* translators: 1: subscription number, 2: date and time the cancellation was received, 3: date and time the subscription ended. */
			esc_html__( 'Subscription #%1$s was cancelled on %2$s. It ended on %3$s.', 'woocommerce-subscriptions' ),
			esc_html( $subscription->get_order_number() ),
			esc_html( $date_received ),
			esc_html( $end_date )
		);
	}
	?>
</p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php

do_action( 'woocommerce_subscriptions_email_order_details', $subscription, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
