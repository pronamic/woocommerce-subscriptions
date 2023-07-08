<?php
/**
 * Order/Subscription details table shown in emails.
 *
 * @author  Prospress
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$text_align = is_rtl() ? 'right' : 'left';

do_action( 'woocommerce_email_before_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email );

if ( 'cancelled_subscription' != $email->id ) {
	echo '<h2>';

	$link_element_url = ( $sent_to_admin ) ? wcs_get_edit_post_link( wcs_get_objects_property( $order, 'id' ) ) : $order->get_view_order_url();

	if ( 'order' == $order_type ) {
		// translators: $1-$2: opening and closing <a> tags $3: order's order number $4: date of order in <time> element
		printf( esc_html_x( '%1$sOrder #%3$s%2$s (%4$s)', 'Used in email notification', 'woocommerce-subscriptions' ), '<a href="' . esc_url( $link_element_url ) . '">', '</a>', esc_html( $order->get_order_number() ), sprintf( '<time datetime="%s">%s</time>', esc_attr( wcs_get_objects_property( $order, 'date_created' )->format( 'c' ) ), esc_html( wcs_format_datetime( wcs_get_objects_property( $order, 'date_created' ) ) ) ) );
	} else {
		// translators: $1-$3: opening and closing <a> tags $2: subscription's order number
		printf( esc_html_x( 'Subscription %1$s#%2$s%3$s', 'Used in email notification', 'woocommerce-subscriptions' ), '<a href="' . esc_url( $link_element_url ) . '">', esc_html( $order->get_order_number() ), '</a>' );
	}
	echo '</h2>';
}
?>
<div style="margin-bottom: 40px;">
	<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;" border="1">
		<thead>
			<tr>
				<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php echo wp_kses_post( WC_Subscriptions_Email::email_order_items_table( $order, $order_items_table_args ) ); ?>
		</tbody>
		<tfoot>
			<?php
			if ( $totals = $order->get_order_item_totals() ) {
				$i = 0;
				foreach ( $totals as $total ) {
					$i++;
					?>
					<tr>
						<th class="td" scope="row" colspan="2" style="text-align:<?php echo esc_attr( $text_align ); ?>; <?php if ( 1 == $i ) { echo 'border-top-width: 4px;'; } ?>"><?php echo esc_html( $total['label'] ); ?></th>
						<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>; <?php if ( 1 == $i ) { echo 'border-top-width: 4px;'; } ?>"><?php echo wp_kses_post( $total['value'] ); ?></td>
					</tr>
					<?php
				}
			}
			if ( $order->get_customer_note() ) {
				?>
				<tr>
					<th class="td" scope="row" colspan="2" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php esc_html_e( 'Note:', 'woocommerce-subscriptions' ); ?></th>
					<td class="td" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo wp_kses_post( wptexturize( $order->get_customer_note() ) ); ?></td>
				</tr>
				<?php
			}
			?>
		</tfoot>
	</table>
</div>

<?php do_action( 'woocommerce_email_after_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email ); ?>
