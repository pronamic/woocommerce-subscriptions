<?php
/**
 * Recurring totals
 *
 * @author 		Prospress
 * @package 	WooCommerce Subscriptions/Templates
 * @version     2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$display_th = true;

?>

			<tr class="recurring-totals">
				<th colspan="2"><?php esc_html_e( 'Recurring Totals', 'woocommerce-subscriptions' ); ?></th>
			</tr>

			<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
				<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<tr class="cart-subtotal recurring-total">
					<?php if ( $display_th ) : $display_th = false; ?>
					<th rowspan="<?php echo esc_attr( $carts_with_multiple_payments ); ?>"><?php esc_html_e( 'Subtotal', 'woocommerce-subscriptions' ); ?></th>
					<?php endif; ?>
					<td><?php wcs_cart_totals_subtotal_html( $recurring_cart ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php $display_th = true; ?>

			<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
				<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
					<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<?php foreach ( $recurring_cart->get_coupons() as $recurring_code => $recurring_coupon ) : ?>
						<?php if ( $recurring_code !== $code ) { continue; } ?>
							<tr class="cart-discount coupon-<?php echo esc_attr( $code ); ?> recurring-total">
								<?php if ( $display_th ) : $display_th = false; ?>
								<th rowspan="<?php echo esc_attr( $carts_with_multiple_payments ); ?>"><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
								<?php endif; ?>
								<td><?php wcs_cart_totals_coupon_html( $recurring_coupon, $recurring_cart ); ?></td>
							</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<?php $display_th = true; ?>
			<?php endforeach; ?>

		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
			<?php wcs_cart_totals_shipping_html(); ?>
		<?php endif; ?>

		<?php if ( WC()->cart->tax_display_cart === 'excl' ) : ?>
			<?php if ( get_option( 'woocommerce_tax_total_display' ) === 'itemized' ) : ?>

				<?php foreach ( WC()->cart->get_taxes() as $tax_id => $tax_total ) : ?>
					<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
						<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<?php foreach ( $recurring_cart->get_tax_totals() as $recurring_code => $recurring_tax ) : ?>
							<?php if ( ! isset( $recurring_tax->tax_rate_id ) || $recurring_tax->tax_rate_id !== $tax_id ) { continue; } ?>
							<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $recurring_code ) ); ?> recurring-total">
								<?php if ( $display_th ) : $display_th = false; ?>
									<th><?php echo esc_html( $recurring_tax->label ); ?></th>
								<?php else : ?>
									<th></th>
								<?php endif; ?>
								<td><?php echo wp_kses_post( wcs_cart_price_string( $recurring_tax->formatted_amount, $recurring_cart ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					<?php $display_th = true; ?>
				<?php endforeach; ?>

			<?php else : ?>

				<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
					<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<tr class="tax-total recurring-total">
						<?php if ( $display_th ) : $display_th = false; ?>
							<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
						<?php else : ?>
							<th></th>
						<?php endif; ?>
						<td><?php echo wp_kses_post( wcs_cart_price_string( $recurring_cart->get_taxes_total(), $recurring_cart ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php $display_th = true; ?>
			<?php endif; ?>
		<?php endif; ?>

		<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
			<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<tr class="order-total recurring-total">
				<?php if ( $display_th ) : $display_th = false; ?>
				<th rowspan="<?php echo esc_attr( $carts_with_multiple_payments ); ?>"><?php esc_html_e( 'Recurring Total', 'woocommerce-subscriptions' ); ?></th>
				<?php endif; ?>
				<td><?php wcs_cart_totals_order_total_html( $recurring_cart ); ?></td>
			</tr>
		<?php endforeach; ?>
