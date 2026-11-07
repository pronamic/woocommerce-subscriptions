<?php
/**
 * Customer Cancelled Subscription email (plain text)
 *
 * @package WooCommerce_Subscriptions/Templates/Emails/Plain
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

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

if ( $subscription->has_status( 'pending-cancel' ) ) {
	echo esc_html(
		sprintf(
			/* translators: 1: subscription number, 2: date and time the cancellation was received, 3: date and time the subscription ends. */
			__( 'We received the cancellation of subscription #%1$s on %2$s. It stays active until %3$s. No more renewal payments are scheduled.', 'woocommerce-subscriptions' ),
			$subscription->get_order_number(),
			$date_received,
			$end_date
		)
	);
} else {
	echo esc_html(
		sprintf(
			/* translators: 1: subscription number, 2: date and time the cancellation was received, 3: date and time the subscription ended. */
			__( 'Subscription #%1$s was cancelled on %2$s. It ended on %3$s.', 'woocommerce-subscriptions' ),
			$subscription->get_order_number(),
			$date_received,
			$end_date
		)
	);
}

echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";

do_action( 'woocommerce_subscriptions_email_order_details', $subscription, $sent_to_admin, $plain_text, $email );

// translators: %s: subscription URL
echo "\n" . esc_html( sprintf( _x( 'View Subscription: %s', 'in plain emails for subscription information', 'woocommerce-subscriptions' ), esc_url( $subscription->get_view_order_url() ) ) ) . "\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) );
	echo "\n\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
