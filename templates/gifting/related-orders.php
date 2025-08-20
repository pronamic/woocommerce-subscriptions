<?php
/**
 * Related orders template.
 *
 * @package WooCommerce Subscriptions Gifting/Templates
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

?>
<?php $show_prices = wcsg_is_wc_subscriptions_pre( '2.2.19' ); ?>
<header>
	<h2><?php esc_html_e( 'Related Orders', 'woocommerce-subscriptions' ); ?></h2>
</header>

<table class="shop_table shop_table_responsive my_account_orders">

	<thead>
		<tr>
			<th class="order-number"><span class="nobr"><?php esc_html_e( 'Order', 'woocommerce-subscriptions' ); ?></span></th>
			<th class="order-date"><span class="nobr"><?php esc_html_e( 'Date', 'woocommerce-subscriptions' ); ?></span></th>
			<th class="order-status"><span class="nobr"><?php esc_html_e( 'Status', 'woocommerce-subscriptions' ); ?></span></th>
			<?php if ( $show_prices || get_current_user_id() === $subscription->get_user_id() ) : ?>
			<th class="order-total"><span class="nobr"><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ); ?></span></th>
			<?php endif; ?>
			<th class="order-actions">&nbsp;</th>
		</tr>
	</thead>

	<tbody>
		<?php
		foreach ( $subscription_orders as $subscription_order ) {
			$order        = wc_get_order( $subscription_order ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$display_link = current_user_can( 'view_order', wcsg_get_objects_id( $order ) );
			$item_count   = $order->get_item_count();
			$is_recipient = get_current_user_id() !== $order->get_user_id();

			if ( wcsg_is_woocommerce_pre( '3.0' ) ) {
				$order_date = $order->order_date;
			} else {
				$order_date = $order->get_date_created();
				$order_date->format( 'Y-m-d H:i:s' );
			}

			?>
			<tr class="order">
				<td class="order-number" data-title="<?php esc_attr_e( 'Order Number', 'woocommerce-subscriptions' ); ?>">
					<?php if ( $display_link && ( ! $is_recipient || $show_prices ) ) : ?>
					<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
						#<?php echo esc_html( $order->get_order_number() ); ?>
					</a>
					<?php else : ?>
					<b>#<?php echo esc_html( $order->get_order_number() ); ?><b>
					<?php endif; ?>
				</td>
				<td class="order-date" data-title="<?php esc_attr_e( 'Date', 'woocommerce-subscriptions' ); ?>">
					<time datetime="<?php echo esc_attr( $order_date->date( 'Y-m-d' ) ); ?>" title="<?php echo esc_attr( $order_date->getTimestamp() ); ?>"><?php echo wp_kses_post( $order_date->date_i18n( wc_date_format() ) ); ?></time>
				</td>
				<td class="order-status" data-title="<?php esc_attr_e( 'Status', 'woocommerce-subscriptions' ); ?>" style="text-align:left; white-space:nowrap;">
					<?php
					echo esc_html( wc_get_order_status_name( $order->get_status() ) );
					if ( ! in_array( $order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_payment', array( 'pending', 'failed' ), $order ), true ) ) {
						if ( get_current_user_id() != $order->get_user_id() ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
							echo '</br><small>' . esc_html( 'Purchased by ' . $order->get_user()->display_name ) . '</small>';
						}
					}
					?>
				</td>
				<?php if ( $show_prices || get_current_user_id() === $order->get_user_id() ) : ?>
				<td class="order-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'woocommerce-subscriptions' ); ?>">
					<?php
					// translators: $1: formatted order total for the order, $2: number of items bought.
					echo wp_kses_post( sprintf( _n( '%1$s for %2$d item', '%1$s for %2$d items', $item_count, 'woocommerce-subscriptions' ), $order->get_formatted_order_total(), $item_count ) );
					?>
				</td>
				<?php endif; ?>
				<td class="order-actions">
					<?php
					$actions = array();

					if ( $order->needs_payment() ) {
						$actions['pay'] = array(
							'url'  => $order->get_checkout_payment_url(),
							'name' => esc_html_x( 'Pay', 'pay for a subscription', 'woocommerce-subscriptions' ),
						);
					}

					if ( in_array( $order->get_status(), apply_filters( 'woocommerce_valid_order_statuses_for_cancel', array( 'pending', 'failed' ), $order ), true ) ) {
						$actions['cancel'] = array(
							'url'  => $order->get_cancel_order_url( wc_get_page_permalink( 'myaccount' ) ),
							'name' => esc_html_x( 'Cancel', 'cancel subscription', 'woocommerce-subscriptions' ),
						);
					}
					if ( $display_link && ( ! $is_recipient || $show_prices ) ) {
						$actions['view'] = array(
							'url'  => $order->get_view_order_url(),
							'name' => esc_html_x( 'View', 'view a subscription', 'woocommerce-subscriptions' ),
						);
					}
					$actions = apply_filters( 'woocommerce_my_account_my_orders_actions', $actions, $order );

					if ( $actions ) {
						foreach ( $actions as $key => $action ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
							echo wp_kses_post( '<a href="' . esc_url( $action['url'] ) . '" class="button ' . sanitize_html_class( $key ) . '">' . esc_html( $action['name'] ) . '</a>' );
						}
					}
					?>
				</td>
			</tr>
		<?php } ?>
	</tbody>
</table>

<?php do_action( 'woocommerce_subscription_details_after_subscription_related_orders_table', $subscription ); ?>
