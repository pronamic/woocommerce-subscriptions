<?php
/**
 * Add-Cart-to-Subscription List Template.
 *
 * Override this template by copying it to 'yourtheme/woocommerce/cart/cart-add-to-subscription-list.php'.
 *
 * On occasion, this template file may need to be updated and you (the theme developer) will need to copy the new files to your theme to maintain compatibility.
 * We try to do this as little as possible, but it does happen.
 * When this occurs the version of the template file will be bumped and the readme will list any important changes.
 *
 * @version 4.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $subscriptions ) ) {
	?>
	<div class="wcsatt-add-to-subscription-error" role="alert">
		<?php esc_html_e( 'No matching subscriptions found.', 'woocommerce-subscriptions' ); ?>
	</div>
	<?php
} else {
	?>
	<div class="woocommerce_account_subscriptions">

		<?php if ( ! empty( $subscriptions ) ) : ?>
		<table class="my_account_subscriptions woocommerce-orders-table woocommerce-MyAccount-subscriptions shop_table shop_table_responsive woocommerce-orders-table--subscriptions">

			<thead>
				<tr>
					<th class="subscription-id order-number woocommerce-orders-table__header woocommerce-orders-table__header-order-number woocommerce-orders-table__header-subscription-id"><span class="nobr"><?php esc_html_e( 'Subscription', 'woocommerce-subscriptions' ); ?></span></th>
					<th class="subscription-products-overview woocommerce-orders-table__header woocommerce-orders-table__header-products-overview woocommerce-orders-table__header-products-overview"><span class="nobr"><?php esc_html_e( 'Products', 'woocommerce-subscriptions' ); ?></span></th>
					<th class="subscription-next-payment order-date woocommerce-orders-table__header woocommerce-orders-table__header-order-date woocommerce-orders-table__header-subscription-next-payment"><span class="nobr"><?php echo esc_html_x( 'Next payment', 'table heading', 'woocommerce-subscriptions' ); ?></span></th>
					<th class="subscription-total order-total woocommerce-orders-table__header woocommerce-orders-table__header-order-total woocommerce-orders-table__header-subscription-total"><span class="nobr"><?php echo esc_html_x( 'Total', 'table heading', 'woocommerce-subscriptions' ); ?></span></th>
					<th class="subscription-actions order-actions woocommerce-orders-table__header woocommerce-orders-table__header-order-actions woocommerce-orders-table__header-subscription-actions">&nbsp;</th>
				</tr>
			</thead>

			<tbody>
				<?php foreach ( $subscriptions as $subscription_id => $subscription ) : ?>
					<tr class="order woocommerce-orders-table__row woocommerce-orders-table__row--status-<?php echo esc_attr( $subscription->get_status() ); ?>">
						<td class="subscription-id order-number woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-id woocommerce-orders-table__cell-order-number" data-title="<?php esc_attr_e( 'ID', 'woocommerce-subscriptions' ); ?>">
							<a href="<?php echo esc_url( $subscription->get_view_order_url() ); ?>"><?php echo esc_html( sprintf( _x( '#%s', 'hash before order number', 'woocommerce-subscriptions' ), $subscription->get_order_number() ) ); ?></a>
							<?php do_action( 'woocommerce_my_subscriptions_after_subscription_id', $subscription ); ?>
						</td>
						<td class="subscription-total order-total woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-products-overview" data-title="<?php echo esc_attr_x( 'Products', 'Used in data attribute. Escaped', 'woocommerce-subscriptions' ); ?>">
							<?php echo wp_kses_post( WCS_ATT_Order::get_contents_summary( $subscription ) ); ?>
						</td>
						<td class="subscription-next-payment order-date woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-next-payment woocommerce-orders-table__cell-order-date" data-title="<?php echo esc_attr_x( 'Next Payment', 'table heading', 'woocommerce-subscriptions' ); ?>">

							<?php echo esc_attr( $subscription->get_date_to_display( 'next_payment' ) ); ?>

							<?php if ( ! $subscription->is_manual() && $subscription->has_status( 'active' ) && $subscription->get_time( 'next_payment' ) > 0 ) : ?>
							<br/>
							<small>
								<?php echo esc_attr( $subscription->get_payment_method_to_display( 'customer' ) ); ?>
							</small>
							<?php endif; ?>

						</td>
						<td class="subscription-total order-total woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-total woocommerce-orders-table__cell-order-total" data-title="<?php echo esc_attr_x( 'Total', 'Used in data attribute. Escaped', 'woocommerce-subscriptions' ); ?>">
							<?php echo wp_kses_post( $subscription->get_formatted_order_total() ); ?>
						</td>
						<td class="subscription-actions order-actions woocommerce-orders-table__cell woocommerce-orders-table__cell-subscription-actions woocommerce-orders-table__cell-order-actions">
							<?php do_action( 'woocommerce_wcsatt_add_to_subscription_actions', $subscription, $context ); ?>
							<?php // translators: %s is the subscription order number. ?>
							<button type="submit" class="wcsatt-add-to-subscription-button button add alt" name="<?php echo 'cart' === $context ? 'add-cart-to-subscription' : 'add-to-subscription'; ?>" value="<?php echo absint( $subscription_id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Add to subscription #%s', 'woocommerce-subscriptions' ), $subscription->get_order_number() ) ); ?>"><?php echo esc_attr( sprintf( __( 'Add to subscription #%s', 'woocommerce-subscriptions' ), $subscription->get_order_number() ) ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	<?php
}
