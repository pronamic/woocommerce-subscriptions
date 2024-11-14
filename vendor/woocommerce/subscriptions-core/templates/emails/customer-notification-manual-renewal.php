<?php
/**
 * Customer Notification: Manual renewal needed.
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
					'Your subscription is up for renewal in %1$s — that’s <strong>%2$s</strong>.',
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
		<strong>
		<?php
			esc_html_e( 'This subscription will not renew automatically.', 'woocommerce-subscriptions' );
		?>
		</strong>
		<?php
		if ( $can_renew_early ) {
			echo wp_kses(
				__( 'You can <strong>renew it manually</strong> in a few short steps via the <em>Subscriptions</em> tab in your account dashboard.', 'woocommerce-subscriptions' ),
				[
					'strong' => [],
					'em'     => [],
				]
			);
		}

		?>
	</p>


	<table role="presentation" border="0" cellspacing="0" cellpadding="0" style="margin: 0 auto;">
		<tr>
			<td>
			<?php
			if ( $can_renew_early ) {
				$link_text = __( 'Renew Subscription', 'woocommerce-subscriptions' );
			} else {
				$link_text = __( 'Manage Subscription', 'woocommerce-subscriptions' );
			}
				echo wp_kses(
					'<a href="' . esc_url( $url_for_renewal ) . '">' . esc_html( $link_text ) . '</a>',
					[ 'a' => [ 'href' => true ] ]
				);
				?>

			</td>
		</tr>
	</table>

	<br>
	<p>
		<?php
		esc_html_e( 'Here are the details:', 'woocommerce-subscriptions' );
		?>
	</p>


<?php

// Show subscription details.
\WC_Subscriptions_Email::subscription_details( $subscription, $order, $sent_to_admin, $plain_text );

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
