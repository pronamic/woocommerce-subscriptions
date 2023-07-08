<?php
/**
 * Pay for order form displayed after a customer has clicked the "Change Payment method" button
 * next to a subscription on their My Account page.
 *
 * @author  Prospress
 * @package WooCommerce/Templates
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<form id="order_review" method="post">

	<table class="shop_table">
		<thead>
			<tr>
				<th class="product-name"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="product-quantity"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
				<th class="product-total"><?php echo esc_html_x( 'Totals', 'table headings in notification email', 'woocommerce-subscriptions' ); ?></th>
			</tr>
		</thead>
		<tfoot>
		<?php foreach ( $subscription->get_order_item_totals() as $total ) : ?>
			<tr>
				<th scope="row" colspan="2"><?php echo esc_html( $total['label'] ); ?></th>
				<td class="product-total"><?php echo wp_kses_post( $total['value'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tfoot>
		<tbody>
		<?php foreach ( $subscription->get_items() as $item ) : ?>
			<tr>
				<td class="product-name"><?php echo esc_html( $item['name'] ); ?></td>
				<td class="product-quantity"><?php echo esc_html( $item['qty'] ); ?></td>
				<td class="product-subtotal"><?php echo wp_kses_post( $subscription->get_formatted_line_subtotal( $item ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<div id="payment">
		<?php
		if ( $subscription->has_payment_gateway() ) {
			$pay_order_button_text = _x( 'Change payment method', 'text on button on checkout page', 'woocommerce-subscriptions' );
		} else {
			$pay_order_button_text = _x( 'Add payment method', 'text on button on checkout page', 'woocommerce-subscriptions' );
		}

		$pay_order_button_text     = apply_filters( 'woocommerce_change_payment_button_text', $pay_order_button_text );
		$customer_subscription_ids = WCS_Customer_Store::instance()->get_users_subscription_ids( $subscription->get_customer_id() );
		$payment_gateways_handler  = WC_Subscriptions_Core_Plugin::instance()->get_gateways_handler_class();

		if ( $available_gateways = WC()->payment_gateways->get_available_payment_gateways() ) :
			?>
			<ul class="payment_methods methods">
				<?php

				if ( count( $available_gateways ) ) {
					current( $available_gateways )->set_current();
				}

				foreach ( $available_gateways as $gateway ) :
					$supports_payment_method_changes = WC_Subscriptions_Change_Payment_Gateway::can_update_all_subscription_payment_methods( $gateway, $subscription );
					?>
					<li class="wc_payment_method payment_method_<?php echo esc_attr( $gateway->id ); ?>">
						<input id="payment_method_<?php echo esc_attr( $gateway->id ); ?>" type="radio" class="input-radio <?php echo $supports_payment_method_changes ? 'supports-payment-method-changes' : ''; ?>" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( apply_filters( 'wcs_gateway_change_payment_button_text', $pay_order_button_text, $gateway ) ); ?>"/>
						<label for="payment_method_<?php echo esc_attr( $gateway->id ); ?>"><?php echo esc_html( $gateway->get_title() ); ?><?php echo wp_kses_post( $gateway->get_icon() ); ?></label>
						<?php
						if ( $gateway->has_fields() || $gateway->get_description() ) {
							echo '<div class="payment_box payment_method_' . esc_attr( $gateway->id ) . '" style="display:none;">';
							$gateway->payment_fields();
							echo '</div>';
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<div class="woocommerce-error">
				<p> <?php echo esc_html( apply_filters( 'woocommerce_no_available_payment_methods_message', __( 'Sorry, it seems no payment gateways support changing the recurring payment method. Please contact us if you require assistance or to make alternate arrangements.', 'woocommerce-subscriptions' ) ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $available_gateways ) : ?>
			<?php if ( count( $customer_subscription_ids ) > 1 && $payment_gateways_handler::one_gateway_supports( 'subscription_payment_method_change_admin' ) ) : ?>
			<span class="update-all-subscriptions-payment-method-wrap">
				<?php
				// translators: $1: opening <strong> tag, $2: closing </strong> tag
				$label = sprintf( esc_html__( 'Update the payment method used for %1$sall%2$s of my current subscriptions', 'woocommerce-subscriptions' ), '<strong>', '</strong>' );

				woocommerce_form_field(
					'update_all_subscriptions_payment_method',
					array(
						'type'    => 'checkbox',
						'class'   => array( 'form-row-wide' ),
						'label'   => $label,
						'default' => apply_filters( 'wcs_update_all_subscriptions_payment_method_checked', false ),
					)
				);
				?>
			</span>
			<?php endif; ?>

		<div class="form-row">
			<?php wp_nonce_field( 'wcs_change_payment_method', '_wcsnonce', true, true ); ?>

			<?php do_action( 'woocommerce_subscriptions_change_payment_before_submit' ); ?>

			<?php
			echo wp_kses(
				apply_filters( 'woocommerce_change_payment_button_html', '<input type="submit" class="button alt" id="place_order" value="' . esc_attr( $pay_order_button_text ) . '" data-value="' . esc_attr( $pay_order_button_text ) . '" />' ),
				array(
					'input' => array(
						'type'       => array(),
						'class'      => array(),
						'id'         => array(),
						'value'      => array(),
						'data-value' => array(),
					),
				)
			);
			?>

			<?php do_action( 'woocommerce_subscriptions_change_payment_after_submit' ); ?>

			<input type="hidden" name="woocommerce_change_payment" value="<?php echo esc_attr( $subscription->get_id() ); ?>" />
		</div>
		<?php endif; ?>

	</div>

</form>
