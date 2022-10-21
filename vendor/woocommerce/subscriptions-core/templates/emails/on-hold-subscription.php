<?php
/**
 * Cancelled Subscription email
 *
 * @author  Prospress
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 2.6.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php /* translators: $1: customer's billing first name and last name */ ?>
<p><?php printf( esc_html__( 'A subscription belonging to %1$s has been suspended by the user. Their subscription\'s details are as follows:', 'woocommerce-subscriptions' ), esc_html( $subscription->get_formatted_billing_full_name() ) );?></p>

<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'Last Order Date', 'table heading', 'woocommerce-subscriptions' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'Date Suspended', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td class="td" width="1%" style="text-align:left; vertical-align:middle;">
				<a href="<?php echo esc_url( wcs_get_edit_post_link( $subscription->get_id() ) ); ?>">#<?php echo esc_html( $subscription->get_order_number() ); ?></a>
			</td>
			<td class="td" style="text-align:left; vertical-align:middle;">
				<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
			</td>
			<td class="td" style="text-align:left; vertical-align:middle;">
				<?php
				$last_order_time_created = $subscription->get_time( 'last_order_date_created', 'site' );
				if ( ! empty( $last_order_time_created ) ) {
					echo esc_html( date_i18n( wc_date_format(), $last_order_time_created ) );
				} else {
					esc_html_e( '-', 'woocommerce-subscriptions' );
				}
				?>
			</td>
			<td class="td" style="text-align:left; vertical-align:middle;">
				<?php echo esc_html( date_i18n( wc_date_format(), time() ) ); ?>
			</td>
		</tr>
	</tbody>
</table>
<br/>

<?php
do_action( 'woocommerce_subscriptions_email_order_details', $subscription, $sent_to_admin, $plain_text, $email );

do_action( 'woocommerce_email_customer_details', $subscription, $sent_to_admin, $plain_text, $email );

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
