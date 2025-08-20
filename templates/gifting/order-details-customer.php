<?php
/**
 * Customer order details
 *
 * Shows only the shipping address to the recipient on the view subscription page.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( ! wc_ship_to_billing_address_only() && $order->needs_shipping_address() ) : ?>
<section class="woocommerce-customer-details">

	<section class="woocommerce-columns woocommerce-columns--2 woocommerce-columns--addresses col2-set addresses">

		<div class="woocommerce-column woocommerce-column--1 woocommerce-column--billing-address col-1">

			<h2 class="woocommerce-column__title"><?php esc_html_e( 'Shipping address', 'woocommerce-subscriptions' ); ?></h2>

			<address>
				<?php
				$address = $order->get_formatted_shipping_address() ? $order->get_formatted_shipping_address() : __( 'N/A', 'woocommerce-subscriptions' );
				echo wp_kses_post( $address );
				?>
			</address>

		</div><!-- /.col-1 -->

	</section><!-- /.col1-set -->

</section>
<?php endif; ?>
