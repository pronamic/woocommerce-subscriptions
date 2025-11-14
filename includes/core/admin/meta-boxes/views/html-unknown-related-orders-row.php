<?php
/**
 * Display a row in the related orders table for a unknown subscription or order.
 *
 * @var int $order_id A WC_Order or WC_Subscription order id.
 * @var string $relationship The order's or subscription's relationship.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<tr>
	<td>
		<?php
		// translators: placeholder is an order ID.
		echo sprintf( esc_html_x( '#%s', 'hash before order number', 'woocommerce-subscriptions' ), esc_html( $order_id ) );
		?>
		<div class="wcs-unknown-order-info-wrapper">
			<a href="https://woocommerce.com/document/subscriptions/orders/#why-are-some-orders-in-the-related-orders-table-not-linking-to-the-order">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo wcs_help_tip(
					sprintf( // Translators: The %1 placeholder is the translated order relationship ("Parent Order"), %2 placeholder is a <br> HTML tag.
						__( 'This %1$s couldn\'t be loaded from the database. %1$s Click to learn more.', 'woocommerce-subscriptions' ),
						esc_html( $relationship ),
						'<br>'
					)
				);
				?>
			</a>
		</div>
	</td>
	<td><?php echo esc_html( $relationship ); ?></td>
	<td>&mdash;</td>
	<td>&mdash;</td>
	<td>&mdash;</td>
</tr>
