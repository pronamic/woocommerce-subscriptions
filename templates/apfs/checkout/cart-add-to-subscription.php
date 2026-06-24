<?php
/**
 * Add-Cart-to-Subscription via the Checkout page Template.
 *
 * Override this template by copying it to 'yourtheme/woocommerce/checkout/cart-add-to-subscription.php'.
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
?>

<div class="wcsatt-add-cart-to-subscription-wrapper <?php echo $is_checked ? 'open' : 'closed'; ?>" <?php echo $is_visible ? '' : 'style="display:none;"'; ?>>
	<div class="wcsatt-add-cart-to-subscription-form">
		<h4 class="wcsatt-add-cart-to-subscription-intro"><?php esc_html_e( '&mdash; or &mdash;', 'woocommerce-subscriptions' ); ?></h4>
		<p class="wcsatt-add-cart-to-subscription-action-wrapper">
			<label class="wcsatt-add-cart-to-subscription-action-label">
				<input class="wcsatt-add-cart-to-subscription-action-input" type="checkbox" name="add-to-subscription-checked" value="yes" <?php checked( $is_checked, true ); ?> />
				<span class="wcsatt-add-cart-to-subscription-action">
					<?php
						esc_html_e( 'Add your cart to an existing subscription?', 'woocommerce-subscriptions' );
					?>
				</span>
			</label>
		</p>
		<div class="wcsatt-add-cart-to-subscription-options <?php echo $force_responsive ? 'wcsatt-add-cart-to-subscription-table-wrapper' : ''; ?>" <?php echo $is_checked ? '' : 'style="display:none;"'; ?> >
			<?php
			if ( $is_checked ) {

				/**
				 * 'wcsatt_display_subscriptions_matching_cart' action.
				 *
				 * @since  2.1.0
				 *
				 * @hooked WCS_ATT_Manage_Add::display_subscriptions_matching_cart - 10
				 */
				do_action( 'wcsatt_display_subscriptions_matching_cart' );
			}
			?>
		</div>
	</div>
</div>
