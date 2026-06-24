<?php
/**
 * Single-Product Add-to-Subscription Template.
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/product-add-to-subscription.php'.
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

<div class="wcsatt-add-to-subscription-wrapper closed <?php echo $is_visible ? 'active' : 'inactive'; ?>" data-product_id="<?php echo absint( $product_id ); ?>" <?php echo $is_visible ? '' : 'style="display:none;"'; ?>>
	<label class="wcsatt-add-to-subscription-action-label">
		<input class="wcsatt-add-to-subscription-action-input" type="checkbox" name="add-to-subscription-input" value="yes" />
		<span class="wcsatt-add-to-subscription-action"><?php esc_html_e( 'Add to an existing subscription?', 'woocommerce-subscriptions' ); ?></span>
	</label>
	<div class="wcsatt-add-to-subscription-options <?php echo $force_responsive ? 'wcsatt-add-to-subscription-table-wrapper' : ''; ?>" style="display:none;"></div>
	<input type="hidden" class="add-to-subscription-input" name="add-product-to-subscription" value="<?php echo absint( $product_id ); ?>" />
</div>
