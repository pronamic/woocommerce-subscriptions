<?php
/**
 * The template for displaying the empty state for the subscriptions list table.
 *
 * @version 6.2.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="woo_subscriptions_empty_state">
	<div class="woo_subscriptions_empty_state__container">
		<img src="<?php echo esc_url( wcs_get_image_asset_url( 'subscriptions-empty-state.svg' ) ); ?>" alt="">
		<p class="woo_subscriptions_empty_state__description"><?php echo esc_html( apply_filters( 'woocommerce_subscriptions_not_found_description', __( "This is where you'll see and manage all subscriptions in your store. Create a subscription product to turn one-time purchases into a steady income.", 'woocommerce-subscriptions' ) ) ); ?> </p>
		<div class="woo_subscriptions_empty_state__button_container">
			<a href="<?php echo esc_url( WC_Subscriptions_Admin::add_subscription_url() ); ?>" class="components-button is-secondary"><?php esc_html_e( 'Create subscription product', 'woocommerce-subscriptions' ); ?></a>
		</div>
	</div>
</div>

