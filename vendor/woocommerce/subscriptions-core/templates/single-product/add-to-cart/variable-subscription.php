<?php
/**
 * Variable subscription product add to cart
 *
 * @author  Prospress
 * @package WooCommerce-Subscriptions/Templates
 * @version 1.0.0 - Migrated from WooCommerce Subscriptions v2.6.0
 */

defined( 'ABSPATH' ) || exit;

global $product;

$attribute_keys = array_keys( $attributes );
$user_id        = get_current_user_id();

do_action( 'woocommerce_before_add_to_cart_form' ); ?>

<form class="variations_form cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype='multipart/form-data' data-product_id="<?php echo absint( $product->get_id() ); ?>" data-product_variations="<?php echo htmlspecialchars( wcs_json_encode( $available_variations ) ) ?>">
	<?php do_action( 'woocommerce_before_variations_form' ); ?>

	<?php if ( empty( $available_variations ) && false !== $available_variations ) : ?>
		<p class="stock out-of-stock"><?php esc_html_e( 'This product is currently out of stock and unavailable.', 'woocommerce-subscriptions' ); ?></p>
	<?php else : ?>
		<?php if ( ! $product->is_purchasable() && 0 !== $user_id && 'no' !== wcs_get_product_limitation( $product ) && wcs_is_product_limited_for_user( $product, $user_id ) ) : ?>
			<?php $resubscribe_link = wcs_get_users_resubscribe_link_for_product( $product->get_id() ); ?>
			<?php if ( ! empty( $resubscribe_link ) && 'any' === wcs_get_product_limitation( $product ) && wcs_user_has_subscription( $user_id, $product->get_id(), wcs_get_product_limitation( $product ) ) && ! wcs_user_has_subscription( $user_id, $product->get_id(), 'active' ) && ! wcs_user_has_subscription( $user_id, $product->get_id(), 'on-hold' ) ) : // customer has an inactive subscription, maybe offer the renewal button. ?>
				<a href="<?php echo esc_url( $resubscribe_link ); ?>" class="woocommerce-button button product-resubscribe-link"><?php esc_html_e( 'Resubscribe', 'woocommerce-subscriptions' ); ?></a>
			<?php else : ?>
				<p class="limited-subscription-notice notice"><?php esc_html_e( 'You have an active subscription to this product already.', 'woocommerce-subscriptions' ); ?></p>
			<?php endif; ?>
		<?php else : ?>
			<?php if ( wp_list_filter( $available_variations, array( 'is_purchasable' => false ) ) ) : ?>
				<p class="limited-subscription-notice notice"><?php esc_html_e( 'You have added a variation of this product to the cart already.', 'woocommerce-subscriptions' ); ?></p>
			<?php endif; ?>
			<table class="variations" cellspacing="0">
				<tbody>
				<?php foreach ( $attributes as $attribute_name => $options ) : ?>
					<tr>
						<td class="label"><label for="<?php echo esc_attr( sanitize_title( $attribute_name ) ); ?>"><?php echo wc_attribute_label( $attribute_name ); /* phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped */ ?></label></td>
						<td class="value">
							<?php
							$selected = isset( $_REQUEST[ 'attribute_' . sanitize_title( $attribute_name ) ] ) ? wc_clean( $_REQUEST[ 'attribute_' . sanitize_title( $attribute_name ) ] ) : $product->get_variation_default_attribute( $attribute_name );
							wc_dropdown_variation_attribute_options( array( 'options' => $options, 'attribute' => $attribute_name, 'product' => $product, 'selected' => $selected ) );
							echo wp_kses( end( $attribute_keys ) === $attribute_name ? apply_filters( 'woocommerce_reset_variations_link', '<a class="reset_variations" href="#">' . __( 'Clear', 'woocommerce-subscriptions' ) . '</a>' ) : '', array( 'a' => array( 'class' => array(), 'href' => array() ) ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			/**
			 * Post WC 3.4 the woocommerce_before_add_to_cart_button hook is triggered by the callback @see woocommerce_single_variation_add_to_cart_button() hooked onto woocommerce_single_variation.
			 */
			if ( wcs_is_woocommerce_pre( '3.4' ) ) {
				do_action( 'woocommerce_before_add_to_cart_button' );
			}
			?>

			<div class="single_variation_wrap">
				<?php
				/**
				 * woocommerce_before_single_variation Hook.
				 */
				do_action( 'woocommerce_before_single_variation' );

				/**
				 * woocommerce_single_variation hook. Used to output the cart button and placeholder for variation data.
				 *
				 * @since  1.0.0 - Migrated from WooCommerce Subscriptions v2.4.0
				 * @hooked woocommerce_single_variation - 10 Empty div for variation data.
				 * @hooked woocommerce_single_variation_add_to_cart_button - 20 Qty and cart button.
				 */
				do_action( 'woocommerce_single_variation' );

				/**
				 * woocommerce_after_single_variation Hook.
				 */
				do_action( 'woocommerce_after_single_variation' );
				?>
			</div>

			<?php
			/**
			 * Post WC 3.4 the woocommerce_after_add_to_cart_button hook is triggered by the callback @see woocommerce_single_variation_add_to_cart_button() hooked onto woocommerce_single_variation.
			 */
			if ( wcs_is_woocommerce_pre( '3.4' ) ) {
				do_action( 'woocommerce_after_add_to_cart_button' );
			}
			?>
		<?php endif; ?>
	<?php endif; ?>

	<?php do_action( 'woocommerce_after_variations_form' ); ?>
</form>

<?php
do_action( 'woocommerce_after_add_to_cart_form' );
