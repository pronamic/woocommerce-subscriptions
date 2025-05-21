<?php
/**
 * Recurring cart tax totals
 *
 * @author  WooCommerce
 * @package WooCommerce Subscriptions/Templates
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
 */

defined( 'ABSPATH' ) || exit;
$display_heading = true;

foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) {
	/**
	 * Allow third-parties to filter the tax displayed.
	 *
	 * @since 1.0.0 - Migrated from WooCommerce Subscriptions v3.1.0
	 * @param string The recurring cart's total tax price string.
	 * @param WC_Cart $recurring_cart The recurring cart.
	 */
	$tax_amount = wp_kses_post( apply_filters( 'wcs_recurring_cart_tax_totals_html', wcs_cart_price_string( $recurring_cart->get_taxes_total(), $recurring_cart ), $recurring_cart ) );

	// Skip the tax if there's nothing to display.
	if ( empty( $tax_amount ) ) {
		continue;
	} ?>

	<tr class="tax-total recurring-total">

	<?php if ( $display_heading ) { ?>
		<?php $display_heading = false; ?>
		<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
		<td data-title="<?php echo esc_attr( WC()->countries->tax_or_vat() ); ?>"><?php echo $tax_amount; // XSS ok. ?></td>
	<?php } else { ?>
		<th></th>
		<td><?php echo $tax_amount; // XSS ok. ?></td>
	<?php }
}
