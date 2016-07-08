<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<div class="total_row tax_row" data-order_item_id="<?php echo esc_attr( $item_id ); ?>">
	<p class="wide">
		<select name="recurring_order_taxes_rate_id[<?php echo esc_attr( $item_id ); ?>]">
			<option value=""><?php echo esc_html_x( 'N/A', 'no information about something', 'woocommerce-subscriptions' ); ?></option>
			<?php foreach ( $tax_codes as $tax_id => $tax_code ) : ?>
				<option value="<?php echo esc_attr( $tax_id ); ?>" <?php selected( $tax_id, isset( $item['rate_id'] ) ? $item['rate_id'] : '' ); ?>><?php echo esc_html( $tax_code ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="hidden" name="recurring_order_taxes_id[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item_id ); ?>" />
	</p>
	<p class="first">
		<label><?php esc_html_e( 'Recurring Sales Tax:', 'woocommerce-subscriptions' ) ?></label>
		<input type="text" class="order_taxes_amount wc_input_price" name="recurring_order_taxes_amount[<?php echo esc_attr( $item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" value="<?php if ( isset( $item['tax_amount'] ) ) { echo esc_attr( wc_format_localized_price( $item['tax_amount'] ) ); } ?>" />
	</p>
	<p class="last">
		<label><?php esc_html_e( 'Shipping Tax:', 'woocommerce-subscriptions' ) ?></label>
		<input type="text" class="order_taxes_shipping_amount wc_input_price" name="recurring_order_taxes_shipping_amount[<?php echo esc_attr( $item_id ); ?>]" placeholder="<?php echo esc_attr( wc_format_localized_price( 0 ) ); ?>" value="<?php if ( isset( $item['shipping_tax_amount'] ) ) { echo esc_attr( wc_format_localized_price( $item['shipping_tax_amount'] ) ); } ?>" />
	</p>
	<a href="#" class="delete_recurring_tax_row">&times;</a>
	<div class="clear"></div>
</div>
