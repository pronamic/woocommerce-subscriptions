<?php
/**
 * Recipient e-mail - order items.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$text_align = is_rtl() ? 'right' : 'left';

foreach ( $items as $item_id => $item ) :
	$product = $item->get_product();
	if ( apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
		?>
		<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_order_item_class', 'order_item', $item, $order ) ); ?>">
			<td class="td" style="text-align: <?php echo wp_kses_post( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; word-wrap:break-word;">
			<?php

			// Show title/image etc.
			if ( $show_image ) {
					echo wp_kses_post( apply_filters( 'woocommerce_order_item_thumbnail', '<div style="margin-bottom: 5px"><img src="' . ( $product->get_image_id() ? current( wp_get_attachment_image_src( $product->get_image_id(), 'thumbnail' ) ) : wc_placeholder_img_src() ) . '" alt="' . esc_attr__( 'Product image', 'woocommerce-subscriptions' ) . '" height="' . esc_attr( $image_size[1] ) . '" width="' . esc_attr( $image_size[0] ) . '" style="vertical-align:middle; margin-' . ( is_rtl() ? 'left' : 'right' ) . ': 10px;" /></div>', $item ) );
			}

			// Product name.
			echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) );

			// SKU.
			if ( $show_sku && is_object( $product ) && $product->get_sku() ) {
				// Translators: placeholder is a product SKU.
				sprintf( __( ' (#%s)', 'woocommerce-subscriptions' ), $product->get_sku() );
			}

			// allow other plugins to add additional product information here.
			do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );

			wc_display_item_meta( $item );

			// allow other plugins to add additional product information here.
			do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );

			?>
			</td>
			<td class="td" style="text-align:<?php echo wp_kses_post( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;"><?php echo wp_kses_post( apply_filters( 'woocommerce_email_order_item_quantity', $item->get_quantity(), $item ) ); ?></td>
		</tr>
		<?php
	}

	if ( $show_purchase_note && is_object( $product ) && ( $purchase_note = $product->get_purchase_note() ) ) :
		?>
		<tr>
			<td colspan="3" style="text-align:<?php echo wp_kses_post( $text_align ); ?>; vertical-align:middle; border: 1px solid #eee; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;"><?php echo wp_kses_post( wpautop( do_shortcode( $purchase_note ) ) ); ?></td>
		</tr>
	<?php endif; ?>

<?php endforeach; ?>
