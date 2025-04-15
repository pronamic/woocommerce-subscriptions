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

$columns = array(
	esc_html__( 'Order Number', 'woocommerce-subscriptions' ),
	esc_html__( 'Relationship', 'woocommerce-subscriptions' ),
	esc_html__( 'Date', 'woocommerce-subscriptions' ),
	esc_html__( 'Status', 'woocommerce-subscriptions' ),
	esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ),
);

$columns = apply_filters( 'wcs_related_orders_table_header_columns', $columns );

?>
<div class="woocommerce_subscriptions_related_orders">
	<table>
		<thead>
			<tr>
				<?php foreach ( $columns as $row ) { ?>
					<th><?php echo wp_kses_post( $row ); ?></th>
				<?php } ?>	
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
