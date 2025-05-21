<?php
/**
 * Order/Subscription details table shown in emails.
 *
 * Based on the WooCommerce core email-order-details.php template.
 *
 * @package WooCommerce_Subscriptions/Templates/Emails
 * @version 7.3.0
 */

defined( 'ABSPATH' ) || exit;

$text_align = is_rtl() ? 'right' : 'left';

$email_improvements_enabled = wcs_is_wc_feature_enabled( 'email_improvements' );
$heading_class              = $email_improvements_enabled ? 'email-order-detail-heading' : '';
$order_table_class          = $email_improvements_enabled ? 'email-order-details' : '';
$order_total_text_align     = $email_improvements_enabled ? 'right' : 'left';

if ( $email_improvements_enabled ) {
	add_filter( 'woocommerce_order_shipping_to_display_shipped_via', '__return_false' );
}

do_action( 'woocommerce_email_before_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email );

if ( 'cancelled_subscription' !== $email->id ) {
	echo '<h2 class="' . esc_attr( $heading_class ) . '">';

	$id_heading = sprintf(
		/* translators: %s: Order or subscription ID. */
		( 'order' === $order_type ) ? __( 'Order #%s', 'woocommerce-subscriptions' ) : __( 'Subscription #%s', 'woocommerce-subscriptions' ),
		$order->get_order_number()
	);

	if ( $email_improvements_enabled ) {
		$heading = ( 'order' === $order_type ) ? __( 'Order summary', 'woocommerce-subscriptions' ) : __( 'Subscription summary', 'woocommerce-subscriptions' );
		echo wp_kses_post( $heading );
		echo '<span>';
	} else {
		// Prior to the email improvements, the sub_heading was wrapped in square brackets.
		$id_heading = '[' . $id_heading . ']';
	}

	echo wp_kses_post(
		sprintf(
			'%s%s%s (<time datetime="%s">%s</time>)',
			'<a class="link" href="' . esc_url( ( $sent_to_admin ) ? wcs_get_edit_post_link( $order->get_id() ) : $order->get_view_order_url() ) . '">',
			$id_heading,
			'</a>',
			$order->get_date_created()->format( 'c' ),
			wcs_format_datetime( $order->get_date_created() )
		)
	);

	if ( $email_improvements_enabled ) {
		echo '</span>';
	}

	echo '</h2>';
}
?>
<div style="margin-bottom: <?php echo $email_improvements_enabled ? '24px' : '40px'; ?>;">
	<table class="td font-family <?php echo esc_attr( $order_table_class ); ?>" cellspacing="0" cellpadding="6" style="width: 100%;" border="1">
		<?php if ( ! $email_improvements_enabled ) { ?>
		<thead>
			<tr>
				<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="td" scope="col" style="text-align:<?php echo esc_attr( $text_align ); ?>;"><?php echo esc_html_x( 'Price', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<?php } ?>
		<tbody>
			<?php echo wp_kses_post( WC_Subscriptions_Email::email_order_items_table( $order, $order_items_table_args ) ); ?>
		</tbody>
		<tfoot>
			<?php
			$item_totals       = $order->get_order_item_totals();
			$item_totals_count = count( $item_totals );

			if ( $item_totals ) {
				$i = 0;
				foreach ( $item_totals as $total ) {
					$i++;
					$last_class = ( $i === $item_totals_count ) ? ' order-totals-last' : '';
					?>
					<tr class="order-totals order-totals-<?php echo esc_attr( $total['type'] ?? 'unknown' ); ?><?php echo esc_attr( $last_class ); ?>">
						<th class="td text-align-left" scope="row" colspan="2" style="<?php echo ( 1 === $i ) ? 'border-top-width: 4px;' : ''; ?>">
						<?php
						echo wp_kses_post( $total['label'] ) . ' ';
						if ( $email_improvements_enabled ) {
							echo isset( $total['meta'] ) ? wp_kses_post( $total['meta'] ) : '';
						}
						?>
						</th>
						<td class="td text-align-<?php echo esc_attr( $order_total_text_align ); ?>" style="<?php echo ( 1 === $i ) ? 'border-top-width: 4px;' : ''; ?>"><?php echo wp_kses_post( $total['value'] ); ?></td>
					</tr>
					<?php
				}
			}
			if ( $order->get_customer_note() ) {
				if ( $email_improvements_enabled ) {
					?>
					<tr class="order-customer-note">
						<td class="td text-align-left" colspan="3">
							<b><?php esc_html_e( 'Customer note', 'woocommerce-subscriptions' ); ?></b><br>
							<?php echo wp_kses( nl2br( wptexturize( $order->get_customer_note() ) ), array( 'br' => array() ) ); ?>
						</td>
					</tr>
					<?php
				} else {
					?>
					<tr>
						<th class="td text-align-left" scope="row" colspan="2"><?php esc_html_e( 'Note:', 'woocommerce-subscriptions' ); ?></th>
						<td class="td text-align-left"><?php echo wp_kses( nl2br( wptexturize( $order->get_customer_note() ) ), array() ); ?></td>
					</tr>
					<?php
				}
			}
			?>
		</tfoot>
	</table>
</div>

<?php do_action( 'woocommerce_email_after_' . $order_type . '_table', $order, $sent_to_admin, $plain_text, $email ); ?>
