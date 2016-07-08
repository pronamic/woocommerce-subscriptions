<?php
/**
 * Subscription information template
 *
 * @author	Brent Shepherd / Chuck Mac
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 1.5
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<?php if ( ! empty( $subscriptions ) ) : ?>
<h2><?php esc_html_e( 'Subscription Information:', 'woocommerce-subscriptions' ); ?></h2>
<table cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #eee;" border="1" bordercolor="#eee">
	<thead>
		<tr>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Start Date', 'table heading',  'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'End Date', 'table heading',  'woocommerce-subscriptions' ); ?></th>
			<th scope="col" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html_x( 'Price',  'table heading', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $subscriptions as $subscription ) : ?>
		<tr>
			<td scope="row" style="text-align:left; border: 1px solid #eee;"><a href="<?php echo esc_url( ( $is_admin_email ) ? wcs_get_edit_post_link( $subscription->id ) : $subscription->get_view_order_url() ); ?>"><?php echo esc_html( $subscription->get_order_number() ); ?></a></td>
			<td scope="row" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html( date_i18n( wc_date_format(), $subscription->get_time( 'start', 'site' ) ) ); ?></td>
			<td scope="row" style="text-align:left; border: 1px solid #eee;"><?php echo esc_html( ( 0 < $subscription->get_time( 'end' ) ) ? date_i18n( wc_date_format(), $subscription->get_time( 'end', 'site' ) ) : _x( 'When Cancelled', 'Used as end date for an indefinite subscription', 'woocommerce-subscriptions' ) ); ?></td>
			<td scope="row" style="text-align:left; border: 1px solid #eee;"><?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?></td>
		</tr>
	<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
