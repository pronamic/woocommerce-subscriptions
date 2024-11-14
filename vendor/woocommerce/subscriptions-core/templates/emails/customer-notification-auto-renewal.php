<?php
/**
 * Customer Notification: Notify the customer that an automated renewal their subscription is about to happen.
 *
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version x.x.x
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * @hooked WC_Emails::email_header() Output the email header.
 *
 * @since x.x.x
 */
do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

	<p>
		<?php
		echo esc_html(
			sprintf(
					/* translators: %s: Customer first name */
				__( 'Hi %s.', 'woocommerce-subscriptions' ),
				$subscription->get_billing_first_name()
			)
		);
		?>
	</p>


	<p>
		<?php
		echo wp_kses(
			sprintf(
					// translators: %1$s: human readable time difference (eg 3 days, 1 day), %2$s: date in local format.
				__(
					'Your subscription will <strong>automatically renew</strong> in %1$s — that’s <strong>%2$s</strong>.',
					'woocommerce-subscriptions'
				),
				$subscription_time_til_event,
				$subscription_event_date
			),
			[ 'strong' => [] ]
		);
		?>
	</p>

	<p>
		<?php
			esc_html_e( 'Here are the details:', 'woocommerce-subscriptions' );
		?>
	</p>


<?php

// Show subscription details.
\WC_Subscriptions_Email::subscription_details( $subscription, $order, $sent_to_admin, $plain_text, true );
?>
	<p>
		<small>
			<?php
			echo wp_kses_post(
				sprintf(
					// translators: %s: link to subscription detail in the customer's dashboard.
					__( 'You can manage this subscription from your %s', 'woocommerce-subscriptions' ),
					'<a href="' . esc_url( $subscription->get_view_order_url() ) . '">' . esc_html__( 'account dashboard', 'woocommerce-subscriptions' ) . '</a>',
				)
			);
			?>
		</small>
	</p>

<?php

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/**
 * @hooked WC_Emails::email_footer() Output the email footer.
 *
 * @since x.x.x
 */
do_action( 'woocommerce_email_footer', $email );
