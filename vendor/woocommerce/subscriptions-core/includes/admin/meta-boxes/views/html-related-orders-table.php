<?php
/**
 * Display the related orders for a subscription or order.
 *
 * @var object $post The primitive post object that is being displayed (as an order or subscription)
 * @var WC_Order|WC_Subscription $order The order that is being displayed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

?>
<div class="woocommerce_subscriptions_related_orders">
	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Order Number', 'woocommerce-subscriptions' ); ?></th>
				<th><?php esc_html_e( 'Relationship', 'woocommerce-subscriptions' ); ?></th>
				<th><?php esc_html_e( 'Date', 'woocommerce-subscriptions' ); ?></th>
				<th><?php esc_html_e( 'Status', 'woocommerce-subscriptions' ); ?></th>
				<th><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			if ( has_action( 'woocommerce_subscriptions_related_orders_meta_box_rows' ) ) {
				wcs_deprecated_hook( 'woocommerce_subscriptions_related_orders_meta_box_rows', 'subscriptions-core 5.1.0', 'wcs_related_orders_meta_box_rows' );

				/**
				 * Renders renewal order rows in the Related Orders table.
				 *
				 * This action is deprecated in favour of 'wcs_related_orders_meta_box_rows'.
				 *
				 * @deprecated subscriptions-core 5.1.0
				 *
				 * @param WC_Post $post The order post object.
				 */
				do_action( 'woocommerce_subscriptions_related_orders_meta_box_rows', $post );
			}

			/**
			 * Renders renewal order rows in the Related Orders table.
			 *
			 * @since subscriptions-core 5.1.0
			 *
			 * @param WC_Order|WC_Subscription $order The order or subscriptions that is being displayed.
			 */
			do_action( 'wcs_related_orders_meta_box_rows', $order );
			?>
		</tbody>
	</table>
</div>
