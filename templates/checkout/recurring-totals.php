<?php
/**
 * Recurring totals
 *
 * @author  Prospress
 * @package WooCommerce Subscriptions/Templates
 * @version 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$display_th = true;

?>

			<tr class="recurring-totals">
				<th colspan="2"><?php esc_html_e( 'Recurring totals', 'woocommerce-subscriptions' ); ?></th>
			</tr>

			<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
				<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
					<?php continue; ?>
				<?php endif; ?>
				<tr class="cart-subtotal recurring-total">
					<?php
					if ( $display_th ) :
						$display_th = false;
						?>
						<th rowspan="<?php echo esc_attr( $carts_with_multiple_payments ); ?>"><?php esc_html_e( 'Subtotal', 'woocommerce-subscriptions' ); ?></th>
						<td data-title="<?php esc_attr_e( 'Subtotal', 'woocommerce-subscriptions' ); ?>"><?php wcs_cart_totals_subtotal_html( $recurring_cart ); ?></td>
					<?php else : ?>
						<td><?php wcs_cart_totals_subtotal_html( $recurring_cart ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			<?php $display_th = true; ?>

			<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
				<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
					<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<?php foreach ( $recurring_cart->get_coupons() as $recurring_code => $recurring_coupon ) : ?>
						<?php
						if ( $recurring_code !== $code ) {
							continue;
						}
						?>
							<tr class="cart-discount coupon-<?php echo esc_attr( $code ); ?> recurring-total">
								<?php
								if ( $display_th ) :
									$display_th = false;
									?>
									<th rowspan="<?php echo esc_attr( $carts_with_multiple_payments ); ?>"><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
									<td data-title="<?php wc_cart_totals_coupon_label( $coupon ); ?>"><?php wcs_cart_totals_coupon_html( $recurring_coupon, $recurring_cart ); ?>
									<?php
									echo ' ';
									wcs_cart_coupon_remove_link_html( $recurring_coupon );
									?>
									</td>
								<?php else : ?>
									<td><?php wcs_cart_totals_coupon_html( $recurring_coupon, $recurring_cart ); ?></td>
								<?php endif; ?>
							</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				<?php $display_th = true; ?>
			<?php endforeach; ?>

		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
			<?php wcs_cart_totals_shipping_html(); ?>
		<?php endif; ?>
		<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
			<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<?php foreach ( $recurring_cart->get_fees() as $recurring_fee ) : ?>
				<tr class="fee recurring-total">
					<th><?php echo esc_html( $recurring_fee->name ); ?></th>
					<td><?php wc_cart_totals_fee_html( $recurring_fee ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<?php $display_prices_include_tax = WC_Subscriptions::is_woocommerce_pre( '3.3' ) ? ( 'incl' === WC()->cart->tax_display_cart ) : WC()->cart->display_prices_including_tax(); ?>
		<?php if ( wc_tax_enabled() && ( ! $display_prices_include_tax ) ) : ?>
			<?php if ( get_option( 'woocommerce_tax_total_display' ) === 'itemized' ) : ?>

				<?php foreach ( WC()->cart->get_taxes() as $tax_id => $tax_total ) : ?>
					<?php foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) : ?>
						<?php if ( 0 == $recurring_cart->next_payment_date ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<?php foreach ( $recurring_cart->get_tax_totals() as $recurring_code => $recurring_tax ) : ?>
							<?php
							if ( ! isset( $recurring_tax->tax_rate_id ) || $recurring_tax->tax_rate_id !== $tax_id ) {
								continue;
							}
							?>
							<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $recurring_code ) ); ?> recurring-total">
								<?php
								if ( $display_th ) :
									$display_th = false;
									?>
									<th><?php echo esc_html( $recurring_tax->label ); ?></th>
									<td data-title="<?php echo esc_attr( $recurring_tax->label ); ?>"><?php echo wp_kses_post( wcs_cart_price_string( $recurring_tax->formatted_amount, $recurring_cart ) ); ?></td>
								<?php else : ?>
									<th></th>
									<td><?php echo wp_kses_post( wcs_cart_price_string( $recurring_tax->formatted_amount, $recurring_cart ) ); ?></td>
								<?php endif; ?>
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
						<?php
						if ( $display_th ) :
							$display_th = false;
							?>
							<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
							<td data-title="<?php echo esc_attr( WC()->countries->tax_or_vat() ); ?>"><?php echo wp_kses_post( wcs_cart_price_string( $recurring_cart->get_taxes_total(), $recurring_cart ) ); ?></td>
						<?php else : ?>
							<th></th>
							<td><?php echo wp_kses_post( wcs_cart_price_string( $recurring_cart->get_taxes_total(), $recurring_cart ) ); ?></td>
						<?php endif; ?>
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
				<?php
				if ( $display_th ) :
					$display_th = false;
					?>
					<th rowspan="<?php echo esc_attr( $carts_with_multiple_payments ); ?>"><?php esc_html_e( 'Recurring total', 'woocommerce-subscriptions' ); ?></th>
					<td data-title="<?php esc_attr_e( 'Recurring total', 'woocommerce-subscriptions' ); ?>"><?php wcs_cart_totals_order_total_html( $recurring_cart ); ?></td>
				<?php else : ?>
					<td><?php wcs_cart_totals_order_total_html( $recurring_cart ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
