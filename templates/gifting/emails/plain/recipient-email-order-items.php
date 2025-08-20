<?php
/**
 * Recipient e-mail: order items.
 *
 * @package WooCommerce Subscriptions Gifting/Templates/Emails/Plain
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$text_align = is_rtl() ? 'right' : 'left';

foreach ( $items as $item_id => $item ) {
	$product = $item->get_product();
	if ( apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {

		// Translators: placeholder is the product name.
		echo sprintf( esc_html__( 'Product: %s', 'woocommerce-subscriptions' ), esc_html( $item->get_name() ) ) . "\n";

		if ( $show_sku && is_object( $product ) && $product->get_sku() ) {
			// Translators: placeholder is the product SKU.
			echo sprintf( esc_html__( 'SKU: #%s', 'woocommerce-subscriptions' ), esc_html( $product->get_sku() ) ) . "\n";
		}

		// allow other plugins to add additional product information here.
		do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, $plain_text );

		echo esc_html(
			wp_strip_all_tags(
				wc_display_item_meta(
					$item,
					array(
						'before'    => "\n- ",
						'separator' => "\n- ",
						'after'     => '',
						'echo'      => false,
						'autop'     => false,
					)
				)
			)
		);

		// allow other plugins to add additional product information here.
		do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, $plain_text );

		// Translators: placeholder is the item's quantity.
		echo "\n" . sprintf( esc_html__( 'Quantity: %s', 'woocommerce-subscriptions' ), esc_html( $item->get_quantity() ) ) . "\n";
	}

	if ( $show_purchase_note && is_object( $product ) && ( $purchase_note = $product->get_purchase_note() ) ) {
		// Translators: placeholder is a purchase note.
		echo sprintf( esc_html__( 'Purchase Note: %s', 'woocommerce-subscriptions' ), do_shortcode( $purchase_note ) ) . "\n\n";
	}
}

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";
