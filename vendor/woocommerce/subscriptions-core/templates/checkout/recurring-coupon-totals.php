<?php
/**
 * Recurring cart subtotals totals
 *
 * @author  WooCommerce
 * @package WooCommerce Subscriptions/Templates
 * @version 3.1.0
 */

defined( 'ABSPATH' ) || exit;
$display_heading = true;

foreach ( WC()->cart->get_coupons() as $code => $coupon ) {
	foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) {
		foreach ( $recurring_cart->get_coupons() as $recurring_code => $recurring_coupon ) {
			if ( $recurring_code !== $code ) {
				continue;
			} ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( $code ); ?> recurring-total">
			<?php if ( $display_heading ) { ?>
				<?php $display_heading = false; ?>
				<th rowspan="<?php echo esc_attr( count( $recurring_carts ) ); ?>"><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td data-title="<?php wc_cart_totals_coupon_label( $coupon ); ?>"><?php
				wcs_cart_totals_coupon_html( $recurring_coupon, $recurring_cart );
				echo '&nbsp;';
				wcs_cart_coupon_remove_link_html( $recurring_coupon );?>
				</td>
			<?php } else { ?>
				<td><?php wcs_cart_totals_coupon_html( $recurring_coupon, $recurring_cart ); ?></td>
			<?php } ?>
			</tr> <?php
		}
	}
}
