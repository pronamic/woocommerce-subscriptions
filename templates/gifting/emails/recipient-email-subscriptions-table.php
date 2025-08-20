<?php
/**
 * Recipient e-mail: subscriptions table.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<h2><?php esc_html_e( 'Subscription Information', 'woocommerce-subscriptions' ); ?></h2>
<table cellspacing="0" cellpadding="6" style="margin: 0 0 18px; width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'Start Date', 'table heading', 'woocommerce-subscriptions' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'End Date', 'table heading', 'woocommerce-subscriptions' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php echo esc_html_x( 'Period', 'table heading', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $subscriptions as $subscription ) : ?>
		<tr>
			<td class="td" scope="row" style="text-align:left;"><a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>"><?php /* Translators: placeholder is a subscription number. */ echo sprintf( esc_html_x( '#%s', 'subscription number in email table. (eg: #106)', 'woocommerce-subscriptions' ), esc_html( $subscription->get_order_number() ) ); ?></a></td>
			<td class="td" scope="row" style="text-align:left;"><?php echo esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'date_created', 'site' ) ) ); ?></td>
			<td class="td" scope="row" style="text-align:left;"><?php echo esc_html( ( 0 < $subscription->get_time( 'end' ) ) ? date_i18n( wc_date_format(), $subscription->get_time( 'end', 'site' ) ) : _x( 'When Cancelled', 'Used as end date for an indefinite subscription', 'woocommerce-subscriptions' ) ); ?></td>
			<td class="td" scope="row" style="text-align:left;">
				<?php
				$subscription_details = array(
					'recurring_amount'      => '',
					'subscription_period'   => $subscription->get_billing_period(),
					'subscription_interval' => $subscription->get_billing_interval(),
					'initial_amount'        => '',
					'use_per_slash'         => false,
				);
				$subscription_details = apply_filters( 'woocommerce_subscription_price_string_details', $subscription_details, $subscription );
				echo wp_kses_post( wcs_price_string( $subscription_details ) );
				?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
