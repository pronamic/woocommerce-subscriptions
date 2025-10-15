<?php
/**
 * Recipient e-mail: order details.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<h2><?php echo esc_html( $title ); ?></h2>
<table cellspacing="0" cellpadding="6" style="margin: 0 0 18px; width: 100%; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;">
	<thead>
		<tr>
			<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Product', 'woocommerce-subscriptions' ); ?></th>
			<th class="td" scope="col" style="text-align:left;"><?php esc_html_e( 'Quantity', 'woocommerce-subscriptions' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		WCSG_Email::recipient_email_order_items_table(
			$order,
			array(
				'show_sku'      => $sent_to_admin,
				'show_image'    => '',
				'image_size'    => '',
				'plain_text'    => $plain_text,
				'sent_to_admin' => $sent_to_admin,
			)
		);
		?>
	</tbody>
</table>
