<?php
/**
 * Pay for order form displayed after a customer has clicked the "Change Payment method" button
 * next to a subscription on their My Account page.
 *
 * @author 		WooThemes
 * @package 	WooCommerce/Templates
 * @version     1.6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<form id="order_review" method="post">

	<table class="shop_table">
		<thead>
			<tr>
				<th class="product-name"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="product-quantity"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="product-total"><?php echo esc_html_x( 'Totals', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tfoot>
		<?php
		if ( $totals = $subscription->get_order_item_totals() ) {
			foreach ( $totals as $total ) : ?>
			<tr>
				<th scope="row" colspan="2"><?php echo esc_html( $total['label'] ); ?></th>
				<td class="product-total"><?php echo wp_kses_post( $total['value'] ); ?></td>
			</tr>
			<?php endforeach;
		};
		?>
		</tfoot>
		<tbody>
			<?php
			$recurring_order_items = $subscription->get_items();
			if ( sizeof( $recurring_order_items ) > 0 ) :
				foreach ( $recurring_order_items as $item ) :
					echo '
						<tr>
							<td class="product-name">' . esc_html( $item['name'] ) . '</td>
							<td class="product-quantity">' . esc_html( $item['qty'] ) . '</td>
							<td class="product-subtotal">' . wp_kses_post( $subscription->get_formatted_line_subtotal( $item ) ) . '</td>
						</tr>';
				endforeach;
			endif;
			?>
		</tbody>
	</table>

	<div id="payment">
		<?php $pay_order_button_text = apply_filters( 'woocommerce_change_payment_button_text', _x( 'Change Payment Method', 'text on button on checkout page', 'woocommerce-subscriptions' ) );

		if ( $available_gateways = WC()->payment_gateways->get_available_payment_gateways() ) { ?>
		<ul class="payment_methods methods">
			<?php

			if ( sizeof( $available_gateways ) ) {
				current( $available_gateways )->set_current();
			}

			foreach ( $available_gateways as $gateway ) { ?>
				<li>
					<input id="payment_method_<?php echo esc_attr( $gateway->id ); ?>" type="radio" class="input-radio" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( apply_filters( 'wcs_gateway_change_payment_button_text', $pay_order_button_text, $gateway ) ); ?>" />
					<label for="payment_method_<?php echo esc_attr( $gateway->id ); ?>"><?php echo esc_html( $gateway->get_title() ); ?> <?php echo wp_kses_post( $gateway->get_icon() ); ?></label>
					<?php
					if ( $gateway->has_fields() || $gateway->get_description() ) {
						echo '<div class="payment_box payment_method_' . esc_attr( $gateway->id ) . '" style="display:none;">';
						$gateway->payment_fields();
						echo '</div>';
					}
					?>
				</li>
				<?php
			} ?>
		</ul>
				<?php } else { ?>
		<div class="woocommerce-error">
			<p> <?php esc_html_e( 'Sorry, it seems no payment gateways support changing the recurring payment method. Please contact us if you require assistance or to make alternate arrangements.', 'woocommerce-subscriptions' ); ?></p>
		</div>
				<?php } ?>

		<?php if ( $available_gateways ) : ?>
		<div class="form-row">
			<?php wp_nonce_field( 'wcs_change_payment_method', '_wcsnonce', true, true ); ?>
			<?php echo wp_kses( apply_filters( 'woocommerce_change_payment_button_html', '<input type="submit" class="button alt" id="place_order" value="' . esc_attr( $pay_order_button_text ) . '" data-value="' . esc_attr( $pay_order_button_text ) . '" />' ), array( 'input' => array( 'type' => array(), 'class' => array(), 'id' => array(), 'value' => array(), 'data-value' => array() ) ) ); ?>
			<input type="hidden" name="woocommerce_change_payment" value="<?php echo esc_attr( $subscription->id ); ?>" />
		</div>
		<?php endif; ?>

	</div>

</form>
