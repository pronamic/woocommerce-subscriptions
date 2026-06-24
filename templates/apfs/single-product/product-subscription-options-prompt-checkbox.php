<?php
/**
 * Product Subscription Options Checkbox Prompt Template.
 *
 * Override this template by copying it to 'yourtheme/woocommerce/single-product/product-subscription-options-prompt-checkbox.php'.
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

<label class="wcsatt-options-prompt-label">
	<input class="wcsatt-options-prompt-action-input" type="checkbox" name="subscribe-to-action-input" value="yes" />
	<span class="wcsatt-options-prompt-action"><?php echo wp_kses_post( $cta ); ?></span>
</label>
