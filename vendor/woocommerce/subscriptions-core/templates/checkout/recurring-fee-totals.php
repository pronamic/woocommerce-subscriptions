<?php
/**
 * Recurring cart fee totals
 *
 * @author  WooCommerce
 * @package WooCommerce Subscriptions/Templates
 * @version 3.1.0
 */

defined( 'ABSPATH' ) || exit;

foreach ( $recurring_carts as $recurring_cart_key => $recurring_cart ) {
	foreach ( $recurring_cart->get_fees() as $recurring_fee ) { ?>
		<tr class="fee recurring-total">
			<th><?php echo esc_html( $recurring_fee->name ); ?></th>
			<td><?php wc_cart_totals_fee_html( $recurring_fee ); ?></td>
		</tr> <?php
	}
}
