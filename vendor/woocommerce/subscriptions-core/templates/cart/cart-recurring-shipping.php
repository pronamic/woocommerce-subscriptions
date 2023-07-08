<?php
/**
 * Recurring Shipping Methods Display
 *
 * Based on the WooCommerce core template: /woocommerce/templates/cart/cart-shipping.php
 *
 * @author  Prospress
 * @package WooCommerce Subscriptions/Templates
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<tr class="shipping recurring-total <?php echo esc_attr( $recurring_cart_key ); ?>">
	<th><?php echo wp_kses_post( $package_name ); ?></th>
	<td data-title="<?php echo esc_attr( $package_name ); ?>">
		<?php if ( 1 < count( $available_methods ) ) : ?>
			<ul id="shipping_method_<?php echo esc_attr( $recurring_cart_key ); ?>">
				<?php foreach ( $available_methods as $method ) : ?>
					<li>
						<?php
							wcs_cart_print_shipping_input( $index, $method, $chosen_method, 'radio' );
							printf( '<label for="shipping_method_%1$s_%2$s">%3$s</label>', esc_attr( $index ), esc_attr( sanitize_title( $method->id ) ), wp_kses_post( wcs_cart_totals_shipping_method( $method, $recurring_cart ) ) );
							do_action( 'woocommerce_after_shipping_rate', $method, $index );
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php elseif ( ! WC()->customer->has_calculated_shipping() ) : ?>
			<?php echo wp_kses_post( wpautop( __( 'Shipping costs will be calculated once you have provided your address.', 'woocommerce-subscriptions' ) ) ); ?>
		<?php else : ?>
			<?php echo wp_kses_post( apply_filters( 'woocommerce_no_shipping_available_html', wpautop( __( 'There are no shipping methods available. Please double check your address, or contact us if you need any help.', 'woocommerce-subscriptions' ) ) ) ); ?>
		<?php endif; ?>

		<?php if ( $show_package_details ) : ?>
			<?php echo '<p class="woocommerce-shipping-contents"><small>' . esc_html( $package_details ) . '</small></p>'; ?>
		<?php endif; ?>
	</td>
</tr>
